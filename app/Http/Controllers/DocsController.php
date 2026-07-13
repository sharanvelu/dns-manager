<?php

namespace App\Http\Controllers;

use App\Support\DocsRepository;
use Illuminate\Contracts\View\View;

class DocsController extends Controller
{
    public function __invoke(DocsRepository $docs, string $page = 'index'): View
    {
        $doc = $docs->find($page);

        abort_if($doc === null, 404);

        return view('docs.show', [
            'doc' => $doc,
            'pages' => $docs->pages(),
            'current' => $doc['slug'],
        ]);
    }
}
