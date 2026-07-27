<?php

declare(strict_types = 1);

namespace App\Http\Middleware;

use Inertia\Middleware;
use Illuminate\Http\Request;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return array_merge(parent::share($request), [
            'name' => config('app.name'),
            'auth' => [
                // Explicit shape — never serialize the raw model (oidc_sub etc.).
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'avatar_url' => $user->avatar_url,
                    'roles' => $user->roles ?? [],
                ] : null,
                'can' => [
                    'createZones' => $user?->can('create-zones') ?? false,
                    'manageProviders' => $user?->can('manage-providers') ?? false,
                    'viewProviders' => $user?->can('view-providers') ?? false,
                    'manageUsers' => $user?->can('manage-users') ?? false,
                    'viewUsers' => $user?->can('view-users') ?? false,
                    'viewGlobalActivity' => $user?->can('view-global-activity') ?? false,
                    'hasZoneAccess' => $user
                        ? ($user->isSuperAdmin() || $user->isSuperViewer() || $user->hasAnyZoneAccess())
                        : false,
                ],
            ],
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
                'importResult' => $request->session()->get('importResult'),
            ],
        ]);
    }
}
