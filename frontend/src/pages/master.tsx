import { createContext, useCallback, useContext, useEffect, useState, type FormEvent, type ReactNode } from "react";
import { Link, useNavigate } from "react-router-dom";
import { getJson, masterMutate, masterLogout, reauthenticate } from "../lib/apiClient";
import { AdminTable } from "../components/AdminTable";
import { ManageNav, type ManageNavItem } from "../components/ManageNav";
import { Alert } from "../components/Alert";
import { Badge } from "../components/Badge";
import { Button } from "../components/Button";
import { Dialog } from "../components/Dialog";

/*
 * v0.25.0 — Master administration.
 *
 * The server decides every permission; the UI only hides controls a
 * role cannot use. Sensitive actions that come back with
 * `reauthentication_required` open a password prompt, then retry.
 * Passwords, tokens, SMTP secrets and database paths are never
 * returned by the API, so they cannot be shown here.
 */

export type PlatformRole = "user" | "moderator" | "admin";
export interface MasterMe {
  user_id: number;
  display_name: string;
  role: PlatformRole;
  permissions: Record<string, boolean>;
}

const MeContext = createContext<MasterMe | null>(null);
export const useMasterMe = () => useContext(MeContext);

type Mutation = Awaited<ReturnType<typeof masterMutate>>;

/** Runs a master mutation; on reauth demand, prompts and retries once. */
function useSensitive() {
  const [pending, setPending] = useState<null | { run: () => Promise<Mutation>; resolve: (m: Mutation) => void }>(null);
  const [password, setPassword] = useState("");
  const [error, setError] = useState<string | null>(null);

  const perform = useCallback(async (run: () => Promise<Mutation>): Promise<Mutation> => {
    const first = await run();
    if (first.ok || first.error !== "reauthentication_required") return first;
    return new Promise<Mutation>((resolve) => setPending({ run, resolve }));
  }, []);

  async function confirm(e: FormEvent) {
    e.preventDefault();
    if (!pending) return;
    const r = await reauthenticate(password);
    if (!r.ok) { setError("That password did not match."); return; }
    setPassword(""); setError(null);
    const again = await pending.run();
    pending.resolve(again);
    setPending(null);
  }

  const prompt = (
    <Dialog
      open={pending !== null}
      title="Confirm it's you"
      onClose={() => { pending?.resolve({ ok: false, status: 403, data: null, error: "reauth_cancelled" }); setPending(null); }}
    >
      <form onSubmit={confirm}>
        <p>This action is sensitive. Enter your password to continue.</p>
        <label htmlFor="master-reauth">Password</label>
        <input id="master-reauth" type="password" autoComplete="current-password" value={password}
          onChange={(e) => setPassword(e.target.value)} required />
        {error && <p role="alert">{error}</p>}
        <Button variant="primary" type="submit">Confirm</Button>
      </form>
    </Dialog>
  );
  return { perform, prompt };
}

const SECTIONS: Array<ManageNavItem & { perm: string }> = [
  { to: "/master", label: "Overview", end: true, perm: "view_console" },
  { to: "/master/users", label: "Users", perm: "view_users" },
  { to: "/master/adventures", label: "Adventures", perm: "view_adventures" },
  { to: "/master/submissions", label: "Submissions", perm: "view_submissions" },
  { to: "/master/reports", label: "Reports", perm: "review_reports" },
  { to: "/master/email-queue", label: "Email queue", perm: "manage_email_queue" },
  { to: "/master/settings", label: "Settings", perm: "configure_registration" },
  { to: "/master/activity", label: "Activity", perm: "view_activity" },
];

/** Shell for every master page: session gate, role-aware navigation. */
export function MasterShell({ title, perm, children }: { title: string; perm: string; children: ReactNode }) {
  const navigate = useNavigate();
  const [me, setMe] = useState<MasterMe | null | undefined>(undefined);
  useEffect(() => {
    void getJson<MasterMe & { status: string }>("/master/me").then((m) => {
      setMe(m);
      if (!m) navigate("/master/login", { replace: true });
    });
  }, [navigate]);
  if (me === undefined) return <p>Loading…</p>;
  if (!me) return null;
  const items = SECTIONS.filter((s) => me.permissions[s.perm]);
  return (
    <MeContext.Provider value={me}>
      <div className="bp-master">
        <ManageNav items={items} label="Master sections" />
        <section aria-labelledby="master-page-h" className="bp-master__body">
          <p className="bp-master__who">
            Signed in as {me.display_name} · <Badge tone={me.role === "admin" ? "danger" : "review"}>
              {me.role === "admin" ? "Administrator" : "Moderator"}</Badge>{" "}
            <button type="button" className="bp-btn bp-btn--ghost bp-btn--sm"
              onClick={async () => { await masterLogout(); navigate("/master/login", { replace: true }); }}>
              Sign out
            </button>
          </p>
          <h1 id="master-page-h">{title}</h1>
          {me.permissions[perm] ? children : (
            <Alert tone="warning" title="Not available">Your role cannot open this section.</Alert>
          )}
        </section>
      </div>
    </MeContext.Provider>
  );
}

