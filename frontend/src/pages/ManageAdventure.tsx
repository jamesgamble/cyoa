/**
 * Manage adventure — drafts, preview, and publishing (v0.17.0).
 *
 * Owners, editors, and administrators can save draft edits, open the
 * private preview, and move the adventure between statuses. The set of
 * offered actions comes from the server, which is also the only place
 * the rules are enforced: publishing requires a valid opening scene,
 * archived adventures are read-only, and unpublishing never deletes
 * content. Publish, unpublish, and archive are confirmed first.
 */
import { useCallback, useEffect, useState } from "react";
import { Link, useParams } from "react-router-dom";
import {
  ActivityItem,
  Alert,
  Badge,
  Button,
  DangerZone,
  Dialog,
  Panel,
  TextArea,
} from "../components";
import { LoadingState, NotFoundState, UnauthorizedState } from "../states";
import { useHelpContext } from "../components/GlobalHelp";
import {
  changeAdventureStatus,
  fetchManageAdventure,
  saveAdventureDraft,
  type ManageOutcome,
  type ManagePayload,
  type StatusAction,
} from "../lib/apiClient";

const ACTION_LABELS: Record<StatusAction, string> = {
  publish: "Publish",
  unpublish: "Unpublish",
  set_in_progress: "Set in progress",
  set_complete: "Set complete",
  set_on_hold: "Set on hold",
  archive: "Archive",
};

const CONFIRM_COPY: Partial<Record<StatusAction, { title: string; body: string; confirm: string }>> = {
  publish: {
    title: "Publish this adventure?",
    body: "Readers will be able to find and read it. You can unpublish at any time.",
    confirm: "Publish",
  },
  unpublish: {
    title: "Unpublish this adventure?",
    body: "It returns to draft and disappears from public pages. Nothing is deleted — every scene, choice, and setting is kept.",
    confirm: "Unpublish",
  },
  archive: {
    title: "Archive this adventure?",
    body: "Archiving makes the adventure read-only: no further edits, contributions, or status changes. Content is preserved.",
    confirm: "Archive",
  },
};

const STATE_LABELS: Record<string, string> = {
  draft: "Draft",
  published: "In progress",
  "on-hold": "On hold",
  complete: "Complete",
  archived: "Archived",
  suspended: "Suspended",
};

function badgeTone(state: string) {
  if (state === "published" || state === "complete") return "published" as const;
  if (state === "archived" || state === "suspended") return "archived" as const;
  if (state === "on-hold") return "review" as const;
  return "draft" as const;
}

