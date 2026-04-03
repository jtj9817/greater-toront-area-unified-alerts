<?php

function drtFixturePath(string $name): string
{
    return base_path('tests/fixtures/drt/'.$name);
}

test('drt list fixtures include expected pagination and labels', function () {
    $pageOne = (string) file_get_contents(drtFixturePath('list-page-1.html'));
    $pageTwo = (string) file_get_contents(drtFixturePath('list-page-2.html'));

    expect($pageOne)->toContain('/Modules/News/en/ServiceAlertsandDetours?page=2')
        ->and($pageOne)->toContain('Posted on')
        ->and($pageOne)->toContain('Read more')
        ->and($pageTwo)->toContain('Posted on')
        ->and($pageTwo)->toContain('Routes: 920and 921')
        ->and($pageTwo)->toContain('Read more');
});

test('drt detail fixtures include stable boundaries and route variants', function () {
    $routeFixture = (string) file_get_contents(drtFixturePath('detail-route-singular-conlin-grandview.html'));
    $routesFixture = (string) file_get_contents(drtFixturePath('detail-routes-bullets-odd-whitespace.html'));

    expect($routeFixture)->toContain('Back to Search')
        ->and($routeFixture)->toContain('Subscribe')
        ->and($routeFixture)->toContain('<strong>Route: </strong>')
        ->and($routeFixture)->toContain('<strong>When:&nbsp;</strong>')
        ->and($routesFixture)->toContain('Back to Search')
        ->and($routesFixture)->toContain('Subscribe')
        ->and($routesFixture)->toContain('<strong>Routes: 920and 921</strong>')
        ->and($routesFixture)->toContain('<ul>')
        ->and($routesFixture)->toContain('<li>');
});
