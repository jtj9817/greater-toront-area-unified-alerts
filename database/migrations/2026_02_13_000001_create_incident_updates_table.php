<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incident_updates', function (Blueprint $table): void {
            $table->id();
            $table->string('event_num');
            $table->string('update_type', 50);
            $table->text('content');
            $table->json('metadata')->nullable();
            $table->string('source', 50)->default('synthetic');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['event_num', 'created_at']);
            $table->index('update_type');
            $table->index('created_at');

            $table->foreign('event_num')
                ->references('event_num')
                ->on('fire_incidents')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_updates');
    }
};
