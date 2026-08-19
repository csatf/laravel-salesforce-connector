<?php

declare(strict_types=1);

use Csatf\LaravelSalesforceConnector\SalesforceConnectorServiceProvider;

/*
| The point of these tests: Forrest only *publishes* its config, it never
| merges a default. An app that has not run vendor:publish gets
| config('forrest') === null and fails on the first call. The package fills
| that gap, so consuming apps carry SF_* env vars and no config file.
*/

test('it supplies a complete forrest config without the app publishing one', function () {
    expect(config('forrest'))->toBeArray()
        ->and(config('forrest.credentials'))->toBeArray()
        ->and(config('forrest.storage.type'))->not->toBeNull();
});

test('it defaults to the client credentials flow', function () {
    // Forrest's own default is WebServer, which needs a callback URL and a
    // browser round-trip — unusable for server-to-server integration.
    expect(config('forrest.authentication'))->toBe('ClientCredentials');
});

test('it turns TLS verification on', function () {
    // Forrest ships 'verify' => false, silently disabling certificate checks.
    expect(config('forrest.client.verify'))->toBeTrue();
});

test('it stores tokens in the cache rather than the session', function () {
    // Server-to-server calls have no session to store a token in.
    expect(config('forrest.storage.type'))->toBe('cache');
});

test('it enables guzzle http errors so failures surface as exceptions', function () {
    expect(config('forrest.client.http_errors'))->toBeTrue();
});

test('an app-published forrest config takes precedence', function () {
    config()->set('forrest', ['authentication' => 'OAuthJWT']);

    (new SalesforceConnectorServiceProvider($this->app))->register();

    expect(config('forrest.authentication'))->toBe('OAuthJWT')
        // ...while unspecified keys still fall back to the package defaults.
        ->and(config('forrest.storage.type'))->toBe('cache');
});

test('an app can opt out of package-managed forrest config', function () {
    config()->set('salesforce-connector.manage_forrest_config', false);
    config()->set('forrest', null);

    (new SalesforceConnectorServiceProvider($this->app))->register();

    expect(config('forrest'))->toBeNull();
});

test('it exposes the salesforce offset ceiling', function () {
    expect(config('salesforce-connector.limits.max_offset'))->toBe(2000);
});
