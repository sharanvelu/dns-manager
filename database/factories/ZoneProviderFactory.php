<?php

declare(strict_types = 1);

namespace Database\Factories;

use App\Models\DnsZone;
use App\Models\Provider;
use App\Models\ZoneProvider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ZoneProvider>
 */
class ZoneProviderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'dns_zone_id' => DnsZone::factory(),
            'provider_id' => Provider::factory(),
            'config' => null,
            'enabled' => true,
        ];
    }

    public function cloudflare(?string $zoneId = null): static
    {
        return $this->state(fn () => [
            'provider_id' => Provider::factory()->cloudflare(),
            'config' => ['zone_id' => $zoneId ?? fake()->md5()],
        ]);
    }

    public function disabled(): static
    {
        return $this->state(fn () => ['enabled' => false]);
    }
}
