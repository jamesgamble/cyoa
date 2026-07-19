/**
 * Public API client (v0.10.0).
 *
 * Read-only fetch helpers for the endpoints exposed under `/api`.
 * Each helper resolves to the parsed payload on success, or `null`
 * when the server returned a non-2xx response (404 for a missing
 * adventure/scene, 503 while the database is offline, etc.).
 *
 * Callers use these to progressively enhance the frontend: pages
 * start with fixture data so tests and offline development still
 * work, then swap to API data once it arrives. See
 * `frontend/src/pages/Discover.tsx` etc. for the pattern.
 */
import { apiUrl } from "../config/api";
import type { AdventureSummary } from "../components/AdventureCard";
import type { Scene } from "../data/scenes";

/** Any fetch/parse failure returns null — pages fall back to fixtures. */
async function getJson<T>(path: string, signal?: AbortSignal): Promise<T | null> {
  try {
    const res = await fetch(apiUrl(path), {
      signal,
      headers: { Accept: "application/json" },
    });
    if (!res.ok) return null;
    return (await res.json()) as T;
  } catch {
    return null;
  }
}

export interface DiscoverResponse {
  adventures: AdventureSummary[];
}
export interface AdventureResponse {
  adventure: AdventureSummary;
}
export interface SceneResponse {
  scene: Scene;
}
export interface OutlineEntry {
  id: string;
  sceneNumber: number;
  chapter: string | null;
  title: string;
  isEnding: boolean;
  isStart: boolean;
  choices: Array<{ label: string; target: string }>;
}
export interface OutlineResponse {
  adventureSlug: string;
  scenes: OutlineEntry[];
}

export async function fetchDiscover(
  signal?: AbortSignal,
): Promise<AdventureSummary[] | null> {
  const data = await getJson<DiscoverResponse>("/adventures", signal);
  return data?.adventures ?? null;
}

export async function fetchAdventure(
  slug: string,
  signal?: AbortSignal,
): Promise<AdventureSummary | null> {
  const data = await getJson<AdventureResponse>(
    `/adventures/${encodeURIComponent(slug)}`,
    signal,
  );
  return data?.adventure ?? null;
}

export async function fetchScene(
  adventureSlug: string,
  sceneId: string,
  signal?: AbortSignal,
): Promise<Scene | null> {
  const data = await getJson<SceneResponse>(
    `/adventures/${encodeURIComponent(adventureSlug)}/scenes/${encodeURIComponent(sceneId)}`,
    signal,
  );
  return data?.scene ?? null;
}

export async function fetchOutline(
  adventureSlug: string,
  signal?: AbortSignal,
): Promise<OutlineResponse | null> {
  return await getJson<OutlineResponse>(
    `/adventures/${encodeURIComponent(adventureSlug)}/outline`,
    signal,
  );
}

/* ------------------------------------------------------------------ */
/* Registration                                                        */
/* ------------------------------------------------------------------ */

export interface RegistrationSettings {
  registration_enabled: boolean;
  minimum_password_length: number;
  requires_email_verification: boolean;
  requires_admin_approval: boolean;
}

export async function fetchRegistrationSettings(
  signal?: AbortSignal,
): Promise<RegistrationSettings | null> {
  return await getJson<RegistrationSettings>("/registration/settings", signal);
}

export async function fetchCsrfToken(
  signal?: AbortSignal,
): Promise<string | null> {
  const data = await getJson<{ token: string }>("/csrf-token", signal);
  return data?.token ?? null;
}

export interface RegistrationInput {
  email: string;
  username: string;
  display_name: string;
  password: string;
  password_confirmation: string;
  terms_accepted: boolean;
  /** Honeypot — must be empty. */
  nickname_url?: string;
}

export interface RegistrationResult {
  status: number;
  outcome:
    | "accepted"
    | "invalid"
    | "rate_limited"
    | "csrf_failed"
    | "registration_disabled"
    | "service_unavailable";
  fields?: Record<string, string>;
}

