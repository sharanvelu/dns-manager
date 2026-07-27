<?php

declare(strict_types = 1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('zone_providers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dns_zone_id')->constrained()->cascadeOnDelete();
            $table->foreignId('provider_id')->constrained()->cascadeOnDelete();
            $table->text('config')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['dns_zone_id', 'provider_id']);
            $table->index('enabled');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zone_providers');
    }
};
