/**
 * OTP copy that must be computed, kept out of the page so it is testable without a DOM.
 */

/** `OtpService::KODE_DIGIT`; six zero-padded digits, transported as a string. */
export const KODE_OTP_PANJANG = 6;

export function pesanHitungMundurOtp(sisaDetik: number): string {
    const detik = Math.max(0, Math.floor(sisaDetik));

    return `Kode berlaku ${Math.floor(detik / 60)} menit ${detik % 60} detik lagi.`;
}

/**
 * The server says "Kode OTP sudah pernah dipakai."; the pattern's action-oriented copy is
 * shorter and says what to do, so the mapping lives here. Every other rejection message
 * (invalid, expired) is already specific and is passed through untouched.
 */
export function pesanRalatKodeOtp(pesanServer: string): string {
    return pesanServer.includes('pernah dipakai')
        ? 'Kode ini sudah dipakai. Minta kode baru.'
        : pesanServer;
}

export function pesanTerlaluBanyakOtp(
    pesanServer: string,
    retryAfter: number | null,
): string {
    if (retryAfter !== null) {
        return `Terlalu banyak percobaan. Coba lagi dalam ${retryAfter} detik.`;
    }

    return pesanServer.trim() === ''
        ? 'Terlalu banyak percobaan. Coba lagi nanti.'
        : pesanServer;
}
