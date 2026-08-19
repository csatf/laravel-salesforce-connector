<?php

declare(strict_types=1);

namespace Csatf\LaravelSalesforceConnector\Testing;

use Closure;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\Assert as PHPUnit;
use Throwable;

/**
 * An in-memory stand-in for the Forrest client.
 *
 * Binds itself as the container's `forrest` instance, so the Forrest facade,
 * SoqlClient, and anything else resolving it transparently hit the fake. It
 * records every SOQL string it is handed, which is usually the thing worth
 * asserting on: a filter that never reaches the WHERE clause is invisible in
 * the response body but obvious in the query log.
 *
 * Responses are matched in registration order; the first matching rule wins,
 * falling back to the default response.
 *
 *     $sf = SalesforceFake::swap();
 *     $sf->respondTo('COUNT_DISTINCT', ['records' => [['total' => 1]]]);
 *     $sf->respondWith(['done' => true, 'records' => [$row]]);
 *
 *     // ...exercise the code under test...
 *
 *     $sf->assertQueried("LastName LIKE 'Smith%'");
 */
class SalesforceFake
{
    /** @var array<int, array{matcher: Closure(string): bool, response: Closure(string): mixed}> */
    protected array $rules = [];

    /** @var Closure(string): mixed */
    protected Closure $default;

    /** @var array<int, string> */
    protected array $queries = [];

    protected int $authenticateCount = 0;

    /** @var array<int, Throwable> */
    protected array $pendingThrows = [];

    /** @var (Closure(string): mixed)|null */
    protected ?Closure $nextHandler = null;

    protected ?Throwable $authenticateThrow = null;

    /** @var array<int, array<string, string>> */
    protected array $versionsResponse = [
        ['label' => 'Testing', 'url' => '/services/data/v62.0', 'version' => '62.0'],
    ];

    /**
     * Final so that {@see swap()}'s `new static` is safe for subclasses that
     * add app-specific response helpers.
     */
    final public function __construct()
    {
        $this->default = static fn (): array => ['done' => true, 'records' => []];
    }

    /**
     * Instantiate the fake and bind it into the container as `forrest`.
     */
    public static function swap(): static
    {
        $fake = new static;

        app()->instance('forrest', $fake);
        Facade::clearResolvedInstance('forrest');

        return $fake;
    }

    /**
     * Respond to queries matching a substring or predicate.
     *
     * @param  string|Closure(string): bool  $matcher
     * @param  mixed|Closure(string): mixed  $response
     */
    public function respondTo(string|Closure $matcher, mixed $response): static
    {
        $predicate = $matcher instanceof Closure
            ? $matcher
            : static fn (string $soql): bool => str_contains($soql, $matcher);

        $this->rules[] = [
            'matcher' => $predicate,
            'response' => $response instanceof Closure ? $response : static fn (): mixed => $response,
        ];

        return $this;
    }

    /**
     * Set the fallback response for queries no rule matched.
     *
     * @param  mixed|Closure(string): mixed  $response
     */
    public function respondWith(mixed $response): static
    {
        $this->default = $response instanceof Closure ? $response : static fn (): mixed => $response;

        return $this;
    }

    /**
     * Convenience for the common aggregate shape: `SELECT COUNT_DISTINCT(x) total`.
     */
    public function respondToCount(int $total, string $alias = 'total'): static
    {
        return $this->respondTo('COUNT', ['records' => [[$alias => $total]]]);
    }

    /**
     * Serve a record set across multiple pages, exercising the `nextRecordsUrl`
     * pagination path that a single-page fake would never reach.
     *
     * @param  array<int, array<int, array<string, mixed>>>  $pages
     */
    public function respondWithPages(array $pages): static
    {
        $pages = array_values($pages);
        $lastIndex = max(0, count($pages) - 1);

        /*
         | The page index is encoded in the nextRecordsUrl rather than tracked
         | in a cursor, so a second top-level query correctly restarts at page
         | zero instead of resuming wherever the previous one stopped.
         */
        $build = static fn (int $index): array => [
            'done' => $index >= $lastIndex,
            'records' => $pages[$index] ?? [],
            'nextRecordsUrl' => $index >= $lastIndex
                ? null
                : '/services/data/v62.0/query/next-'.($index + 1),
        ];

        $this->respondWith(static fn (): array => $build(0));

        $this->onNext(static function (string $url) use ($build, $lastIndex): array {
            $segment = substr($url, (int) strrpos($url, '/') + 1);
            $index = (int) str_replace('next-', '', $segment);

            return $build(min($index, $lastIndex));
        });

        return $this;
    }

