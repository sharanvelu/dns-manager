<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Spatie\Activitylog\Models\Activity;

class FlushActivities extends Command
{
    protected $signature = 'dns:flush-activities
                            {--days= : Only delete activities older than this many days}
                            {--force : Skip the confirmation prompt}';

    protected $description = 'Delete audit-trail activities — everything, or only those older than --days';

    public function handle(): int
    {
        $days = $this->option('days');

        if ($days !== null && (! ctype_digit($days) || (int) $days < 1)) {
            $this->error('--days must be a positive integer.');

            return self::INVALID;
        }

        /** @var class-string<Activity> $model */
        $model = config('activitylog.activity_model', Activity::class);

        $query = $model::query()->when(
            $days !== null,
            fn ($q) => $q->where('created_at', '<', now()->subDays((int) $days)),
        );

        $count = $query->count();

        if ($count === 0) {
            $this->info('No activities to delete.');

            return self::SUCCESS;
        }

        $scope = $days === null ? "all {$count}" : "{$count} (older than {$days} days)";

        if (! $this->option('force') && ! $this->confirm("Permanently delete {$scope} activity record(s)?")) {
            $this->comment('Aborted — nothing deleted.');

            return self::FAILURE;
        }

        $deleted = $query->delete();

        $this->info("Deleted {$deleted} activity record(s).");

        return self::SUCCESS;
    }
}
