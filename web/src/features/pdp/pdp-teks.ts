import type { JenisPersetujuanPdp } from '@/lib/api/types';

/**
 * The copy of the five consent slots, in one place.
 *
 * F02 is a legal surface in plain Indonesian: UU PDP Pasal 22 requires language that is
 * easy to understand, so the sentences stay short and avoid terms like "retensi" or
 * "pemrosesan" (the pattern's §4 and §9 say the same). Centralising the strings keeps the
 * screen, the gate and the collapsible detail from drifting into three dialects of the
 * same sentence.
 */

export const JUDUL_JENIS: Record<JenisPersetujuanPdp, string> = {
    syarat_ketentuan: 'Syarat dan ketentuan',
    kebijakan_privasi: 'Kebijakan privasi',
    berbagi_data_medis: 'Berbagi data medis',
    pemasaran: 'Informasi dan promosi',
    komunikasi_tindak_lanjut: 'Pesan tindak lanjut',
};

export const TUJUAN_JENIS: Record<JenisPersetujuanPdp, string> = {
    syarat_ketentuan:
        'Aturan pemakaian layanan Sehatly dan hak serta kewajiban Anda.',
    kebijakan_privasi:
        'Bagaimana Sehatly mengumpulkan, memakai, dan menyimpan data Anda.',
    berbagi_data_medis:
        'Izin agar data kesehatan Anda dibagikan ke fasilitas kesehatan lain saat dibutuhkan.',
    pemasaran:
        'Kabar dan penawaran dari Sehatly. Tidak memengaruhi layanan Anda.',
    komunikasi_tindak_lanjut:
        'Pengingat dan kabar terkait konsultasi, resep, atau jadwal Anda.',
};

/**
 * The collapsible half: what the data is for and how to change the answer.
 *
 * `MASA_SIMPAN` is shared because it is the same record for all five kinds. The sentence
 * itself is still DRAFT pending the official retention policy (F02 open question #3) -
 * kept in one constant so confirming it is a one-line change.
 */
export const RINCIAN_JENIS: Record<
    JenisPersetujuanPdp,
    { data: string; ubah: string }
> = {
    syarat_ketentuan: {
        data: 'Aturan ini mengatur cara Anda memakai layanan Sehatly, kewajiban Anda, dan kewajiban Sehatly.',
        ubah: 'Pilih Tarik persetujuan pada baris ini. Anda dapat menyetujui lagi kapan saja.',
    },
    kebijakan_privasi: {
        data: 'Sehatly memakai identitas, kontak, dan data layanan yang Anda berikan untuk menjalankan akun, konsultasi, resep, dan tagihan Anda.',
        ubah: 'Pilih Tarik persetujuan pada baris ini. Anda dapat menyetujui lagi kapan saja.',
    },
    berbagi_data_medis: {
        data: 'Riwayat konsultasi dan resep Anda dibagikan hanya saat fasilitas kesehatan membutuhkannya. Tanpa izin ini, surat rujukan tidak dapat dibuat.',
        ubah: 'Pilih Tarik persetujuan pada baris ini. Konsultasi dan resep Anda tetap berjalan.',
    },
    pemasaran: {
        data: 'Sehatly mengirim kabar fitur baru dan penawaran. Menolak tidak mengurangi akses Anda ke layanan lain.',
        ubah: 'Pilih Tarik persetujuan pada baris ini, atau Setujui untuk berhenti berlangganan kabar tersebut.',
    },
    komunikasi_tindak_lanjut: {
        data: 'Sehatly mengirim pengingat jadwal, hasil konsultasi, dan kabar resep. Pesan penting akun tetap dikirim tanpa persetujuan ini.',
        ubah: 'Pilih Tarik persetujuan pada baris ini, atau Setujui untuk berhenti menerima pesan tindak lanjut.',
    },
};

export const MASA_SIMPAN =
    'Kami menyimpan catatan keputusan ini selama Anda memiliki akun, sebagai bukti persetujuan Anda.';

export const CATATAN_KEPUTUSAN =
    'Catatan keputusan disimpan sebagai bukti persetujuan Anda.';

export const TAUTAN_JENIS: Record<JenisPersetujuanPdp, { to: string; label: string }> = {
    syarat_ketentuan: {
        to: '/syarat-ketentuan',
        label: 'Baca syarat dan ketentuan lengkap',
    },
    kebijakan_privasi: {
        to: '/kebijakan-privasi',
        label: 'Baca kebijakan privasi lengkap',
    },
    berbagi_data_medis: {
        to: '/kebijakan-privasi',
        label: 'Baca kebijakan privasi lengkap',
    },
    pemasaran: {
        to: '/kebijakan-privasi',
        label: 'Baca kebijakan privasi lengkap',
    },
    komunikasi_tindak_lanjut: {
        to: '/kebijakan-privasi',
        label: 'Baca kebijakan privasi lengkap',
    },
};
