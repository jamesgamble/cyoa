/**
 * Account dashboard pages (v0.14.0).
 *
 * Every sub-page requires a live session cookie. If the caller is not
 * authenticated the API returns 401 and the page renders an
 * UnauthorizedState prompting the reader to sign in.
 */
import { useCallback, useEffect, useState, type FormEvent } from "react";
import { Link, useNavigate, useSearchParams } from "react-router-dom";
import { UnauthorizedState } from "../states";
import { Badge } from "../components/Badge";
import { useHelpContext } from "../components/GlobalHelp";
import {
  fetchAccountProfile,
  fetchAccountSecurity,
  fetchAccountAdventures,
  fetchContributionHistory,
  fetchAccountBookmarks,
  updateAccountProfile,
  fetchNotificationPreferences,
  saveNotificationPreferences,
  unfollowAdventure,
  requestAccountEmailChange,
  confirmAccountEmailChange,
  revokeOtherAccountSessions,
  importAccountLocalProgress,
  submitLogout,
  type AccountProfile,
  type AccountSession,
  type AccountAdventure,
  type AccountBookmark,
  type ContributionHistoryEntry,
  type NotificationPreference,
  type FollowedAdventure,
} from "../lib/apiClient";
import { readLocalProgress } from "../hooks/useLocalProgress";

/* ─────────────────────────── Shared ─────────────────────────── */

function useProfile() {
  const [profile, setProfile] = useState<AccountProfile | null>(null);
  const [state, setState] = useState<"loading" | "ready" | "unauth">("loading");
  const refresh = useCallback(async () => {
    const r = await fetchAccountProfile();
    if (r.status === 401) { setState("unauth"); return; }
    setProfile(r.profile ?? null);
    setState("ready");
  }, []);
  useEffect(() => { void refresh(); }, [refresh]);
  return { profile, state, refresh, setProfile };
}

function Loading() { return <p role="status">Loading…</p>; }

function StatusMessage({ msg }: { msg: string | null }) {
  if (!msg) return null;
  return <p role="status" className="bp-validation">{msg}</p>;
}

/* ─────────────────────────── Overview ───────────────────────── */

export function AccountOverview() {
  useHelpContext({ section: "account" });
  const { profile, state } = useProfile();
  const navigate = useNavigate();

  async function onSignOut() {
    await submitLogout();
    navigate("/", { replace: true });
  }

  if (state === "loading") return <Loading />;
  if (state === "unauth" || !profile) {
    return (
      <section aria-labelledby="account-h">
        <h1 id="account-h">Account</h1>
        <UnauthorizedState />
      </section>
    );
  }
  return (
    <section aria-labelledby="account-h">
      <h1 id="account-h">Welcome, {profile.display_name}</h1>
      <p>
        Signed in as <strong>{profile.username}</strong> ({profile.email}).
      </p>
      <ul>
        <li><Link to="/account/profile">Edit your profile</Link></li>
        <li><Link to="/account/security">Review active sessions</Link></li>
        <li><Link to="/account/notifications">Notification preferences</Link></li>
        <li><Link to="/account/adventures">Your adventures</Link></li>
        <li><Link to="/account/bookmarks">Bookmarks &amp; reading history</Link></li>
      </ul>
      <button type="button" onClick={onSignOut}>Sign out</button>
    </section>
  );
}

/* ─────────────────────────── Profile ─────────────────────────── */

