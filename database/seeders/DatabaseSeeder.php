<?php

namespace Database\Seeders;

use App\Enums\SyncStatus;
use App\Models\DnsEntry;
use App\Models\DnsZone;
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

        $zone = DnsZone::factory()->create([
            'name' => 'example.com',
            'description' => 'Primary homelab zone',
        ]);

        $cloudflare = Provider::factory()->cloudflare()->create([
            'name' => 'Cloudflare — main account',
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

        $cloudflareAttachment = $zone->zoneProviders()->create([
            'provider_id' => $cloudflare->id,
            'config' => ['zone_id' => 'seed-cf-zone'],
        ]);

        $piholeAttachment = $zone->zoneProviders()->create([
            'provider_id' => $pihole->id,
        ]);

        $entries = [
            ['name' => 'home', 'type' => 'A', 'content' => '192.168.1.10'],
            ['name' => 'nas', 'type' => 'A', 'content' => '192.168.1.20'],
            ['name' => 'media', 'type' => 'CNAME', 'content' => 'nas.example.com'],
            ['name' => '@', 'type' => 'MX', 'content' => 'mail.example.com', 'priority' => 10],
        ];

        foreach ($entries as $i => $attributes) {
            $entry = DnsEntry::factory()->for($zone, 'zone')->create($attributes);

            foreach ([$cloudflareAttachment, $piholeAttachment] as $attachment) {
                if (! $attachment->provider->managesType($entry->type->value)) {
                    continue;
                }

                $drifted = $i === 1 && $attachment->is($piholeAttachment);

                $entry->syncStates()->create([
                    'zone_provider_id' => $attachment->id,
                    'external_id' => $attachment->is($cloudflareAttachment)
                        ? 'cf-seed-'.$i
                        : "{$entry->content} {$entry->fqdn}",
                    'sync_status' => $drifted ? SyncStatus::Drifted : SyncStatus::Synced,
                    'last_synced_at' => now()->subMinutes(30),
                    'last_error' => $drifted ? 'Remote record differs from the managed entry.' : null,
                ]);

                SyncLog::record($attachment->provider, $entry, 'push', 'success', "{$entry->type->value} {$entry->fqdn} synced to {$attachment->label()}");
            }
        }

        SyncLog::record($pihole, null, 'drift-check', 'success', 'Checked 3 record(s), 1 drifted', zoneId: $zone->id);
    }
}
