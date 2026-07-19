import type { ReactNode } from "react";

interface Props {
  title?: string;
  message?: string;
  /** Optional call-to-action rendered under the message. */
  action?: ReactNode;
}

/**
 * Empty state — an editorial "nothing to display" plate. Neutral
 * voice, no illustrations, no marketing copy.
 */
export function EmptyState({
  title = "Nothing here yet",
  message,
  action,
}: Props) {
  return (
    <div className="bp-state bp-empty-state" data-testid="empty-state">
      <h2>{title}</h2>
      {message && <p>{message}</p>}
      {action && <div className="bp-state__action">{action}</div>}
    </div>
  );
}