function useLoad<T>(path: string) {
  const [data, setData] = useState<T | null>(null);
  const [tick, setTick] = useState(0);
  useEffect(() => { void getJson<T>(path).then(setData); }, [path, tick]);
  return { data, reload: () => setTick((t) => t + 1) };
}

function Outcome({ result }: { result: Mutation | null }) {
  if (!result) return null;
  if (result.ok) return <Alert tone="success">Done.</Alert>;
  const map: Record<string, string> = {
    forbidden: "Your role cannot do that.",
    reauth_cancelled: "Cancelled — nothing changed.",
    conflict: "That change is not allowed (for example, removing the last administrator).",
    invalid: "Please check the values and try again.",
  };
  return <Alert tone="danger">{map[result.error ?? ""] ?? "Something went wrong."}</Alert>;
}

/* ─────────────── Overview ─────────────── */

export function MasterOverview() {
  return <MasterShell title="Master administration" perm="view_console"><OverviewBody /></MasterShell>;
}
function OverviewBody() {
  const { data } = useLoad<Record<string, number | boolean>>("/master/overview");
  if (!data) return <p>Loading…</p>;
  const cards: Array<[string, string, string]> = [
    ["Users", String(data.users), "/master/users"],
    ["Suspended users", String(data.suspended_users), "/master/users"],
    ["Adventures", String(data.adventures), "/master/adventures"],
    ["Suspended adventures", String(data.suspended_adventures), "/master/adventures"],
    ["Pending submissions", String(data.pending_submissions), "/master/submissions"],
    ["Open reports", String(data.open_reports), "/master/reports"],
    ["Open escalations", String(data.open_escalations), "/master/activity"],
  ];
  return (
    <>
      {data.maintenance_mode === true && <Alert tone="warning" title="Maintenance mode is on">Only administrators can make changes.</Alert>}
      <dl className="bp-master__stats">
        {cards.map(([label, value, to]) => (
          <div key={label}><dt><Link to={to}>{label}</Link></dt><dd>{value}</dd></div>
        ))}
      </dl>
    </>
  );
}

/* ─────────────── Users ─────────────── */

interface UserRow { id: number; username: string; display_name: string; email: string | null; status: string; role: PlatformRole; created_at: string }

export function MasterUsers() {
  return <MasterShell title="Users" perm="view_users"><UsersBody /></MasterShell>;
}
function UsersBody() {
  const me = useMasterMe()!;
  const [q, setQ] = useState("");
  const { data, reload } = useLoad<{ users: UserRow[] }>(`/master/users?q=${encodeURIComponent(q)}`);
  const { perform, prompt } = useSensitive();
  const [result, setResult] = useState<Mutation | null>(null);
  const act = async (id: number, action: string, body: object = {}) => {
    setResult(await perform(() => masterMutate(`/master/users/${id}/${action}`, "POST", body)));
    reload();
  };
  return (
    <>
      {prompt}
      <label htmlFor="user-q">Search users</label>
      <input id="user-q" type="search" value={q} onChange={(e) => setQ(e.target.value)} />
      <Outcome result={result} />
      <AdminTable<UserRow>
        caption="Accounts"
        rows={data?.users ?? []}
        rowKey={(r) => String(r.id)}
        columns={[
          { key: "name", header: "Name", cell: (r) => <>{r.display_name} <small>@{r.username}</small></> },
          ...(me.role === "admin" ? [{ key: "email", header: "Email", cell: (r: UserRow) => r.email ?? "" }] : []),
          { key: "status", header: "Status", cell: (r) => <Badge tone={r.status === "active" ? "published" : "danger"}>{r.status}</Badge> },
          { key: "role", header: "Role", cell: (r) => me.permissions.manage_roles ? (
            <select aria-label={`Role for ${r.display_name}`} value={r.role}
              onChange={(e) => void act(r.id, "role", { role: e.target.value })}>
              <option value="user">User</option><option value="moderator">Moderator</option><option value="admin">Administrator</option>
            </select>) : r.role },
          { key: "actions", header: "Actions", cell: (r) => (
            <span className="bp-master__actions">
              {me.permissions.suspend_user && (r.status === "suspended"
                ? <Button size="sm" onClick={() => void act(r.id, "restore")}>Restore</Button>
                : <Button size="sm" variant="danger" onClick={() => void act(r.id, "suspend")}>Suspend</Button>)}
              {me.permissions.trigger_reset && <Button size="sm" onClick={() => void act(r.id, "reset")}>Send reset email</Button>}
              {me.permissions.escalate_account && <Button size="sm" variant="ghost" onClick={() => {
                const note = window.prompt("Describe the account issue for administrators");
                if (note) void act(r.id, "escalate", { note });
              }}>Escalate</Button>}
            </span>
          ) },
        ]}
      />
    </>
  );
}

