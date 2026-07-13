<?php

use App\Jobs\CheckProviderDrift;
use App\Models\Provider;
use Illuminate\Support\Facades\Schedule;

Schedule::call(function () {
    Provider::query()
        ->where('enabled', true)
        ->pluck('id')
        ->each(fn (int $id) => CheckProviderDrift::dispatch($id));
})->name('dns-drift-check')->everyFifteenMinutes();
