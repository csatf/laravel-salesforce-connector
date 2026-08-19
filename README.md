# csatf/laravel-salesforce-connector

The connectivity layer CSATF Laravel apps use to talk to Salesforce over the REST
API: opinionated `omniphx/forrest` configuration, SOQL escaping and query
building, `nextRecordsUrl` pagination, a health probe, and a test fake.

This package deliberately stops at connectivity. **Object and field mappings stay
in the consuming app** — they are domain, not infrastructure, and they differ per
app. What lives here is the part every app would otherwise get subtly wrong.

Extracted from `csatf/compliance-api`, which is the reference implementation.

## Install

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/csatf/laravel-salesforce-connector.git" }
]
```

```sh
composer require csatf/laravel-salesforce-connector
```

Then set the environment variables below. There is **no config file to publish** —
see [Forrest configuration](#forrest-configuration).

```dotenv
SF_AUTH_METHOD=ClientCredentials
SF_CONSUMER_KEY=
SF_CONSUMER_SECRET=
SF_LOGIN_URL=https://your-org.my.salesforce.com   # MUST be My Domain
SF_API_VERSION=62.0                               # pin it
SF_TOKEN_STORAGE=cache
SF_VERIFY_SSL=true
```

Getting those values, and the Salesforce-side setup that has to happen first, is
in **[docs/salesforce-org-setup.md](docs/salesforce-org-setup.md)**. Read that
before assuming the code is broken — most first-time failures are org config.

Optionally publish the config to inspect or override defaults:

```sh
php artisan vendor:publish --tag=salesforce-connector-config
```

## What it provides

### 1. Forrest configuration

`omniphx/forrest` reads `config('forrest')` but **only publishes** its config file
— it never merges a default. An app that hasn't run `vendor:publish` gets
`config('forrest') === null` and fails on the first call, which is why every app
otherwise ends up carrying a 94-line vendor config file it never meaningfully
edits.

This package supplies that config from `SF_*` env vars, and changes three of
Forrest's defaults that are wrong for server-to-server use:

| Setting | Forrest default | Here | Why |
|---|---|---|---|
| `authentication` | `WebServer` | `ClientCredentials` | No certificate to rotate, no callback, works headlessly |
| `client.verify` | `false` | `true` | Forrest ships with TLS verification **off** |
| `storage.type` | `session` | `cache` | Server-to-server calls have no session |

An app that publishes its own `config/forrest.php` still wins — values merge
recursively over these defaults. Opt out entirely with
`SF_MANAGE_FORREST_CONFIG=false`.

### 2. `SoqlClient`

The only place an app should touch the Forrest facade.

```php
use Csatf\LaravelSalesforceConnector\Soql\SoqlClient;
use Csatf\LaravelSalesforceConnector\Soql\SoqlQuery;

public function __construct(private SoqlClient $salesforce) {}

$envelope = $this->salesforce->query($soql);              // raw response
$records  = $this->salesforce->fetchAll($soql);           // Collection, all pages
$row      = $this->salesforce->first($soql);              // ?array
$total    = $this->salesforce->scalar($soql, 'total', 0); // aggregate alias
$ids      = $this->salesforce->pluck($soql, 'il');        // one column, all pages
```

- **Authenticates once** per instance, lazily.
- **`fetchAll()` follows `nextRecordsUrl`.** Salesforce caps a response at 2000
  records and signals more with `done: false`. Not following that is a silent
  truncation bug — you get a plausible, wrong, partial result set. (Distinct from
  Forrest's `queryAll()`, which is the *queryAll resource*: deleted and archived
  records.)
- **Recovers from a vanished token.** The token lives in the cache store, so a
  deploy or `cache:clear` between requests leaves a long-lived worker believing it
  is authenticated when it isn't. Rather than 500, it re-authenticates and retries
  exactly once.

### 3. SOQL building and escaping

SOQL has no bound-parameter API over REST — the query is a string in a URL — so
every user-supplied value must be escaped where it is interpolated.

```php
use Csatf\LaravelSalesforceConnector\Soql\Soql;

Soql::escape("O'Brien");      // O\'Brien
Soql::quote('Smith');         // 'Smith'
Soql::escapeLike('50%');      // 50\%
Soql::in('Id', ['A', 'B']);   // Id IN ('A', 'B')
```

**`escapeLike()` matters more than it looks.** `%` and `_` are SOQL wildcards. A
starts-with filter built with plain escaping turns `?name_last=%` into
`LIKE '%%'`, which matches every row — the filter silently stops filtering. `_` is
subtler: `a_c` quietly matches `abc`. This was a live bug in the reference
implementation and is the reason this lives in one place.

`SoqlQuery` composes statements without sprintf templates:

```php
$soql = SoqlQuery::from('Placement_Requirement__c')
    ->select(['Id', 'Name'])
    ->whereEquals('Status__c', 'Expired')
    ->whereLike('Contact__r.LastName', $lastName, 'starts_with')
    ->whereIn('Contact__r.Industry_Link__c', $ilNumbers)
    ->orderBy('Name')
    ->limit(25)
    ->offset(($page - 1) * 25);
