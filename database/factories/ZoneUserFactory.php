<?php

declare(strict_types = 1);

namespace Database\Factories;

use App\Models\User;
use App\Enums\ZoneRole;
use App\Models\DnsZone;
use App\Models\ZoneUser;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ZoneUser>
 */
class ZoneUserFactory extends Factory
{
    public function definition(): array
    {
        return [
            'dns_zone_id' => DnsZone::factory(),
            'user_id' => User::factory(),
            'roles' => [ZoneRole::ZoneViewer->value],
        ];
    }

    public function admin(): static
    {
        return $this->state(fn () => ['roles' => [ZoneRole::ZoneAdmin->value]]);
    }

    public function viewer(): static
    {
        return $this->state(fn () => ['roles' => [ZoneRole::ZoneViewer->value]]);
    }

    public function dnsManager(): static
    {
        return $this->state(fn () => ['roles' => [ZoneRole::ZoneDnsManager->value]]);
    }

    public function providerManager(): static
    {
        return $this->state(fn () => ['roles' => [ZoneRole::ZoneProviderManager->value]]);
    }
}
