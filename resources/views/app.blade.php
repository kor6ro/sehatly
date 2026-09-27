{{--
    The SPA shell fallback, and the end of the Inertia root template.

    Before todo 30 this file was Inertia's root view: it rendered
    `<x-inertia::app />`, `<x-inertia::head>`, and a `@vite()` call whose second
    argument was `"resources/js/pages/{$page['component']}.tsx"` - a per-page
    dynamic import driven by the Inertia request header. All of that is gone, and
    so is every `@inertia` reference: `inertiajs/inertia-laravel` is uninstalled,
    so the `<x-inertia::*>` component namespace no longer resolves at all.

    It is now reached only when the SPA has not been built. `routes/web.php`
    serves `web/dist/index.html` - the real shell, produced by `npm run build` in
    `web/`, mounting on `#root` - whenever that file exists, and renders this view
    with a 503 when it does not. The status is the point: a 200 carrying a
    placeholder would be a lie a client cannot detect, and a 503 says plainly that
    the deployment is incomplete.
--}}
<!doctype html>
<html lang="id">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Sehatly</title>
    </head>
    <body>
        <div id="root"></div>
        <main>
            <h1>The web client has not been built.</h1>
            <p>
                This deployment serves the SPA shell from Laravel, but
                <code>{{ $spaIndex }}</code> does not exist yet.
            </p>
            <p>Build it with:</p>
            <pre>cd web &amp;&amp; npm install &amp;&amp; npm run build</pre>
            <p>
                The API is unaffected and remains available under
                <code>/api/v1</code>.
            </p>
        </main>
    </body>
</html>
