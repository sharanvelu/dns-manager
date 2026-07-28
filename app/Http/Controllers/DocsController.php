<?php

declare(strict_types = 1);

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;
use App\Support\DocsRepository;

class DocsController extends Controller
{
    public function __invoke(DocsRepository $docs, string $page = 'index'): Response
    {
        $doc = $docs->find($page);

        abort_if($doc === null, 404);

        return Inertia::render('docs/show', [
            'doc' => [
                'slug' => $doc['slug'],
                'title' => $doc['title'],
                'description' => $doc['description'],
                'html' => $doc['html'],
                'headings' => $doc['headings'],
            ],
            'pages' => $docs->pages(),
            'current' => $doc['slug'],
            'version' => config('app.version'),
            'docsSiteUrl' => config('app.docs_site_url'),
            'searchIndex' => $docs->searchIndex(),
        ]);
    }
}
