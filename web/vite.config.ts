import { fileURLToPath, URL } from 'node:url';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import { defineConfig, type UserConfig } from 'vite';

const apiTarget = process.env.SEHATLY_API_TARGET ?? 'http://localhost:8000';

/**
 * `lint` and `fmt` are `vite-plus` extensions, not core `vite` keys. `web/`
 * deliberately builds with upstream `vite`: `vite-plus` aliases `vite` to
 * `@voidzero-dev/vite-plus-core`, which would change the very
 * `build.cssMinify` default pinned below. `vp` still reads these blocks when
 * run from `web/`; `npm run build` ignores them.
 */
interface ToolchainConfig extends UserConfig {
    lint?: {
        ignorePatterns?: string[];
        options?: { denyWarnings?: boolean; typeAware?: boolean };
    };
    fmt?: {
        printWidth?: number;
        tabWidth?: number;
        singleQuote?: boolean;
        semi?: boolean;
        singleAttributePerLine?: boolean;
        htmlWhitespaceSensitivity?: 'css' | 'strict' | 'ignore';
        ignorePatterns?: string[];
        sortTailwindcss?: { functions?: string[]; stylesheet?: string };
    };
}

const config: ToolchainConfig = {
    plugins: [react(), tailwindcss()],
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./src', import.meta.url)),
        },
    },
    build: {
        // Vite 8 changed this default from `esbuild` to `lightningcss`.
        // lightningcss strips the `-webkit-backdrop-filter` prefix that the
        // shadcn overlay components (sidebar, dialog, dropdown-menu, sheet,
        // sonner) rely on for Safari and older iOS, and it does so silently:
        // the build still succeeds. Verified in
        // `.omo/evidence/task-5-sehatly.md`.
        cssMinify: 'esbuild',
    },
    server: {
        proxy: {
            '/api': {
                target: apiTarget,
                changeOrigin: true,
            },
        },
        watch: {
            ignored: ['**/.agents/**', '**/.claude/**', '**/.cursor/**'],
        },
    },
    // Carried over from the root `vite.config.ts:42-76` so the relocated kit
    // is neither linted nor reformatted away from the shadcn registry style.
    lint: {
        ignorePatterns: [
            'node_modules/**',
            'dist/**',
            'playwright-report/**',
            'test-results/**',
            'src/components/ui/*',
        ],
        options: {
            denyWarnings: true,
            typeAware: true,
        },
    },
    fmt: {
        printWidth: 80,
        tabWidth: 4,
        singleQuote: true,
        semi: true,
        singleAttributePerLine: false,
        htmlWhitespaceSensitivity: 'css',
        ignorePatterns: ['package.json', 'src/components/ui/*'],
        sortTailwindcss: {
            functions: ['clsx', 'cn', 'cva'],
            stylesheet: 'src/styles/app.css',
        },
    },
};

export default defineConfig(config);
