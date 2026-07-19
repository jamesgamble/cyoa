import { useEffect, useRef, type ReactNode } from "react";

interface Props {
  open: boolean;
  onClose: () => void;
  title: string;
  children: ReactNode;
}

export function HelpDrawer({ open, onClose, title, children }: Props) {
  const closeRef = useRef<HTMLButtonElement>(null);

  useEffect(() => {
    if (!open) return;
    closeRef.current?.focus();
    function onKey(e: KeyboardEvent) {
      if (e.key === "Escape") onClose();
    }
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [open, onClose]);

  return (
    <aside
      className="bp-drawer"
      data-open={open ? "true" : "false"}
      role="dialog"
      aria-modal="true"
      aria-labelledby="bp-drawer-title"
      aria-hidden={open ? "false" : "true"}
    >
      <div className="bp-drawer__header">
        <h2 id="bp-drawer-title" style={{ margin: 0, fontSize: "var(--bp-fs-lg)" }}>{title}</h2>
        <button
          ref={closeRef}
          type="button"
          className="bp-btn bp-btn--ghost bp-btn--sm"
          onClick={onClose}
          aria-label="Close help drawer"
        >
          Close
        </button>
      </div>
      <div>{children}</div>
    </aside>
  );
}
