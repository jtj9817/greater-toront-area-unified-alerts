<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('notification_preferences', 'subscriptions')) {
            return;
        }

        Schema::table('notification_preferences', function (Blueprint $table): void {
            $table->json('subscriptions')->nullable()->after('severity_threshold');
        });

        if (Schema::hasColumn('notification_preferences', 'subscribed_routes')) {
            DB::table('notification_preferences')
                ->select(['id', 'subscribed_routes'])
                ->orderBy('id')
                ->lazy()
                ->each(static function (object $row): void {
                    DB::table('notification_preferences')
                        ->where('id', $row->id)
                        ->update([
                            'subscriptions' => $row->subscribed_routes,
                        ]);
                });

            Schema::table('notification_preferences', function (Blueprint $table): void {
                $table->dropColumn('subscribed_routes');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('notification_preferences', 'subscribed_routes')) {
            return;
        }

        Schema::table('notification_preferences', function (Blueprint $table): void {
            $table->json('subscribed_routes')->nullable()->after('severity_threshold');
        });

        if (Schema::hasColumn('notification_preferences', 'subscriptions')) {
            DB::table('notification_preferences')
                ->select(['id', 'subscriptions'])
                ->orderBy('id')
                ->lazy()
                ->each(static function (object $row): void {
                    DB::table('notification_preferences')
                        ->where('id', $row->id)
                        ->update([
                            'subscribed_routes' => $row->subscriptions,
                        ]);
                });

            Schema::table('notification_preferences', function (Blueprint $table): void {
                $table->dropColumn('subscriptions');
            });
        }
    }
};
