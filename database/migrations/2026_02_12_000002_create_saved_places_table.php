<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('saved_places')) {
            return;
        }

        Schema::create('saved_places', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->decimal('lat', 10, 7);
            $table->decimal('long', 10, 7);
            $table->unsignedInteger('radius')->default(500);
            $table->string('type', 32)->default('address');
            $table->timestamps();

            $table->index('user_id');
            $table->index(['user_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_places');
    }
};
