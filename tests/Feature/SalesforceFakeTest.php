<?php

declare(strict_types=1);

use Csatf\LaravelSalesforceConnector\Soql\SoqlClient;
use Csatf\LaravelSalesforceConnector\Testing\SalesforceFake;
use Omniphx\Forrest\Providers\Laravel\Facades\Forrest;
use PHPUnit\Framework\ExpectationFailedException;

beforeEach(function () {
    $this->sf = SalesforceFake::swap();
    $this->client = new SoqlClient;
});

test('it intercepts calls made through the Forrest facade', function () {
    Forrest::authenticate();

    expect(Forrest::query('SELECT Id FROM Contact'))->toBe(['done' => true, 'records' => []])
        ->and($this->sf->queries())->toBe(['SELECT Id FROM Contact']);
});

test('it matches rules by substring in registration order', function () {
    $this->sf->respondTo('COUNT_DISTINCT', ['records' => [['total' => 7]]]);
    $this->sf->respondTo('FROM Contact', ['records' => [['Id' => 'contact']]]);
    $this->sf->respondWith(['records' => [['Id' => 'fallback']]]);

    expect($this->client->scalar('SELECT COUNT_DISTINCT(Id) total FROM Contact', 'total'))->toBe(7)
        ->and($this->client->first('SELECT Id FROM Contact'))->toBe(['Id' => 'contact'])
        ->and($this->client->first('SELECT Id FROM Account'))->toBe(['Id' => 'fallback']);
});

test('it matches rules by closure', function () {
    $this->sf->respondTo(
        fn (string $soql): bool => str_contains($soql, 'GROUP BY'),
        ['records' => [['il' => 'ABC']]],
    );

    expect($this->client->first('SELECT Id il FROM Contact GROUP BY Id'))->toBe(['il' => 'ABC']);
});

test('a closure response receives the query', function () {
    $this->sf->respondWith(fn (string $soql): array => ['records' => [['echo' => $soql]]]);

    expect($this->client->first('SELECT Id FROM Contact'))->toBe(['echo' => 'SELECT Id FROM Contact']);
});

test('respondToCount is a shorthand for the aggregate shape', function () {
    $this->sf->respondToCount(12);

    expect($this->client->scalar('SELECT COUNT_DISTINCT(Id) total FROM Contact', 'total'))->toBe(12);
});

test('a fresh top-level query restarts pagination', function () {
    $this->sf->respondWithPages([[['Id' => '1']], [['Id' => '2']]]);

    expect($this->client->fetchAll('SELECT Id FROM Contact'))->toHaveCount(2)
        ->and($this->client->fetchAll('SELECT Id FROM Contact'))->toHaveCount(2);
});

test('assertQueried passes when a matching query ran', function () {
    $this->client->query("SELECT Id FROM Contact WHERE LastName LIKE 'Smith%'");

    $this->sf->assertQueried("LastName LIKE 'Smith%'");
});

test('assertQueried fails with the query log attached', function () {
    $this->client->query('SELECT Id FROM Contact');

    expect(fn () => $this->sf->assertQueried('Account'))
        ->toThrow(
            ExpectationFailedException::class,
            'SELECT Id FROM Contact'
        );
});

test('assertNotQueried guards against a filter leaking into the query', function () {
    $this->client->query('SELECT Id FROM Contact');

    $this->sf->assertNotQueried('DELETE');
});

test('assertQueryCount and assertNothingQueried count calls', function () {
    $this->sf->assertNothingQueried();

    $this->client->query('SELECT Id FROM Contact');
    $this->client->query('SELECT Id FROM Account');

    $this->sf->assertQueryCount(2);
});

test('queryContaining returns the first match or null', function () {
    $this->client->query('SELECT Id FROM Contact');

    expect($this->sf->queryContaining('Contact'))->toBe('SELECT Id FROM Contact')
        ->and($this->sf->queryContaining('Nope'))->toBeNull();
});

test('it records queries that threw, so failures are still inspectable', function () {
    $this->sf->throwOnNextQuery(new RuntimeException('nope'));

    try {
        $this->client->query('SELECT Id FROM Contact');
    } catch (RuntimeException) {
        // expected
    }

    $this->sf->assertQueried('SELECT Id FROM Contact');
});
