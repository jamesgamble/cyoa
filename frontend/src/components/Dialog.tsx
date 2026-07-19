import { useEffect, useRef, type ReactNode } from "react";

interface Props {
  open: boolean;
  onClose: () => void;
  title: string;
  children: ReactNode;
  actions?: ReactNode;
  labelledById?: string;
}

export function Dialog({ open, onClose, title, children, actions, labelledById }: Props) {
  const ref = useRef<HTMLDialogElement>(null);

  useEffect(() => {
    const d = ref.current;
    if (!d) return;
    if (open && !d.open) {
      if (typeof d.showModal === "function") d.showModal();
      else d.setAttribute("open", "");
    }
    if (!open && d.open) {
      if (typeof d.close === "function") d.close();
      else d.removeAttribute("open");
    }
  }, [open]);

  const titleId = labelledById ?? "bp-dialog-title";

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
