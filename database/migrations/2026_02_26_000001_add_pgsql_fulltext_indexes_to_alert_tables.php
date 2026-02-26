<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS fire_incidents_fulltext ON fire_incidents USING gin ((to_tsvector('simple', concat_ws(' ', event_type, prime_street, cross_streets))))");
        DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS police_calls_fulltext ON police_calls USING gin ((to_tsvector('simple', concat_ws(' ', call_type, cross_streets))))");
        DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS transit_alerts_fulltext ON transit_alerts USING gin ((to_tsvector('simple', concat_ws(' ', title, description, stop_start, stop_end, route, route_type))))");
        DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS go_transit_alerts_fulltext ON go_transit_alerts USING gin ((to_tsvector('simple', concat_ws(' ', message_subject, message_body, corridor_or_route, corridor_code, service_mode))))");
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS fire_incidents_fulltext');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS police_calls_fulltext');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS transit_alerts_fulltext');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS go_transit_alerts_fulltext');
    }
};
