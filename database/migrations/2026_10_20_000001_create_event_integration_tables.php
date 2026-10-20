<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_integration_channels', function (Blueprint $table): void {
            $table->id();
            $table->nullableMorphs('owner');
            $table->string('name');
            $table->string('transport', 50);
            $table->string('event_name', 150);
            $table->boolean('is_active')->default(true);
            $table->json('destination')->nullable();
            $table->json('filters')->nullable();
            $table->json('output_format')->nullable();
            $table->json('options')->nullable();
            $table->timestamps();

            $table->index(['event_name', 'is_active']);
        });

        Schema::create('event_integration_activities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('channel_id')
                ->constrained('event_integration_channels')
                ->cascadeOnDelete();
            $table->string('event_name', 150)->index();
            $table->json('payload');
            $table->string('status', 50)->default('pending')->index();
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->unsignedSmallInteger('max_attempts')->default(3);
            $table->text('last_error')->nullable();
            $table->string('idempotency_key', 128)->unique()->nullable();
            $table->timestamp('lease_expires_at')->nullable()->index();
            $table->timestamp('next_attempt_at')->nullable()->index();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamps();

            $table->index(['channel_id', 'status']);
            $table->index(['status', 'next_attempt_at']);
            $table->index(['status', 'lease_expires_at']);
            $table->index(['status', 'dispatched_at']);
            $table->index(['status', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_integration_activities');
        Schema::dropIfExists('event_integration_channels');
    }
};
