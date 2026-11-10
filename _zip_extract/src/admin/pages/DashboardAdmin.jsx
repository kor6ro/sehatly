import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { ChevronRight, CircleCheck } from 'lucide-react';
import { getPerluTindakan } from '../api';
import { ErrorState, PageHeader } from '../components/ui';

const ITEMS = [
  {
    key: 'marketing',
    label: 'Pendaftar Marketing menunggu persetujuan',
    to: '/admin/akun?role=marketing&status=menunggu_persetujuan',
  },
  {
    key: 'dokter',
    label: 'Dokter menunggu verifikasi',
    to: '/admin/dokter',
  },
  {
    key: 'undangan',
    label: 'Undangan belum diaktifkan',
    to: '/admin/akun?status=diundang',
  },
];

/**
 * Props:
 *  nama     -> nama admin untuk sapaan
 *  children -> widget yang sudah ada (kartu KPI, grafik, log) ditaruh di bawah "Perlu tindakan"
 */
export default function DashboardAdmin({ nama, children }) {
  const [data, setData] = useState(null);
  const [error, setError] = useState(null);

  const load = useCallback(() => {
    setError(null);
    getPerluTindakan()
      .then(setData)
      .catch((e) => setError(e.message));
  }, []);

  useEffect(load, [load]);

  const aktif = data ? ITEMS.filter((i) => (data[i.key] ?? 0) > 0) : [];

  return (
    <>
      <PageHeader title={nama ? `Halo, ${nama}` : 'Dashboard'} subtitle="Yang perlu kamu tangani hari ini." />

      <section className="adm-card" aria-labelledby="perlu-tindakan">
        <h2 id="perlu-tindakan" className="adm-card__title">
          Perlu tindakan
        </h2>

        {error && <ErrorState message={error} onRetry={load} />}
        {!error && !data && <p className="adm-muted">Memuat…</p>}

        {data && aktif.length === 0 && (
          <p className="adm-allclear">
            <CircleCheck size={18} aria-hidden="true" /> Semua beres. Tidak ada yang menunggu tindakan.
          </p>
        )}

        {aktif.map((item) => (
          <Link key={item.key} to={item.to} className="adm-todo">
            <span className="adm-todo__count">{data[item.key]}</span>
            <span className="adm-todo__label">{item.label}</span>
            <span className="adm-todo__cta">
              Tinjau <ChevronRight size={16} aria-hidden="true" />
            </span>
          </Link>
        ))}
      </section>

      {children}
    </>
  );
}
