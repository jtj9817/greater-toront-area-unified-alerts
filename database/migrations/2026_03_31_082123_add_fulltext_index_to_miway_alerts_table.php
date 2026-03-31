<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return;
        }

        Schema::table('miway_alerts', function (Blueprint $table) {
            $table->fullText(
                ['header_text', 'description_text'],
                'miway_alerts_fulltext',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return;
        }

        Schema::table('miway_alerts', function (Blueprint $table) {
            $table->dropFullText('miway_alerts_fulltext');
        });
    }
};
