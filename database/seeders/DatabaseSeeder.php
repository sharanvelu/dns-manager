<?php

namespace Database\Seeders;

use App\Enums\SyncStatus;
use App\Models\DnsEntry;
use App\Models\Provider;
use App\Models\SyncLog;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Development seed data. Real logins happen via OIDC; the seeded user
     * only exists so `actingAs` and local tinkering have something to use.
     */
    public function run(): void
    {
        User::factory()->create([
            'name' => 'Dev User',
            'email' => 'dev@example.com',
            'oidc_sub' => 'seed-dev-user',
        ]);

        $cloudflare = Provider::factory()->cloudflare()->create([
            'name' => 'Cloudflare — example.com',
            'managed_record_types' => ['A', 'AAAA', 'CNAME', 'MX', 'TXT'],
            'health_status' => 'ok',
            'last_checked_at' => now()->subMinutes(7),
        ]);

        $pihole = Provider::factory()->pihole()->create([
            'name' => 'Pi-hole — homelab',
            'managed_record_types' => ['A', 'AAAA', 'CNAME'],
            'health_status' => 'ok',
            'last_checked_at' => now()->subMinutes(7),
        ]);

        $entries = [
            ['name' => 'home.example.com', 'type' => 'A', 'content' => '192.168.1.10'],
            ['name' => 'nas.example.com', 'type' => 'A', 'content' => '192.168.1.20'],
            ['name' => 'media.example.com', 'type' => 'CNAME', 'content' => 'nas.example.com'],
            ['name' => 'example.com', 'type' => 'MX', 'content' => 'mail.example.com', 'priority' => 10],
        ];

        foreach ($entries as $i => $attributes) {
            $entry = DnsEntry::factory()->create($attributes);

            foreach ([$cloudflare, $pihole] as $provider) {
                if (! in_array($entry->type->value, $provider->managed_record_types, true)) {
                    continue;
                }

                $entry->syncStates()->create([
                    'provider_id' => $provider->id,
                    'external_id' => $provider->type->value === 'cloudflare'
                        ? 'cf-seed-'.$i
                        : "{$entry->content} {$entry->name}",
                    'sync_status' => $i === 1 && $provider->is($pihole) ? SyncStatus::Drifted : SyncStatus::Synced,
                    'last_synced_at' => now()->subMinutes(30),
                    'last_error' => $i === 1 && $provider->is($pihole) ? 'Remote record differs from the managed entry.' : null,
                ]);

                SyncLog::record($provider, $entry, 'push', 'success', "{$entry->type->value} {$entry->name} synced");
            }
        }

        SyncLog::record($pihole, null, 'drift-check', 'success', 'Checked 3 record(s), 1 drifted');
    }
}
