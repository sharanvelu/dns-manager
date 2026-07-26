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

    public function technitium(): static
    {
        return $this->state(fn () => [
            'type' => ProviderType::Technitium,
            'config' => [
                'base_url' => 'https://technitium.internal:53443',
                'api_token' => fake()->sha256(),
                'verify_tls' => false,
            ],
            'managed_record_types' => ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'SRV', 'NS', 'CAA', 'PTR'],
        ]);
    }

    public function disabled(): static
    {
        return $this->state(fn () => ['enabled' => false]);
    }
}
