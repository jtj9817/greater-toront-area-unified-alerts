<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function loadMigration(string $filename): object
{
    return require database_path('migrations/'.$filename);
}

test('pgsql fulltext migration opts out of transactions for concurrent index operations', function () {
    $migration = loadMigration('2026_02_26_000001_add_pgsql_fulltext_indexes_to_alert_tables.php');

    expect($migration->withinTransaction)->toBeFalse();
});

test('pgsql fulltext migration creates concurrent gin indexes with expected expressions', function () {
    Schema::shouldReceive('getConnection->getDriverName')->andReturn('pgsql');

    DB::shouldReceive('statement')->once()->withArgs(function (string $sql): bool {
        return str_contains($sql, 'CREATE INDEX CONCURRENTLY IF NOT EXISTS fire_incidents_fulltext')
            && str_contains($sql, "to_tsvector('simple', concat_ws(' ', event_type, prime_street, cross_streets))");
    });

    DB::shouldReceive('statement')->once()->withArgs(function (string $sql): bool {
        return str_contains($sql, 'CREATE INDEX CONCURRENTLY IF NOT EXISTS police_calls_fulltext')
            && str_contains($sql, "to_tsvector('simple', concat_ws(' ', call_type, cross_streets))");
    });

    DB::shouldReceive('statement')->once()->withArgs(function (string $sql): bool {
        return str_contains($sql, 'CREATE INDEX CONCURRENTLY IF NOT EXISTS transit_alerts_fulltext')
            && str_contains($sql, "to_tsvector('simple', concat_ws(' ', title, description, stop_start, stop_end, route, route_type))");
    });

    DB::shouldReceive('statement')->once()->withArgs(function (string $sql): bool {
        return str_contains($sql, 'CREATE INDEX CONCURRENTLY IF NOT EXISTS go_transit_alerts_fulltext')
            && str_contains($sql, "to_tsvector('simple', concat_ws(' ', message_subject, message_body, corridor_or_route, corridor_code, service_mode))");
    });

    $migration = loadMigration('2026_02_26_000001_add_pgsql_fulltext_indexes_to_alert_tables.php');
    $migration->up();
});

test('pgsql fulltext migration drops indexes concurrently on rollback', function () {
    Schema::shouldReceive('getConnection->getDriverName')->andReturn('pgsql');

    DB::shouldReceive('statement')->once()->with('DROP INDEX CONCURRENTLY IF EXISTS fire_incidents_fulltext');
    DB::shouldReceive('statement')->once()->with('DROP INDEX CONCURRENTLY IF EXISTS police_calls_fulltext');
    DB::shouldReceive('statement')->once()->with('DROP INDEX CONCURRENTLY IF EXISTS transit_alerts_fulltext');
    DB::shouldReceive('statement')->once()->with('DROP INDEX CONCURRENTLY IF EXISTS go_transit_alerts_fulltext');

    $migration = loadMigration('2026_02_26_000001_add_pgsql_fulltext_indexes_to_alert_tables.php');
    $migration->down();
});

test('pgsql fulltext migration is a no-op for non-pgsql drivers', function () {
    Schema::shouldReceive('getConnection->getDriverName')->andReturn('mysql');
    DB::shouldReceive('statement')->never();

    $migration = loadMigration('2026_02_26_000001_add_pgsql_fulltext_indexes_to_alert_tables.php');
    $migration->up();
    $migration->down();
});

test('mysql fulltext migration runs for mariadb driver family', function () {
    Schema::shouldReceive('getConnection->getDriverName')->andReturn('mariadb');
    Schema::shouldReceive('table')->times(8)->andReturnNull();

    $migration = loadMigration('2026_02_19_120000_add_fulltext_indexes_to_alert_tables.php');

    $migration->up();
    $migration->down();
});