/* ─────────────── Adventures ─────────────── */

interface AdvRow { id: number; slug: string; title: string; state: string; owner_name: string; open_reports: number }

export function MasterAdventures() {
  return <MasterShell title="Adventures" perm="view_adventures"><AdventuresBody /></MasterShell>;
}
function AdventuresBody() {
  const me = useMasterMe()!;
  const [q, setQ] = useState("");
  const { data, reload } = useLoad<{ adventures: AdvRow[] }>(`/master/adventures?q=${encodeURIComponent(q)}`);
  const { perform, prompt } = useSensitive();
  const [result, setResult] = useState<Mutation | null>(null);
  const act = async (id: number, action: string, body: object = {}) => {
    setResult(await perform(() => masterMutate(`/master/adventures/${id}/${action}`, "POST", body)));
    reload();
  };
  return (
    <>
      {prompt}
      <label htmlFor="adv-q">Search adventures</label>
      <input id="adv-q" type="search" value={q} onChange={(e) => setQ(e.target.value)} />
      <Outcome result={result} />
      <AdminTable<AdvRow>
        caption="Adventures"
        rows={data?.adventures ?? []}
        rowKey={(r) => String(r.id)}
        columns={[
          { key: "t", header: "Title", cell: (r) => <Link to={`/adventure/${r.slug}`}>{r.title}</Link> },
          { key: "o", header: "Owner", cell: (r) => r.owner_name },
          { key: "s", header: "State", cell: (r) => <Badge tone={r.state === "suspended" ? "danger" : "published"}>{r.state}</Badge> },
          { key: "r", header: "Open reports", align: "right", cell: (r) => r.open_reports },
          { key: "a", header: "Actions", cell: (r) => (
            <span className="bp-master__actions">
              {r.state === "suspended"
                ? <Button size="sm" onClick={() => void act(r.id, "restore")}>Restore</Button>
                : <Button size="sm" variant="danger" onClick={() => void act(r.id, "suspend")}>Suspend</Button>}
              {me.permissions.transfer_ownership && <Button size="sm" onClick={() => {
                const id = Number(window.prompt("New owner's user number"));
                if (id > 0 && window.confirm(`Transfer "${r.title}" to user ${id}? The current owner becomes an editor.`)) {
                  void act(r.id, "transfer", { user_id: id, confirm: true });
                }
              }}>Transfer ownership</Button>}
            </span>
          ) },
        ]}
      />
    </>
  );
}

/* ─────────────── Submissions ─────────────── */

interface SubRow { id: number; state: string; choice_label: string | null; scene_title: string | null; adventure_slug: string; adventure_title: string; created_at: string }

