<?php

declare(strict_types = 1);

namespace Database\Factories;

use App\Models\DnsZone;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DnsZone>
 */
class DnsZoneFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->domainName(),
            'description' => null,
        ];
    }
}