    /**
     * Handle `next()` calls (pagination follow-up) explicitly.
     *
     * @param  Closure(string): mixed  $handler
     */
    public function onNext(Closure $handler): static
    {
        $this->nextHandler = $handler;

        return $this;
    }

    /**
     * Queue an exception to be thrown by the next query call. Useful for
     * exercising re-authentication and error handling.
     */
    public function throwOnNextQuery(Throwable $e): static
    {
        $this->pendingThrows[] = $e;

        return $this;
    }

    /**
     * Make every authentication attempt fail — the "bad credentials / wrong
     * login URL" case, as distinct from a query-level permission failure.
     */
    public function throwOnAuthenticate(Throwable $e): static
    {
        $this->authenticateThrow = $e;

        return $this;
    }

    // ---------------------------------------------------------------------
    // Forrest surface
    // ---------------------------------------------------------------------

    public function authenticate(?string $url = null): void
    {
        $this->authenticateCount++;

        if ($this->authenticateThrow !== null) {
            throw $this->authenticateThrow;
        }
    }

    /**
     * @return array<int, array<string, string>>
     */
    public function versions(array $options = []): array
    {
        return $this->versionsResponse;
    }

    public function query(string $soql, array $options = []): mixed
    {
        $this->queries[] = $soql;

        if ($this->pendingThrows !== []) {
            throw array_shift($this->pendingThrows);
        }

        foreach ($this->rules as $rule) {
            if (($rule['matcher'])($soql)) {
                return ($rule['response'])($soql);
            }
        }

        return ($this->default)($soql);
    }

    public function next(string $nextUrl, array $options = []): mixed
    {
        if ($this->nextHandler !== null) {
            return ($this->nextHandler)($nextUrl);
        }

        return ['done' => true, 'records' => []];
    }

    // ---------------------------------------------------------------------
    // Inspection & assertions
    // ---------------------------------------------------------------------

    /**
     * @return array<int, string>
     */
    public function queries(): array
    {
        return $this->queries;
    }

    public function authenticateCount(): int
    {
        return $this->authenticateCount;
    }

    /**
     * The first recorded query containing the given needle, or null.
     */
    public function queryContaining(string $needle): ?string
    {
        foreach ($this->queries as $query) {
            if (str_contains($query, $needle)) {
                return $query;
            }
        }

        return null;
    }

    public function assertQueried(string $needle): static
    {
        PHPUnit::assertNotNull(
            $this->queryContaining($needle),
            "Expected a SOQL query containing [{$needle}].\nQueries run:\n".$this->formatQueries()
        );

        return $this;
    }

    public function assertNotQueried(string $needle): static
    {
        PHPUnit::assertNull(
            $this->queryContaining($needle),
            "Expected no SOQL query containing [{$needle}], but one was run.\nQueries run:\n".$this->formatQueries()
        );

        return $this;
    }

    public function assertQueryCount(int $expected): static
    {
        PHPUnit::assertCount(
            $expected,
            $this->queries,
            "Expected {$expected} SOQL queries.\nQueries run:\n".$this->formatQueries()
        );

        return $this;
    }

    public function assertNothingQueried(): static
    {
        return $this->assertQueryCount(0);
    }

    private function formatQueries(): string
    {
        if ($this->queries === []) {
            return '  (none)';
        }

        return collect($this->queries)
            ->map(static fn (string $q, int $i): string => '  '.($i + 1).'. '.$q)
            ->implode("\n");
    }
}
