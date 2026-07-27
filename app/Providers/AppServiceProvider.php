<?php

declare(strict_types = 1);

namespace App\Providers;

use App\Models\User;
use App\Models\DnsZone;
use App\Models\DnsEntry;
use App\Models\ZoneUser;
use App\Policies\DnsZonePolicy;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\Facades\Event;
use SocialiteProviders\OIDC\Provider;
use App\Models\Provider as DnsProvider;
use Illuminate\Support\ServiceProvider;
use SocialiteProviders\Manager\SocialiteWasCalled;
use Illuminate\Database\Eloquent\Relations\Relation;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
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

        // Super Admin passes every ability; everything else is deny-by-default.
        // Super Viewer is read-only by construction: no mutating gate or
        // policy ability ever returns true for them.
        Gate::before(fn (User $user) => $user->isSuperAdmin() ? true : null);

        Gate::policy(DnsZone::class, DnsZonePolicy::class);

        Gate::define('create-zones', fn (User $user) => false);
        Gate::define('manage-providers', fn (User $user) => false);
        Gate::define('view-providers', fn (User $user) => $user->isSuperViewer());
        Gate::define('manage-users', fn (User $user) => $user->isUserAdmin());
        Gate::define('view-users', fn (User $user) => $user->isUserAdmin() || $user->isSuperViewer());
        Gate::define('view-global-activity', fn (User $user) => $user->isSuperViewer());

        // Short aliases stored in activity_log.subject_type / causer_type —
        // keeps the audit trail readable and the filter values stable.
        Relation::morphMap([
            'entry' => DnsEntry::class,
            'provider' => DnsProvider::class,
            'user' => User::class,
            'zone' => DnsZone::class,
            'zone-grant' => ZoneUser::class,
        ]);
    }
}
