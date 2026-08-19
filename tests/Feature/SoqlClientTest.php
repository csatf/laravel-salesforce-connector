<?php

declare(strict_types=1);

use Csatf\LaravelSalesforceConnector\Soql\SoqlClient;
use Csatf\LaravelSalesforceConnector\Soql\SoqlQuery;
use Csatf\LaravelSalesforceConnector\Testing\SalesforceFake;
use Omniphx\Forrest\Exceptions\MissingTokenException;

beforeEach(function () {
    $this->sf = SalesforceFake::swap();
    $this->client = new SoqlClient;
});

test('it authenticates once across many queries', function () {
    $this->sf->respondWith(['done' => true, 'records' => []]);

    $this->client->query('SELECT Id FROM Contact');
    $this->client->query('SELECT Id FROM Account');
    $this->client->query('SELECT Id FROM Lead');

    expect($this->sf->authenticateCount())->toBe(1);
});

test('it accepts a SoqlQuery object', function () {
    $this->client->query(SoqlQuery::from('Contact')->select('Id')->limit(1));

    $this->sf->assertQueried('SELECT Id FROM Contact LIMIT 1');
});

test('it returns the raw response envelope', function () {
    $this->sf->respondWith(['done' => true, 'totalSize' => 1, 'records' => [['Id' => '003']]]);

    expect($this->client->query('SELECT Id FROM Contact'))
        ->toBe(['done' => true, 'totalSize' => 1, 'records' => [['Id' => '003']]]);
});

test('it normalises a non-array response to an empty array', function () {
    $this->sf->respondWith(null);

    expect($this->client->query('SELECT Id FROM Contact'))->toBe([]);
});

test('fetchAll follows nextRecordsUrl until done', function () {
    $this->sf->respondWithPages([
        [['Id' => '1'], ['Id' => '2']],
        [['Id' => '3'], ['Id' => '4']],
        [['Id' => '5']],
    ]);

    $records = $this->client->fetchAll('SELECT Id FROM Contact');

    expect($records)->toHaveCount(5)
        ->and($records->pluck('Id')->all())->toBe(['1', '2', '3', '4', '5']);
});

test('fetchAll returns a single page unchanged when done is true', function () {
    $this->sf->respondWith(['done' => true, 'records' => [['Id' => '1']]]);

    expect($this->client->fetchAll('SELECT Id FROM Contact'))->toHaveCount(1);
});

test('fetchAll stops when done is false but no next URL is supplied', function () {
    $this->sf->respondWith(['done' => false, 'records' => [['Id' => '1']]]);

    expect($this->client->fetchAll('SELECT Id FROM Contact'))->toHaveCount(1);
});

test('fetchAll handles a response with no records key', function () {
    $this->sf->respondWith(['done' => true]);

    expect($this->client->fetchAll('SELECT Id FROM Contact'))->toBeEmpty();
});

test('first returns the leading record or null', function () {
    $this->sf->respondWith(['records' => [['Id' => 'A'], ['Id' => 'B']]]);
    expect($this->client->first('SELECT Id FROM Contact'))->toBe(['Id' => 'A']);

    $this->sf->respondWith(['records' => []]);
    expect($this->client->first('SELECT Id FROM Contact'))->toBeNull();
});

test('scalar pulls an aggregate alias off the first row', function () {
    $this->sf->respondWith(['records' => [['total' => 42]]]);

    expect($this->client->scalar('SELECT COUNT_DISTINCT(Id) total FROM Contact', 'total', 0))->toBe(42);
});

test('scalar falls back to the default when the alias is missing', function () {
    $this->sf->respondWith(['records' => []]);

    expect($this->client->scalar('SELECT COUNT(Id) total FROM Contact', 'total', 0))->toBe(0);
});

test('pluck collects one column across every page, dropping blanks', function () {
    $this->sf->respondWithPages([
        [['il' => 'A'], ['il' => null]],
        [['il' => 'B'], ['il' => '']],
    ]);

    expect($this->client->pluck('SELECT Industry_Link__c il FROM Contact', 'il'))->toBe(['A', 'B']);
});

test('it re-authenticates once and retries when the cached token has vanished', function () {
    // Simulates the cache being flushed mid-process: the client believes it is
    // authenticated, but the token store is empty.
    $this->sf->respondWith(['done' => true, 'records' => [['Id' => '1']]]);
    $this->client->query('SELECT Id FROM Contact');
    expect($this->sf->authenticateCount())->toBe(1);

    $this->sf->throwOnNextQuery(new MissingTokenException('No token available'));

    $records = $this->client->fetchAll('SELECT Id FROM Contact');

    expect($this->sf->authenticateCount())->toBe(2)
        ->and($records)->toHaveCount(1);
});

test('it gives up if re-authentication does not fix the token', function () {
    $this->sf->throwOnNextQuery(new MissingTokenException('No token available'));
    $this->sf->throwOnNextQuery(new MissingTokenException('No token available'));

    $this->client->query('SELECT Id FROM Contact');
})->throws(MissingTokenException::class);

test('forgetAuthentication forces the next call to authenticate again', function () {
    $this->client->query('SELECT Id FROM Contact');
    $this->client->forgetAuthentication();
    $this->client->query('SELECT Id FROM Contact');

    expect($this->sf->authenticateCount())->toBe(2);
});
