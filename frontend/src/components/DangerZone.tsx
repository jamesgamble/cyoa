import type { ReactNode } from "react";

interface Props {
  title?: string;
  description?: string;
  children: ReactNode;
}

/**
 * Danger zone — a clearly demarcated container for irreversible or
 * destructive actions. Deep red accent, matched restraint elsewhere.
 */
export function DangerZone({ title = "Danger zone", description, children }: Props) {
  return (
    <section className="bp-danger-zone" role="region" aria-label={title} data-testid="danger-zone">
      <header className="bp-danger-zone__header">
        <h3 className="bp-danger-zone__title">{title}</h3>
        {description && <p className="bp-danger-zone__desc">{description}</p>}
      </header>
      <div className="bp-danger-zone__body">{children}</div>
    </section>
  );
}