```

`offset()` clamps to Salesforce's hard **OFFSET 2000** ceiling, which SOQL rejects
outright rather than returning an empty set. Use `exceedsOffsetCeiling()` to tell
a caller that deeper pages are unreachable instead of silently serving the wrong
one:

```php
if ($query->exceedsOffsetCeiling($offset)) {
    // surface a "refine your search" response rather than a misleading page
}
```

### 4. `FilterCompiler`

Turns an app-owned filter map plus request values into SOQL conditions, so adding
a filter is a config change:

```php
// config/your-app-salesforce.php
'filters' => [
    'name_last'    => ['path' => 'Contact__r.LastName', 'op' => 'starts_with'],
    'email'        => ['path' => 'Contact__r.Email',    'op' => 'equals'],
    'local_number' => ['path' => 'Local__c',            'op' => 'contains'],
],
```

```php
$conditions = (new FilterCompiler)->compile(
    config('your-app-salesforce.filters'),
    $request->only(['name_last', 'email', 'local_number']),
);

$query->whereRawAll($conditions);
```

Null and empty values are skipped, so an absent query parameter contributes no
condition. Operators: `equals`, `not_equals`, `starts_with`, `ends_with`,
`contains`, `in`, `greater_than`, `less_than`, `greater_or_equal`,
`less_or_equal`. All values are escaped, wildcards included.

### 5. `SalesforceHealth`

```php
app(SalesforceHealth::class)->check();
// ['healthy' => true, 'auth' => true, 'read' => true, 'error' => null]
```

Separates "can we authenticate" from "do we have field-level read access", which
are the two failure modes that look identical from the outside. Set
`SF_HEALTH_PROBE_OBJECT=Contact` to assert a real read as well as auth.

It uses `versions()`, **not `Forrest::limits()`** — `/limits` returns
`403 API_DISABLED_FOR_ORG` under the Salesforce Integration license, so a
limits-based health check reports a permanent outage on a perfectly healthy org.

### 6. `SalesforceFake`

Replaces hand-rolled Forrest mocks. Binds itself as the container's `forrest`
instance, so the facade, `SoqlClient`, and your repositories all hit it.

```php
use Csatf\LaravelSalesforceConnector\Testing\SalesforceFake;

$sf = SalesforceFake::swap();
$sf->respondToCount(1);
$sf->respondTo('GROUP BY', ['records' => [['il' => 'ABC1234567']]]);
$sf->respondWith(['done' => true, 'records' => [$row]]);

$this->getJson(route('api.roster', ['name_last' => 'Smith']))->assertSuccessful();

$sf->assertQueried("LastName LIKE 'Smith%'");
```

Rules match by substring or closure, first match wins, with a fallback response.
Also available: `respondWithPages()` (exercises the `nextRecordsUrl` path a
single-page fake never reaches), `throwOnNextQuery()`, `throwOnAuthenticate()`,
`queries()`, `assertNotQueried()`, `assertQueryCount()`, `assertNothingQueried()`.

Asserting on the *query* rather than the response is usually the point: a filter
that never reaches the `WHERE` clause is invisible in the response body and
obvious in the query log.

## What stays in your app

- The object/field map (`config/<app>-salesforce.php`) — your Salesforce schema.
- The repository that turns SOQL rows into your models or DTOs.
- Controllers, routes, requests, resources.

A repository in a consuming app looks roughly like:

```php
class RosterRepository
{
    public function __construct(
        private SoqlClient $salesforce,
        private FilterCompiler $filters,
    ) {}

    public function search(array $filters): Collection
    {
        $query = SoqlQuery::from(config('app-salesforce.root'))
            ->select(array_values(config('app-salesforce.fields')))
            ->whereRawAll(config('app-salesforce.base_conditions'))
            ->whereRawAll($this->filters->compile(config('app-salesforce.filters'), $filters));

        return $this->salesforce->fetchAll($query)->map($this->toModel(...));
    }
}
```

## Requirements

PHP 8.2+, Laravel 11 / 12 / 13, `omniphx/forrest` ^3.0.

## Development

```sh
composer install
vendor/bin/pest
vendor/bin/phpstan analyse
vendor/bin/pint
```

The suite resolves to Laravel 13 / Testbench 11 locally, via
`csatf/laravel-devtools` ^1.3. Laravel 11 and 12 support is declared and honoured
by the constraints, but is not what the local suite exercises — devtools itself
requires Laravel 12+, so a Laravel 11 test environment has to be resolved
without it.
