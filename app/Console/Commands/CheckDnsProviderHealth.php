<?php

declare(strict_types = 1);

namespace App\Console\Commands;

use App\Models\Provider;
use Illuminate\Console\Command;
use App\Jobs\CheckProviderHealth;

class CheckDnsProviderHealth extends Command
{
    protected $signature = 'dns:check-provider-health
                            {--provider= : Only check this provider ID}';

    protected $description = 'Queue a connectivity health check for every enabled DNS provider';

    public function handle(): int
    {
        $providers = Provider::query()
            ->where('enabled', true)
            ->when($this->option('provider'), fn ($q, $id) => $q->whereKey($id))
            ->pluck('name', 'id');

        foreach ($providers->keys() as $id) {
            CheckProviderHealth::dispatch($id);
        }

        $this->info(sprintf(
            'Queued health check for %d provider(s)%s.',
            $providers->count(),
            $providers->isEmpty() ? '' : ': ' . $providers->implode(', '),
        ));

        return self::SUCCESS;
    }
}
