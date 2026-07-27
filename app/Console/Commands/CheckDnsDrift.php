<?php

declare(strict_types = 1);

namespace App\Console\Commands;

use App\Models\Provider;
use Illuminate\Console\Command;
use App\Jobs\CheckProviderDrift;

class CheckDnsDrift extends Command
{
    protected $signature = 'dns:check-drift
                            {--provider= : Only check this provider ID}';

    protected $description = 'Queue a drift check for every enabled DNS provider';

    public function handle(): int
    {
        $providers = Provider::query()
            ->where('enabled', true)
            ->when($this->option('provider'), fn ($q, $id) => $q->whereKey($id))
            ->pluck('name', 'id');

        foreach ($providers->keys() as $id) {
            CheckProviderDrift::dispatch($id);
        }

        $this->info(sprintf(
            'Queued drift check for %d provider(s)%s.',
            $providers->count(),
            $providers->isEmpty() ? '' : ': ' . $providers->implode(', '),
        ));

        return self::SUCCESS;
    }
}
