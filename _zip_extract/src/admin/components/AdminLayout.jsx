import { useEffect, useState } from 'react';
import { NavLink, Outlet } from 'react-router-dom';
import {
  Bell,
  ClipboardList,
  Images,
  LayoutDashboard,
  LogOut,
  ScrollText,
  ShieldCheck,
  Stethoscope,
  Users,
} from 'lucide-react';
import { getPerluTindakan } from '../api';
import { initial } from '../format';

// 7 item saja (sebelumnya 13). Role akun ada sebagai tab di halaman Akun.
const NAV = [
  {
    title: 'Utama',
    items: [{ to: '/admin', label: 'Dashboard', icon: LayoutDashboard, end: true }],
  },
  {
    title: 'Kelola',
    items: [
      { to: '/admin/akun', label: 'Akun', icon: Users, badge: 'akun' },
      { to: '/admin/dokter', label: 'Dokter', icon: Stethoscope, badge: 'dokter' },
      { to: '/admin/hero', label: 'Hero landing', icon: Images },
    ],
  },
  {
    title: 'Laporan & kepatuhan',
    items: [
      { to: '/admin/laporan', label: 'Laporan', icon: ClipboardList },
      { to: '/admin/audit-log', label: 'Audit log', icon: ScrollText },
      { to: '/admin/persetujuan-pdp', label: 'Persetujuan PDP', icon: ShieldCheck },
    ],
  },
];

const tanggal = new Intl.DateTimeFormat('id-ID', {
  weekday: 'long',
  day: 'numeric',
  month: 'long',
  year: 'numeric',
  hour: '2-digit',
  minute: '2-digit',
  timeZone: 'Asia/Jakarta',
});

function Clock() {
  const [now, setNow] = useState(() => new Date());
  useEffect(() => {
    const id = setInterval(() => setNow(new Date()), 30_000);
    return () => clearInterval(id);
  }, []);
  return <span className="adm-clock">{tanggal.format(now).replace('.', ':')}</span>;
}

/**
 * Props:
 *  me       -> { nama_lengkap, tipe }  (pengguna yang sedang login)
 *  onLogout -> fungsi keluar
 */
export default function AdminLayout({ me, onLogout }) {
  const [badges, setBadges] = useState({});

  useEffect(() => {
    const ctrl = new AbortController();
    getPerluTindakan(ctrl.signal)
      .then((p) => setBadges({ akun: p.marketing ?? 0, dokter: p.dokter ?? 0 }))
      .catch(() => {});
    return () => ctrl.abort();
  }, []);

  return (
    <div className="adm-shell">
      <aside className="adm-sidebar" aria-label="Menu admin">
        <div className="adm-brand">
          <span className="adm-brand__mark" aria-hidden="true" />
          <div>
            <strong>Sehatly</strong>
            <small>Portal Admin</small>
          </div>
        </div>

        <nav>
          {NAV.map((group) => (
            <div key={group.title} className="adm-navgroup">
              <div className="adm-navgroup__title">{group.title}</div>
              {group.items.map(({ to, label, icon: Icon, end, badge }) => (
                <NavLink key={to} to={to} end={end} className="adm-navitem">
                  <Icon size={18} aria-hidden="true" />
                  <span>{label}</span>
                  {badge && badges[badge] > 0 && (
                    <span className="adm-navitem__badge" aria-label={`${badges[badge]} menunggu`}>
                      {badges[badge]}
                    </span>
                  )}
                </NavLink>
              ))}
            </div>
          ))}
        </nav>

        <button type="button" className="adm-btn adm-logout" onClick={onLogout}>
          <LogOut size={16} aria-hidden="true" /> Keluar
        </button>
      </aside>

      <div className="adm-main">
        <div className="adm-topbar">
          <Clock />
          <button type="button" className="adm-iconbtn" aria-label="Notifikasi">
            <Bell size={18} aria-hidden="true" />
          </button>
          <div className="adm-avatar" title={me?.nama_lengkap}>
            {initial(me?.nama_lengkap)}
          </div>
        </div>
        <main className="adm-content">
          <Outlet />
        </main>
      </div>
    </div>
  );
}