export function AccountProfilePage() {
  useHelpContext({ section: "account" });
  const { profile, state, refresh } = useProfile();
  const [displayName, setDisplayName] = useState("");
  const [bio, setBio] = useState("");
  const [publicProfile, setPublicProfile] = useState(true);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [msg, setMsg] = useState<string | null>(null);
  const [emailValue, setEmailValue] = useState("");
  const [emailMsg, setEmailMsg] = useState<string | null>(null);

  useEffect(() => {
    if (!profile) return;
    setDisplayName(profile.display_name);
    setBio(profile.bio);
    setPublicProfile(profile.public_profile);
  }, [profile]);

  if (state === "loading") return <Loading />;
  if (state === "unauth" || !profile) return <UnauthorizedState />;

  async function onSave(e: FormEvent) {
    e.preventDefault();
    setErrors({}); setMsg(null);
    const r = await updateAccountProfile({
      display_name: displayName, bio, public_profile: publicProfile,
    });
    if (r.ok) { setMsg("Profile saved."); await refresh(); return; }
    if (r.status === 422 && r.fields) { setErrors(r.fields); setMsg("Fix the highlighted fields."); return; }
    if (r.status === 401) { setMsg("You are signed out. Sign in again to save."); return; }
    setMsg("Could not save — please try again.");
  }

  async function onChangeEmail(e: FormEvent) {
    e.preventDefault();
    setEmailMsg(null);
    const r = await requestAccountEmailChange(emailValue);
    if (r.ok) {
      setEmailMsg(`Check ${emailValue} for a confirmation link.`);
      setEmailValue("");
      return;
    }
    if (r.status === 409) { setEmailMsg("That address is already in use."); return; }
    if (r.status === 422) { setEmailMsg("Enter a valid email address."); return; }
    setEmailMsg("Could not request the change — please try again.");
  }

  return (
    <section aria-labelledby="profile-h">
      <h1 id="profile-h">Profile</h1>
      <form onSubmit={onSave} noValidate>
        <label>
          <span>Display name</span>
          <input
            value={displayName}
            onChange={(e) => setDisplayName(e.target.value)}
            required
            maxLength={60}
          />
          {errors.display_name && <p role="alert" className="bp-validation">Enter a name between 1 and 60 characters.</p>}
        </label>
        <label>
          <span>Short bio</span>
          <textarea
            value={bio}
            onChange={(e) => setBio(e.target.value)}
            maxLength={500}
            rows={4}
          />
          {errors.bio && <p role="alert" className="bp-validation">Keep the bio under 500 characters.</p>}
        </label>
        <label>
          <input
            type="checkbox"
            checked={publicProfile}
            onChange={(e) => setPublicProfile(e.target.checked)}
          />
          <span>Show my profile to other readers</span>
        </label>
        <StatusMessage msg={msg} />
        <button type="submit">Save profile</button>
      </form>

      <hr />
      <h2>Change email</h2>
      <p>
        Current address: <strong>{profile.email}</strong>. Enter a new
        address; a confirmation link will be sent there.
      </p>
      <form onSubmit={onChangeEmail} noValidate>
        <label>
          <span>New email</span>
          <input
            type="email"
            value={emailValue}
            onChange={(e) => setEmailValue(e.target.value)}
            autoComplete="email"
            required
          />
        </label>
        <StatusMessage msg={emailMsg} />
        <button type="submit">Send confirmation link</button>
      </form>
    </section>
  );
}

/* ─────────────────────────── Security ─────────────────────────── */

