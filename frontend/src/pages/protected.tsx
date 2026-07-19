import { useEffect, useState, type FormEvent } from "react";
import { Link, useNavigate, useLocation, useParams } from "react-router-dom";
import { UnauthorizedState } from "../states";
import {
  fetchMasterSession,
  masterLogin,
  masterLogout,
  fetchSmtpSettings,
  saveSmtpSettings,
  sendTestEmail,
  fetchEmailQueue,
  cancelQueuedMessage,
  PASSWORD_UNCHANGED,
  type SmtpSettings,
  type QueueResponse,
} from "../lib/apiClient";

/*
 * v0.12.0 — Master (administrator) console pages.
 *
 * These pages talk to /api/master/*. Every request that mutates state
 * flows through masterMutate() in apiClient.ts, which fetches a fresh
 * CSRF token first and sets X-CSRF-Token. The password field is never
 * displayed — a redacted sentinel from the server indicates whether a
 * password is on file so the operator knows to leave it alone or
 * rotate it.
 */

// v0.14.0 — the /account routes moved to `AccountPages.tsx`. Manage
// remains here until authoring flows arrive in a later release.

export function Manage() {
  const { slug } = useParams();
  return (
    <section>
      <h1>Manage {slug ?? "adventure"}</h1>
      <UnauthorizedState />
    </section>
  );
}


/* ---------------- MasterLogin ---------------- */

export function MasterLogin() {
  const navigate = useNavigate();
  const location = useLocation();
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    void fetchMasterSession().then((s) => {
      if (s.authenticated) navigate("/master", { replace: true });
    });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    setError(null);
    setBusy(true);
    const result = await masterLogin(email, password);
    setBusy(false);
    if (result.ok) {
      const dest = new URLSearchParams(location.search).get("next") ?? "/master";
      navigate(dest, { replace: true });
      return;
    }
    if (result.status === 401) setError("Incorrect email or password, or your account has no administrator role.");
    else if (result.status === 403) setError("Session validation failed — reload and try again.");
    else setError("Sign in is temporarily unavailable.");
  }

  return (
    <section aria-labelledby="master-login-h">
      <h1 id="master-login-h">Master sign in</h1>
      <p>
        This page is only for site administrators. Sign in with the account you
        created via <code>scripts/bootstrap-admin.php</code>.
      </p>
      <form onSubmit={onSubmit} noValidate>
        <label>
          <span>Email</span>
          <input
            type="email"
            autoComplete="username"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            required
          />
        </label>
        <label>
          <span>Password</span>
          <input
            type="password"
            autoComplete="current-password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            required
          />
        </label>
        {error && (
          <p role="alert" className="validation-message">
            {error}
          </p>
        )}
        <button type="submit" disabled={busy}>
          {busy ? "Signing in…" : "Sign in"}
        </button>
      </form>
    </section>
  );
}

/* ---------------- Master index ---------------- */

export function Master() {
  const [ready, setReady] = useState(false);
  const [authed, setAuthed] = useState(false);
  const navigate = useNavigate();

  useEffect(() => {
    void fetchMasterSession().then((s) => {
      setAuthed(s.authenticated);
      setReady(true);
      if (!s.authenticated) navigate("/master/login", { replace: true });
    });
  }, [navigate]);

  return (
    <section aria-labelledby="master-h">
      <h1 id="master-h">Master administration</h1>
      {!ready ? (
        <p>Loading…</p>
      ) : !authed ? (
        <UnauthorizedState />
      ) : (
        <>
          <nav aria-label="Master sections">
            <ul>
              <li><Link to="/master/settings/email">Email settings</Link></li>
              <li><Link to="/master/email-queue">Email queue</Link></li>
            </ul>
          </nav>
          <button
            type="button"
            onClick={async () => {
              await masterLogout();
              navigate("/master/login", { replace: true });
            }}
          >
            Sign out
          </button>
        </>
      )}
    </section>
  );
}


/* ---------------- Email settings ---------------- */

const emptySettings: SmtpSettings = {
  host: "", port: 587, encryption: "starttls", username: "", password: "",
  from_email: "", from_name: "", reply_to: "", enabled: false,
  retry_limit: 5, batch_size: 25, has_password: false, updated_at: "",
};

