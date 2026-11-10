import { useEffect, useId, useRef } from 'react';

// Memakai <dialog> bawaan browser: fokus terkunci, Esc menutup, dan aksesibel.
export default function Modal({ open, onClose, title, children }) {
  const ref = useRef(null);
  const titleId = useId();

  useEffect(() => {
    const dialog = ref.current;
    if (!dialog) return;
    if (open && !dialog.open) dialog.showModal();
    if (!open && dialog.open) dialog.close();
  }, [open]);

  return (
    <dialog
      ref={ref}
      className="adm-modal"
      aria-labelledby={titleId}
      onClose={onClose}
      onClick={(e) => {
        if (e.target === ref.current) onClose(); // klik di area gelap
      }}
    >
      {open && (
        <div className="adm-modal__body">
          <h2 id={titleId} className="adm-modal__title">
            {title}
          </h2>
          {children}
        </div>
      )}
    </dialog>
  );
}
