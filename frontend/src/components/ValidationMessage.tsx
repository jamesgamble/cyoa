import type { ReactNode } from "react";

interface Props {
  tone?: "error" | "warning" | "info";
  id?: string;
  children: ReactNode;
}

/**
 * Validation message — a short line of feedback under a field or form.
 * Errors are announced as alerts; warnings and info are polite.
 */
export function ValidationMessage({ tone = "error", id, children }: Props) {
  const cls = `bp-validation bp-validation--${tone}`;
  if (tone === "error") {
    return <p className={cls} id={id} role="alert">{children}</p>;
  }
  return (
    <p className={cls} id={id} role="status" aria-live="polite">
      {children}
    </p>
  );
}
