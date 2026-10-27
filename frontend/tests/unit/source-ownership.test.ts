import assert from 'node:assert/strict';
import { existsSync } from 'node:fs';
import path from 'node:path';
import { describe, it } from 'node:test';
import { fileURLToPath } from 'node:url';

const sourceRoot = path.resolve(
    path.dirname(fileURLToPath(import.meta.url)),
    '..',
    '..',
    'src',
);

describe('shared component ownership', () => {
    it('keeps cross-portal application components under shared', () => {
        const sharedComponents = [
            'auth/masuk-dialog.tsx',
            'auth/otp-field.tsx',
            'form/field.tsx',
            'layout/page-header.tsx',
            'layout/pagination.tsx',
            'layout/static-page.tsx',
            'layout/theme-toggle.tsx',
            'offline-banner.tsx',
            'states/empty-state.tsx',
            'states/error-state.tsx',
            'states/loading-state.tsx',
        ];

        for (const relativePath of sharedComponents) {
            assert.ok(
                existsSync(path.join(sourceRoot, 'shared', 'components', relativePath)),
                `${relativePath} must be in shared/components`,
            );
            assert.ok(
                !existsSync(path.join(sourceRoot, 'components', relativePath)),
                `${relativePath} must not remain in components`,
            );
        }
    });
});

describe('patient portal ownership', () => {
    it('keeps patient-only components and features under portals/pasien', () => {
        const patientModules = [
            'components/allergy/alergi-dialog.tsx',
            'components/dokter/doctor-card.tsx',
            'components/layout/landing-header.tsx',
            'components/profile/profile-details.tsx',
            'components/profile/profile-form.tsx',
            'features/dokter/direktori.tsx',
            'features/dokter/panel-pencarian.tsx',
            'features/dokter/use-posisi-pasien.ts',
            'features/dokter-profil/bintang-rating.tsx',
            'features/dokter-profil/credential-panel.tsx',
            'features/dokter-profil/doctor-profile-hero.tsx',
            'features/dokter-profil/doctor-trust-badge.tsx',
            'features/dokter-profil/format-jadwal.ts',
            'features/dokter-profil/profil-dokter-isi.tsx',
            'features/dokter-profil/reviews-block.tsx',
            'features/dokter-profil/schedule-preview.tsx',
            'features/landing/data.ts',
            'features/landing/hero-carousel.tsx',
            'features/landing/ikon-spesialis.ts',
            'features/landing/jejak-dokter.tsx',
            'features/landing/landing-footer.tsx',
            'features/landing/rel-menggulir.tsx',
            'features/landing/sections.tsx',
            'features/pembayaran/invoice-summary.tsx',
            'features/pembayaran/metode-pembayaran-picker.tsx',
            'features/pembayaran/payment-instructions.tsx',
            'features/pembayaran/pembayaran-menunggu.tsx',
            'features/pembayaran/promo-input.tsx',
            'features/pembayaran/status-pembayaran.tsx',
            'features/pengingat/format-pengingat.ts',
            'features/pengingat/pengingat-form-dialog.tsx',
            'features/pengingat/pengingat-list.tsx',
            'features/pesanan-obat/checkout-form.tsx',
            'features/pesanan-obat/order-tracking.tsx',
            'features/pesanan-obat/use-stok-resep.ts',
            'features/rekam-medis/rekam-medis-edit-form.tsx',
            'features/rekam-medis/rekam-medis-view.tsx',
            'features/rekam-medis/riwayat-akses-panel.tsx',
            'features/rekam-medis/riwayat-baris.tsx',
            'features/ulasan/form-ulasan.tsx',
            'features/ulasan/pilih-bintang.tsx',
            'features/booking/booking-form.tsx',
            'features/notifikasi/notifikasi-list.tsx',
            'features/notifikasi/preferensi-notifikasi-form.tsx',
            'features/pdp/consent-gate.tsx',
            'features/pdp/pdp-slot.tsx',
            'features/pdp/pdp-teks.ts',
        ];

        for (const relativePath of patientModules) {
            assert.ok(
                existsSync(path.join(sourceRoot, 'portals', 'pasien', relativePath)),
                `${relativePath} must be in portals/pasien`,
            );
            assert.ok(
                !existsSync(path.join(sourceRoot, relativePath)),
                `${relativePath} must not remain in the shared legacy folders`,
            );
        }
    });
});

