/**
 * Teach `node --test` the `@/*` path alias from `tsconfig.json`.
 *
 * ## Why this is needed at all
 *
 * `npm run test:unit` runs `node --test` directly over the TypeScript sources, with no
 * bundler. Node's type stripping erases `import type` but resolves a VALUE import with
 * real ESM rules, and `@/lib/realtime/dedupe` is neither relative nor a package in
 * `node_modules`. Without this hook the only files a unit test could reach are the ones
 * with zero imports - which is exactly the wrong constraint: it makes the modules worth
 * testing the untestable ones.
 *
 * `module.registerHooks` is the in-thread hook API (Node 22.15+/23.5+), so there is no
 * worker, no `--loader` flag and no `node_modules` mutation.
 *
 * ## What it does NOT do
 *
 * It rewrites the `@/` prefix only, and only to `.ts`. Anything else - a relative
 * specifier, a bare package name, a `.tsx` - is handed straight to Node's own resolver,
 * so a genuine typo still fails loudly instead of being quietly resolved.
 */
import { registerHooks } from 'node:module';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const akarSrc = path.resolve(
    path.dirname(fileURLToPath(import.meta.url)),
    '..',
    '..',
    'src',
);

registerHooks({
    resolve(specifier, context, nextResolve) {
        if (!specifier.startsWith('@/')) {
            return nextResolve(specifier, context);
        }

        const absolut = path.join(akarSrc, specifier.slice(2));

        return nextResolve(pathToFileURL(`${absolut}.ts`).href, context);
    },
});