export async function submitRegistration(
  input: RegistrationInput,
  csrfToken: string,
): Promise<RegistrationResult> {
  try {
    const res = await fetch(apiUrl("/register"), {
      method: "POST",
      credentials: "same-origin",
      headers: {
        "Content-Type": "application/json",
        Accept: "application/json",
        "X-CSRF-Token": csrfToken,
      },
      body: JSON.stringify(input),
    });
    const status = res.status;
    let body: { error?: string; status?: string; fields?: Record<string, string> } = {};
    try {
      body = await res.json();
    } catch {
      /* empty body */
    }
    if (status === 202 || body.status === "accepted") {
      return { status, outcome: "accepted" };
    }
    if (status === 422) {
      return { status, outcome: "invalid", fields: body.fields ?? {} };
    }
    if (status === 429) return { status, outcome: "rate_limited" };
    if (status === 403 && body.error === "csrf_failed") {
      return { status, outcome: "csrf_failed" };
    }
    if (status === 403 && body.error === "registration_disabled") {
      return { status, outcome: "registration_disabled" };
    }
    return { status, outcome: "service_unavailable" };
  } catch {
    return { status: 0, outcome: "service_unavailable" };
  }
}


/* ------------------------------------------------------------------ */
/* Master (administrator) console                                      */
/* ------------------------------------------------------------------ */

/**
 * v0.12.0 — client helpers for the master console.
 *
 * Every mutating request re-fetches a CSRF token first; this matches
 * the server-side double-submit check and avoids stashing tokens in
 * component state where they might leak into logs or error reports.
 * The password field is redacted server-side, so the settings form
 * receives the sentinel string `__unchanged__` — see SmtpSettings.
 */

export interface SmtpSettings {
  host: string;
  port: number;
  encryption: "none" | "starttls" | "tls";
  username: string;
  /** Redacted sentinel from the server: "__unchanged__" or "". */
  password: string;
  from_email: string;
  from_name: string;
  reply_to: string;
  enabled: boolean;
  retry_limit: number;
  batch_size: number;
  has_password: boolean;
  updated_at: string;
}
export const PASSWORD_UNCHANGED = "__unchanged__" as const;

export interface QueueMessage {
  id: number;
  template_key: string;
  to_email: string;
  status: "pending" | "sending" | "sent" | "failed" | "cancelled";
  attempts: number;
  last_error: string | null;
  next_attempt_at: string;
  sent_at: string | null;
  created_at: string;
}
export interface QueueResponse {
  counts: Record<string, number>;
  messages: QueueMessage[];
}

async function masterMutate<T>(
  path: string,
  method: "POST" | "PUT",
  body: unknown,
): Promise<{ ok: boolean; status: number; data: T | null; error?: string; fields?: Record<string, string> }> {
  const token = await fetchCsrfToken();
  if (!token) return { ok: false, status: 0, data: null, error: "csrf_unavailable" };
  try {
    const res = await fetch(apiUrl(path), {
      method,
      credentials: "same-origin",
      headers: {
        "Content-Type": "application/json",
        Accept: "application/json",
        "X-CSRF-Token": token,
      },
      body: JSON.stringify(body ?? {}),
    });
    let parsed: unknown = null;
    try { parsed = await res.json(); } catch { /* empty */ }
    if (res.ok) return { ok: true, status: res.status, data: parsed as T };
    const err = (parsed as { error?: string } | null)?.error;
    const fields = (parsed as { fields?: Record<string, string> } | null)?.fields;
    return { ok: false, status: res.status, data: null, error: err, fields };
  } catch {
    return { ok: false, status: 0, data: null, error: "network_error" };
  }
}

export async function fetchMasterSession(): Promise<{ authenticated: boolean; user_id: number | null }> {
  const data = await getJson<{ authenticated: boolean; user_id: number | null }>("/master/session");
  return data ?? { authenticated: false, user_id: null };
}

export async function masterLogin(email: string, password: string) {
  return masterMutate<{ status: string; user_id: number }>("/master/login", "POST", { email, password });
}

export async function masterLogout() {
  return masterMutate<{ status: string }>("/master/logout", "POST", {});
}

export async function fetchSmtpSettings(): Promise<SmtpSettings | null> {
  const data = await getJson<{ settings: SmtpSettings }>("/master/settings/email");
  return data?.settings ?? null;
}

