<?php

declare(strict_types = 1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('providers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type');
            $table->text('config');
            $table->json('managed_record_types')->default('[]');
            $table->boolean('enabled')->default(true);
            $table->string('health_status')->default('unchecked');
            $table->text('health_message')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();

            $table->index('type');
            $table->index('enabled');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('providers');
    }
};
