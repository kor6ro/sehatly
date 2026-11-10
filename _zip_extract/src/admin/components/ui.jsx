import { STATUS } from '../format';

// Header halaman yang sama untuk semua halaman admin.
export function PageHeader({ title, subtitle, actions }) {
  return (
    <header className="adm-pagehead">
      <div>
        <h1>{title}</h1>
        {subtitle && <p>{subtitle}</p>}
      </div>
      {actions && <div className="adm-pagehead__actions">{actions}</div>}
    </header>
  );
}

export function StatusBadge({ status }) {
  const s = STATUS[status] ?? { label: status, tone: 'muted' };
  return <span className={`adm-badge adm-badge--${s.tone}`}>{s.label}</span>;
}

export function EmptyState({ title, text, action }) {
  return (
    <div className="adm-empty">
      <strong>{title}</strong>
      {text && <p>{text}</p>}
      {action}
    </div>
  );
}

export function ErrorState({ message, onRetry }) {
  return (
    <div className="adm-empty adm-empty--error" role="alert">
      <strong>Gagal memuat data</strong>
      <p>{message}</p>
      {onRetry && (
        <button type="button" className="adm-btn" onClick={onRetry}>
          Coba lagi
        </button>
      )}
    </div>
  );
}

export function RowSkeleton({ count = 4 }) {
  return (
    <div aria-hidden="true">
      {Array.from({ length: count }, (_, i) => (
        <div key={i} className="adm-row adm-row--skeleton">
          <div className="adm-skel adm-skel--avatar" />
          <div className="adm-skel adm-skel--lines" />
        </div>
      ))}
    </div>
  );
}