export function MasterSubmissions() {
  return <MasterShell title="Submissions" perm="view_submissions"><SubmissionsBody /></MasterShell>;
}
function SubmissionsBody() {
  const [state, setState] = useState("pending");
  const { data } = useLoad<{ submissions: SubRow[] }>(`/master/submissions?state=${state}`);
  return (
    <>
      <label htmlFor="sub-state">Show</label>
      <select id="sub-state" value={state} onChange={(e) => setState(e.target.value)}>
        {["pending", "changes_requested", "approved", "rejected", "withdrawn", "all"].map((s) => <option key={s} value={s}>{s.replace("_", " ")}</option>)}
      </select>
      <p>Decisions stay with each adventure's team; open the adventure's management page to review.</p>
      <AdminTable<SubRow>
        caption="Branch submissions across the platform"
        rows={data?.submissions ?? []}
        rowKey={(r) => String(r.id)}
        columns={[
          { key: "a", header: "Adventure", cell: (r) => <Link to={`/manage/${r.adventure_slug}`}>{r.adventure_title}</Link> },
          { key: "c", header: "Choice", cell: (r) => r.choice_label ?? "" },
          { key: "s", header: "New scene", cell: (r) => r.scene_title ?? "" },
          { key: "st", header: "State", cell: (r) => <Badge tone="review">{r.state}</Badge> },
          { key: "d", header: "Received", cell: (r) => new Date(r.created_at).toLocaleDateString() },
        ]}
      />
    </>
  );
}

/* ─────────────── Reports ─────────────── */

interface ReportRow { id: number; target_type: string; scene_id: number | null; scene_title: string | null; scene_state: string | null; reason: string; details: string | null; state: string; adventure_slug: string; adventure_title: string; created_at: string }

export function MasterReports() {
  return <MasterShell title="Reports" perm="review_reports"><ReportsBody /></MasterShell>;
}
function ReportsBody() {
  const [state, setState] = useState("open");
  const { data, reload } = useLoad<{ reports: ReportRow[] }>(`/master/reports?state=${state}`);
  const { perform, prompt } = useSensitive();
  const [result, setResult] = useState<Mutation | null>(null);
  const act = async (id: number, action: string) => {
    setResult(await perform(() => masterMutate(`/master/reports/${id}/${action}`, "POST", {})));
    reload();
  };
  return (
    <>
      {prompt}
      <label htmlFor="rep-state">Show</label>
      <select id="rep-state" value={state} onChange={(e) => setState(e.target.value)}>
        {["open", "resolved", "dismissed", "all"].map((s) => <option key={s} value={s}>{s}</option>)}
      </select>
      <p>Reports never remove content on their own. Reporter identities are not shown.</p>
      <Outcome result={result} />
      <AdminTable<ReportRow>
        caption="Content reports"
        rows={data?.reports ?? []}
        rowKey={(r) => String(r.id)}
        columns={[
          { key: "a", header: "Adventure", cell: (r) => <Link to={`/adventure/${r.adventure_slug}`}>{r.adventure_title}</Link> },
          { key: "t", header: "Target", cell: (r) => r.scene_title ? `${r.target_type}: ${r.scene_title}${r.scene_state === "hidden" ? " (hidden)" : ""}` : r.target_type },
          { key: "r", header: "Reason", cell: (r) => <>{r.reason}{r.details && <><br /><small>{r.details}</small></>}</> },
          { key: "s", header: "State", cell: (r) => <Badge tone={r.state === "open" || r.state === "escalated" ? "warning" : "archived"}>{r.state}</Badge> },
          { key: "x", header: "Actions", cell: (r) => (
            <span className="bp-master__actions">
              <Button size="sm" onClick={() => void act(r.id, "dismiss")}>Dismiss</Button>
              <Button size="sm" onClick={() => void act(r.id, "resolve")}>Resolve</Button>
              {r.scene_id !== null && (r.scene_state === "hidden"
                ? <Button size="sm" onClick={() => void act(r.id, "restore")}>Restore scene</Button>
                : <Button size="sm" variant="danger" onClick={() => void act(r.id, "hide")}>Hide scene</Button>)}
            </span>
          ) },
        ]}
      />
    </>
  );
}

/* ─────────────── Settings ─────────────── */

type Settings = Record<string, Record<string, boolean | number | string>>;
const LABELS: Record<string, string> = {
  registration_enabled: "Allow new registrations", require_email_verification: "Require email verification",
  require_admin_approval: "Require administrator approval", minimum_password_length: "Minimum password length",
  registrations_per_ip_per_hour: "Registrations per address per hour",
  anonymous_reading_allowed: "Allow reading without an account", anonymous_reports_allowed: "Allow reports without an account",
  anonymous_contributions_allowed: "Allow anonymous contributions",
  max_adventures_per_user: "Adventures per user", adventures_per_user_per_hour: "New adventures per user per hour",
  contributions_per_user_per_hour: "Contributions per user per hour", contributions_per_ip_per_hour: "Contributions per address per hour",
  maintenance_mode: "Read-only mode (reading and administrator sign-in still work)", maintenance_message: "Maintenance notice shown to visitors", new_adventures_enabled: "Allow new adventures", contributions_globally_paused: "Pause all contributions",
};
const GROUP_TITLES: Record<string, string> = { registration: "Registration", anonymous: "Anonymous use", limits: "Global limits", maintenance: "Maintenance" };

