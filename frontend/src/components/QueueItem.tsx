import type { ReactNode } from "react";
import { Badge } from "./Badge";

interface Props {
  title: string;
  submitter?: string;
  submittedAt?: string;
  excerpt?: string;
  status?: "review" | "warning" | "danger" | "published" | "draft";
  actions?: ReactNode;
}

/**
 * Queue item — one row in a moderation queue. Fixed layout; user
 * content shown as plain text.
 */
export function QueueItem({ title, submitter, submittedAt, excerpt, status, actions }: Props) {
  return (
    <article className="bp-queue-item" data-testid="queue-item">
      <div className="bp-queue-item__head">
        <h3 className="bp-queue-item__title">{title}</h3>
        {status && <Badge tone={status}>{status}</Badge>}
      </div>
      {(submitter || submittedAt) && (
        <p className="bp-queue-item__meta">
          {submitter && <span>by {submitter}</span>}
          {submitter && submittedAt && <span aria-hidden="true"> · </span>}
          {submittedAt && <span>{submittedAt}</span>}
        </p>
      )}
      {excerpt && <p className="bp-queue-item__excerpt">{excerpt}</p>}
      {actions && <div className="bp-queue-item__actions">{actions}</div>}
    </article>
  );
}
