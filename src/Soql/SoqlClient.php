<?php

declare(strict_types=1);

namespace Csatf\LaravelSalesforceConnector\Soql;

use Illuminate\Support\Collection;
use Omniphx\Forrest\Exceptions\MissingTokenException;
use Omniphx\Forrest\Exceptions\TokenExpiredException;
use Omniphx\Forrest\Providers\Laravel\Facades\Forrest;
use Stringable;

/**
 * The single place in an application that talks to Forrest.
 *
 * Wraps three things every consumer would otherwise reimplement: authenticating
 * once (and recovering when the cached token disappears), following Salesforce's
 * `nextRecordsUrl` pagination, and reducing result envelopes to plain data.
 */
class SoqlClient
{
    private bool $authenticated = false;

    /**
     * Run a query and return the raw Salesforce response envelope.
     *
     * @return array<string, mixed>
     */
    public function query(SoqlQuery|Stringable|string $soql): array
    {
        return $this->call(static fn (string $query): mixed => Forrest::query($query), (string) $soql);
    }

    /**
     * Run a query and follow `nextRecordsUrl` until every record is collected.
     *
     * Salesforce returns at most 2000 records per response and signals more
     * with `done: false`. Forgetting to follow that is the classic silent
     * truncation bug — you get a plausible-looking partial result set.
     *
     * Not to be confused with Forrest's `queryAll()`, which is Salesforce's
     * queryAll resource (includes deleted and archived records).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function fetchAll(SoqlQuery|Stringable|string $soql): Collection
    {
        $response = $this->query($soql);

        /** @var Collection<int, array<string, mixed>> $records */
        $records = collect($response['records'] ?? []);

        while (($response['done'] ?? true) === false && ! empty($response['nextRecordsUrl'])) {
            $nextUrl = (string) $response['nextRecordsUrl'];

            $response = $this->call(static fn (string $url): mixed => Forrest::next($url), $nextUrl);

            $records = $records->merge($response['records'] ?? []);
        }

        return $records->values();
    }

    /**
     * Run a query and return only the first record, or null.
     *
     * @return array<string, mixed>|null
     */
    public function first(SoqlQuery|Stringable|string $soql): ?array
    {
        $records = $this->query($soql)['records'] ?? [];

        return $records[0] ?? null;
    }

    /**
     * Pull a single aggregate value out of the first row.
     *
     * Typically used with a COUNT/COUNT_DISTINCT alias:
     *
     *     $client->scalar($query, 'total', 0)
     */
    public function scalar(SoqlQuery|Stringable|string $soql, string $alias, mixed $default = null): mixed
    {
        return data_get($this->query($soql), "records.0.{$alias}", $default);
    }

    /**
     * Run a query and pluck one aliased column across every (paginated) row.
     *
     * @return array<int, mixed>
     */
    public function pluck(SoqlQuery|Stringable|string $soql, string $alias): array
    {
        return $this->fetchAll($soql)
            ->pluck($alias)
            ->filter(static fn (mixed $value): bool => $value !== null && $value !== '')
            ->values()
            ->all();
    }

    /**
     * Authenticate if this instance has not yet done so.
     */
    public function authenticate(): void
    {
        if ($this->authenticated) {
            return;
        }

        Forrest::authenticate();

        $this->authenticated = true;
    }

    /**
     * Force the next call to re-authenticate.
     */
    public function forgetAuthentication(): void
    {
        $this->authenticated = false;
    }

    /**
     * Execute a Forrest call, authenticating first and recovering once from a
     * vanished token.
     *
     * The token lives in the cache store, so anything that flushes the cache
     * between requests — a deploy, a `cache:clear`, an eviction — leaves a
     * long-lived process believing it is authenticated when it is not. Rather
     * than surface that as a 500, re-authenticate and retry exactly once.
     *
     * @param  callable(string): mixed  $callback
     * @return array<string, mixed>
     */
    protected function call(callable $callback, string $argument): array
    {
        $this->authenticate();

        try {
            $response = $callback($argument);
        } catch (MissingTokenException|TokenExpiredException) {
            $this->forgetAuthentication();
            $this->authenticate();

            $response = $callback($argument);
        }

        return is_array($response) ? $response : [];
    }
}
