import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

declare global {
    interface Window {
        Pusher: typeof Pusher;
    }
}

function requireEnv(name: keyof ImportMetaEnv): string {
    const value = import.meta.env[name];

    if (typeof value !== 'string' || value === '') {
        throw new Error(
            `Reverb is not configured: ${name} is missing from the web build.`,
        );
    }

    return value;
}

let connection: Echo<'reverb'> | null = null;

export function connectEcho(bearerToken: string | null): Echo<'reverb'> {
    if (connection === null) {
        globalThis.window.Pusher = Pusher;

        connection = new Echo({
            broadcaster: 'reverb',
            key: requireEnv('VITE_REVERB_APP_KEY'),
            wsHost: requireEnv('VITE_REVERB_HOST'),
            wsPort: Number(import.meta.env.VITE_REVERB_PORT ?? '80'),
            wssPort: Number(import.meta.env.VITE_REVERB_PORT ?? '443'),
            forceTLS: import.meta.env.VITE_REVERB_SCHEME === 'https',
            enabledTransports: ['ws', 'wss'],
            bearerToken,
        });
    } else {
        connection.options.bearerToken = bearerToken;
    }

    return connection;
}

export function disconnectEcho(): void {
    connection?.disconnect();
    connection = null;
}
