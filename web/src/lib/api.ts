import ky from 'ky';
import { getAccessToken } from '@/lib/token';

const origin = import.meta.env.VITE_API_ORIGIN ?? '';

/**
 * The single transport for the whole SPA. `VITE_API_ORIGIN` is empty in
 * production, where Laravel serves this bundle from `public/` and `/api/v1` is
 * same-origin; in development `vite.config.ts` proxies `/api` to
 * `http://localhost:8000`. No endpoint is declared here on purpose: the route
 * table belongs to the module todos, so the skeleton only pins the contract.
 *
 * Call it with a path *relative* to this base (`api('dokter')`), never with a
 * leading slash: ky resolves a leading-slash input against the origin and
 * would drop the `/api/v1` segment.
 */
export const api = ky.create({
    baseUrl: `${origin}/api/v1/`,
    credentials: 'include',
    retry: { limit: 1, methods: ['get', 'head'] },
    hooks: {
        beforeRequest: [
            ({ request }) => {
                request.headers.set('Accept', 'application/json');
                request.headers.set('X-Requested-With', 'XMLHttpRequest');

                const token = getAccessToken();

                if (token !== null) {
                    request.headers.set('Authorization', `Bearer ${token}`);
                }
            },
        ],
    },
});
