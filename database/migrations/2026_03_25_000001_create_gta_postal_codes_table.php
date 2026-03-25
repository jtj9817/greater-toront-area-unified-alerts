<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gta_postal_codes', function (Blueprint $table) {
            $table->id();
            $table->string('fsa', 3)->unique();
            $table->string('municipality');
            $table->string('neighbourhood')->nullable();
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);

            $table->index('municipality');
        });

        $data = require database_path('data/gta_postal_codes.php');
        DB::table('gta_postal_codes')->insert($data);
    }

    public function down(): void
    {
        Schema::dropIfExists('gta_postal_codes');
    }
};
