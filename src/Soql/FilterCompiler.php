<?php

declare(strict_types=1);

namespace Csatf\LaravelSalesforceConnector\Soql;

use InvalidArgumentException;

/**
 * Compiles a declarative filter map plus a set of request values into SOQL
 * conditions.
 *
 * The filter map is app-owned config — it names the SOQL field path and the
 * comparison for each supported request parameter — so adding a filter is a
 * config change rather than a code change:
 *
 *     'name_last' => ['path' => 'Contact__r.LastName', 'op' => 'starts_with'],
 *
 * Values that are null or an empty string are skipped, so an absent query
 * parameter contributes no condition.
 *
 * @phpstan-type FilterDefinition array{path: string, op?: string}
 */
final class FilterCompiler
{
    public const OPERATORS = [
        'equals',
        'not_equals',
        'starts_with',
        'ends_with',
        'contains',
        'in',
        'greater_than',
        'less_than',
        'greater_or_equal',
        'less_or_equal',
    ];

    /**
     * @param  array<string, FilterDefinition>  $definitions  filter key => path + op
     * @param  array<string, mixed>  $values  filter key => request value
     * @return array<int, string> SOQL conditions
     */
    public function compile(array $definitions, array $values): array
    {
        $conditions = [];

        foreach ($definitions as $key => $definition) {
            $value = $values[$key] ?? null;

            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            $conditions[] = $this->condition($definition, $value);
        }

        return $conditions;
    }

    /**
     * @param  FilterDefinition  $definition
     */
    private function condition(array $definition, mixed $value): string
    {
        $path = $definition['path'];
        $op = $definition['op'] ?? 'equals';

        if (! in_array($op, self::OPERATORS, true)) {
            throw new InvalidArgumentException(
                "Unknown SOQL filter operator [{$op}] for field [{$path}]."
            );
        }

        if ($op === 'in') {
            return Soql::in($path, is_array($value) ? $value : [$value]);
        }

        $raw = (string) $value;

        return match ($op) {
            'starts_with' => "{$path} LIKE '".Soql::escapeLike($raw)."%'",
            'ends_with' => "{$path} LIKE '%".Soql::escapeLike($raw)."'",
            'contains' => "{$path} LIKE '%".Soql::escapeLike($raw)."%'",
            'not_equals' => "{$path} != ".Soql::quote($raw),
            'greater_than' => "{$path} > ".Soql::quote($raw),
            'less_than' => "{$path} < ".Soql::quote($raw),
            'greater_or_equal' => "{$path} >= ".Soql::quote($raw),
            'less_or_equal' => "{$path} <= ".Soql::quote($raw),
            default => "{$path} = ".Soql::quote($raw),
        };
    }
}
