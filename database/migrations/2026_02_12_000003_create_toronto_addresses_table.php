<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('toronto_addresses', function (Blueprint $table): void {
            $table->id();
            $table->string('street_num', 32)->nullable();
            $table->string('street_name', 160);
            $table->decimal('lat', 10, 7);
            $table->decimal('long', 10, 7);
            $table->string('zip', 16)->nullable();
            $table->timestamps();

            $table->index('street_name');
            $table->index(['street_num', 'street_name']);
            $table->index('zip');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('toronto_addresses');
    }
};