export function MasterEmailSettings() {
  const navigate = useNavigate();
  const [ready, setReady] = useState(false);
  const [settings, setSettings] = useState<SmtpSettings>(emptySettings);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saveMsg, setSaveMsg] = useState<string | null>(null);
  const [testTo, setTestTo] = useState("");
  const [testMsg, setTestMsg] = useState<string | null>(null);

  useEffect(() => {
    void (async () => {
      const s = await fetchMasterSession();
      if (!s.authenticated) { navigate("/master/login", { replace: true }); return; }
      const cfg = await fetchSmtpSettings();
      if (cfg) setSettings(cfg);
      setReady(true);
    })();
  }, [navigate]);

  async function onSave(e: FormEvent) {
    e.preventDefault();
    setErrors({}); setSaveMsg(null);
    const result = await saveSmtpSettings(settings);
    if (result.ok) {
      setSaveMsg("Settings saved.");
      // Re-fetch to reset the password field to the redacted sentinel.
      const fresh = await fetchSmtpSettings();
      if (fresh) setSettings(fresh);
    } else if (result.status === 422 && result.fields) {
      setErrors(result.fields);
      setSaveMsg("Fix the highlighted fields.");
    } else {
      setSaveMsg("Could not save — please try again.");
    }
  }

  async function onTest() {
    setTestMsg(null);
    if (!testTo) { setTestMsg("Enter a recipient first."); return; }
    const r = await sendTestEmail(testTo);
    setTestMsg(r.ok ? `Queued test message for ${testTo}.` : "Could not queue test message.");
  }

  if (!ready) return <p>Loading…</p>;

  return (
    <section aria-labelledby="email-settings-h">
      <h1 id="email-settings-h">Email settings</h1>
      <p>
        These credentials are used by the queued email worker. Passwords are
        encrypted at rest with the application key stored outside SQLite and
        are never returned to the browser.
      </p>
      <form onSubmit={onSave} noValidate>
        <Field label="Host" name="host" error={errors.host}>
          <input value={settings.host} onChange={(e) => setSettings({ ...settings, host: e.target.value })} />
        </Field>
        <Field label="Port" name="port" error={errors.port}>
          <input type="number" value={settings.port}
                 onChange={(e) => setSettings({ ...settings, port: parseInt(e.target.value, 10) || 0 })} />
        </Field>
        <Field label="Encryption" name="encryption" error={errors.encryption}>
          <select value={settings.encryption}
                  onChange={(e) => setSettings({ ...settings, encryption: e.target.value as SmtpSettings["encryption"] })}>
            <option value="starttls">STARTTLS</option>
            <option value="tls">TLS</option>
            <option value="none">None</option>
          </select>
        </Field>
        <Field label="Username" name="username" error={errors.username}>
          <input value={settings.username} onChange={(e) => setSettings({ ...settings, username: e.target.value })} />
        </Field>
        <Field
          label={settings.has_password ? "Password (stored — leave to keep)" : "Password"}
          name="password"
          error={errors.password}
        >
          <input
            type="password"
            autoComplete="new-password"
            value={settings.password === PASSWORD_UNCHANGED ? "" : settings.password}
            placeholder={settings.has_password ? "•••••••• (unchanged)" : ""}
            onChange={(e) => setSettings({ ...settings, password: e.target.value })}
          />
          {settings.has_password && (
            <button type="button" onClick={() => setSettings({ ...settings, password: "" })}>
              Clear stored password
            </button>
          )}
        </Field>
        <Field label="From email" name="from_email" error={errors.from_email}>
          <input type="email" value={settings.from_email}
                 onChange={(e) => setSettings({ ...settings, from_email: e.target.value })} />
        </Field>
        <Field label="From name" name="from_name" error={errors.from_name}>
          <input value={settings.from_name} onChange={(e) => setSettings({ ...settings, from_name: e.target.value })} />
        </Field>
        <Field label="Reply-to" name="reply_to" error={errors.reply_to}>
          <input type="email" value={settings.reply_to}
                 onChange={(e) => setSettings({ ...settings, reply_to: e.target.value })} />
        </Field>
        <Field label="Retry limit" name="retry_limit" error={errors.retry_limit}>
          <input type="number" value={settings.retry_limit}
                 onChange={(e) => setSettings({ ...settings, retry_limit: parseInt(e.target.value, 10) || 0 })} />
        </Field>
        <Field label="Batch size" name="batch_size" error={errors.batch_size}>
          <input type="number" value={settings.batch_size}
                 onChange={(e) => setSettings({ ...settings, batch_size: parseInt(e.target.value, 10) || 0 })} />
        </Field>
        <label>
          <input type="checkbox" checked={settings.enabled}
                 onChange={(e) => setSettings({ ...settings, enabled: e.target.checked })} />
          <span>Enabled — the worker will send queued messages</span>
        </label>
        {saveMsg && <p role="status">{saveMsg}</p>}
        <button type="submit">Save settings</button>
      </form>

      <hr />
      <h2>Send a test message</h2>
      <p>Queues the <code>operator_test</code> template. The worker delivers it on its next run.</p>
      <label>
        <span>Recipient</span>
        <input type="email" value={testTo} onChange={(e) => setTestTo(e.target.value)} />
      </label>
      <button type="button" onClick={onTest}>Send test</button>
      {testMsg && <p role="status">{testMsg}</p>}
    </section>
  );
}

