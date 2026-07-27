<?php

declare(strict_types = 1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * Zones overhaul (v2.0.0): entries move under DNS zones with zone-relative
 * names, and sync state is rekeyed to (entry, zone attachment). This is a
 * deliberate clean slate — entries, sync state, and sync logs are dropped.
 * Provider credentials are preserved; only the obsolete per-provider
 * Cloudflare `zone_id` is stripped from their configs (it now lives on the
 * zone attachment). Shipped migrations are never edited, so both fresh and
 * existing installs converge here.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::dropIfExists('sync_logs');
        Schema::dropIfExists('dns_entry_provider');
        Schema::dropIfExists('dns_entries');

        Schema::create('dns_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dns_zone_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type', 10);
            $table->text('content');
            $table->unsignedInteger('ttl')->nullable();
            $table->unsignedInteger('priority')->nullable();
            $table->boolean('proxied')->default(false);
            $table->string('comment')->nullable();
            $table->timestamps();

            $table->unique(['dns_zone_id', 'name', 'type', 'content']);
            $table->index(['dns_zone_id', 'type']);
            $table->index('name');
        });

        Schema::create('dns_entry_zone_provider', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dns_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('zone_provider_id')->constrained('zone_providers')->cascadeOnDelete();
            $table->string('external_id')->nullable();
            $table->string('sync_status')->default('pending');
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['dns_entry_id', 'zone_provider_id']);
            $table->index('sync_status');
        });

        Schema::create('sync_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('dns_zone_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('dns_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action');
            $table->string('status');
            $table->text('message')->nullable();
            $table->timestamps();

            $table->index('created_at');
            $table->index(['dns_zone_id', 'created_at']);
        });

        $this->stripZoneIdFromProviderConfigs();
    }

    public function down(): void
    {
        // Clean-slate migration: the previous data is unrecoverable by design.
    }

    /**
     * Raw Crypt round-trip instead of the Provider model so this migration
     * stays valid no matter how the model evolves. Mirrors the
     * `encrypted:array` cast (json_encode + encryptString).
     */
    private function stripZoneIdFromProviderConfigs(): void
    {
        foreach (DB::table('providers')->get(['id', 'config']) as $row) {
            $config = json_decode(Crypt::decryptString($row->config), true);

            if (! is_array($config) || ! array_key_exists('zone_id', $config)) {
                continue;
            }

            unset($config['zone_id']);

            DB::table('providers')
                ->where('id', $row->id)
                ->update(['config' => Crypt::encryptString(json_encode($config))]);
        }
    }
};
