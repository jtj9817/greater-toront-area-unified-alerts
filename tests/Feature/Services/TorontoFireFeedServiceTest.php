<?php

use App\Services\TorontoFireFeedService;
use Illuminate\Support\Facades\Http;

test('it parses a valid xml response into normalized records', function () {
    $xml = <<<'XML'
    <tfs_active_incidents>
      <update_from_db_time>2026-01-31 13:45:01</update_from_db_time>
      <event>
        <prime_street>WILSON AVE, NY</prime_street>
        <cross_streets>AGATE RD / JULIAN RD</cross_streets>
        <dispatch_time>2026-01-31T13:15:32</dispatch_time>
        <event_num>F26015952</event_num>
        <event_type>Rescue - Elevator</event_type>
        <alarm_lev>0</alarm_lev>
        <beat>144</beat>
        <units_disp>P144, S143</units_disp>
      </event>
    </tfs_active_incidents>
    XML;

    Http::fake([
        '*' => Http::response($xml, 200, ['Content-Type' => 'text/xml']),
    ]);

    $service = app(TorontoFireFeedService::class);
    $results = $service->fetch();

    expect($results['updated_at'])->toBe('2026-01-31 13:45:01');
    expect($results['events'])->toHaveCount(1);
    expect($results['events'][0]['event_num'])->toBe('F26015952');
    expect($results['events'][0]['event_type'])->toBe('Rescue - Elevator');
    expect($results['events'][0]['prime_street'])->toBe('WILSON AVE, NY');
    expect($results['events'][0]['cross_streets'])->toBe('AGATE RD / JULIAN RD');
    expect($results['events'][0]['dispatch_time'])->toBe('2026-01-31T13:15:32');
    expect($results['events'][0]['alarm_level'])->toBe(0);
    expect($results['events'][0]['beat'])->toBe('144');
    expect($results['events'][0]['units_dispatched'])->toBe('P144, S143');
});

test('it throws exception on http error', function () {
    Http::fake([
        '*' => Http::response('server error', 500),
    ]);

    $service = app(TorontoFireFeedService::class);
    $service->fetch();
})->throws(RuntimeException::class, 'Toronto Fire feed request failed: 500');

test('it throws exception on empty response body', function () {
    Http::fake([
        '*' => Http::response('', 200),
    ]);

    $service = app(TorontoFireFeedService::class);
    $service->fetch();
})->throws(RuntimeException::class, 'Toronto Fire feed returned an empty response body');

test('it throws exception on invalid xml', function () {
    Http::fake([
        '*' => Http::response('<tfs_active_incidents><event></tfs_active_incidents>', 200),
    ]);

    $service = app(TorontoFireFeedService::class);
    $service->fetch();
})->throws(RuntimeException::class, 'Failed to parse Toronto Fire XML feed');

test('it throws exception on connection timeout', function () {
    Http::fake([
        '*' => Http::response('Timeout', 408),
    ]);

    $service = app(TorontoFireFeedService::class);
    $service->fetch();
})->throws(RuntimeException::class, 'Toronto Fire feed request failed: 408');

test('it throws exception when update_from_db_time is missing', function () {
    $xml = <<<'XML'
    <tfs_active_incidents>
      <event>
        <dispatch_time>2026-01-31T13:15:32</dispatch_time>
        <event_num>F26015952</event_num>
        <event_type>Rescue - Elevator</event_type>
        <alarm_lev>0</alarm_lev>
      </event>
    </tfs_active_incidents>
    XML;

    Http::fake([
        '*' => Http::response($xml, 200),
    ]);

    $service = app(TorontoFireFeedService::class);
    $service->fetch();
})->throws(RuntimeException::class, 'Toronto Fire XML feed missing update_from_db_time');

test('it handles missing optional fields', function () {
    $xml = <<<'XML'
    <tfs_active_incidents>
      <update_from_db_time>2026-01-31 13:45:01</update_from_db_time>
      <event>
        <prime_street> </prime_street>
        <cross_streets></cross_streets>
        <dispatch_time>2026-01-31T13:15:32</dispatch_time>
        <event_num>F26015952</event_num>
        <event_type>MEDICAL</event_type>
        <alarm_lev>0</alarm_lev>
        <beat> </beat>
        <units_disp></units_disp>
      </event>
    </tfs_active_incidents>
    XML;

    Http::fake([
        '*' => Http::response($xml, 200),
    ]);

    $service = app(TorontoFireFeedService::class);
    $results = $service->fetch();

    expect($results['events'][0]['prime_street'])->toBeNull();
    expect($results['events'][0]['cross_streets'])->toBeNull();
    expect($results['events'][0]['beat'])->toBeNull();
    expect($results['events'][0]['units_dispatched'])->toBeNull();
});

test('it returns empty array for empty events', function () {
    config(['feeds.allow_empty_feeds' => true]);

    $xml = <<<'XML'
    <tfs_active_incidents>
      <update_from_db_time>2026-01-31 13:45:01</update_from_db_time>
    </tfs_active_incidents>
    XML;

    Http::fake([
        '*' => Http::response($xml, 200),
    ]);

    $service = app(TorontoFireFeedService::class);
    $results = $service->fetch();

    expect($results['events'])->toBeEmpty();
});

test('it throws exception on empty events when empty feeds are not allowed', function () {
    config(['feeds.allow_empty_feeds' => false]);

    $xml = <<<'XML'
    <tfs_active_incidents>
      <update_from_db_time>2026-01-31 13:45:01</update_from_db_time>
    </tfs_active_incidents>
    XML;

    Http::fake([
        '*' => Http::response($xml, 200),
    ]);

    $service = app(TorontoFireFeedService::class);
    $service->fetch();
})->throws(RuntimeException::class, 'Toronto Fire feed returned zero events');
