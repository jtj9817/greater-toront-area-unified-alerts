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
        Schema::create('police_calls', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('object_id')->unique();
            $table->string('call_type_code')->index();
            $table->string('call_type');
            $table->string('division')->nullable()->index();
            $table->string('cross_streets')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->dateTime('occurrence_time');
            $table->boolean('is_active')->default(true);
            $table->timestamp('feed_updated_at')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'occurrence_time']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('police_calls');
    }
};