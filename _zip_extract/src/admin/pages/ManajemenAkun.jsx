import { useCallback, useEffect, useMemo, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { KeyRound, Plus, RefreshCw, Search, ShieldCheck } from 'lucide-react';
import * as api from '../api';
import { ROLES, STATUS, formatWaktu, initial, roleLabel } from '../format';
import Modal from '../components/Modal';
import { EmptyState, ErrorState, PageHeader, RowSkeleton, StatusBadge } from '../components/ui';

// Aksi per baris, ditentukan oleh status akun.
function aksiUntuk(u, helpers) {
  const { setDialog } = helpers;
  const undanganId = u.undangan_id ?? u.undangan?.id ?? u.id; // SESUAIKAN

  switch (u.status) {
    case 'menunggu_persetujuan':
      return [
        {
          label: 'Setujui',
          primary: true,
          onClick: () =>
            setDialog({
              title: `Setujui ${u.nama_lengkap}?`,
              text: 'Akun akan aktif dan pendaftar mendapat notifikasi.',
              confirm: 'Setujui',
              run: () => api.setujui(u.id),
              done: 'Akun disetujui.',
            }),
        },
        {
          label: 'Tolak',
          danger: true,
          onClick: () =>
            setDialog({
              title: `Tolak ${u.nama_lengkap}?`,
              text: 'Alasan akan tersimpan di riwayat persetujuan.',
              confirm: 'Tolak',
              danger: true,
              reason: 'wajib',
              run: (alasan) => api.tolak(u.id, alasan),
              done: 'Pendaftaran ditolak.',
            }),
        },
      ];
    case 'diundang':
      return [
        {
          label: 'Kirim ulang',
          onClick: () =>
            setDialog({
              title: 'Kirim ulang undangan?',
              text: 'Tautan lama tidak berlaku lagi.',
              confirm: 'Kirim ulang',
              run: () => api.kirimUlangUndangan(undanganId),
              done: 'Undangan dikirim ulang.',
            }),
        },
        {
          label: 'Batalkan',
          danger: true,
          onClick: () =>
            setDialog({
              title: 'Batalkan undangan?',
              text: 'Akun yang belum diaktifkan ini akan dihapus. Nomor HP bisa dipakai lagi.',
              confirm: 'Batalkan undangan',
              danger: true,
              run: () => api.batalkanUndangan(undanganId),
              done: 'Undangan dibatalkan.',
            }),
        },
      ];
    case 'aktif':
      return [
        {
          label: 'Reset sandi',
          icon: KeyRound,
          onClick: () =>
            setDialog({
              title: `Reset sandi ${u.nama_lengkap}?`,
              text: 'Tautan reset dikirim ke nomor terdaftar. Admin tidak pernah melihat sandi.',
              confirm: 'Kirim tautan reset',
              run: () => api.resetSandi(u.id),
              done: 'Tautan reset dikirim.',
            }),
        },
        {
          label: 'Nonaktifkan',
          danger: true,
          onClick: () =>
            setDialog({
              title: `Nonaktifkan ${u.nama_lengkap}?`,
              text: 'Akun tidak bisa masuk. Riwayat pesanan dan konsultasi tetap tersimpan.',
              confirm: 'Nonaktifkan',
              danger: true,
              reason: 'opsional',
              run: (alasan) => api.ubahStatusPengguna(u.id, false, alasan),
              done: 'Akun dinonaktifkan.',
            }),
        },
      ];
    case 'nonaktif':
    case 'ditangguhkan':
      return [
        {
          label: 'Aktifkan',
          onClick: () =>
            setDialog({
              title: `Aktifkan ${u.nama_lengkap}?`,
              confirm: 'Aktifkan',
              run: () => api.ubahStatusPengguna(u.id, true),
              done: 'Akun diaktifkan.',
            }),
        },
      ];
    default:
      return [];
  }
}

function useDebounced(value, ms = 300) {
  const [v, setV] = useState(value);
  useEffect(() => {
    const id = setTimeout(() => setV(value), ms);
    return () => clearTimeout(id);
  }, [value, ms]);
  return v;
}

/** Props: me -> { tipe } pengguna yang login (menentukan siapa yang boleh mengundang Admin) */
export default function ManajemenAkun({ me }) {
  const [params, setParams] = useSearchParams();
  const role = params.get('role') ?? 'semua';
  const status = params.get('status') ?? '';
  const page = Number(params.get('page') ?? 1);

  const [q, setQ] = useState(params.get('q') ?? '');
  const dq = useDebounced(q);

  const [rows, setRows] = useState(null);
  const [meta, setMeta] = useState({});
  const [ringkasan, setRingkasan] = useState(null);
  const [error, setError] = useState(null);
  const [tick, setTick] = useState(0); // naik = muat ulang

  const [dialog, setDialog] = useState(null);
  const [inviteOpen, setInviteOpen] = useState(false);
  const [toast, setToast] = useState(null);

  const setParam = useCallback(
    (patch) => {
      const next = new URLSearchParams(params);
      Object.entries({ page: '', ...patch }).forEach(([k, v]) => (v ? next.set(k, v) : next.delete(k)));
      setParams(next, { replace: true });
    },
    [params, setParams],
  );

  // pencarian -> URL
  useEffect(() => {
    if (dq !== (params.get('q') ?? '')) setParam({ q: dq });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [dq]);

  // daftar
  useEffect(() => {
    const ctrl = new AbortController();
    setError(null);
    setRows(null);
    api
      .getPengguna({ role, status, q: params.get('q') ?? '', page, signal: ctrl.signal })
      .then(({ items, meta: m }) => {
        setRows(items);
        setMeta(m);
      })
      .catch((e) => e.name !== 'AbortError' && setError(e.message));
    return () => ctrl.abort();
  }, [role, status, page, params, tick]);

  // angka di tab & dropdown
  useEffect(() => {
    api.getRingkasan().then(setRingkasan).catch(() => {});
  }, [tick]);

  useEffect(() => {
    if (!toast) return undefined;
    const id = setTimeout(() => setToast(null), 4000);
    return () => clearTimeout(id);
  }, [toast]);

  const reload = () => setTick((t) => t + 1);
  const roleCfg = ROLES.find((r) => r.key === role);
  const canInvite = roleCfg?.canInvite?.includes(me?.tipe);
  const totalSemua = ringkasan ? Object.values(ringkasan.perRole).reduce((a, b) => a + Number(b || 0), 0) : null;

  const statusOptions = useMemo(
    () => [
      { value: '', label: 'Semua status' },
      ...['menunggu_persetujuan', 'diundang', 'aktif', 'nonaktif', 'ditolak'].map((s) => ({
        value: s,
        label: STATUS[s].label + (ringkasan?.perStatus?.[s] != null ? ` (${ringkasan.perStatus[s]})` : ''),
      })),
    ],
    [ringkasan],
  );

  return (
    <>
      <PageHeader
        title="Akun"
        subtitle={roleCfg?.hint ?? 'Telusuri, aktifkan, nonaktifkan, atau atur ulang sandi akun.'}
        actions={
          <>
            <button type="button" className="adm-btn" onClick={reload}>
              <RefreshCw size={16} aria-hidden="true" /> Muat ulang
            </button>
            {canInvite && (
              <button type="button" className="adm-btn adm-btn--primary" onClick={() => setInviteOpen(true)}>
                <Plus size={16} aria-hidden="true" /> Undang {roleLabel(role)}
              </button>
            )}
          </>
        }
      />

      {/* Satu baris tab role. Status dan pencarian ada di bawahnya, tidak bertumpuk. */}
      <div className="adm-tabs" role="tablist" aria-label="Role akun">
        {ROLES.map((r) => {
          const count = r.key === 'semua' ? totalSemua : ringkasan?.perRole?.[r.key];
          return (
            <button
              key={r.key}
              type="button"
              role="tab"
              aria-selected={role === r.key}
              className="adm-tab"
              onClick={() => setParam({ role: r.key === 'semua' ? '' : r.key })}
            >
              {r.label}
              {count != null && <span className="adm-tab__count">{count}</span>}
            </button>
          );
        })}
      </div>

      <div className="adm-filters">
        <label className="adm-search">
          <Search size={16} aria-hidden="true" />
          <input
            type="search"
            placeholder="Cari nama atau nomor"
            aria-label="Cari nama atau nomor"
            value={q}
            onChange={(e) => setQ(e.target.value)}
          />
        </label>
        <select
          className="adm-select"
          aria-label="Filter status"
          value={status}
          onChange={(e) => setParam({ status: e.target.value })}
        >
          {statusOptions.map((o) => (
            <option key={o.value} value={o.value}>
              {o.label}
            </option>
          ))}
        </select>
      </div>

      <div className="adm-list">
        {error && <ErrorState message={error} onRetry={reload} />}
        {!error && rows === null && <RowSkeleton />}
        {!error && rows?.length === 0 && (
          <EmptyState
            title="Tidak ada akun"
            text="Ubah kata kunci atau filter untuk melihat akun lain."
            action={
              (q || status) && (
                <button
                  type="button"
                  className="adm-btn"
                  onClick={() => {
                    setQ('');
                    setParams(role === 'semua' ? {} : { role }, { replace: true });
                  }}
                >
                  Hapus filter
                </button>
              )
            }
          />
        )}

        {rows?.map((u) => {
          const aksi = aksiUntuk(u, { setDialog });
          const masuk = formatWaktu(u.last_login_at ?? u.terakhir_masuk);
          return (
            <article key={u.id} className="adm-row">
              <div className="adm-row__avatar" aria-hidden="true">
                {initial(u.nama_lengkap)}
              </div>
              <div className="adm-row__main">
                <div className="adm-row__title">
                  <strong>{u.nama_lengkap}</strong>
                  <StatusBadge status={u.status} />
                  {role === 'semua' && <span className="adm-tag">{roleLabel(u.tipe)}</span>}
                  {u.telepon_terverifikasi && (
                    <span className="adm-verified">
                      <ShieldCheck size={13} aria-hidden="true" /> Terverifikasi
                    </span>
                  )}
                </div>
                <div className="adm-row__meta">
                  {[u.no_telepon, u.email, masuk ? `Terakhir masuk ${masuk}` : 'Belum pernah masuk']
                    .filter(Boolean)
                    .join(' · ')}
                </div>
              </div>
              <div className="adm-row__actions">
                {aksi.map((a) => (
                  <button
                    key={a.label}
                    type="button"
                    className={`adm-btn adm-btn--sm${a.primary ? ' adm-btn--primary' : ''}${a.danger ? ' adm-btn--danger' : ''}`}
                    onClick={a.onClick}
                  >
                    {a.icon && <a.icon size={14} aria-hidden="true" />} {a.label}
                  </button>
                ))}
              </div>
            </article>
          );
        })}
      </div>

      {meta?.last_page > 1 && (
        <nav className="adm-pager" aria-label="Halaman">
          <button
            type="button"
            className="adm-btn adm-btn--sm"
            disabled={page <= 1}
            onClick={() => setParam({ page: String(page - 1) })}
          >
            Sebelumnya
          </button>
          <span>
            Halaman {meta.current_page ?? page} dari {meta.last_page}
          </span>
          <button
            type="button"
            className="adm-btn adm-btn--sm"
            disabled={page >= meta.last_page}
            onClick={() => setParam({ page: String(page + 1) })}
          >
            Berikutnya
          </button>
        </nav>
      )}

      <ConfirmDialog
        dialog={dialog}
        onClose={() => setDialog(null)}
        onDone={(msg) => {
          setDialog(null);
          setToast(msg);
          reload();
        }}
      />

      <InviteDialog
        open={inviteOpen}
        role={role}
        onClose={() => setInviteOpen(false)}
        onDone={(msg) => {
          setInviteOpen(false);
          setToast(msg);
          reload();
        }}
      />

      {toast && (
        <div className="adm-toast" role="status">
          {toast}
        </div>
      )}
    </>
  );
}

// ---- Dialog konfirmasi (setujui / tolak / nonaktifkan / reset / batalkan) ----
function ConfirmDialog({ dialog, onClose, onDone }) {
  const [alasan, setAlasan] = useState('');
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState(null);

  useEffect(() => {
    setAlasan('');
    setErr(null);
    setBusy(false);
  }, [dialog]);

  const submit = async (e) => {
    e.preventDefault();
    if (dialog.reason === 'wajib' && !alasan.trim()) {
      setErr('Alasan wajib diisi.');
      return;
    }
    setBusy(true);
    try {
      await dialog.run(alasan.trim() || undefined);
      onDone(dialog.done);
    } catch (ex) {
      setErr(ex.errors?.alasan?.[0] ?? ex.message);
      setBusy(false);
    }
  };

  return (
    <Modal open={!!dialog} onClose={onClose} title={dialog?.title}>
      <form onSubmit={submit}>
        {dialog?.text && <p className="adm-muted">{dialog.text}</p>}
        {dialog?.reason && (
          <label className="adm-field">
            <span>Alasan{dialog.reason === 'opsional' ? ' (opsional)' : ''}</span>
            <textarea rows={3} value={alasan} onChange={(e) => setAlasan(e.target.value)} autoFocus />
          </label>
        )}
        {err && (
          <p className="adm-error" role="alert">
            {err}
          </p>
        )}
        <div className="adm-modal__footer">
          <button type="button" className="adm-btn" onClick={onClose} disabled={busy}>
            Batal
          </button>
          <button
            type="submit"
            className={`adm-btn ${dialog?.danger ? 'adm-btn--dangerfill' : 'adm-btn--primary'}`}
            disabled={busy}
          >
            {busy ? 'Memproses…' : dialog?.confirm}
          </button>
        </div>
      </form>
    </Modal>
  );
}

// ---- Form undangan ----------------------------------------------------------
function InviteDialog({ open, role, onClose, onDone }) {
  const [form, setForm] = useState({ nama_lengkap: '', no_telepon: '', email: '', kanal: 'whatsapp' });
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState(null);
  const [fieldErr, setFieldErr] = useState({});

  useEffect(() => {
    if (open) {
      setForm({ nama_lengkap: '', no_telepon: '', email: '', kanal: 'whatsapp' });
      setErr(null);
      setFieldErr({});
      setBusy(false);
    }
  }, [open]);

  const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.value }));

  const submit = async (e) => {
    e.preventDefault();
    setBusy(true);
    setErr(null);
    setFieldErr({});
    try {
      await api.buatUndangan({ ...form, tipe: role, email: form.email || undefined });
      onDone(`Undangan dikirim ke ${form.no_telepon}.`);
    } catch (ex) {
      setFieldErr(ex.errors ?? {});
      setErr(Object.keys(ex.errors ?? {}).length ? null : ex.message);
      setBusy(false);
    }
  };

  const field = (name, label, props = {}) => (
    <label className="adm-field">
      <span>{label}</span>
      <input value={form[name]} onChange={set(name)} aria-invalid={!!fieldErr[name]} {...props} />
      {fieldErr[name] && <small className="adm-error">{fieldErr[name][0]}</small>}
    </label>
  );

  return (
    <Modal open={open} onClose={onClose} title={`Undang ${roleLabel(role)}`}>
      <form onSubmit={submit}>
        <p className="adm-muted">
          Penerima membuka tautan, memverifikasi nomor HP dengan OTP, lalu membuat sandinya sendiri.
        </p>
        {field('nama_lengkap', 'Nama lengkap', { required: true, autoFocus: true })}
        {field('no_telepon', 'Nomor HP', { required: true, inputMode: 'tel', placeholder: '08xxxxxxxxxx' })}
        {field('email', 'Email (opsional)', { type: 'email' })}
        <label className="adm-field">
          <span>Kirim lewat</span>
          <select value={form.kanal} onChange={set('kanal')}>
            <option value="whatsapp">WhatsApp</option>
            <option value="sms">SMS</option>
          </select>
        </label>
        {err && (
          <p className="adm-error" role="alert">
            {err}
          </p>
        )}
        <div className="adm-modal__footer">
          <button type="button" className="adm-btn" onClick={onClose} disabled={busy}>
            Batal
          </button>
          <button type="submit" className="adm-btn adm-btn--primary" disabled={busy}>
            {busy ? 'Mengirim…' : 'Kirim undangan'}
          </button>
        </div>
      </form>
    </Modal>
  );
}
