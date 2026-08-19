<?php

declare(strict_types=1);

use Csatf\LaravelSalesforceConnector\Soql\FilterCompiler;

beforeEach(function () {
    $this->compiler = new FilterCompiler;
});

test('it compiles each operator', function (string $op, mixed $value, string $expected) {
    $conditions = $this->compiler->compile(
        ['field' => ['path' => 'Contact__r.LastName', 'op' => $op]],
        ['field' => $value],
    );

    expect($conditions)->toBe([$expected]);
})->with([
    'equals' => ['equals', 'Smith', "Contact__r.LastName = 'Smith'"],
    'not equals' => ['not_equals', 'Smith', "Contact__r.LastName != 'Smith'"],
    'starts with' => ['starts_with', 'Smi', "Contact__r.LastName LIKE 'Smi%'"],
    'ends with' => ['ends_with', 'ith', "Contact__r.LastName LIKE '%ith'"],
    'contains' => ['contains', 'mit', "Contact__r.LastName LIKE '%mit%'"],
    'greater than' => ['greater_than', '2024-01-01', "Contact__r.LastName > '2024-01-01'"],
    'less than' => ['less_than', '2024-01-01', "Contact__r.LastName < '2024-01-01'"],
    'greater or equal' => ['greater_or_equal', '5', "Contact__r.LastName >= '5'"],
    'less or equal' => ['less_or_equal', '5', "Contact__r.LastName <= '5'"],
    'in' => ['in', ['A', 'B'], "Contact__r.LastName IN ('A', 'B')"],
]);

test('it defaults to equals when no operator is given', function () {
    $conditions = $this->compiler->compile(
        ['field' => ['path' => 'Name']],
        ['field' => 'Smith'],
    );

    expect($conditions)->toBe(["Name = 'Smith'"]);
});

test('it skips absent and empty values', function (mixed $value) {
    $conditions = $this->compiler->compile(
        ['field' => ['path' => 'Name', 'op' => 'equals']],
        ['field' => $value],
    );

    expect($conditions)->toBe([]);
})->with([
    'null' => [null],
    'empty string' => [''],
    'empty array' => [[]],
]);

test('it skips filters with no matching request value', function () {
    $conditions = $this->compiler->compile(
        ['field' => ['path' => 'Name', 'op' => 'equals']],
        [],
    );

    expect($conditions)->toBe([]);
});

test('it keeps a literal zero, which is a real value', function () {
    $conditions = $this->compiler->compile(
        ['field' => ['path' => 'Count__c', 'op' => 'equals']],
        ['field' => 0],
    );

    expect($conditions)->toBe(["Count__c = '0'"]);
});

test('it escapes LIKE wildcards in user values', function () {
    $conditions = $this->compiler->compile(
        ['name_last' => ['path' => 'LastName', 'op' => 'starts_with']],
        ['name_last' => '%'],
    );

    // The regression: this must NOT compile to LIKE '%%'.
    expect($conditions)->toBe(["LastName LIKE '\\%%'"])
        ->and($conditions[0])->not->toBe("LastName LIKE '%%'");
});

test('it escapes quotes in user values', function () {
    $conditions = $this->compiler->compile(
        ['name_last' => ['path' => 'LastName', 'op' => 'equals']],
        ['name_last' => "O'Brien"],
    );

    expect($conditions)->toBe(["LastName = 'O\\'Brien'"]);
});

test('it compiles multiple filters in definition order', function () {
    $conditions = $this->compiler->compile(
        [
            'name_last' => ['path' => 'LastName', 'op' => 'starts_with'],
            'email' => ['path' => 'Email', 'op' => 'equals'],
        ],
        ['email' => 'a@b.com', 'name_last' => 'Smith'],
    );

    expect($conditions)->toBe([
        "LastName LIKE 'Smith%'",
        "Email = 'a@b.com'",
    ]);
});

test('it rejects an unknown operator', function () {
    $this->compiler->compile(
        ['field' => ['path' => 'Name', 'op' => 'sounds_like']],
        ['field' => 'Smith'],
    );
})->throws(InvalidArgumentException::class, 'sounds_like');