export function AccountSecurityPage() {
  useHelpContext({ section: "account" });
  const [profile, setProfile] = useState<AccountProfile | null>(null);
  const [sessions, setSessions] = useState<AccountSession[]>([]);
  const [state, setState] = useState<"loading" | "ready" | "unauth">("loading");
  const [msg, setMsg] = useState<string | null>(null);
  const [params, setParams] = useSearchParams();

  const refresh = useCallback(async () => {
    const r = await fetchAccountSecurity();
    if (r.status === 401) { setState("unauth"); return; }
    setProfile(r.profile ?? null);
    setSessions(r.sessions ?? []);
    setState("ready");
  }, []);

  // Consume ?email_change_token=... if it lands here from the email link.
  useEffect(() => {
    const token = params.get("email_change_token");
    if (!token) { void refresh(); return; }
    void (async () => {
      const r = await confirmAccountEmailChange(token);
      if (r.ok) setMsg("Your email address has been updated.");
      else if (r.status === 409) setMsg("That address is already in use by another account.");
      else setMsg("The confirmation link is invalid or has expired.");
      params.delete("email_change_token");
      setParams(params, { replace: true });
      await refresh();
    })();
  }, [params, refresh, setParams]);

  if (state === "loading") return <Loading />;
  if (state === "unauth" || !profile) return <UnauthorizedState />;

  async function onRevokeOthers() {
    setMsg(null);
    const r = await revokeOtherAccountSessions();
    if (r.ok) { setMsg(`Signed out ${r.data?.revoked ?? 0} other session(s).`); await refresh(); return; }
    setMsg("Could not revoke sessions — please try again.");
  }

  return (
    <section aria-labelledby="security-h">
      <h1 id="security-h">Security</h1>
      <dl>
        <dt>Email verified</dt>
        <dd>{profile.email_verified_at ? profile.email_verified_at : "Not verified"}</dd>
        <dt>Password last changed</dt>
        <dd>{profile.password_changed_at ?? "Never (or before v0.13.0)"}</dd>
        <dt>Last sign-in</dt>
        <dd>{profile.last_login_at ?? "—"}</dd>
      </dl>
      <p>
        <Link to="/change-password">Change your password</Link>
      </p>
      <StatusMessage msg={msg} />

      <h2>Active sessions</h2>
      <table>
        <thead>
          <tr>
            <th>Session</th><th>Started</th><th>Last active</th><th>Expires</th><th>Device</th>
          </tr>
        </thead>
        <tbody>
          {sessions.map((s) => (
            <tr key={s.id}>
              <td>{s.is_current ? "This device" : `#${s.id}`}</td>
              <td>{s.created_at}</td>
              <td>{s.last_active_at}</td>
              <td>{s.expires_at}</td>
              <td>{s.user_agent || "—"}</td>
            </tr>
          ))}
        </tbody>
      </table>
      {sessions.length > 1 && (
        <button type="button" onClick={onRevokeOthers}>
          Sign out other sessions
        </button>
      )}
    </section>
  );
}

/* ─────────────────────────── Notifications ───────────────────── */

/**
 * Email preferences (reworked in v0.22.0).
 *
 * One switch per notification kind. Security and recovery mail is
 * locked on: those messages are how an account is recovered. Routine
 * updates from adventures you follow are bundled into one digest
 * rather than one email per branch.
 */
