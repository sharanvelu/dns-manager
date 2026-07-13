<?php

namespace Database\Factories;

use App\Enums\HealthStatus;
use App\Enums\ProviderType;
use App\Models\Provider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Provider>
 */
class ProviderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true),
            'type' => ProviderType::Cloudflare,
            'config' => [
                'api_token' => fake()->sha256(),
                'zone_id' => fake()->md5(),
            ],
            'managed_record_types' => ['A', 'AAAA', 'CNAME'],
            'enabled' => true,
            'health_status' => HealthStatus::Unchecked,
        ];
    }

    public function cloudflare(): static
    {
        return $this->state(fn () => [
            'type' => ProviderType::Cloudflare,
            'config' => [
                'api_token' => fake()->sha256(),
                'zone_id' => fake()->md5(),
            ],
        ]);
    }

    public function pihole(): static
    {
        return $this->state(fn () => [
            'type' => ProviderType::Pihole,
            'config' => [
                'base_url' => 'https://pihole.internal',
                'app_password' => fake()->password(20),
                'verify_tls' => false,
            ],
            'managed_record_types' => ['A', 'AAAA', 'CNAME'],
        ]);
    }

    public function disabled(): static
    {
        return $this->state(fn () => ['enabled' => false]);
    }
}
