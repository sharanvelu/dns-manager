<?php

namespace App\Http\Controllers;

use App\Support\ActivityQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

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