function Field({
  label, name, error, children,
}: { label: string; name: string; error?: string; children: React.ReactNode }) {
  return (
    <div>
      <label htmlFor={name}>{label}</label>
      <div>{children}</div>
      {error && <p role="alert" className="validation-message" data-field={name}>{error}</p>}
    </div>
  );
}

/* ---------------- Email queue ---------------- */

export function MasterEmailQueue() {
  const navigate = useNavigate();
  const [ready, setReady] = useState(false);
  const [data, setData] = useState<QueueResponse | null>(null);
  const [filter, setFilter] = useState<string>("");

  async function refresh() {
    const q = await fetchEmailQueue(filter || undefined);
    setData(q);
  }

  useEffect(() => {
    void (async () => {
      const s = await fetchMasterSession();
      if (!s.authenticated) { navigate("/master/login", { replace: true }); return; }
      await refresh();
      setReady(true);
    })();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [navigate]);

  useEffect(() => { if (ready) void refresh(); /* eslint-disable-next-line */ }, [filter]);

  async function onCancel(id: number) {
    await cancelQueuedMessage(id);
    await refresh();
  }

  if (!ready) return <p>Loading…</p>;

  return (
    <section aria-labelledby="queue-h">
      <h1 id="queue-h">Email queue</h1>
      {data && (
        <p>
          Pending {data.counts.pending ?? 0} • Sending {data.counts.sending ?? 0}
          • Sent {data.counts.sent ?? 0} • Failed {data.counts.failed ?? 0}
          • Cancelled {data.counts.cancelled ?? 0}
        </p>
      )}
      <label>
        <span>Filter status</span>
        <select value={filter} onChange={(e) => setFilter(e.target.value)}>
          <option value="">All</option>
          <option value="pending">Pending</option>
          <option value="sending">Sending</option>
          <option value="sent">Sent</option>
          <option value="failed">Failed</option>
          <option value="cancelled">Cancelled</option>
        </select>
      </label>
      <table>
        <thead>
          <tr>
            <th>#</th><th>Template</th><th>Recipient</th><th>Status</th>
            <th>Attempts</th><th>Last error</th><th>Next attempt</th><th></th>
          </tr>
        </thead>
        <tbody>
          {(data?.messages ?? []).map((m) => (
            <tr key={m.id}>
              <td>{m.id}</td>
              <td>{m.template_key}</td>
              <td>{m.to_email}</td>
              <td>{m.status}</td>
              <td>{m.attempts}</td>
              <td>{m.last_error ?? "—"}</td>
              <td>{m.next_attempt_at}</td>
              <td>
                {m.status === "pending" && (
                  <button type="button" onClick={() => onCancel(m.id)}>Cancel</button>
                )}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
      {data?.messages.length === 0 && <p>No messages match this filter yet.</p>}
    </section>
  );
}
