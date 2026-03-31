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
        Schema::create('miway_alerts', function (Blueprint $table) {
            $table->id();
            $table->string('external_id')->unique();
            $table->text('header_text');
            $table->text('description_text')->nullable();
            $table->string('cause')->nullable();
            $table->string('effect')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->string('url')->nullable();
            $table->string('detour_pdf_url')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamp('feed_updated_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('miway_alerts');
    }
};
