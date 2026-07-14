<?php

namespace App\Providers;

use App\Models\DnsEntry;
use App\Models\Provider as DnsProvider;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use SocialiteProviders\Manager\SocialiteWasCalled;
use SocialiteProviders\OIDC\Provider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (config('app.force_https')) {
            URL::forceScheme('https');
        }

        Vite::usePreloadTagAttributes(false);

        Event::listen(function (SocialiteWasCalled $event) {
            $event->extendSocialite('oidc', Provider::class);
        });

        Gate::define('manage-entries', fn (User $user) => $user->canManageEntries());
        Gate::define('manage-providers', fn (User $user) => $user->canManageProviders());
        Gate::define('manage-users', fn (User $user) => $user->isSuperAdmin());
        Gate::define('view-activity', fn (User $user) => $user->isSuperAdmin());

        // Short aliases stored in activity_log.subject_type / causer_type —
        // keeps the audit trail readable and the filter values stable.
        Relation::morphMap([
            'entry' => DnsEntry::class,
            'provider' => DnsProvider::class,
            'user' => User::class,
        ]);
    }
}
