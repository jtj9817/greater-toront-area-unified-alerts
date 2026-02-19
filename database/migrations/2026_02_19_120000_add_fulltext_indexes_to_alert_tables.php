<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        Schema::table('fire_incidents', function (Blueprint $table) {
            $table->fullText(['event_type', 'prime_street', 'cross_streets'], 'fire_incidents_fulltext');
        });

        Schema::table('police_calls', function (Blueprint $table) {
            $table->fullText(['call_type', 'cross_streets'], 'police_calls_fulltext');
        });

        Schema::table('transit_alerts', function (Blueprint $table) {
            $table->fullText(['title', 'description', 'stop_start', 'stop_end', 'route', 'route_type'], 'transit_alerts_fulltext');
        });

        Schema::table('go_transit_alerts', function (Blueprint $table) {
            $table->fullText(['message_subject', 'message_body', 'corridor_or_route', 'corridor_code', 'service_mode'], 'go_transit_alerts_fulltext');
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        Schema::table('fire_incidents', function (Blueprint $table) {
            $table->dropFullText('fire_incidents_fulltext');
        });

        Schema::table('police_calls', function (Blueprint $table) {
            $table->dropFullText('police_calls_fulltext');
        });

        Schema::table('transit_alerts', function (Blueprint $table) {
            $table->dropFullText('transit_alerts_fulltext');
        });

        Schema::table('go_transit_alerts', function (Blueprint $table) {
            $table->dropFullText('go_transit_alerts_fulltext');
        });
    }
};
