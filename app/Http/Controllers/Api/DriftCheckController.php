<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\CheckProviderDrift;
use App\Models\Provider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DriftCheckController extends Controller
{
    /**
     * Queue drift checks for all enabled providers (or one, via provider_id).
     * Same behavior as the built-in schedule — intended for external
     * automation tools (N8N, cron, CI) via bearer token.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'provider_id' => ['nullable', 'integer', 'exists:providers,id'],
        ]);

        $providers = Provider::query()
            ->where('enabled', true)
            ->when($request->input('provider_id'), fn ($q, $id) => $q->whereKey($id))
            ->pluck('name', 'id');

        foreach ($providers->keys() as $id) {
            CheckProviderDrift::dispatch($id);
        }

        return response()->json([
            'queued' => $providers->count(),
            'providers' => $providers->values(),
        ]);
    }
}
