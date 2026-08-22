/**
 * Collaborators and ownership transfer (v0.20.0).
 *
 * Owners invite registered users by email, revoke pending invitations,
 * change editor and reviewer roles, remove team members, and hand the
 * adventure to someone else. Every rule is enforced on the server; this
 * panel only offers what the server says the viewer may do, and it
 * always re-reads the roster from the response of each action.
 */
import { useCallback, useEffect, useId, useState } from "react";
import { Alert } from "./Alert";
import { Badge } from "./Badge";
import { Button } from "./Button";
import { Dialog } from "./Dialog";
import { Panel } from "./Panel";
import { PasswordField } from "./PasswordField";
import { Select } from "./Select";
import { Checkbox } from "./Checkbox";
import {
  changeCollaboratorRole,
  fetchRoster,
  inviteCollaborator,
  reauthenticate,
  removeCollaborator,
  revokeInvitation,
  transferOwnership,
  type RosterPayload,
} from "../lib/apiClient";

const ROLE_LABEL: Record<string, string> = {
  owner: "Owner",
  editor: "Editor",
  reviewer: "Reviewer",
};

const ERROR_COPY: Record<string, string> = {
  forbidden: "You do not have permission to do that.",
  reauthentication_required: "Confirm your password before transferring ownership.",
  confirmation_required: "Tick the confirmation box to continue.",
  expired: "That invitation has expired.",
  conflict: "That invitation has already been used or revoked.",
  invalid: "Check the details and try again.",
  not_found: "We could not find that person.",
  csrf_unavailable: "Your session expired. Reload the page and try again.",
};

function message(error?: string, fields?: Record<string, string>): string {
  const first = fields ? Object.values(fields)[0] : undefined;
  if (first) return first;
  return ERROR_COPY[error ?? ""] ?? "Something went wrong. Try again.";
}