describe('staff portal ownership', () => {
    it('keeps exclusive staff features in their portals and PDP badge shared', () => {
        const staffModules = [
            'portals/admin/features/admin/admin-gate.tsx',
            'portals/admin/features/admin/admin-jadwal.tsx',
            'portals/admin/features/admin/cache.ts',
            'portals/admin/features/admin/format-admin.ts',
            'portals/admin/features/admin/pesan.ts',
            'portals/admin/features/admin/status-badges.tsx',
            'portals/dokter/features/dashboard-dokter/antrean.tsx',
            'portals/dokter/features/dashboard-dokter/jadwal.tsx',
            'portals/dokter/features/dashboard-dokter/menunggu.tsx',
            'portals/dokter/features/dashboard-dokter/pintasan.tsx',
            'portals/kurir/features/kurir/kurir-status-badge.tsx',
            'portals/apotek/features/resep/antrean-verifikasi-list.tsx',
            'portals/apotek/features/resep/apoteker-verifikasi-queue.tsx',
            'shared/features/pdp/pdp-status-badge.tsx',
        ];
        const oldModules = [
            'features/admin/admin-gate.tsx',
            'features/admin/admin-jadwal.tsx',
            'features/admin/cache.ts',
            'features/admin/format-admin.ts',
            'features/admin/pesan.ts',
            'features/admin/status-badges.tsx',
            'features/dashboard-dokter/antrean.tsx',
            'features/dashboard-dokter/jadwal.tsx',
            'features/dashboard-dokter/menunggu.tsx',
            'features/dashboard-dokter/pintasan.tsx',
            'features/kurir/kurir-status-badge.tsx',
            'features/resep/antrean-verifikasi-list.tsx',
            'features/resep/apoteker-verifikasi-queue.tsx',
            'features/pdp/pdp-status-badge.tsx',
        ];

        for (const relativePath of staffModules) {
            assert.ok(existsSync(path.join(sourceRoot, relativePath)), `${relativePath} must exist`);
        }
        for (const relativePath of oldModules) {
            assert.ok(!existsSync(path.join(sourceRoot, relativePath)), `${relativePath} must be relocated`);
        }
    });
});

describe('shared domain feature ownership', () => {
    it('keeps cross-portal workflows and supporting modules under shared', () => {
        const sharedFeatures = [
            'booking/booking-calendar.tsx',
            'booking/booking-list.tsx',
            'booking/booking-status-badge.tsx',
            'booking/refund-card.tsx',
            'booking/reschedule-dialog.tsx',
            'booking/slot-picker.tsx',
            'konsultasi/chat-window.tsx',
            'konsultasi/keluar-sesi-dialog.tsx',
            'konsultasi/soap-form.tsx',
            'konsultasi/status-badge.tsx',
            'konsultasi/surat-keterangan-dialog.tsx',
            'konsultasi/use-konsultasi-channel.ts',
            'notifikasi/deep-link.ts',
            'notifikasi/notification-bell.tsx',
            'notifikasi/use-tandai-dibaca.ts',
            'resep/obat-autocomplete.tsx',
            'resep/resep-composer.tsx',
            'resep/resep-detail.tsx',
            'resep/resep-qr.tsx',
            'resep/resep-status-badge.tsx',
            'resep/warning-panel.tsx',
        ];

        for (const relativePath of sharedFeatures) {
            assert.ok(
                existsSync(path.join(sourceRoot, 'shared', 'features', relativePath)),
                `${relativePath} must be in shared/features`,
            );
            assert.ok(
                !existsSync(path.join(sourceRoot, 'features', relativePath)),
                `${relativePath} must not remain in features`,
            );
        }
        assert.ok(
            !existsSync(path.join(sourceRoot, 'hooks', 'use-konsultasi-channel.ts')),
            'use-konsultasi-channel.ts must move beside the shared consultation feature',
        );
    });
});

describe('shared frontend infrastructure', () => {
    it('removes the legacy feature tree without moving generic UI or hooks', () => {
        assert.ok(!existsSync(path.join(sourceRoot, 'features')), 'the legacy features folder must be gone');
        assert.ok(existsSync(path.join(sourceRoot, 'components', 'ui', 'button.tsx')));
        assert.ok(existsSync(path.join(sourceRoot, 'hooks', 'use-online-status.ts')));
        assert.ok(existsSync(path.join(sourceRoot, 'hooks', 'use-mobile.tsx')));
        assert.ok(
            existsSync(path.join(sourceRoot, 'shared', 'features', 'pdp', 'pdp-status-badge.tsx')),
        );
    });
});
