interface Props {
  /** Actor (username). Rendered as plain text. */
  actor: string;
  /** Short verb phrase describing the action. */
  action: string;
  /** Optional object of the action (title, path, etc.). */
  target?: string;
  /** ISO timestamp or human relative label. */
  at: string;
}

/**
 * Activity item — a single row in an activity feed. Compact,
 * text-only, uses restrained editorial voice.
 */
export function ActivityItem({ actor, action, target, at }: Props) {
  return (
    <li className="bp-activity-item" data-testid="activity-item">
      <p className="bp-activity-item__line">
        <span className="bp-activity-item__actor">{actor}</span>
        <span> {action}</span>
        {target && (
          <>
            <span> </span>
            <span className="bp-activity-item__target">{target}</span>
          </>
        )}
      </p>
      <p className="bp-activity-item__time"><time>{at}</time></p>
    </li>
  );
}