export function ManageAdventure() {
  const { slug = "" } = useParams();
  useHelpContext({ section: "publishing" });

  const [outcome, setOutcome] = useState<ManageOutcome | null>(null);
  const [data, setData] = useState<ManagePayload | null>(null);
  const [pending, setPending] = useState<StatusAction | null>(null);
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [description, setDescription] = useState("");

  const load = useCallback(async () => {
    const r = await fetchManageAdventure(slug);
    setOutcome(r.outcome);
    setData(r.data);
    if (r.data) setDescription(r.data.adventure.description);
  }, [slug]);

  useEffect(() => { void load(); }, [load]);

  async function runAction(action: StatusAction) {
    setBusy(true);
    setError(null);
    setNotice(null);
    const res = await changeAdventureStatus(slug, action);
    setBusy(false);
    setPending(null);
    if (res.ok) {
      setNotice(`${ACTION_LABELS[action]} — done.`);
      await load();
      return;
    }
    if (res.status === 422 && res.error === "no_opening_scene") {
      setError("Publishing needs a valid opening scene with a title and some text.");
    } else if (res.status === 409) {
      setError("This adventure is archived and read-only.");
    } else if (res.status === 403) {
      setError("You are not allowed to change this adventure’s status.");
    } else {
      setError("That status change could not be applied.");
    }
  }

  async function onSaveDraft() {
    setBusy(true);
    setError(null);
    setNotice(null);
    const res = await saveAdventureDraft(slug, { description });
    setBusy(false);
    if (res.ok) { setNotice("Draft saved."); await load(); return; }
    if (res.status === 409) setError("This adventure is archived and read-only.");
    else if (res.status === 422) setError("Please check the description and try again.");
    else setError("The draft could not be saved.");
  }

  function requestAction(action: StatusAction) {
    if (CONFIRM_COPY[action]) { setPending(action); return; }
    void runAction(action);
  }

  if (outcome === null) return <LoadingState message="Loading adventure…" />;
  if (outcome === "unauthenticated") return <UnauthorizedState />;
  if (outcome === "forbidden") {
    return (
      <section>
        <h1>Manage adventure</h1>
        <Alert tone="warning" title="You cannot manage this adventure">
          Only the adventure’s owner, its editors, and site administrators can
          save drafts, preview, or change its status.
        </Alert>
      </section>
    );
  }
  if (outcome === "not_found" || !data) return <NotFoundState />;

  const { adventure, role, read_only: readOnly, available_actions: actions } = data;
  const confirm = pending ? CONFIRM_COPY[pending] : undefined;

  return (
    <section aria-labelledby="manage-h" data-testid="manage-adventure">
      <h1 id="manage-h">{adventure.title}</h1>
      <p>
        <Badge tone={badgeTone(adventure.state)}>
          {STATE_LABELS[adventure.state] ?? adventure.state}
        </Badge>{" "}
        <span className="bp-meta">Your role: {role}</span>
      </p>

      {notice && <Alert tone="success" title={notice} />}
      {error && <Alert tone="danger" title={error} />}
      {readOnly && (
        <Alert tone="warning" title="This adventure is archived">
          Archived adventures are read-only. The story stays available exactly
          as it was; no edits or status changes are possible.
        </Alert>
      )}
      {!data.has_valid_opening && !readOnly && (
        <Alert tone="warning" title="Publishing needs a valid opening scene">
          Add a title and some text to the opening scene before publishing.
        </Alert>
      )}

      <Panel title="Preview">
        <p>
          Preview the reader experience, including unpublished scenes. Preview
          is limited to collaborators and administrators, and is never indexed
          by search engines.
        </p>
        <p>
          <Link to={`/adventure/${adventure.slug}/preview`} data-testid="preview-link">
            Open private preview
          </Link>
        </p>
      </Panel>

      <Panel title="Draft">
        <TextArea
          label="Description"
          value={description}
          disabled={readOnly}
          onChange={(e) => setDescription(e.target.value)}
        />
        <Button variant="primary" onClick={onSaveDraft} disabled={busy || readOnly}>
          Save draft
        </Button>
      </Panel>

      <Panel title="Status">
        {actions.length === 0 && <p>No status changes are available.</p>}
        <div className="bp-actions">
          {actions
            .filter((a) => a !== "archive")
            .map((action) => (
              <Button
                key={action}
                variant={action === "publish" ? "primary" : "secondary"}
                disabled={busy}
                onClick={() => requestAction(action)}
              >
                {ACTION_LABELS[action]}
              </Button>
            ))}
        </div>
      </Panel>

      {actions.includes("archive") && (
        <DangerZone description="Archiving preserves the story but makes it permanently read-only.">
          <Button variant="danger" disabled={busy} onClick={() => requestAction("archive")}>
            Archive
          </Button>
        </DangerZone>
      )}

      <Panel title="Activity">
        {data.activity.length === 0 ? (
          <p>No status changes recorded yet.</p>
        ) : (
          <ul className="bp-activity" data-testid="activity-list">
            {data.activity.map((a) => (
              <ActivityItem
                key={a.id}
                actor={a.actor ?? "Someone"}
                action={ACTION_LABELS[a.action as StatusAction]?.toLowerCase() ?? a.action}
                target={`${a.from_state ?? "?"} → ${a.to_state ?? "?"}`}
                at={a.created_at}
              />
            ))}
          </ul>
        )}
      </Panel>

      <Dialog
        open={pending !== null}
        onClose={() => setPending(null)}
        title={confirm?.title ?? ""}
        actions={
          <>
            <Button onClick={() => setPending(null)}>Cancel</Button>
            <Button
              variant={pending === "archive" ? "danger" : "primary"}
              disabled={busy}
              onClick={() => pending && runAction(pending)}
            >
              {confirm?.confirm ?? "Confirm"}
            </Button>
          </>
        }
      >
        <p>{confirm?.body}</p>
      </Dialog>
    </section>
  );
}
