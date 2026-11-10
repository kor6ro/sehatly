const dtf = new Intl.DateTimeFormat('id-ID', {
  day: 'numeric',
  month: 'short',
  year: 'numeric',
  hour: '2-digit',
  minute: '2-digit',
  timeZone: 'Asia/Jakarta',
});

export function formatWaktu(value) {
  if (!value) return null;
  const d = new Date(String(value).replace(' ', 'T'));
  return Number.isNaN(d.getTime()) ? null : dtf.format(d).replace(/\./g, ':').replace(' pukul', ',');
}

export const initial = (nama = '') => nama.trim().charAt(0).toUpperCase() || '?';

// Status akun -> label + warna (nilai sama dengan users.status di DDL)
export const STATUS = {
  pending_verifikasi: { label: 'Menunggu OTP', tone: 'warn' },
  menunggu_persetujuan: { label: 'Menunggu persetujuan', tone: 'warn' },
  diundang: { label: 'Diundang', tone: 'info' },
  aktif: { label: 'Aktif', tone: 'ok' },
  ditolak: { label: 'Ditolak', tone: 'danger' },
  nonaktif: { label: 'Nonaktif', tone: 'muted' },
  ditangguhkan: { label: 'Ditangguhkan', tone: 'muted' },
};

// Tab role. canInvite: siapa yang boleh menekan tombol "Undang" di tab itu.
export const ROLES = [
  { key: 'semua', label: 'Semua' },
  { key: 'pasien', label: 'Pasien', hint: 'Pasien mendaftar sendiri lewat aplikasi.' },
  { key: 'dokter', label: 'Dokter', canInvite: ['admin', 'superadmin'] },
  { key: 'apoteker', label: 'Apoteker', canInvite: ['admin', 'superadmin'] },
  { key: 'kurir', label: 'Kurir', canInvite: ['admin', 'superadmin'] },
  { key: 'marketing', label: 'Marketing', hint: 'Marketing mendaftar sendiri, lalu perlu disetujui admin.' },
  { key: 'admin', label: 'Admin', canInvite: ['superadmin'] },
  { key: 'superadmin', label: 'Superadmin' },
];

export const roleLabel = (key) => ROLES.find((r) => r.key === key)?.label ?? key;
