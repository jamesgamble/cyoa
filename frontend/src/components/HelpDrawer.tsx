import { useEffect, useId, useRef, type ReactNode } from "react";

interface Props {
  open: boolean;
  onClose: () => void;
  title: string;
  children: ReactNode;
}

/**
 * Side drawer. While closed it is inert (not focusable or announced);
 * while open it traps Tab, closes on Escape, and returns focus to the
 * control that opened it.
 */
export function HelpDrawer({ open, onClose, title, children }: Props) {
  const rootRef = useRef<HTMLElement>(null);
  const closeRef = useRef<HTMLButtonElement>(null);
  const titleId = useId();

  useEffect(() => {
    const root = rootRef.current;
    if (root) {
      if (open) root.removeAttribute("inert");
      else root.setAttribute("inert", "");
    }
    if (!open) return;
    const previous = document.activeElement as HTMLElement | null;
    closeRef.current?.focus();
    function onKey(e: KeyboardEvent) {
      if (e.key === "Escape") { onClose(); return; }
      if (e.key !== "Tab" || !rootRef.current) return;
      const f = rootRef.current.querySelectorAll<HTMLElement>(
        'a[href], button:not([disabled]), input:not([disabled]), select, textarea, [tabindex]:not([tabindex="-1"])',
      );
      if (f.length === 0) return;
      const first = f[0], last = f[f.length - 1];
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    }
    window.addEventListener("keydown", onKey);
    return () => {
      window.removeEventListener("keydown", onKey);
      previous?.focus?.();
    };
  }, [open, onClose]);

  return (
    <aside
      ref={rootRef}
      className="bp-drawer"
      data-open={open ? "true" : "false"}
      role="dialog"
      aria-modal="true"
      aria-labelledby={titleId}
      aria-hidden={open ? "false" : "true"}
    >
      <div className="bp-drawer__header">
        <h2 id={titleId} style={{ margin: 0, fontSize: "var(--bp-fs-lg)" }}>{title}</h2>
        <button
          ref={closeRef}
          type="button"
          className="bp-btn bp-btn--ghost bp-btn--sm"
          onClick={onClose}
        >
          Close<span className="bp-visually-hidden"> help</span>
        </button>
      </div>
      <div>{children}</div>
    </aside>
  );
}
