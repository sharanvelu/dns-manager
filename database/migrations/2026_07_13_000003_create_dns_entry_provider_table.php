<?php

declare(strict_types = 1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('dns_entry_provider', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dns_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('provider_id')->constrained()->cascadeOnDelete();
            $table->string('external_id')->nullable();
            $table->string('sync_status')->default('pending');
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['dns_entry_id', 'provider_id']);
            $table->index('sync_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dns_entry_provider');
    }
};
