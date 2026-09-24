/**
 * Revision history (v0.24.0) — managers see who changed published text
 * and when, compare an old version with the current one, and restore it.
 * Restoring saves the current text as a new revision first.
 */
import { useCallback, useEffect, useState } from "react";
import { Alert } from "./Alert";
import { Button } from "./Button";
import { Dialog } from "./Dialog";
import { Panel } from "./Panel";
import {
  compareRevision,
  fetchRevisions,
  restoreRevision,
  type RevisionComparison,
  type RevisionEntry,
  type RevisionList,
} from "../lib/apiClient";

const FIELD_LABELS: Record<string, string> = {
  description: "Description",
  writing_guidelines: "Writing guidelines",
  title: "Scene title",
  body: "Scene text",
  label: "Choice text",
};

export function RevisionHistoryPanel({ slug, onRestored }: { slug: string; onRestored?: () => void }) {
  const [list, setList] = useState<RevisionList | null | undefined>(undefined);
  const [comparison, setComparison] = useState<RevisionComparison | null>(null);
  const [pending, setPending] = useState<RevisionEntry | null>(null);
  const [message, setMessage] = useState<{ tone: "success" | "danger"; text: string } | null>(null);

  const load = useCallback(async () => setList(await fetchRevisions(slug)), [slug]);
  useEffect(() => { void load(); }, [load]);

  async function compare(r: RevisionEntry) {
    setComparison(await compareRevision(slug, r.id));
  }

  async function restore() {
    if (!pending) return;
    const res = await restoreRevision(slug, pending.id);
    setPending(null);
    if (res.ok) {
      setMessage({ tone: "success", text: "Earlier version restored. The replaced text was saved as a new revision." });
      setComparison(null);
      await load();
      onRestored?.();
    } else {
      setMessage({ tone: "danger", text: res.status === 409 ? "This adventure is read-only." : "That version could not be restored." });
    }
  }

  return (
    <Panel title="Revision history">
      {list === undefined && <p>Loading revisions…</p>}
      {list === null && <p>Revision history is available to owners, editors, and administrators.</p>}
      {message && <Alert tone={message.tone} title={message.text} />}
      {list && (
        <>
          <p className="bp-meta">
            Changes to published text are saved automatically. The latest {list.retain} versions of each piece of text are kept.
          </p>
          {list.revisions.length === 0 ? (
            <p>No revisions yet.</p>
          ) : (
            <ul className="bp-activity" data-testid="revision-list">
              {list.revisions.map((r) => (
                <li key={r.id} data-testid="revision-item">
                  <strong>{r.target_label}</strong> — {FIELD_LABELS[r.field] ?? r.field}
                  {r.reason === "restore" && " (before restore)"}
                  <br />
                  <span className="bp-meta">
                    {r.editor} · <time dateTime={r.created_at}>{new Date(r.created_at).toLocaleString()}</time>
                  </span>{" "}
                  <Button onClick={() => void compare(r)}>Compare</Button>{" "}
                  {!list.read_only && <Button onClick={() => setPending(r)}>Restore</Button>}
                </li>
              ))}
            </ul>
          )}
        </>
      )}

      {comparison && (
        <section aria-label="Comparison" data-testid="revision-diff">
          <h3>{comparison.older_label} compared with {comparison.newer_label}</h3>
          <ul className="bp-diff">
            {comparison.diff.map((line, i) => (
              <li key={i} data-op={line.op}>
                {line.op === "added" && <ins>+ {line.text}</ins>}
                {line.op === "removed" && <del>− {line.text}</del>}
                {line.op === "same" && <span>{line.text}</span>}
              </li>
            ))}
          </ul>
        </section>
      )}

      <Dialog
        open={pending !== null}
        onClose={() => setPending(null)}
        title="Restore this version?"
        actions={
          <>
            <Button onClick={() => setPending(null)}>Cancel</Button>
            <Button variant="primary" onClick={() => void restore()}>Restore version</Button>
          </>
        }
      >
        <p>The current text will be replaced, and kept as a new revision so you can switch back.</p>
      </Dialog>
    </Panel>
  );
}
