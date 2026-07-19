import type { ReactNode } from "react";

type AlertTone = "info" | "success" | "warning" | "danger";

export function Alert({
  tone = "info",
  title,
  children,
}: {
  tone?: AlertTone;
  title?: string;
  children?: ReactNode;
}) {
  const role = tone === "danger" || tone === "warning" ? "alert" : "status";
  return (
    <div className={`bp-alert bp-alert--${tone}`} role={role}>
      {title && <p className="bp-alert__title">{title}</p>}
      {children}
    </div>
  );
}
