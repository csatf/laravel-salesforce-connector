<?php

declare(strict_types=1);

namespace Csatf\LaravelSalesforceConnector\Soql;

use Illuminate\Support\Traits\Conditionable;
use LogicException;
use Stringable;

/**
 * A small immutable-ish builder for SOQL SELECT statements.
 *
 * It exists to keep sprintf() templates out of application repositories, and
 * to make the OFFSET ceiling impossible to trip over. It intentionally covers
 * only the read subset used for querying: SELECT / WHERE / GROUP BY /
 * ORDER BY / LIMIT / OFFSET.
 */
final class SoqlQuery implements Stringable
{
    use Conditionable;

    /** @var array<int, string> */
    private array $select = [];

    /** @var array<int, string> */
    private array $conditions = [];

    /** @var array<int, string> */
    private array $groupBy = [];

    /** @var array<int, string> */
    private array $orderBy = [];

    private ?int $limit = null;

    private ?int $offset = null;

    public function __construct(private string $from) {}

    public static function from(string $object): self
    {
        return new self($object);
    }

    /**
     * @param  array<int, string>|string  $fields
     */
    public function select(array|string $fields): self
    {
        $fields = is_array($fields) ? $fields : [$fields];

        $this->select = array_values(array_unique([...$this->select, ...$fields]));

        return $this;
    }

    /**
     * Add a raw, already-escaped condition.
     *
     * Values interpolated here MUST have gone through {@see Soql}. Prefer
     * whereEquals()/whereLike()/whereIn() or a FilterCompiler map.
     */
    public function whereRaw(string $condition): self
    {
        $this->conditions[] = $condition;

        return $this;
    }

    /**
     * @param  array<int, string>  $conditions
     */
    public function whereRawAll(array $conditions): self
    {
        foreach ($conditions as $condition) {
            $this->whereRaw($condition);
        }

        return $this;
    }

    public function whereEquals(string $field, string $value): self
    {
        return $this->whereRaw($field.' = '.Soql::quote($value));
    }

    public function whereBoolean(string $field, bool $value): self
    {
        return $this->whereRaw($field.' = '.($value ? 'true' : 'false'));
    }

    /**
     * @param  'starts_with'|'ends_with'|'contains'  $mode
     */
    public function whereLike(string $field, string $value, string $mode = 'contains'): self
    {
        $escaped = Soql::escapeLike($value);

        return $this->whereRaw(match ($mode) {
            'starts_with' => "{$field} LIKE '{$escaped}%'",
            'ends_with' => "{$field} LIKE '%{$escaped}'",
            default => "{$field} LIKE '%{$escaped}%'",
        });
    }

    /**
     * @param  iterable<int|string, string>  $values
     */
    public function whereIn(string $field, iterable $values): self
    {
        return $this->whereRaw(Soql::in($field, $values));
    }

    /**
     * @param  array<int, string>|string  $fields
     */
    public function groupBy(array|string $fields): self
    {
        $fields = is_array($fields) ? $fields : [$fields];

        $this->groupBy = [...$this->groupBy, ...$fields];

        return $this;
    }

    /**
     * @param  'ASC'|'DESC'  $direction
     */
    public function orderBy(string $field, string $direction = 'ASC'): self
    {
        $direction = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';

        $this->orderBy[] = "{$field} {$direction}";

        return $this;
    }

    public function limit(int $limit): self
    {
        $this->limit = max(0, $limit);

        return $this;
    }

    /**
     * Set the OFFSET, clamped to the Salesforce ceiling.
     *
     * SOQL rejects OFFSET above 2000 outright, so a deep page would 400 rather
     * than return an empty set. Clamping keeps the failure mode "repeats the
     * last reachable page" instead of "500s".
     *
     * {@see exceedsOffsetCeiling()} to detect and report the clamp.
     */
    public function offset(int $offset): self
    {
        $this->offset = max(0, min($offset, $this->offsetCeiling()));

        return $this;
    }

    /**
     * Whether the given offset would be clamped — lets a caller surface a
     * "results beyond this point are unreachable" signal instead of silently
     * serving the wrong page.
     */
    public function exceedsOffsetCeiling(int $offset): bool
    {
        return $offset > $this->offsetCeiling();
    }

    public function offsetCeiling(): int
    {
        $ceiling = config('salesforce-connector.limits.max_offset', 2000);

        return is_numeric($ceiling) ? (int) $ceiling : 2000;
    }

    public function toSoql(): string
    {
        if ($this->select === []) {
            throw new LogicException('A SOQL query requires at least one selected field.');
        }

        $soql = 'SELECT '.implode(', ', $this->select).' FROM '.$this->from;

        if ($this->conditions !== []) {
            $soql .= ' WHERE '.implode(' AND ', $this->conditions);
        }

        if ($this->groupBy !== []) {
            $soql .= ' GROUP BY '.implode(', ', $this->groupBy);
        }

        if ($this->orderBy !== []) {
            $soql .= ' ORDER BY '.implode(', ', $this->orderBy);
        }

        if ($this->limit !== null) {
            $soql .= ' LIMIT '.$this->limit;
        }

        if ($this->offset !== null && $this->offset > 0) {
            $soql .= ' OFFSET '.$this->offset;
        }

        return $soql;
    }

    public function __toString(): string
    {
        return $this->toSoql();
    }
}
