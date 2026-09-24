import { useEffect, useId, useRef, type ReactNode } from "react";

interface Props {
  open: boolean;
  onClose: () => void;
  title: string;
  children: ReactNode;
  actions?: ReactNode;
  labelledById?: string;
}

/**
 * Modal dialog built on the native <dialog> element: focus is trapped
 * by the browser, Escape closes it, and focus returns to whatever
 * opened it.
 */
export function Dialog({ open, onClose, title, children, actions, labelledById }: Props) {
  const ref = useRef<HTMLDialogElement>(null);
  const opener = useRef<HTMLElement | null>(null);
  const autoId = useId();

  useEffect(() => {
    const d = ref.current;
    if (!d) return;
    if (open && !d.open) {
      opener.current = document.activeElement as HTMLElement | null;
      if (typeof d.showModal === "function") d.showModal();
      else d.setAttribute("open", "");
    }
    if (!open && d.open) {
      if (typeof d.close === "function") d.close();
      else d.removeAttribute("open");
      opener.current?.focus?.();
    }
  }, [open]);

  const titleId = labelledById ?? `bp-dialog-title-${autoId}`;

  return (
    <dialog
      ref={ref}
      className="bp-dialog"
      aria-labelledby={titleId}
      onClose={onClose}
      onCancel={onClose}
    >
      <h2 id={titleId} className="bp-dialog__title">
        {title}
      </h2>
      <div>{children}</div>
      <div className="bp-dialog__actions">
        {actions}
      </div>
    </dialog>
  );
}
