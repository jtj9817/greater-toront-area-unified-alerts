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
        Schema::create('fire_incidents', function (Blueprint $table) {
            $table->id();
            $table->string('event_num')->unique();
            $table->string('event_type');
            $table->string('prime_street')->nullable();
            $table->string('cross_streets')->nullable();
            $table->dateTime('dispatch_time');
            $table->unsignedTinyInteger('alarm_level')->default(0);
            $table->string('beat')->nullable();
            $table->string('units_dispatched')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('feed_updated_at')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'dispatch_time']);
            $table->index('event_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fire_incidents');
    }
};
