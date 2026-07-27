<?php

declare(strict_types = 1);

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\Request;
use App\Support\ActivityQuery;
use Illuminate\Http\JsonResponse;

class ActivityLogController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = ActivityQuery::validateFilters($request);

        return Inertia::render('activity', [
            'activities' => ActivityQuery::activities($filters),
            'filters' => $filters,
            'users' => ActivityQuery::users(),
            'events' => ActivityQuery::events(),
            'subject' => ActivityQuery::resolveSubject($filters),
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        return response()->json(ActivityQuery::activities(ActivityQuery::validateFilters($request)));
    }
}
