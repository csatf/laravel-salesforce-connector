<?php

declare(strict_types=1);

use Csatf\LaravelSalesforceConnector\Soql\SoqlQuery;

test('it builds a minimal select', function () {
    expect((string) SoqlQuery::from('Contact')->select('Id'))
        ->toBe('SELECT Id FROM Contact');
});

test('it builds a full query in SOQL clause order', function () {
    $soql = SoqlQuery::from('Placement_Requirement__c')
        ->select(['Id', 'Name'])
        ->whereEquals('Status__c', 'Expired')
        ->whereLike('Contact__r.LastName', 'Smith', 'starts_with')
        ->groupBy('Contact__r.Industry_Link__c')
        ->orderBy('Name')
        ->limit(25)
        ->offset(50)
        ->toSoql();

    expect($soql)->toBe(
        'SELECT Id, Name FROM Placement_Requirement__c '
        ."WHERE Status__c = 'Expired' AND Contact__r.LastName LIKE 'Smith%' "
        .'GROUP BY Contact__r.Industry_Link__c '
        .'ORDER BY Name ASC '
        .'LIMIT 25 OFFSET 50'
    );
});

test('it deduplicates selected fields', function () {
    $soql = SoqlQuery::from('Contact')->select(['Id', 'Name'])->select('Id')->toSoql();

    expect($soql)->toBe('SELECT Id, Name FROM Contact');
});

test('it joins conditions with AND', function () {
    $soql = SoqlQuery::from('Contact')
        ->select('Id')
        ->whereRawAll(["A__c = '1'", "B__c = '2'"])
        ->toSoql();

    expect($soql)->toBe("SELECT Id FROM Contact WHERE A__c = '1' AND B__c = '2'");
});

test('it renders booleans unquoted', function () {
    $soql = SoqlQuery::from('Contact')->select('Id')->whereBoolean('Is_Active__c', false)->toSoql();

    expect($soql)->toBe('SELECT Id FROM Contact WHERE Is_Active__c = false');
});

test('it escapes values passed to where helpers', function () {
    $soql = SoqlQuery::from('Contact')
        ->select('Id')
        ->whereEquals('LastName', "O'Brien")
        ->whereLike('FirstName', '%', 'starts_with')
        ->toSoql();

    expect($soql)->toContain("LastName = 'O\\'Brien'")
        ->toContain("FirstName LIKE '\\%%'");
});

test('it normalises order direction', function () {
    $soql = SoqlQuery::from('Contact')->select('Id')->orderBy('Name', 'desc')->toSoql();

    expect($soql)->toBe('SELECT Id FROM Contact ORDER BY Name DESC');
});

test('it clamps offset to the Salesforce ceiling', function () {
    $soql = SoqlQuery::from('Contact')->select('Id')->offset(50_000)->toSoql();

    expect($soql)->toEndWith('OFFSET 2000');
});

test('it reports when an offset would be clamped', function () {
    $query = SoqlQuery::from('Contact')->select('Id');

    expect($query->exceedsOffsetCeiling(2001))->toBeTrue()
        ->and($query->exceedsOffsetCeiling(2000))->toBeFalse();
});

test('it honours a configured offset ceiling', function () {
    config()->set('salesforce-connector.limits.max_offset', 100);

    expect(SoqlQuery::from('Contact')->select('Id')->offset(500)->toSoql())
        ->toEndWith('OFFSET 100');
});

test('it omits a zero offset', function () {
    expect(SoqlQuery::from('Contact')->select('Id')->offset(0)->toSoql())
        ->toBe('SELECT Id FROM Contact');
});

test('it floors negative limits and offsets at zero', function () {
    $soql = SoqlQuery::from('Contact')->select('Id')->limit(-5)->offset(-5)->toSoql();

    expect($soql)->toBe('SELECT Id FROM Contact LIMIT 0');
});

test('it supports conditional building', function () {
    $soql = SoqlQuery::from('Contact')
        ->select('Id')
        ->when(false, fn (SoqlQuery $q) => $q->whereEquals('Name', 'skipped'))
        ->when(true, fn (SoqlQuery $q) => $q->whereEquals('Name', 'applied'))
        ->toSoql();

    expect($soql)->toBe("SELECT Id FROM Contact WHERE Name = 'applied'");
});

test('it refuses to build without a select list', function () {
    SoqlQuery::from('Contact')->toSoql();
})->throws(LogicException::class);