export async function saveSmtpSettings(settings: SmtpSettings) {
  return masterMutate<{ status: string }>("/master/settings/email", "PUT", settings);
}

export async function sendTestEmail(to: string) {
  return masterMutate<{ status: string }>("/master/settings/email/test", "POST", { to });
}

export async function fetchEmailQueue(status?: string): Promise<QueueResponse | null> {
  const qs = status ? `?status=${encodeURIComponent(status)}` : "";
  return await getJson<QueueResponse>(`/master/email-queue${qs}`);
}

export async function cancelQueuedMessage(id: number) {
  return masterMutate<{ status: string }>(`/master/email-queue/${id}/cancel`, "POST", {});
}


/* ------------------------------------------------------------------ */
/* Authentication (v0.13.0)                                            */
/*                                                                     */
/* All mutating requests are protected by the double-submit CSRF       */
/* cookie: this module fetches a fresh token first and sends it in     */
/* X-CSRF-Token. On success, the session cookie (`bp_session`) is set  */
/* by the server as HttpOnly, SameSite=Lax; JavaScript cannot read it, */
/* and every subsequent request includes it via `credentials`.         */
/* ------------------------------------------------------------------ */

export type AuthOutcome =
  | "ok"
  | "invalid"
  | "pending_verification"
  | "suspended"
  | "not_active"
  | "token_invalid"
  | "csrf_failed"
  | "rate_limited"
  | "unauthenticated"
  | "service_unavailable";

export interface AuthResult {
  status: number;
  outcome: AuthOutcome;
  redirect?: string;
  fields?: Record<string, string>;
}

async function authMutate(
  path: string,
  body: unknown,
): Promise<AuthResult> {
  const token = await fetchCsrfToken();
  if (!token) return { status: 0, outcome: "csrf_failed" };
  try {
    const res = await fetch(apiUrl(path), {
      method: "POST",
      credentials: "same-origin",
      headers: {
        "Content-Type": "application/json",
        Accept: "application/json",
        "X-CSRF-Token": token,
      },
      body: JSON.stringify(body ?? {}),
    });
    let parsed: {
      status?: string; error?: string; redirect?: string;
      fields?: Record<string, string>;
    } = {};
    try { parsed = await res.json(); } catch { /* empty */ }
    if (res.ok && (parsed.status === "ok" || res.status === 200)) {
      return { status: res.status, outcome: "ok", redirect: parsed.redirect };
    }
    const err = parsed.error ?? "service_unavailable";
    // Map server outcomes to the union above.
    const outcome: AuthOutcome =
      err === "invalid" || err === "pending_verification" || err === "suspended"
        || err === "not_active" || err === "token_invalid" || err === "csrf_failed"
        || err === "rate_limited" || err === "unauthenticated"
        ? err as AuthOutcome
        : "service_unavailable";
    return { status: res.status, outcome, fields: parsed.fields };
  } catch {
    return { status: 0, outcome: "service_unavailable" };
  }
}

export async function fetchAuthSession(): Promise<{ authenticated: boolean; user_id: number | null }> {
  const data = await getJson<{ authenticated: boolean; user_id: number | null }>("/auth/session");
  return data ?? { authenticated: false, user_id: null };
}

export async function submitLogin(email: string, password: string, redirect?: string) {
  return authMutate("/auth/login", { email, password, redirect });
}

export async function submitLogout() {
  return authMutate("/auth/logout", {});
}

export async function submitVerifyEmail(token: string) {
  return authMutate("/auth/verify-email", { token });
}

export async function submitResendVerification(email: string) {
  return authMutate("/auth/resend-verification", { email });
}

export async function submitForgotPassword(email: string) {
  return authMutate("/auth/forgot-password", { email });
}

export async function submitResetPassword(token: string, password: string, password_confirmation: string) {
  return authMutate("/auth/reset-password", { token, password, password_confirmation });
}

export async function submitChangePassword(current_password: string, new_password: string, new_password_confirmation: string) {
  return authMutate("/auth/change-password", { current_password, new_password, new_password_confirmation });
}

