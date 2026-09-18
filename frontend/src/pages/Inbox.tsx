/**
 * Notification inbox (v0.20.0, reworked in v0.22.0) — /account/inbox
 *
 * Every in-site notification lands here: submissions, decisions,
 * invitations, ownership changes, updates to adventures you follow,
 * and account security notices.
 *
 * Routine items can be deleted. Security notices cannot — they are the
 * user's own record of what happened to their account.
 */
import { useCallback, useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { Alert, Badge, Button, Panel } from "../components";
import { LoadingState } from "../states";
import { useHelpContext } from "../components/GlobalHelp";
import {
  fetchInbox,
  markNotificationRead,
  deleteNotification,
  deleteReadNotifications,
  type InboxNotification,
} from "../lib/apiClient";

export function AccountInboxPage() {
  const [items, setItems] = useState<InboxNotification[]>([]);
  const [unread, setUnread] = useState(0);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [message, setMessage] = useState<string | null>(null);

  useHelpContext({ section: "notifications" });

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

  const remove = useCallback(async (id: number) => {
    setMessage(null);
    const res = await deleteNotification(id);
    if (res.ok) { await load(); return; }
    setMessage(
      res.status === 403
        ? "Security notices stay in your inbox and cannot be deleted."
        : "That notification could not be deleted.",
    );
  }, [load]);

  const clearRead = useCallback(async () => {
    setMessage(null);
    const res = await deleteReadNotifications();
    if (res.ok) {
      setMessage(`Removed ${res.data?.deleted ?? 0} read notification(s).`);
      await load();
      return;
    }
    setMessage("Could not clear your read notifications.");
  }, [load]);

  if (loading) return <LoadingState message="Loading your inbox…" />;

  return (
    <Panel
      title="Inbox"
      actions={
        <>
          {unread > 0 && (
            <Button size="sm" onClick={() => void read(null)}>Mark all read</Button>
          )}{" "}
          <Button size="sm" variant="secondary" onClick={() => void clearRead()}>
            Clear read
          </Button>{" "}
          <Link to="/account/notifications">Email preferences</Link>
        </>
      }
    >
      {error && <Alert tone="warning">{error}</Alert>}
      {message && <p role="status">{message}</p>}
      {!error && items.length === 0 && <p data-testid="inbox-empty">No notifications yet.</p>}
      <ul className="bp-roster" aria-label="Notifications" data-testid="inbox-list">
        {items.map((n) => (
          <li key={n.id} className="bp-roster__item" data-testid="inbox-item">
            <div>
              <strong>{n.title}</strong>{" "}
              {n.read_at === null && <Badge tone="draft">New</Badge>}{" "}
              {n.routine === false && <Badge tone="review">Security</Badge>}
              <p>{n.body}</p>
              <p className="bp-roster__meta">{n.label ?? ""} · {n.created_at}</p>
              {n.url && <Link to={n.url}>Open</Link>}
            </div>
            <div>
              {n.read_at === null && (
                <Button size="sm" onClick={() => void read(n.id)}>Mark read</Button>
              )}{" "}
              {n.routine !== false && (
                <Button
                  size="sm"
                  variant="secondary"
                  onClick={() => void remove(n.id)}
                  aria-label={`Delete notification: ${n.title}`}
                >
                  Delete
                </Button>
              )}
            </div>
          </li>
        ))}
      </ul>
    </Panel>
  );
}
