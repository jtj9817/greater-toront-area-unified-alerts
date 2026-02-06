<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('go_transit_alerts', function (Blueprint $table) {
            $table->id();
            $table->string('external_id')->unique();
            $table->string('alert_type');
            $table->string('service_mode');
            $table->string('corridor_or_route');
            $table->string('corridor_code')->nullable();
            $table->string('sub_category')->nullable();
            $table->string('message_subject');
            $table->text('message_body')->nullable();
            $table->string('direction')->nullable();
            $table->string('trip_number')->nullable();
            $table->string('delay_duration')->nullable();
            $table->string('status')->nullable();
            $table->string('line_colour')->nullable();
            $table->dateTime('posted_at');
            $table->boolean('is_active')->default(true);
            $table->timestamp('feed_updated_at')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'posted_at']);
            $table->index('alert_type');
            $table->index('service_mode');
            $table->index('feed_updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('go_transit_alerts');
    }
};
