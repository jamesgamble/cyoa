/**
 * Invitation landing page (v0.20.0) — /invitations/:token
 *
 * The link carries a single-use token. Only the addressee can act on
 * it, and only while it is unexpired and unrevoked; the server decides
 * all of that, so this page simply reports what it is told.
 */
import { useCallback, useEffect, useState } from "react";
import { Link, useNavigate, useParams } from "react-router-dom";
import { Alert, Button, Panel } from "../components";
import { LoadingState } from "../states";
import { useHelpContext } from "../components/GlobalHelp";
import {
  fetchInvitation,
  respondToInvitation,
  type InvitationDetails,
} from "../lib/apiClient";

type Problem = "not_found" | "expired" | "used" | "forbidden" | "signed_out" | "error";

const PROBLEM_COPY: Record<Problem, { title: string; body: string }> = {
  not_found: { title: "Invitation not found", body: "This link is not valid. Ask the owner to send a new invitation." },
  expired: { title: "Invitation expired", body: "Invitations expire for safety. Ask the owner to send a new one." },
  used: { title: "Invitation already used", body: "This invitation has already been accepted, declined, or revoked." },
  forbidden: { title: "Invitation is for someone else", body: "Sign in with the account the invitation was sent to." },
  signed_out: { title: "Sign in to continue", body: "Sign in with the invited account to view this invitation." },
  error: { title: "Something went wrong", body: "We could not load this invitation. Try again shortly." },
};

export function Invitation() {
  const { token = "" } = useParams();
  const navigate = useNavigate();
  const [invitation, setInvitation] = useState<InvitationDetails | null>(null);
  const [problem, setProblem] = useState<Problem | null>(null);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [declined, setDeclined] = useState(false);

  useHelpContext({ section: "collaboration" });

  const load = useCallback(async () => {
    setLoading(true);
    const res = await fetchInvitation(token);
    if (res.data) { setInvitation(res.data); setProblem(null); }
    else if (res.status === 401) setProblem("signed_out");
    else if (res.status === 403) setProblem("forbidden");
    else if (res.status === 410) setProblem("expired");
    else if (res.status === 409) setProblem("used");
    else if (res.status === 404) setProblem("not_found");
    else setProblem("error");
    setLoading(false);
  }, [token]);

  useEffect(() => { void load(); }, [load]);

  const respond = useCallback(
    async (action: "accept" | "decline") => {
      setBusy(true);
      const res = await respondToInvitation(token, action);
      setBusy(false);
      if (!res.ok) { await load(); return; }
      if (action === "accept" && res.data?.adventure_slug) {
        navigate(`/adventure/${res.data.adventure_slug}`);
        return;
      }
      setDeclined(true);
      setInvitation(null);
    },
    [load, navigate, token],
  );

  if (loading) return <LoadingState message="Loading invitation…" />;

  if (declined) {
    return (
      <Panel title="Invitation declined">
        <p>Thanks — the owner has been told. Nothing was shared with you.</p>
        <Link to="/discover">Browse adventures</Link>
      </Panel>
    );
  }

  if (!invitation) {
    const copy = PROBLEM_COPY[problem ?? "error"];
    return (
      <Panel title={copy.title}>
        <Alert tone="warning">{copy.body}</Alert>
        {problem === "signed_out" ? (
          <Link to={`/login?redirect=/invitations/${encodeURIComponent(token)}`}>Sign in</Link>
        ) : (
          <Link to="/discover">Browse adventures</Link>
        )}
      </Panel>
    );
  }

  return (
    <Panel title={`Join “${invitation.adventure_title}”`}>
      <p>
        {invitation.invited_by} invited you to help with{" "}
        <Link to={`/adventure/${invitation.adventure_slug}`}>{invitation.adventure_title}</Link> as{" "}
        <strong>{invitation.role === "editor" ? "an editor" : "a reviewer"}</strong>.
      </p>
      {invitation.message && <blockquote>{invitation.message}</blockquote>}
      <p className="bp-roster__meta">This invitation expires {invitation.expires_at}.</p>
      <p>
        {invitation.role === "editor"
          ? "Editors can edit scenes and choices and review contributions."
          : "Reviewers can leave private notes and recommend decisions, but cannot publish."}
      </p>
      <div className="bp-roster__actions">
        <Button variant="primary" disabled={busy} onClick={() => void respond("accept")}>
          Accept invitation
        </Button>
        <Button disabled={busy} onClick={() => void respond("decline")}>
          Decline
        </Button>
      </div>
    </Panel>
  );
}
