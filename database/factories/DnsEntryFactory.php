<?php

namespace Database\Factories;

use App\Enums\RecordType;
use App\Models\DnsEntry;
use App\Models\DnsZone;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DnsEntry>
 */
class DnsEntryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'dns_zone_id' => DnsZone::factory(),
            'name' => fake()->unique()->domainWord(),
            'type' => RecordType::A,
            'content' => fake()->ipv4(),
            'ttl' => null,
            'priority' => null,
            'proxied' => false,
            'comment' => null,
        ];
    }

    public function apex(): static
    {
        return $this->state(fn () => ['name' => '@']);
    }

    public function cname(string $target = 'target.example.com'): static
    {
        return $this->state(fn () => [
            'type' => RecordType::CNAME,
            'content' => $target,
        ]);
    }

    public function mx(): static
    {
        return $this->state(fn () => [
            'type' => RecordType::MX,
            'content' => 'mail.example.com',
            'priority' => 10,
        ]);
    }
}
