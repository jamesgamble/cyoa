import type { ReactNode } from "react";

export function Panel({
  title,
  actions,
  children,
}: {
  title: string;
  actions?: ReactNode;
  children: ReactNode;
}) {
  return (
    <section className="bp-panel">
      <header className="bp-panel__header">
        <h2 className="bp-panel__title">{title}</h2>
        {actions && <div>{actions}</div>}
      </header>
      <div className="bp-panel__body">{children}</div>
    </section>
  );
}
