export const FLASH_EVENT = 'sehatly:flash';

export type FlashLevel = 'success' | 'info' | 'warning' | 'error';

export type Flash = {
    level: FlashLevel;
    message: string;
};

export function dispatchFlash(flash: Flash): void {
    globalThis.dispatchEvent(
        new CustomEvent<Flash>(FLASH_EVENT, { detail: flash }),
    );
}
