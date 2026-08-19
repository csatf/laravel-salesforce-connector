<?php

declare(strict_types=1);

namespace Csatf\LaravelSalesforceConnector\Soql;

/**
 * Escaping helpers for building SOQL string literals by hand.
 *
 * SOQL has no bound-parameter API over REST — the query is a string in the URL
 * — so every user-supplied value has to be escaped at the point of
 * interpolation. These helpers are the only sanctioned way to do that.
 */
final class Soql
{
    /**
     * Escape a value for use inside a single-quoted SOQL literal.
     *
     * Handles the two characters that can break out of the literal: the
     * backslash (escape character) and the single quote (terminator).
     * Backslashes must be doubled first, or the doubling would also affect
     * the backslashes introduced when escaping quotes.
     */
    public static function escape(string $value): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    }

    /**
     * Escape a value for use inside a LIKE pattern.
     *
     * On top of literal escaping, this neutralises the two SOQL wildcards:
     * `%` (any sequence) and `_` (any single character). Without this, a user
     * submitting "%" into a starts-with filter produces `LIKE '%%'`, which
     * matches every row — the filter silently stops filtering. `_` is subtler:
     * "a_c" quietly matches "abc".
     *
     * Callers append their own unescaped wildcard, e.g.
     * `"Name LIKE '".Soql::escapeLike($v)."%'"`.
     */
    public static function escapeLike(string $value): string
    {
        return str_replace(['%', '_'], ['\\%', '\\_'], self::escape($value));
    }

    /**
     * Wrap a value as a fully escaped, quoted SOQL literal.
     */
    public static function quote(string $value): string
    {
        return "'".self::escape($value)."'";
    }

    /**
     * Build a quoted, comma-separated list for an `IN (...)` clause.
     *
     * @param  iterable<int|string, string>  $values
     */
    public static function inList(iterable $values): string
    {
        $quoted = [];

        foreach ($values as $value) {
            $quoted[] = self::quote((string) $value);
        }

        return implode(', ', $quoted);
    }

    /**
     * Build a complete `<field> IN (...)` condition.
     *
     * @param  iterable<int|string, string>  $values
     */
    public static function in(string $field, iterable $values): string
    {
        return $field.' IN ('.self::inList($values).')';
    }
}
