/// <reference types="vite/client" />

interface ImportMetaEnv {
    readonly VITE_API_ORIGIN?: string;
    readonly VITE_BROADCAST_DRIVER?: 'reverb' | 'pusher';
    readonly VITE_PUSHER_APP_KEY?: string;
    readonly VITE_PUSHER_CLUSTER?: string;
    readonly VITE_PUSHER_FORCE_TLS?: string;
    readonly VITE_REVERB_APP_KEY?: string;
    readonly VITE_REVERB_HOST?: string;
    readonly VITE_REVERB_PORT?: string;
    readonly VITE_REVERB_SCHEME?: string;
}

interface ImportMeta {
    readonly env: ImportMetaEnv;
}