export function AccountNotificationsPage() {
  useHelpContext({ section: "notifications" });
  const [prefs, setPrefs] = useState<NotificationPreference[]>([]);
  const [following, setFollowing] = useState<FollowedAdventure[]>([]);
  const [state, setState] = useState<"loading" | "ready" | "unauth">("loading");
  const [msg, setMsg] = useState<string | null>(null);

  const refresh = useCallback(async () => {
    const r = await fetchNotificationPreferences();
    if (r.status === 401) { setState("unauth"); return; }
    setPrefs(r.data?.preferences ?? []);
    setFollowing(r.data?.following ?? []);
    setState("ready");
  }, []);
  useEffect(() => { void refresh(); }, [refresh]);

  if (state === "loading") return <Loading />;
  if (state === "unauth") return <UnauthorizedState />;

  function toggle(kind: string, value: boolean) {
    setPrefs((rows) => rows.map((p) => (p.kind === kind ? { ...p, email: value } : p)));
  }

  async function onSave(e: FormEvent) {
    e.preventDefault();
    setMsg(null);
    const payload: Record<string, boolean> = {};
    for (const p of prefs) if (!p.locked) payload[p.kind] = p.email;
    const r = await saveNotificationPreferences(payload);
    if (r.ok) { setMsg("Preferences saved."); setPrefs(r.data?.preferences ?? prefs); return; }
    setMsg("Could not save — please try again.");
  }

  async function onUnfollow(slug: string) {
    setMsg(null);
    const r = await unfollowAdventure(slug);
    if (r.ok) { await refresh(); return; }
    setMsg("Could not update that subscription.");
  }

  return (
    <section aria-labelledby="notifications-h">
      <h1 id="notifications-h">Notifications</h1>
      <p>
        Everything below also appears in your{" "}
        <Link to="/account/inbox">inbox</Link>. These switches only control email.
      </p>
      <form onSubmit={onSave}>
        <ul className="bp-list" data-testid="preference-list">
          {prefs.map((p) => (
            <li key={p.kind}>
              <label>
                <input
                  type="checkbox"
                  checked={p.email}
                  disabled={p.locked}
                  data-testid={`pref-${p.kind}`}
                  onChange={(e) => toggle(p.kind, e.target.checked)}
                />
                <span>{p.label}</span>
              </label>
              {p.locked && (
                <p className="bp-muted">
                  Security and recovery email cannot be switched off.
                </p>
              )}
              {p.aggregated && !p.locked && (
                <p className="bp-muted">Sent as one bundled update, not one email per change.</p>
              )}
            </li>
          ))}
        </ul>
        <StatusMessage msg={msg} />
        <button type="submit">Save preferences</button>
      </form>

      <hr />
      <h2>Adventures you follow</h2>
      <p>
        Following an adventure subscribes you to its updates. It is separate
        from a bookmark, which remembers where you stopped reading.
      </p>
      {following.length === 0 ? (
        <p data-testid="following-empty">You are not following any adventures yet.</p>
      ) : (
        <ul className="bp-list" data-testid="following-list">
          {following.map((f) => (
            <li key={f.slug}>
              <Link to={`/adventure/${f.slug}`}>{f.title}</Link>{" "}
              <button type="button" onClick={() => void onUnfollow(f.slug)}>
                Unfollow
              </button>
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}

/* ─────────────────────────── My adventures ───────────────────── */

export function AccountAdventuresPage() {
  useHelpContext({ section: "account" });
  const [rows, setRows] = useState<AccountAdventure[] | null>(null);
  const [state, setState] = useState<"loading" | "ready" | "unauth">("loading");

  useEffect(() => {
    void (async () => {
      const r = await fetchAccountAdventures();
      if (r.status === 401) { setState("unauth"); return; }
      setRows(r.adventures ?? []);
      setState("ready");
    })();
  }, []);

  if (state === "loading") return <Loading />;
  if (state === "unauth") return <UnauthorizedState />;

  return (
    <section aria-labelledby="adv-h">
      <h1 id="adv-h">My adventures</h1>
      <p><Link to="/start">Create a new adventure</Link></p>
      {rows && rows.length === 0 ? (
        <p>You haven't started an adventure yet. <Link to="/start">Begin one</Link>.</p>
      ) : (
        <table>
          <thead>
            <tr><th>Title</th><th>State</th><th>Visibility</th><th>Contributions</th><th>Updated</th><th></th></tr>
          </thead>
          <tbody>
            {(rows ?? []).map((a) => (
              <tr key={a.id}>
                <td>{a.title}</td>
                <td>{a.state}</td>
                <td>{a.visibility}</td>
                <td>{a.contribution_state}</td>
                <td>{a.updated_at}</td>
                <td><Link to={`/manage/${a.slug}`}>Manage</Link></td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </section>
  );
}

/* ─────────────────────────── Contributions ───────────────────── */

export function AccountContributionsPage() {
  useHelpContext({ section: "account" });
  const [state, setState] = useState<"loading" | "ready" | "unauth">("loading");
  const [rows, setRows] = useState<ContributionHistoryEntry[]>([]);
  useEffect(() => {
    void (async () => {
      const r = await fetchContributionHistory();
      if (r.status === 401) { setState("unauth"); return; }
      setRows((r.contributions ?? []) as ContributionHistoryEntry[]);
      setState("ready");
    })();
  }, []);
  if (state === "loading") return <Loading />;
  if (state === "unauth") return <UnauthorizedState />;
  return (
    <section aria-labelledby="contrib-h">
      <h1 id="contrib-h">Contributions</h1>
      {rows.length === 0 ? (
        <p data-testid="contributions-empty">
          You haven't submitted a contribution yet. Open any adventure that accepts
          branches and choose “Add a branch”.
        </p>
      ) : (
        <ul className="bp-list" data-testid="contributions-list">
          {rows.map((row) => (
            <li key={row.id} data-testid="contribution-item">
              <p>
                <Link to={`/adventure/${row.adventure_slug}`}>{row.adventure_title}</Link>{" "}
                <Badge tone={row.state === "published" ? "published" : row.state === "declined" ? "archived" : "review"}>
                  {row.state === "published"
                    ? "Published"
                    : row.state === "declined"
                      ? "Declined"
                      : row.state === "changes_requested"
                        ? "Changes requested"
                        : "Awaiting review"}
                </Badge>
              </p>
              <p>
                “{row.choice_text}” → {row.scene_title}
                {row.scene_type === "ending" ? " (ending)" : ""}
              </p>
              <p className="bp-muted">
                From “{row.source_scene_title}” · credited as{" "}
                {row.attribution === "anonymous"
                  ? "Anonymous"
                  : row.attribution === "display_name"
                    ? "your display name"
                    : "your username"}
              </p>
              {row.moderator_note && <p data-testid="contribution-note">{row.moderator_note}</p>}
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}

/* ─────────────────────────── Bookmarks ───────────────────────── */

export function AccountBookmarksPage() {
  useHelpContext({ section: "account" });
  const [rows, setRows] = useState<AccountBookmark[]>([]);
  const [state, setState] = useState<"loading" | "ready" | "unauth">("loading");
  const [msg, setMsg] = useState<string | null>(null);
  const [confirming, setConfirming] = useState<"bookmarks" | "history" | null>(null);

  const refresh = useCallback(async () => {
    const r = await fetchAccountBookmarks();
    if (r.status === 401) { setState("unauth"); return; }
    setRows(r.bookmarks ?? []);
    setState("ready");
  }, []);
  useEffect(() => { void refresh(); }, [refresh]);

  /** Collect every progress record we can find in localStorage. */
  function collectLocalEntries() {
    const out: Array<{ slug: string; bookmarks: string[]; history: string[] }> = [];
    if (typeof window === "undefined") return out;
    for (let i = 0; i < window.localStorage.length; i++) {
      const key = window.localStorage.key(i);
      if (!key || !key.startsWith("bp-progress:")) continue;
      const slug = key.slice("bp-progress:".length);
      const p = readLocalProgress(slug);
      if (!p) continue;
      out.push({ slug, bookmarks: p.bookmarks, history: p.history });
    }
    return out;
  }

  async function onImport(kind: "bookmarks" | "history") {
    setMsg(null);
    setConfirming(null);
    const entries = collectLocalEntries().map((e) => ({
      slug: e.slug,
      bookmarks: kind === "bookmarks" ? e.bookmarks : [],
      history:   kind === "history"   ? e.history   : [],
    })).filter((e) => (e.bookmarks?.length ?? 0) + (e.history?.length ?? 0) > 0);
    if (entries.length === 0) { setMsg("Nothing to import from this browser."); return; }
    const r = await importAccountLocalProgress(entries);
    if (r.ok) {
      const b = r.data?.imported_bookmarks ?? 0;
      const h = r.data?.imported_history ?? 0;
      setMsg(`Imported ${b} bookmark(s) and ${h} history entry (entries).`);
      await refresh();
    } else {
      setMsg("Could not import — please try again.");
    }
  }

  if (state === "loading") return <Loading />;
  if (state === "unauth") return <UnauthorizedState />;

  return (
    <section aria-labelledby="bm-h">
      <h1 id="bm-h">Bookmarks</h1>
      <p>Bookmarks and reading history stored in this browser can be imported to your account.</p>
      <p>
        <button type="button" onClick={() => setConfirming("bookmarks")}>Import local bookmarks</button>{" "}
        <button type="button" onClick={() => setConfirming("history")}>Import local reading history</button>
      </p>
      {confirming && (
        <div role="alertdialog" aria-labelledby="confirm-h">
          <p id="confirm-h">
            Import your local {confirming} to your account? This copies the data
            from this browser's storage into your account and is safe to run
            multiple times.
          </p>
          <button type="button" onClick={() => onImport(confirming)}>Confirm import</button>{" "}
          <button type="button" onClick={() => setConfirming(null)}>Cancel</button>
        </div>
      )}
      <StatusMessage msg={msg} />

      <h2>Saved bookmarks</h2>
      {rows.length === 0 ? (
        <p>No bookmarks yet.</p>
      ) : (
        <ul>
          {rows.map((b) => (
            <li key={b.id}>
              <Link to={`/adventure/${b.adventure_slug}`}>{b.adventure_title}</Link>
              {b.scene_title ? ` — ${b.scene_title}` : ""}
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}
