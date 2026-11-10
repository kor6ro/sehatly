// ============================================================================
// Satu-satunya file yang perlu disesuaikan dengan backend / klien HTTP kamu.
// Bagian bertanda "SESUAIKAN" adalah tebakan dari daftar route yang kamu kirim.
// ============================================================================

const BASE = import.meta.env?.VITE_API_URL ?? '/api/v1';

// SESUAIKAN: ambil token dari tempat kamu menyimpannya (Sanctum bearer token).
function getToken() {
  try {
    return localStorage.getItem('token');
  } catch {
    return null;
  }
}

export class ApiError extends Error {
  constructor(message, status, errors) {
    super(message);
    this.status = status;
    this.errors = errors ?? {}; // 422: { field: ['pesan', ...] }
  }
}

async function request(path, { method = 'GET', params, body, signal } = {}) {
  const url = new URL(BASE + path, window.location.origin);
  Object.entries(params ?? {}).forEach(([k, v]) => {
    if (v !== undefined && v !== null && v !== '') url.searchParams.set(k, v);
  });

  const token = getToken();
  const res = await fetch(url, {
    method,
    signal,
    headers: {
      Accept: 'application/json',
      ...(body ? { 'Content-Type': 'application/json' } : {}),
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
    body: body ? JSON.stringify(body) : undefined,
  });

  const json = await res.json().catch(() => null);
  if (!res.ok) {
    throw new ApiError(json?.message ?? 'Terjadi kesalahan. Coba lagi.', res.status, json?.errors);
  }
  return json;
}

// ---- Akun -------------------------------------------------------------------

// GET /admin/pengguna?role=&status=&q=&page=
export async function getPengguna({ role, status, q, page, signal }) {
  const json = await request('/admin/pengguna', {
    params: { role: role === 'semua' ? '' : role, status, q, page },
    signal,
  });
  return { items: json?.data ?? [], meta: json?.meta ?? {} };
}

// GET /admin/pengguna/ringkasan
// SESUAIKAN: bentuk respons. Normalizer ini menerima beberapa kemungkinan nama.
export async function getRingkasan(signal) {
  const d = (await request('/admin/pengguna/ringkasan', { signal }))?.data ?? {};
  const perRole = d.per_role ?? d.role ?? {};
  const perStatus = d.per_status ?? d.status ?? {};
  return {
    perRole,
    perStatus,
    menunggu: d.menunggu_persetujuan ?? perStatus.menunggu_persetujuan ?? 0,
    diundang: perStatus.diundang ?? d.diundang ?? 0,
  };
}

// POST /admin/pengguna/{id}/setujui
export const setujui = (id) => request(`/admin/pengguna/${id}/setujui`, { method: 'POST', body: {} });

// POST /admin/pengguna/{id}/tolak  (alasan wajib)
export const tolak = (id, alasan) =>
  request(`/admin/pengguna/${id}/tolak`, { method: 'POST', body: { alasan } });

// POST /admin/undangan  { tipe, nama_lengkap, no_telepon, email?, kanal, ... }
export const buatUndangan = (payload) => request('/admin/undangan', { method: 'POST', body: payload });

// POST /admin/undangan/{id}/kirim-ulang
export const kirimUlangUndangan = (undanganId) =>
  request(`/admin/undangan/${undanganId}/kirim-ulang`, { method: 'POST', body: {} });

// DELETE /admin/undangan/{id}
export const batalkanUndangan = (undanganId) =>
  request(`/admin/undangan/${undanganId}`, { method: 'DELETE' });

// SESUAIKAN: pakai route yang sudah ada di halaman lama (Nonaktifkan / Reset sandi).
export const ubahStatusPengguna = (id, aktif, alasan) =>
  request(`/admin/pengguna/${id}/status`, { method: 'PUT', body: { aktif, alasan } });

export const resetSandi = (id) => request(`/admin/pengguna/${id}/reset-sandi`, { method: 'POST', body: {} });

// ---- Dashboard --------------------------------------------------------------

// Menghitung hal-hal yang menunggu tindakan admin. Gagal satu tidak merusak yang lain.
export async function getPerluTindakan(signal) {
  const [ringkasan, dokter] = await Promise.allSettled([
    getRingkasan(signal),
    // SESUAIKAN: nama filter verifikasi dokter di endpoint /admin/dokter
    request('/admin/dokter', { params: { status_verifikasi: 'pending', per_page: 1 }, signal }),
  ]);
  return {
    marketing: ringkasan.status === 'fulfilled' ? ringkasan.value.menunggu : null,
    undangan: ringkasan.status === 'fulfilled' ? ringkasan.value.diundang : null,
    dokter: dokter.status === 'fulfilled' ? (dokter.value?.meta?.total ?? dokter.value?.data?.length ?? 0) : null,
  };
}
