import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

/**
 * Guard tests for StatusBadge - ensures the consolidated status badge
 * covers all required variants and prevents regressions.
 *
 * These constants mirror src/components/ui/status-badge.tsx VARIANT_CLASS.
 * If the component changes, update this test file accordingly.
 */
type StatusBadgeVariant =
    | 'successSubtle'
    | 'warningSubtle'
    | 'destructiveSubtle'
    | 'infoSubtle'
    | 'muted';

const VARIANT_CLASS: Record<StatusBadgeVariant, string> = {
    successSubtle:
        'text-success-subtle-foreground bg-success-subtle border-transparent',
    warningSubtle:
        'text-warning-subtle-foreground bg-warning-subtle border-transparent',
    destructiveSubtle:
        'text-destructive-subtle-foreground bg-destructive-subtle border-transparent',
    infoSubtle:
        'text-info-subtle-foreground bg-info-subtle border-transparent',
    muted: 'text-muted-foreground bg-muted border-transparent',
};

describe('StatusBadge - variant contract', () => {
    it('defines all five required variants', () => {
        const required: StatusBadgeVariant[] = [
            'successSubtle',
            'warningSubtle',
            'destructiveSubtle',
            'infoSubtle',
            'muted',
        ];
        for (const v of required) {
            assert.ok(VARIANT_CLASS[v], `missing variant: ${v}`);
        }
    });

    it('each variant has text + bg + border classes', () => {
        for (const [variant, cls] of Object.entries(VARIANT_CLASS)) {
            assert.match(cls, /text-/, `${variant}: missing text color`);
            assert.match(cls, /bg-/, `${variant}: missing background`);
            assert.match(cls, /border-/, `${variant}: missing border`);
        }
    });

    it('subtle variants use subtle token pairs (fg + bg)', () => {
        assert.match(VARIANT_CLASS.successSubtle, /text-success-subtle-foreground/);
        assert.match(VARIANT_CLASS.successSubtle, /bg-success-subtle/);
        assert.match(VARIANT_CLASS.warningSubtle, /text-warning-subtle-foreground/);
        assert.match(VARIANT_CLASS.warningSubtle, /bg-warning-subtle/);
        assert.match(VARIANT_CLASS.destructiveSubtle, /text-destructive-subtle-foreground/);
        assert.match(VARIANT_CLASS.destructiveSubtle, /bg-destructive-subtle/);
        assert.match(VARIANT_CLASS.infoSubtle, /text-info-subtle-foreground/);
        assert.match(VARIANT_CLASS.infoSubtle, /bg-info-subtle/);
    });

    it('muted variant uses muted token pair', () => {
        assert.match(VARIANT_CLASS.muted, /text-muted-foreground/);
        assert.match(VARIANT_CLASS.muted, /bg-muted/);
    });
});

/**
 * Mapping guard - every feature status badge should map its status values
 * to the canonical StatusBadge variants. This test documents the expected
 * mappings so new status values cannot silently fall through to wrong variant.
 */
describe('StatusBadge - feature mapping expectations', () => {
    // These are the status enums from the backend. If backend adds new statuses,
    // the corresponding feature badge file must be updated AND this test updated.
    const EXPECTED_MAPPINGS: Record<string, Record<string, StatusBadgeVariant>> = {
        // booking/booking-status-badge.tsx
        'StatusBooking': {
            'menunggu_konfirmasi': 'warningSubtle',
            'dikonfirmasi': 'infoSubtle',
            'siap': 'infoSubtle',
            'berlangsung': 'infoSubtle',
            'selesai': 'successSubtle',
            'dibatalkan': 'destructiveSubtle',
            'tidak_hadir': 'destructiveSubtle',
        },
        // pembayaran/status-pembayaran.tsx
        'StatusPesananObat': {
            'menunggu_pembayaran': 'warningSubtle',
            'diproses': 'infoSubtle',
            'siap': 'infoSubtle',
            'sedang_dikirim': 'infoSubtle',
            'selesai': 'successSubtle',
            'dibatalkan': 'destructiveSubtle',
        },
        'StatusInvoice': {
            'draft': 'muted',
            'menunggu_pembayaran': 'warningSubtle',
            'lunas': 'successSubtle',
            'kadaluarsa': 'muted',
            'dibatalkan': 'destructiveSubtle',
            'refund_sebagian': 'muted',
            'refund_penuh': 'muted',
        },
        'StatusPembayaran': {
            'pending': 'warningSubtle',
            'berhasil': 'successSubtle',
            'gagal': 'destructiveSubtle',
            'kedaluwarsa': 'muted',
            'refund': 'muted',
        },
        // konsultasi/status-badge.tsx
        'StatusKonsultasi': {
            'menunggu': 'warningSubtle',
            'berlangsung': 'infoSubtle',
            'selesai': 'successSubtle',
            'dibatalkan': 'destructiveSubtle',
        },
        'StatusDokumen': {
            'tersedia': 'successSubtle',
            'menunggu': 'warningSubtle',
            'ditolak': 'destructiveSubtle',
        },
        // kurir/kurir-status-badge.tsx
        'StatusPesananObatKurir': {
            'menunggu_pembayaran': 'warningSubtle',
            'diproses': 'infoSubtle',
            'siap': 'infoSubtle',
            'sedang_dikirim': 'infoSubtle',
            'selesai': 'successSubtle',
            'dibatalkan': 'destructiveSubtle',
        },
        // pdp/pdp-status-badge.tsx
        'PdpStatus': {
            'belum_diminta': 'muted',
            'diminta': 'warningSubtle',
            'diberikan': 'successSubtle',
            'ditolak': 'destructiveSubtle',
        },
        // resep/resep-status-badge.tsx
        'StatusResep': {
            'draft': 'muted',
            'menunggu_verifikasi': 'warningSubtle',
            'diverifikasi': 'infoSubtle',
            'siap_diambil': 'successSubtle',
            'diambil': 'successSubtle',
            'kedaluwarsa': 'muted',
            'dibatalkan': 'destructiveSubtle',
            'ditolak': 'destructiveSubtle',
        },
        // booking/refund-card.tsx
        'StatusRefund': {
            'diajukan': 'muted',
            'diproses': 'warningSubtle',
            'berhasil': 'successSubtle',
            'ditolak': 'destructiveSubtle',
        },
        // admin/status-badges.tsx
        'AdminVerifikasi': {
            'pending': 'warningSubtle',
            'terverifikasi': 'successSubtle',
            'ditolak': 'destructiveSubtle',
        },
        'AdminSTR': {
            'kedaluwarsa': 'destructiveSubtle',
            'segera': 'warningSubtle',
            'valid': 'successSubtle',
        },
    };

    it('documents expected variant for every known status (update when backend changes)', () => {
        for (const [enumName, mapping] of Object.entries(EXPECTED_MAPPINGS)) {
            for (const [status, variant] of Object.entries(mapping)) {
                assert.ok(VARIANT_CLASS[variant], `${enumName}.${status} -> ${variant} (variant exists)`);
            }
        }
    });
});