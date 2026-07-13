<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- Relative links inside the markdown (e.g. [Providers](providers)) resolve
         against /docs/ from every page, including the index at /docs. --}}
    <base href="{{ url('docs') }}/">

    <title>{{ $doc['title'] }} — DNS Manager Docs (v{{ config('app.version') }})</title>
    @if ($doc['description'] !== '')
        <meta name="description" content="{{ $doc['description'] }}">
    @endif

    <link rel="icon" href="{{ url('favicon.svg') }}" type="image/svg+xml">
    <link rel="alternate icon" href="{{ url('favicon.ico') }}" sizes="any">

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />

    <style>
        :root {
            --bg: #fafafa;
            --panel: #ffffff;
            --text: #18181b;
            --muted: #71717a;
            --border: #e4e4e7;
            --code-bg: #f4f4f5;
            --link: #3b5bdb;
            --banner-bg: #f4f4f5;
            --accent-current: #18181b;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #0c0c0e;
                --panel: #131316;
                --text: #e4e4e7;
                --muted: #9d9da6;
                --border: #26262b;
                --code-bg: #1c1c21;
                --link: #91a7ff;
                --banner-bg: #17171b;
                --accent-current: #fafafa;
            }
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: 'Instrument Sans', ui-sans-serif, system-ui, sans-serif;
            font-size: 15px;
            line-height: 1.65;
            background: var(--bg);
            color: var(--text);
            -webkit-font-smoothing: antialiased;
        }

        a { color: var(--link); text-decoration: none; }
        a:hover { text-decoration: underline; }

        .shell {
            display: flex;
            min-height: 100vh;
            max-width: 76rem;
            margin: 0 auto;
        }

        /* ---- Sidebar ---- */
        .sidebar {
            flex: 0 0 15rem;
            padding: 1.5rem 1.25rem;
            border-right: 1px solid var(--border);
        }

        .brand {
            display: flex;
            align-items: baseline;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
        }

        .brand .name { font-weight: 600; font-size: 0.95rem; color: var(--text); }
        .brand .version {
            font-size: 0.75rem;
            color: var(--muted);
            font-variant-numeric: tabular-nums;
        }

        .nav-label {
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--muted);
            margin: 0 0 0.5rem;
        }

        .nav-list { list-style: none; margin: 0; padding: 0; }
        .nav-list li { margin: 0; }

        .nav-list a {
            display: block;
            padding: 0.3rem 0.5rem;
            margin: 0 -0.5rem;
            border-radius: 6px;
            color: var(--muted);
            font-size: 0.875rem;
        }

        .nav-list a:hover { color: var(--text); text-decoration: none; background: var(--code-bg); }

        .nav-list a[aria-current="page"] {
            color: var(--accent-current);
            font-weight: 500;
            background: var(--code-bg);
        }

        /* ---- Main column ---- */
        .main {
            flex: 1;
            min-width: 0;
            display: flex;
            flex-direction: column;
            padding: 1.5rem 2.5rem 2rem;
        }

        .banner {
            border: 1px solid var(--border);
            background: var(--banner-bg);
            border-radius: 8px;
            padding: 0.6rem 0.9rem;
            font-size: 0.8125rem;
            color: var(--muted);
            margin-bottom: 2rem;
        }

        .content { max-width: 72ch; flex: 1; }

        .content h1, .content h2, .content h3, .content h4 {
            line-height: 1.3;
            font-weight: 600;
            margin: 2em 0 0.6em;
        }
        .content h1 { font-size: 1.6rem; margin-top: 0; }
        .content h2 {
            font-size: 1.2rem;
            border-bottom: 1px solid var(--border);
            padding-bottom: 0.35em;
        }
        .content h3 { font-size: 1.02rem; }

        .content p { margin: 0.9em 0; }
        .content ul, .content ol { padding-left: 1.4em; }
        .content li { margin: 0.3em 0; }
        .content img { max-width: 100%; }
        .content hr { border: 0; border-top: 1px solid var(--border); margin: 2em 0; }

        .content code {
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: 0.85em;
            background: var(--code-bg);
            border: 1px solid var(--border);
            border-radius: 5px;
            padding: 0.1em 0.35em;
        }

        .content pre {
            background: var(--code-bg);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 0.9rem 1rem;
            overflow-x: auto;
            line-height: 1.55;
        }

        .content pre code { background: none; border: 0; padding: 0; font-size: 0.8125rem; }

        .content blockquote {
            margin: 1em 0;
            padding: 0.1em 1em;
            border-left: 3px solid var(--border);
            color: var(--muted);
        }

        .content table {
            border-collapse: collapse;
            width: 100%;
            margin: 1.2em 0;
            font-size: 0.875rem;
        }

        .content th, .content td {
            border: 1px solid var(--border);
            padding: 0.45rem 0.7rem;
            text-align: left;
            vertical-align: top;
        }

        .content th {
            background: var(--code-bg);
            font-weight: 600;
            font-size: 0.8125rem;
        }

        /* ---- Footer ---- */
        .footer {
            margin-top: 3rem;
            padding-top: 1rem;
            border-top: 1px solid var(--border);
            display: flex;
            gap: 1.25rem;
            flex-wrap: wrap;
            font-size: 0.8125rem;
            color: var(--muted);
        }

        /* ---- Mobile: sidebar becomes a simple top nav ---- */
        @media (max-width: 768px) {
            .shell { flex-direction: column; }

            .sidebar {
                flex: none;
                border-right: 0;
                border-bottom: 1px solid var(--border);
                padding: 1.25rem 1.25rem 1rem;
            }

            .brand { margin-bottom: 0.75rem; }

            .nav-list {
                display: flex;
                flex-wrap: wrap;
                gap: 0.25rem 0.75rem;
            }

            .nav-list a { margin: 0; padding: 0.2rem 0.5rem; }

            .main { padding: 1.25rem; }
        }
    </style>
</head>
<body>
    <div class="shell">
        <aside class="sidebar">
            <div class="brand">
                <span class="name">DNS Manager</span>
                <span class="version">v{{ config('app.version') }}</span>
            </div>
            <p class="nav-label">Documentation</p>
            <nav aria-label="Documentation pages">
                <ul class="nav-list">
                    @foreach ($pages as $navPage)
                        <li>
                            <a href="{{ route('docs', $navPage['slug'] === 'index' ? null : $navPage['slug']) }}"
                               @if ($navPage['slug'] === $current) aria-current="page" @endif>
                                {{ $navPage['title'] }}
                            </a>
                        </li>
                    @endforeach
                </ul>
            </nav>
        </aside>

        <div class="main">
            <div class="banner" role="note">
                You are viewing the documentation for your installed version (v{{ config('app.version') }}).
                Documentation for the latest version is available at
                <a href="{{ config('app.docs_site_url') }}">{{ config('app.docs_site_url') }}</a>.
            </div>

            <main class="content">
                {!! $doc['html'] !!}
            </main>

            <footer class="footer">
                <a href="{{ url('dashboard') }}">&larr; Back to DNS Manager</a>
                <a href="{{ config('app.docs_site_url') }}">Latest documentation</a>
            </footer>
        </div>
    </div>
</body>
</html>
