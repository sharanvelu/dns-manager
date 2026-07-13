<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
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
        Vite::usePreloadTagAttributes(false);

        Event::listen(function (SocialiteWasCalled $event) {
            $event->extendSocialite('oidc', Provider::class);
        });

        Gate::define('manage-entries', fn (User $user) => $user->canManageEntries());
        Gate::define('manage-providers', fn (User $user) => $user->canManageProviders());
        Gate::define('manage-users', fn (User $user) => $user->isSuperAdmin());
    }
}
