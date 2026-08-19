<?php

declare(strict_types=1);

use Csatf\LaravelSalesforceConnector\Soql\Soql;

test('it escapes single quotes so a value cannot terminate the literal', function () {
    expect(Soql::escape("O'Brien"))->toBe("O\\'Brien");
});

test('it doubles backslashes before escaping quotes', function () {
    expect(Soql::escape('back\\slash'))->toBe('back\\\\slash');

    // A trailing backslash must not escape the closing quote of the literal.
    expect(Soql::quote('trailing\\'))->toBe("'trailing\\\\'");
});

test('it quotes values', function () {
    expect(Soql::quote('Smith'))->toBe("'Smith'");
});

test('escapeLike neutralises the percent wildcard', function () {
    // Without this, `?name_last=%` becomes LIKE '%%' and matches every row —
    // the filter silently stops filtering.
    expect(Soql::escapeLike('%'))->toBe('\\%');
    expect(Soql::escapeLike('50%'))->toBe('50\\%');
});

test('escapeLike neutralises the underscore wildcard', function () {
    // `_` is a single-character wildcard: "a_c" would otherwise match "abc".
    expect(Soql::escapeLike('a_c'))->toBe('a\\_c');
});

test('escapeLike still escapes quotes and backslashes', function () {
    expect(Soql::escapeLike("O'Br%en"))->toBe("O\\'Br\\%en");
});

test('escapeLike leaves ordinary values untouched', function () {
    expect(Soql::escapeLike('Smith'))->toBe('Smith');
});

test('it builds an IN list', function () {
    expect(Soql::inList(['A', 'B']))->toBe("'A', 'B'");
    expect(Soql::in('Contact__r.Industry_Link__c', ['A', 'B']))
        ->toBe("Contact__r.Industry_Link__c IN ('A', 'B')");
});

test('it escapes values inside an IN list', function () {
    expect(Soql::in('Field__c', ["O'Brien"]))->toBe("Field__c IN ('O\\'Brien')");
});

test('it builds an empty IN list without breaking syntax', function () {
    expect(Soql::in('Field__c', []))->toBe('Field__c IN ()');
});
