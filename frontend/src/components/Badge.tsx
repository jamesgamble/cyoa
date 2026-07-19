import type { ReactNode } from "react";

export type BadgeTone = "draft" | "published" | "review" | "warning" | "danger";

export function Badge({ tone, children }: { tone: BadgeTone; children: ReactNode }) {
  return <span className={`bp-badge bp-badge--${tone}`}>{children}</span>;
}
