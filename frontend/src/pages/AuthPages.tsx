import { useEffect, useState, type FormEvent } from "react";
import { Link, useNavigate, useLocation, useSearchParams } from "react-router-dom";
import {
  fetchAuthSession,
  submitLogin,
  submitLogout,
  submitVerifyEmail,
  submitResendVerification,
  submitForgotPassword,
  submitResetPassword,
  submitChangePassword,
  type AuthOutcome,
} from "../lib/apiClient";

/*
 * v0.13.0 — public authentication pages.
 *
 * These pages sit at:
 *   /login           — email + password sign-in with next-URL support
 *   /forgot-password — request a reset email (enumeration-safe response)
 *   /reset-password  — consume a reset token and set a new password
 *   /verify          — consume a verification token
 *   /change-password — authenticated password change
 *
 * Every mutating call routes through `authMutate` in apiClient.ts,
 * which fetches a fresh CSRF token per request. Session cookies are
 * HttpOnly so we never touch them from JavaScript; presence of a
 * session is discovered through GET /auth/session.
 */

/** Client-side allow-list mirror of AuthService::isSafeRedirect. */
function safeRedirect(candidate: string | null | undefined): string | null {
  if (!candidate) return null;
  if (candidate[0] !== "/") return null;
  if (candidate.length >= 2 && (candidate[1] === "/" || candidate[1] === "\\")) return null;
  if (candidate.includes("\\")) return null;
  if (/^\/[^/]*:/.test(candidate)) return null;
  return candidate;
}

function outcomeMessage(o: AuthOutcome): string {
  switch (o) {
    case "invalid": return "Incorrect email or password.";
    case "pending_verification": return "Please verify your email address before signing in.";
    case "suspended": return "This account is suspended. Contact an administrator.";
    case "not_active": return "This account cannot sign in.";
    case "token_invalid": return "This link is invalid or has already been used.";
    case "csrf_failed": return "Session validation failed — reload the page and try again.";
    case "rate_limited": return "Too many attempts. Try again later.";
    case "unauthenticated": return "You need to sign in first.";
    case "service_unavailable": return "The service is temporarily unavailable.";
    default: return "Something went wrong.";
  }
}

/* ─────────────────────────── Login ────────────────────────────── */

export function Login() {
  const navigate = useNavigate();
  const location = useLocation();
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const nextParam = new URLSearchParams(location.search).get("next");
  const redirectTo = safeRedirect(nextParam) ?? "/";

  useEffect(() => {
    void fetchAuthSession().then((s) => {
      if (s.authenticated) navigate(redirectTo, { replace: true });
    });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    setError(null);
    setBusy(true);
    const result = await submitLogin(email, password, redirectTo);
    setBusy(false);
    if (result.outcome === "ok") {
      navigate(safeRedirect(result.redirect ?? null) ?? redirectTo, { replace: true });
      return;
    }
    setError(outcomeMessage(result.outcome));
  }

  return (
    <section>
      <h1>Sign in</h1>
      <p>Reading Branching Paths adventures does not require an account. Sign in to bookmark, contribute, and manage your work.</p>
      {error && <p role="alert" aria-live="polite">{error}</p>}
      <form onSubmit={onSubmit} noValidate>
        <label>
          Email
          <input type="email" name="email" autoComplete="email" required
                 value={email} onChange={(e) => setEmail(e.target.value)} />
        </label>
        <label>
          Password
          <input type="password" name="password" autoComplete="current-password" required
                 value={password} onChange={(e) => setPassword(e.target.value)} />
        </label>
        <button type="submit" disabled={busy}>{busy ? "Signing in…" : "Sign in"}</button>
      </form>
      <p><Link to="/forgot-password">Forgot your password?</Link></p>
      <p><Link to="/register">Create an account</Link></p>
    </section>
  );
}

/* ─────────────────── Forgot password ─────────────────── */

export function ForgotPassword() {
  const [email, setEmail] = useState("");
  const [busy, setBusy] = useState(false);
  const [done, setDone] = useState(false);

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    await submitForgotPassword(email);
    setBusy(false);
    setDone(true);
  }

  return (
    <section>
      <h1>Reset your password</h1>
      {done ? (
        <p role="status">
          If an active account uses that address, we sent instructions for choosing
          a new password. The link expires within an hour.
        </p>
      ) : (
        <form onSubmit={onSubmit} noValidate>
          <p>Tell us the email address you registered with and we&rsquo;ll send you a link.</p>
          <label>
            Email
            <input type="email" name="email" autoComplete="email" required
                   value={email} onChange={(e) => setEmail(e.target.value)} />
          </label>
          <button type="submit" disabled={busy}>{busy ? "Sending…" : "Send reset link"}</button>
        </form>
      )}
      <p><Link to="/login">Return to sign in</Link></p>
    </section>
  );
}

/* ─────────────────── Reset password ─────────────────── */

