<?php

namespace App\Jobs;

use App\Connectors\DTOs\TestResult;
use App\Enums\HealthStatus;
use App\Models\Provider;
use App\Models\SyncLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class CheckProviderHealth implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $providerId) {}

    public function handle(): void
    {
        $provider = Provider::find($this->providerId);

        if (! $provider || ! $provider->enabled) {
            return;
        }

        try {
            $result = $provider->connector()->testConnection();
        } catch (Throwable $e) {
            $result = TestResult::failure($e->getMessage());
        }

        $provider->update([
            'health_status' => $result->ok ? HealthStatus::Ok : HealthStatus::Error,
            'health_message' => $result->ok ? null : $result->message,
            'last_checked_at' => now(),
        ]);

        SyncLog::record($provider, null, 'health-check', $result->ok ? 'success' : 'error', $result->message);
    }
}
