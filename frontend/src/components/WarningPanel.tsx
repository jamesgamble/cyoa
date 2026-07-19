import type { ReactNode } from "react";

interface Props {
  title: string;
  children: ReactNode;
  actions?: ReactNode;
}

/**
 * Warning panel — non-destructive caution. Uses the warm burnt-orange
 * accent stripe.
 */
export function WarningPanel({ title, children, actions }: Props) {
  return (
    <section className="bp-warning-panel" role="region" aria-label={title} data-testid="warning-panel">
      <h3 className="bp-warning-panel__title">{title}</h3>
      <div className="bp-warning-panel__body">{children}</div>
      {actions && <div className="bp-warning-panel__actions">{actions}</div>}
    </section>
  );
}
