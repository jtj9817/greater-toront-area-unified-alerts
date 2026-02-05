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
        Schema::create('transit_alerts', function (Blueprint $table) {
            $table->id();
            $table->string('external_id')->unique();
            $table->string('source_feed');
            $table->string('alert_type')->nullable();
            $table->string('route_type')->nullable();
            $table->string('route')->nullable();
            $table->string('title');
            $table->mediumText('description')->nullable();
            $table->string('severity')->nullable();
            $table->string('effect')->nullable();
            $table->string('cause')->nullable();
            $table->dateTime('active_period_start')->nullable();
            $table->dateTime('active_period_end')->nullable();
            $table->string('direction')->nullable();
            $table->string('stop_start')->nullable();
            $table->string('stop_end')->nullable();
            $table->string('url')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('feed_updated_at')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'active_period_start']);
            $table->index('feed_updated_at');
            $table->index('source_feed');
            $table->index('route_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transit_alerts');
    }
};
