/**
 * Notification inbox (v0.20.0) — /account/inbox
 *
 * Invitations, invitation outcomes, and ownership changes arrive here
 * as well as by email, so a missed email never loses an invitation.
 */
import { useCallback, useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { Alert, Badge, Button, Panel } from "../components";
import { LoadingState } from "../states";
import { useHelpContext } from "../components/GlobalHelp";
import { fetchInbox, markNotificationRead, type InboxNotification } from "../lib/apiClient";

export function AccountInboxPage() {
  const [items, setItems] = useState<InboxNotification[]>([]);
  const [unread, setUnread] = useState(0);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useHelpContext({ section: "collaboration" });

  const load = useCallback(async () => {
    setLoading(true);
    const res = await fetchInbox();
    if (res.data) {
      setItems(res.data.notifications);
      setUnread(res.data.unread);
      setError(null);
    } else {
      setError(res.status === 401 ? "Sign in to read your inbox." : "Your inbox could not be loaded.");
    }
    setLoading(false);
  }, []);

  useEffect(() => { void load(); }, [load]);

  const read = useCallback(async (id: number | null) => {
    const res = await markNotificationRead(id);
    if (res.ok) await load();
  }, [load]);

  if (loading) return <LoadingState message="Loading your inbox…" />;

  return (
    <Panel
      title="Inbox"
      actions={
        unread > 0 ? (
          <Button size="sm" onClick={() => void read(null)}>Mark all read</Button>
        ) : undefined
      }
    >
      {error && <Alert tone="warning">{error}</Alert>}
      {!error && items.length === 0 && <p>No notifications yet.</p>}
      <ul className="bp-roster" aria-message="Notifications">
        {items.map((n) => (
          <li key={n.id} className="bp-roster__item">
            <div>
              <strong>{n.title}</strong>{" "}
              {n.read_at === null && <Badge tone="draft">New</Badge>}
              <p>{n.body}</p>
              <p className="bp-roster__meta">{n.created_at}</p>
              {n.url && <Link to={n.url}>Open</Link>}
            </div>
            {n.read_at === null && (
              <Button size="sm" onClick={() => void read(n.id)}>Mark read</Button>
            )}
          </li>
        ))}
      </ul>
    </Panel>
  );
}
