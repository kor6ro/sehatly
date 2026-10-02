import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    KODE_OTP_PANJANG,
    pesanHitungMundurOtp,
    pesanRalatKodeOtp,
    pesanTerlaluBanyakOtp,
} from '@/lib/otp';

describe('pesanHitungMundurOtp', () => {
    it('renders minutes and seconds from the server deadline', () => {
        assert.equal(pesanHitungMundurOtp(252), 'Kode berlaku 4 menit 12 detik lagi.');
        assert.equal(pesanHitungMundurOtp(60), 'Kode berlaku 1 menit 0 detik lagi.');
        assert.equal(pesanHitungMundurOtp(9), 'Kode berlaku 0 menit 9 detik lagi.');
    });

    it('never renders a negative countdown', () => {
        assert.equal(pesanHitungMundurOtp(-5), 'Kode berlaku 0 menit 0 detik lagi.');
    });

    it('keeps the six-digit contract', () => {
        assert.equal(KODE_OTP_PANJANG, 6);
    });
});

describe('pesanRalatKodeOtp', () => {
    it('maps the used-code rejection to the action-oriented copy', () => {
        assert.equal(
            pesanRalatKodeOtp('Kode OTP sudah pernah dipakai.'),
            'Kode ini sudah dipakai. Minta kode baru.',
        );
    });

    it('passes invalid and expired messages through', () => {
        assert.equal(
            pesanRalatKodeOtp('Kode OTP tidak valid.'),
            'Kode OTP tidak valid.',
        );
        assert.equal(
            pesanRalatKodeOtp('Kode OTP sudah kedaluwarsa. Silakan minta kode baru.'),
            'Kode OTP sudah kedaluwarsa. Silakan minta kode baru.',
        );
    });
});

describe('pesanTerlaluBanyakOtp', () => {
    it('prefers the server Retry-After in seconds', () => {
        assert.equal(
            pesanTerlaluBanyakOtp('Terlalu banyak permintaan.', 45),
            'Terlalu banyak percobaan. Coba lagi dalam 45 detik.',
        );
    });

    it('falls back to the server message without a header', () => {
        assert.equal(
            pesanTerlaluBanyakOtp('Terlalu banyak percobaan. Coba lagi nanti.', null),
            'Terlalu banyak percobaan. Coba lagi nanti.',
        );
    });

    it('has honest copy when the server says nothing', () => {
        assert.equal(
            pesanTerlaluBanyakOtp('   ', null),
            'Terlalu banyak percobaan. Coba lagi nanti.',
        );
    });
});
