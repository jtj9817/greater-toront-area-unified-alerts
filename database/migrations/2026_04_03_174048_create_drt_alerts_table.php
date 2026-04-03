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
        Schema::create('drt_alerts', function (Blueprint $table) {
            $table->id();
            $table->string('external_id')->unique();
            $table->text('title');
            $table->dateTime('posted_at');
            $table->string('when_text')->nullable();
            $table->string('route_text')->nullable();
            $table->string('details_url');
            $table->text('body_text')->nullable();
            $table->string('list_hash', 40)->nullable();
            $table->timestamp('details_fetched_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('feed_updated_at')->nullable();
            $table->timestamps();

            $table->index('posted_at');
            $table->index(['is_active', 'posted_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('drt_alerts');
    }
};
