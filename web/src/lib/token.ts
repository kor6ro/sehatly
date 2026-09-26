const STORAGE_KEY = 'sehatly.access_token';

export function getAccessToken(): string | null {
    return globalThis.sessionStorage?.getItem(STORAGE_KEY) ?? null;
}

export function setAccessToken(token: string): void {
    globalThis.sessionStorage?.setItem(STORAGE_KEY, token);
}

export function clearAccessToken(): void {
    globalThis.sessionStorage?.removeItem(STORAGE_KEY);
}
