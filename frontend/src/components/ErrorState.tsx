import type { ReactNode } from "react";

interface Props {
  title?: string;
  message?: string;
  action?: ReactNode;
}

/**
 * Error state — a plate shown when the current view cannot render.
 * Announced as an alert. No raw stack traces; no reference numbers
 * shown to end users.
 */
export function ErrorState({
  title = "Something went wrong",
  message,
  action,
}: Props) {
  return (
    <div className="bp-state bp-error-state" role="alert" data-testid="error-state">
      <h2>{title}</h2>
      {message && <p>{message}</p>}
      {action && <div className="bp-state__action">{action}</div>}
    </div>
  );
}
