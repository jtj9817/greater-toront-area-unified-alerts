<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasIndex('notification_logs', ['user_id'])) {
            Schema::table('notification_logs', function (Blueprint $table) {
                $table->dropIndex(['user_id']);
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasIndex('notification_logs', ['user_id'])) {
            Schema::table('notification_logs', function (Blueprint $table) {
                $table->index('user_id');
            });
        }
    }
};
