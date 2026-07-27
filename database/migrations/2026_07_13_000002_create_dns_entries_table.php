<?php

declare(strict_types = 1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('dns_entries', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type', 10);
            $table->text('content');
            $table->unsignedInteger('ttl')->nullable();
            $table->unsignedInteger('priority')->nullable();
            $table->boolean('proxied')->default(false);
            $table->string('comment')->nullable();
            $table->timestamps();

            $table->unique(['name', 'type', 'content']);
            $table->index('type');
            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dns_entries');
    }
};