export function CollaboratorsPanel({ slug }: { slug: string }) {
  const [roster, setRoster] = useState<RosterPayload | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const [email, setEmail] = useState("");
  const [inviteRole, setInviteRole] = useState<"editor" | "reviewer">("editor");
  const [inviteMessage, setInviteMessage] = useState("");

  const [transferTo, setTransferTo] = useState<number | null>(null);
  const [password, setPassword] = useState("");
  const [confirmed, setConfirmed] = useState(false);
  const [stayAsEditor, setStayAsEditor] = useState(true);

  const emailId = useId();

  const load = useCallback(async () => {
    setLoading(true);
    const res = await fetchRoster(slug);
    if (res.data) { setRoster(res.data); setError(null); }
    else setError(res.status === 403 ? ERROR_COPY.forbidden : "The team list could not be loaded.");
    setLoading(false);
  }, [slug]);

  useEffect(() => { void load(); }, [load]);

  const apply = useCallback(
    async (
      run: () => Promise<{ ok: boolean; data: unknown; error?: string; fields?: Record<string, string> }>,
      success: string,
    ) => {
      setBusy(true);
      setNotice(null);
      setError(null);
      const res = await run();
      if (res.ok) {
        const data = res.data as Partial<RosterPayload> & { roster?: RosterPayload | null } | null;
        if (data && Array.isArray(data.collaborators)) setRoster(data as RosterPayload);
        else if (data && data.roster) setRoster(data.roster);
        else await load();
        setNotice(success);
      } else {
        setError(message(res.error, res.fields));
      }
      setBusy(false);
      return res.ok;
    },
    [load],
  );

  if (loading) return <Panel title="Collaborators"><p>Loading the team…</p></Panel>;
  if (!roster) {
    return (
      <Panel title="Collaborators">
        <Alert tone="danger">{error ?? "The team list is unavailable."}</Alert>
      </Panel>
    );
  }

  const canManage = roster.can_manage;
  const transferable = roster.collaborators.filter((m) => m.role !== "owner");

  return (
    <>
      <Panel title="Collaborators">
        {notice && <Alert tone="success">{notice}</Alert>}
        {error && <Alert tone="danger">{error}</Alert>}

        <ul className="bp-roster" aria-label="Team members">
          {roster.collaborators.map((member) => (
            <li key={member.user_id} className="bp-roster__item">
              <div>
                <strong>{member.display_name}</strong>{" "}
                <span className="bp-roster__meta">@{member.username}</span>{" "}
                <Badge tone={member.role === "owner" ? "published" : "review"}>
                  {ROLE_LABEL[member.role] ?? member.role}
                </Badge>
              </div>
              {canManage && member.role !== "owner" && (
                <div className="bp-roster__actions">
                  <Select
                    label={`Role for ${member.display_name}`}
                    value={member.role}
                    disabled={busy}
                    onChange={(e) =>
                      void apply(
                        () => changeCollaboratorRole(slug, member.user_id, e.target.value as "editor" | "reviewer"),
                        `${member.display_name} is now ${e.target.value === "editor" ? "an editor" : "a reviewer"}.`,
                      )
                    }
                  >
                    <option value="editor">Editor</option>
                    <option value="reviewer">Reviewer</option>
                  </Select>
                  <Button
                    variant="danger"
                    size="sm"
                    disabled={busy}
                    onClick={() =>
                      void apply(
                        () => removeCollaborator(slug, member.user_id),
                        `${member.display_name} was removed from the team.`,
                      )
                    }
                  >
                    Remove
                  </Button>
                </div>
              )}
            </li>
          ))}
        </ul>
      </Panel>

      {canManage && (
        <Panel title="Pending invitations">
          {roster.invitations.length === 0 ? (
            <p className="bp-roster__meta">No invitations are waiting.</p>
          ) : (
            <ul className="bp-roster" aria-label="Invitations">
              {roster.invitations.map((inv) => (
                <li key={inv.id} className="bp-roster__item">
                  <div>
                    <strong>{inv.email}</strong>{" "}
                    <Badge tone="review">{ROLE_LABEL[inv.role] ?? inv.role}</Badge>{" "}
                    <Badge tone={inv.state === "pending" ? "draft" : "warning"}>{inv.state}</Badge>
                    <div className="bp-roster__meta">Expires {inv.expires_at}</div>
                  </div>
                  {inv.state === "pending" && (
                    <Button
                      size="sm"
                      disabled={busy}
                      onClick={() =>
                        void apply(() => revokeInvitation(slug, inv.id), "Invitation revoked.")
                      }
                    >
                      Revoke
                    </Button>
                  )}
                </li>
              ))}
            </ul>
          )}
        </Panel>
      )}

      {canManage && (
        <Panel title="Invite someone">
          <form
            onSubmit={(e) => {
              e.preventDefault();
              void apply(
                () => inviteCollaborator(slug, { email, role: inviteRole, message: inviteMessage }),
                "Invitation sent. It expires automatically if unused.",
              ).then((ok) => { if (ok) { setEmail(""); setInviteMessage(""); } });
            }}
          >
            <div className="bp-field">
              <label className="bp-label" htmlFor={emailId}>Email address of a registered user</label>
              <input
                id={emailId}
                className="bp-input"
                type="email"
                required
                autoComplete="email"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
              />
              <span className="bp-help">
                They receive the invitation in their inbox here and by email.
              </span>
            </div>
            <Select
              label="Role"
              value={inviteRole}
              onChange={(e) => setInviteRole(e.target.value as "editor" | "reviewer")}
            >
              {roster.invitable_roles.map((r) => (
                <option key={r} value={r}>{ROLE_LABEL[r] ?? r}</option>
              ))}
            </Select>
            <div className="bp-field">
              <label className="bp-label" htmlFor={`${emailId}-msg`}>Message (optional)</label>
              <textarea
                id={`${emailId}-msg`}
                className="bp-textarea"
                rows={3}
                maxLength={500}
                value={inviteMessage}
                onChange={(e) => setInviteMessage(e.target.value)}
              />
            </div>
            <Button type="submit" variant="primary" disabled={busy || email.trim() === ""}>
              Send invitation
            </Button>
          </form>
        </Panel>
      )}

      {canManage && roster.viewer_role !== "reviewer" && (
        <Panel title="Transfer ownership">
          {transferable.length === 0 ? (
            <p className="bp-roster__meta">
              Add an editor or reviewer first — ownership can only pass to an existing team member.
            </p>
          ) : (
            <>
              <p>
                Ownership moves in one step: the new owner takes over, and you stay on as an
                editor unless you choose to leave. Confirm your password first.
              </p>
              <Button variant="danger" onClick={() => setTransferTo(transferable[0].user_id)}>
                Transfer ownership…
              </Button>
            </>
          )}
        </Panel>
      )}

      <Dialog
        open={transferTo !== null}
        onClose={() => { setTransferTo(null); setPassword(""); setConfirmed(false); }}
        title="Transfer ownership"
        actions={
          <>
            <Button onClick={() => { setTransferTo(null); setPassword(""); setConfirmed(false); }}>
              Cancel
            </Button>
            <Button
              variant="danger"
              disabled={busy || !confirmed || password === "" || transferTo === null}
              onClick={() => {
                const target = transferTo;
                if (target === null) return;
                void (async () => {
                  setBusy(true);
                  const auth = await reauthenticate(password);
                  setBusy(false);
                  if (!auth.ok) { setError(message(auth.error, auth.fields)); return; }
                  const ok = await apply(
                    () => transferOwnership(slug, target, { confirm: true, stayAsEditor }),
                    "Ownership transferred.",
                  );
                  if (ok) { setTransferTo(null); setPassword(""); setConfirmed(false); }
                })();
              }}
            >
              Transfer ownership
            </Button>
          </>
        }
      >
        <Select
          label="New owner"
          value={transferTo ?? ""}
          onChange={(e) => setTransferTo(Number(e.target.value))}
        >
          {transferable.map((m) => (
            <option key={m.user_id} value={m.user_id}>
              {m.display_name} (@{m.username})
            </option>
          ))}
        </Select>
        <PasswordField
          label="Your password"
          value={password}
          onChange={(e) => setPassword(e.target.value)}
          help="Required because this action cannot be undone by you afterwards."
        />
        <Checkbox
          label="Stay on as an editor"
          checked={stayAsEditor}
          onChange={(e) => setStayAsEditor(e.target.checked)}
        />
        <Checkbox
          label="I understand this hands over control of the adventure"
          checked={confirmed}
          onChange={(e) => setConfirmed(e.target.checked)}
        />
      </Dialog>
    </>
  );
}