export function ResetPassword() {
  const [params] = useSearchParams();
  const token = params.get("token") ?? "";
  const [password, setPassword] = useState("");
  const [confirmation, setConfirmation] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [done, setDone] = useState(false);

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    setError(null);
    setBusy(true);
    const result = await submitResetPassword(token, password, confirmation);
    setBusy(false);
    if (result.outcome === "ok") { setDone(true); return; }
    setError(outcomeMessage(result.outcome));
  }

  if (!token) {
    return (
      <section>
        <h1>Reset password</h1>
        <p role="alert">This link is missing its token. Request a new one from the sign-in page.</p>
        <p><Link to="/forgot-password">Send a new reset link</Link></p>
      </section>
    );
  }

  if (done) {
    return (
      <section>
        <h1>Password updated</h1>
        <p>Your password has been changed and every previous session has been signed out.</p>
        <p><Link to="/login">Sign in with your new password</Link></p>
      </section>
    );
  }

  return (
    <section>
      <h1>Choose a new password</h1>
      {error && <p role="alert" aria-live="polite">{error}</p>}
      <form onSubmit={onSubmit} noValidate>
        <label>
          New password
          <input type="password" name="password" autoComplete="new-password" required
                 minLength={12}
                 value={password} onChange={(e) => setPassword(e.target.value)} />
        </label>
        <label>
          Confirm new password
          <input type="password" name="password_confirmation" autoComplete="new-password" required
                 minLength={12}
                 value={confirmation} onChange={(e) => setConfirmation(e.target.value)} />
        </label>
        <button type="submit" disabled={busy}>{busy ? "Updating…" : "Update password"}</button>
      </form>
    </section>
  );
}

/* ─────────────────── Verify email ─────────────────── */

export function VerifyEmail() {
  const [params] = useSearchParams();
  const token = params.get("token") ?? "";
  const [state, setState] = useState<"idle" | "busy" | "ok" | "invalid">("idle");
  const [resendEmail, setResendEmail] = useState("");
  const [resent, setResent] = useState(false);

  useEffect(() => {
    if (!token) return;
    setState("busy");
    void submitVerifyEmail(token).then((r) => {
      setState(r.outcome === "ok" ? "ok" : "invalid");
    });
  }, [token]);

  async function onResend(e: FormEvent) {
    e.preventDefault();
    await submitResendVerification(resendEmail);
    setResent(true);
  }

  if (!token) {
    return (
      <section>
        <h1>Verify your email</h1>
        <p>Open the link we sent to your inbox to confirm your address. Missed it?</p>
        {resent ? (
          <p role="status">
            If an unverified account uses that address, we sent a new verification link.
          </p>
        ) : (
          <form onSubmit={onResend} noValidate>
            <label>
              Email
              <input type="email" autoComplete="email" required
                     value={resendEmail} onChange={(e) => setResendEmail(e.target.value)} />
            </label>
            <button type="submit">Send a new verification link</button>
          </form>
        )}
      </section>
    );
  }

  return (
    <section>
      <h1>Verify your email</h1>
      {state === "busy" && <p role="status">Verifying…</p>}
      {state === "ok" && (
        <>
          <p>Your address is now confirmed and your account is active.</p>
          <p><Link to="/login">Sign in</Link></p>
        </>
      )}
      {state === "invalid" && (
        <>
          <p role="alert">This verification link is invalid, has expired, or has already been used.</p>
          <p><Link to="/verify">Send a new verification link</Link></p>
        </>
      )}
    </section>
  );
}

/* ─────────────────── Change password ─────────────────── */

export function ChangePassword() {
  const navigate = useNavigate();
  const [ready, setReady] = useState(false);
  const [current, setCurrent] = useState("");
  const [next, setNext] = useState("");
  const [confirmation, setConfirmation] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [done, setDone] = useState(false);

  useEffect(() => {
    void fetchAuthSession().then((s) => {
      if (!s.authenticated) navigate("/login?next=/change-password", { replace: true });
      else setReady(true);
    });
  }, [navigate]);

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    setError(null);
    setBusy(true);
    const r = await submitChangePassword(current, next, confirmation);
    setBusy(false);
    if (r.outcome === "ok") { setDone(true); return; }
    setError(outcomeMessage(r.outcome));
  }

  async function onSignOut() {
    await submitLogout();
    navigate("/", { replace: true });
  }

  if (!ready) return <section><h1>Change password</h1><p role="status">Loading…</p></section>;

  return (
    <section>
      <h1>Change password</h1>
      {done ? (
        <>
          <p>Your password was updated. All previous sessions have been signed out.</p>
          <button type="button" onClick={onSignOut}>Sign out on this device too</button>
        </>
      ) : (
        <>
          {error && <p role="alert" aria-live="polite">{error}</p>}
          <form onSubmit={onSubmit} noValidate>
            <label>
              Current password
              <input type="password" autoComplete="current-password" required
                     value={current} onChange={(e) => setCurrent(e.target.value)} />
            </label>
            <label>
              New password
              <input type="password" autoComplete="new-password" required minLength={12}
                     value={next} onChange={(e) => setNext(e.target.value)} />
            </label>
            <label>
              Confirm new password
              <input type="password" autoComplete="new-password" required minLength={12}
                     value={confirmation} onChange={(e) => setConfirmation(e.target.value)} />
            </label>
            <button type="submit" disabled={busy}>{busy ? "Updating…" : "Update password"}</button>
          </form>
        </>
      )}
    </section>
  );
}
