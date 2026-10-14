# Sehatly — front-end

The React SPA and the Playwright end-to-end harness. This is the **only**
front-end project in the repository; there is no second one, and the root
`package.json` exists only to delegate here.

Read [`AGENTS.md`](AGENTS.md) before touching any UI — it holds the UI/UX rules,
the token audit, and the list of things that are forbidden. This file only says
what the project *is* and how to run it.

## How it is served

There is no Vite dev server in the normal workflow. `routes/web.php` reads the
build output directly:

```
browser  →  http://sehatly.test  →  routes/web.php
             ├─ /assets/*        →  frontend/dist/assets/…   (spa.asset)
             └─ everything else  →  frontend/dist/index.html (spa.shell, a catch-all)
                                                    ↓
                                     /api/v1  →  Laravel  →  MySQL
```

The catch-all is what makes a deep link such as `/konsultasi/5` return a real
`200` with the shell instead of a `404`. Its negative lookahead —
`^(?!api|broadcasting|sanctum|up|build|storage|assets).*$` — is load-bearing: it
deliberately does not swallow `api` or `assets`, because neither comes from this
bundle.

So **`npm run build` is the deploy step**, not a formality:

```console
npm run build          # from the repository root, or from here
```

## Commands

From `frontend/`:

| command | what it does |
| --- | --- |
| `npm run build` | Vite 8 production build into `frontend/dist` (gitignored) |
| `npm run types:check` | `tsc --noEmit` |
| `npm run test:unit` | Node's built-in test runner over `tests/unit/*.test.ts` |
| `npm run test:e2e` | Playwright against `http://sehatly.test` — **build first** |
| `npm run dev` | Vite dev server, `/api` proxied to `SEHATLY_API_TARGET` (default `http://localhost:8000`). For UI iteration only; it is not what production serves |
| `npm run preview` | Serve the built `dist/` locally |

From the repository root, `build`, `dev` and `types:check` delegate into this
directory, so `npm run build` at the root still means "build the SPA".

`types:generate` is at the root and points at `tools/openapi-types`, not here.
That generator needs TypeScript 5 and this project pins TypeScript 7; npm cannot
satisfy both in one tree, so the generator carries its own. It is a toolchain
split, not a version conflict in the app.

## Layout

| path | holds |
| --- | --- |
| `src/app/` | router, shell, guards, error boundary — `src/app/router.tsx` is the route table |
| `src/pages/` | one module per route |
| `src/features/` | the logic behind those pages |
| `src/components/` | shared components; `components/ui/` is the shadcn registry output |
| `src/lib/`, `src/hooks/`, `src/stores/`, `src/types/` | cross-cutting code |
| `src/styles/app.css` | **the only source of design tokens** (`@theme` + `:root` + `.dark`). Hardcoding a colour in a component is a bug |
| `tests/unit/` | pure-logic tests (time, phone, OTP, realtime dedupe/reconnect, QR) |
| `tests/e2e/` | 23 Playwright specs, `a11y.ts` for axe |
| `ux/` | benchmarked flow patterns — `ux/flows.md` lists them, `ux/patterns/<flow-id>.md` is the source of truth for a flow |

`tsconfig.json` includes only `src`, `vite.config.ts` and `playwright.config.ts`.

## Tests

```console
npm run types:check && npm run test:unit   # fast, no server needed
npm run build && npm run test:e2e          # e2e reads the built bundle
```

E2E runs two viewports — 1280×900 and 390×844 — with `--workers=2`.

Two things cannot be exercised by a real browser here: `http://sehatly.test` is
not a secure origin, so geolocation has to be stubbed with
`page.addInitScript` (see `dokter-f03.spec.ts`).

## Conventions worth knowing before you start

- **Patient home is `/`.** A landing link may only point at a route that exists
  in `src/app/router.tsx` — an invented path sails past the SPA catch-all and
  fails at e2e, not at review.
- **`IKON_SPESIALISASI` is the only source of specialization icons.** Do not
  reach for `lucide-react` for a specialization elsewhere and let the two drift.
- **A free-text query never goes into the URL.** Filters that are not
  shareable stay out of the address bar.
- **Status strings come from `php artisan sehatly:enums`.** Never hand-write an
  appointment, consultation or prescription status value.