export function MasterSettings() {
  return <MasterShell title="Settings" perm="configure_registration"><SettingsBody /></MasterShell>;
}
function SettingsBody() {
  const { data, reload } = useLoad<{ settings: Settings }>("/master/settings");
  const [draft, setDraft] = useState<Settings | null>(null);
  const { perform, prompt } = useSensitive();
  const [result, setResult] = useState<Mutation | null>(null);
  useEffect(() => { if (data) setDraft(data.settings); }, [data]);
  if (!draft) return <p>Loading…</p>;
  const save = async (group: string) => {
    setResult(await perform(() => masterMutate(`/master/settings/${group}`, "PUT", draft[group])));
    reload();
  };
  return (
    <>
      {prompt}
      <Outcome result={result} />
      <p><Link to="/master/settings/email">Email server settings</Link> — changing the server, sign-in name or password asks for your password again.</p>
      {Object.entries(draft).map(([group, values]) => (
        <fieldset key={group} className="bp-panel">
          <legend>{GROUP_TITLES[group] ?? group}</legend>
          {Object.entries(values).map(([k, v]) => {
            const id = `set-${k}`;
            const set = (nv: boolean | number | string) => setDraft({ ...draft, [group]: { ...values, [k]: nv } });
            return (
              <p key={k}>
                {typeof v === "boolean" ? (
                  <label htmlFor={id}><input id={id} type="checkbox" checked={v} onChange={(e) => set(e.target.checked)} /> {LABELS[k] ?? k}</label>
                ) : (
                  <>
                    <label htmlFor={id}>{LABELS[k] ?? k}</label>
                    <input id={id} type={typeof v === "number" ? "number" : "text"} value={String(v)}
                      onChange={(e) => set(typeof v === "number" ? Number(e.target.value) : e.target.value)} />
                  </>
                )}
              </p>
            );
          })}
          <Button variant="primary" onClick={() => void save(group)}>Save {GROUP_TITLES[group]?.toLowerCase()}</Button>
        </fieldset>
      ))}
    </>
  );
}

/* ─────────────── Activity ─────────────── */

interface ActRow { id: number; action: string; target_type: string; target_id: number | null; note: string | null; security: boolean; state: string; actor_name: string | null; created_at: string }

export function MasterActivity() {
  return <MasterShell title="Activity" perm="view_activity"><ActivityBody /></MasterShell>;
}
function ActivityBody() {
  const me = useMasterMe()!;
  const [security, setSecurity] = useState(false);
  const { data, reload } = useLoad<{ activity: ActRow[] }>(`/master/activity${security ? "?security=1" : ""}`);
  const close = async (id: number) => { await masterMutate(`/master/activity/${id}/close`, "POST", {}); reload(); };
  return (
    <>
      {me.permissions.view_security_activity && (
        <label htmlFor="act-sec"><input id="act-sec" type="checkbox" checked={security} onChange={(e) => setSecurity(e.target.checked)} /> Security-relevant only</label>
      )}
      <AdminTable<ActRow>
        caption="Platform activity"
        rows={data?.activity ?? []}
        rowKey={(r) => String(r.id)}
        columns={[
          { key: "w", header: "When", cell: (r) => new Date(r.created_at).toLocaleString() },
          { key: "b", header: "By", cell: (r) => r.actor_name ?? "System" },
          { key: "a", header: "Action", cell: (r) => <>{r.action.replace(/_/g, " ")}{r.security && <> <Badge tone="warning">security</Badge></>}</> },
          { key: "t", header: "Target", cell: (r) => r.target_id !== null ? `${r.target_type} ${r.target_id}` : r.target_type },
          { key: "n", header: "Note", cell: (r) => r.note ?? "" },
          { key: "s", header: "Status", cell: (r) => r.state === "open"
            ? (me.permissions.suspend_user ? <Button size="sm" onClick={() => void close(r.id)}>Close escalation</Button> : "Open")
            : "" },
        ]}
      />
    </>
  );
}
