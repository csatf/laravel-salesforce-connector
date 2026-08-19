<?php

declare(strict_types=1);

use Csatf\LaravelSalesforceConnector\Health\SalesforceHealth;
use Csatf\LaravelSalesforceConnector\Testing\SalesforceFake;
use Omniphx\Forrest\Exceptions\InvalidLoginCreditialsException;
use Omniphx\Forrest\Exceptions\MissingVersionException;

beforeEach(function () {
    $this->sf = SalesforceFake::swap();
    $this->health = $this->app->make(SalesforceHealth::class);
});

test('it reports healthy when authentication succeeds', function () {
    expect($this->health->check())->toBe([
        'healthy' => true,
        'auth' => true,
        'read' => null,
        'error' => null,
    ]);
});

test('it reports an auth failure distinctly from a read failure', function () {
    $this->sf->throwOnAuthenticate(new InvalidLoginCreditialsException('invalid_client_id'));
    config()->set('salesforce-connector.health.probe_object', 'Contact');

    $result = $this->health->check();

    expect($result['healthy'])->toBeFalse()
        ->and($result['auth'])->toBeFalse()
        // read is null, not false: we never got far enough to find out.
        ->and($result['read'])->toBeNull()
        ->and($result['error'])->toContain('invalid_client_id');
});

test('it reports a read failure once authentication has succeeded', function () {
    // The common real-world case: OAuth works, but field-level security has
    // not been granted, so the query fails while auth is fine.
    $this->sf->throwOnNextQuery(new MissingVersionException('INVALID_FIELD'));
    config()->set('salesforce-connector.health.probe_object', 'Contact');

    $result = $this->health->check();

    expect($result['healthy'])->toBeFalse()
        ->and($result['auth'])->toBeTrue()
        ->and($result['read'])->toBeFalse()
        ->and($result['error'])->toContain('INVALID_FIELD');
});

test('it probes a real read when a probe object is configured', function () {
    config()->set('salesforce-connector.health.probe_object', 'Contact');

    $result = $this->health->check();

    expect($result)->toBe(['healthy' => true, 'auth' => true, 'read' => true, 'error' => null]);
    $this->sf->assertQueried('SELECT Id FROM Contact LIMIT 1');
});

test('it skips the read probe when no probe object is configured', function () {
    config()->set('salesforce-connector.health.probe_object', null);

    expect($this->health->check()['read'])->toBeNull();
    $this->sf->assertNothingQueried();
});

test('it never calls the limits resource', function () {
    // /limits returns 403 API_DISABLED_FOR_ORG under the Salesforce Integration
    // license, so a limits-based probe reports a permanent outage on a healthy org.
    config()->set('salesforce-connector.health.probe_object', 'Contact');

    $this->health->check();

    expect(method_exists($this->sf, 'limits'))->toBeFalse();
});

test('isHealthy reduces the report to a boolean', function () {
    expect($this->health->isHealthy())->toBeTrue();
});

test('it reports the error class alongside the message', function () {
    config()->set('salesforce-connector.health.probe_object', 'Contact');
    $this->sf->throwOnNextQuery(new MissingVersionException('INVALID_FIELD'));

    expect($this->health->check()['error'])->toBe('MissingVersionException: INVALID_FIELD');
});
