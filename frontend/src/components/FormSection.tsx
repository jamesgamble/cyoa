import type { ReactNode } from "react";

interface Props {
  title: string;
  description?: string;
  children: ReactNode;
  /** Optional right-aligned actions inside the section header. */
  actions?: ReactNode;
}

/**
 * Form section — groups related fields under a titled header. Uses
 * the editorial rule and small-caps label style shared with panels.
 */
export function FormSection({ title, description, children, actions }: Props) {
  return (
    <section className="bp-form-section" aria-label={title}>
      <header className="bp-form-section__header">
        <div>
          <h2 className="bp-form-section__title">{title}</h2>
          {description && <p className="bp-form-section__desc">{description}</p>}
        </div>
        {actions && <div className="bp-form-section__actions">{actions}</div>}
      </header>
      <div className="bp-form-section__body">{children}</div>
    </section>
  );
}
