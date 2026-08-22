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


/* ------------------------------------------------------------------ */
/* Account dashboard (v0.14.0)                                         */
/*                                                                     */
/* All routes require a live session cookie. Mutating requests re-fetch */
/* the CSRF token per request via the same pattern used elsewhere.     */
/* ------------------------------------------------------------------ */

export interface AccountProfile {
  id: number;
  email: string;
  username: string;
  display_name: string;
  bio: string;
  public_profile: boolean;
  notify_replies: boolean;
  notify_moderation: boolean;
  notify_updates: boolean;
  email_verified_at: string | null;
  password_changed_at: string | null;
  last_login_at: string | null;
  created_at: string;
}

export interface AccountSession {
  id: number;
  created_at: string;
  last_active_at: string;
  expires_at: string;
  user_agent: string;
  is_current: boolean;
}

export interface AccountAdventure {
  id: number;
  slug: string;
  title: string;
  state: string;
  visibility: string;
  contribution_state: string;
  updated_at: string;
}

export interface AccountBookmark {
  id: number;
  kind: string;
  adventure_slug: string;
  adventure_title: string;
  scene_slug: string | null;
  scene_title: string | null;
  created_at: string;
}

interface AccountFetchResult<T> {
  status: number;
  profile?: AccountProfile | null;
  sessions?: AccountSession[];
  adventures?: AccountAdventure[];
  contributions?: unknown[];
  bookmarks?: AccountBookmark[];
  raw?: T;
}

async function accountGet<T extends object>(path: string): Promise<AccountFetchResult<T>> {
  try {
    const res = await fetch(apiUrl(path), {
      credentials: "same-origin",
      headers: { Accept: "application/json" },
    });
    if (!res.ok) return { status: res.status };
    const data = (await res.json()) as Record<string, unknown>;
    return { status: res.status, ...data } as AccountFetchResult<T>;
  } catch {
    return { status: 0 };
  }
}

async function accountMutate<T>(
  path: string,
  method: "POST" | "PUT",
  body: unknown,
): Promise<{ ok: boolean; status: number; data: T | null; fields?: Record<string, string>; error?: string }> {
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
    let parsed: Record<string, unknown> = {};
    try { parsed = (await res.json()) as Record<string, unknown>; } catch { /* empty */ }
    if (res.ok) return { ok: true, status: res.status, data: parsed as T };
    return {
      ok: false, status: res.status, data: null,
      error: typeof parsed.error === "string" ? (parsed.error as string) : undefined,
      fields: (parsed.fields as Record<string, string> | undefined),
    };
  } catch {
    return { ok: false, status: 0, data: null, error: "network_error" };
  }
}

export const fetchAccountProfile     = () => accountGet<{ profile: AccountProfile }>("/account/profile");
export const fetchAccountSecurity    = () => accountGet<{ profile: AccountProfile; sessions: AccountSession[] }>("/account/security");
export const fetchAccountAdventures  = () => accountGet<{ adventures: AccountAdventure[] }>("/account/adventures");
export const fetchAccountContributions = () => accountGet<{ contributions: unknown[] }>("/account/contributions");
export const fetchAccountBookmarks   = () => accountGet<{ bookmarks: AccountBookmark[] }>("/account/bookmarks");

export function updateAccountProfile(input: { display_name: string; bio: string; public_profile: boolean }) {
  return accountMutate<{ status: string }>("/account/profile", "PUT", input);
}
export function updateAccountNotifications(input: {
  notify_replies: boolean; notify_moderation: boolean; notify_updates: boolean;
}) {
  return accountMutate<{ status: string }>("/account/notifications", "PUT", input);
}
export function requestAccountEmailChange(email: string) {
  return accountMutate<{ status: string }>("/account/email-change", "POST", { email });
}
export function confirmAccountEmailChange(token: string) {
  return accountMutate<{ status: string; email: string }>("/account/email-change/confirm", "POST", { token });
}
export function revokeOtherAccountSessions() {
  return accountMutate<{ status: string; revoked: number }>("/account/sessions/revoke-others", "POST", {});
}
export function importAccountLocalProgress(entries: Array<{ slug: string; bookmarks?: string[]; history?: string[] }>) {
  return accountMutate<{ status: string; imported_bookmarks: number; imported_history: number; skipped: number }>(
    "/account/bookmarks/import", "POST", { entries },
  );
}




/* ------------------------------------------------------------------ */
/* Adventure creation (v0.16.0)                                        */
/*                                                                     */
/* Both routes require a live session cookie. The owner of a new       */
/* adventure is always the authenticated caller — the request body     */
/* cannot nominate a different author.                                 */
/* ------------------------------------------------------------------ */

export interface CreationTemplate {
  label: string;
  visibility: "public" | "unlisted";
  contribution_mode: "immediate" | "approval" | "closed";
  anonymous_contributions: boolean;
  max_branches_per_scene: number;
  requires_passcode: boolean;
}

export interface CreationSettings {
  templates: Record<string, CreationTemplate>;
  genres: string[];
  content_ratings: string[];
  visibilities: string[];
  contribution_modes: string[];
  statuses: string[];
  max_branches_min: number;
  max_branches_max: number;
  limits: {
    max_adventures_per_user: number;
    adventures_per_user_per_hour: number;
    owned: number;
    recent: number;
  };
  can_create: boolean;
}

export interface AdventureDraftInput {
  template: string;
  title: string;
  description: string;
  genre: string;
  content_rating: string;
  content_warnings: string[];
  visibility: string;
  opening_title: string;
  opening_body: string;
  status: string;
  contribution_mode: string;
  anonymous_contributions: boolean;
  max_branches_per_scene: number;
  contribution_passcode: string;
  writing_guidelines: string;
}

export interface CreatedAdventure {
  id: number;
  slug: string;
  title: string;
  state: string;
}

export type CreateAdventureOutcome =
  | "ok"
  | "invalid"
  | "unauthenticated"
  | "not_active"
  | "rate_limited"
  | "limit_reached"
  | "csrf_failed"
  | "service_unavailable";

export interface CreateAdventureResult {
  status: number;
  outcome: CreateAdventureOutcome;
  adventure?: CreatedAdventure;
  fields?: Record<string, string>;
}

export async function fetchCreationSettings(): Promise<
  { status: number; settings: CreationSettings | null }
> {
  try {
    const res = await fetch(apiUrl("/adventures/creation-settings"), {
      credentials: "same-origin",
      headers: { Accept: "application/json" },
    });
    if (!res.ok) return { status: res.status, settings: null };
    return { status: res.status, settings: (await res.json()) as CreationSettings };
  } catch {
    return { status: 0, settings: null };
  }
}

export async function createAdventure(
  input: AdventureDraftInput,
): Promise<CreateAdventureResult> {
  const r = await accountMutate<{ status: string; adventure: CreatedAdventure }>(
    "/adventures",
    "POST",
    input,
  );
  if (r.ok && r.data?.adventure) {
    return { status: r.status, outcome: "ok", adventure: r.data.adventure };
  }
  const known: CreateAdventureOutcome[] = [
    "invalid", "unauthenticated", "not_active",
    "rate_limited", "limit_reached", "csrf_failed",
  ];
  const outcome = known.includes(r.error as CreateAdventureOutcome)
    ? (r.error as CreateAdventureOutcome)
    : "service_unavailable";
  return { status: r.status, outcome, fields: r.fields };
}


/* ------------------------------------------------------------------ */
/* Publication workflow (v0.17.0)                                      */
/*                                                                     */
/* Manage, preview, draft saves, and status changes. Every route       */
/* requires a live session; authorization (owner, editor, or           */
/* administrator) is decided server-side and merely reflected here.    */
/* ------------------------------------------------------------------ */

export type AdventureState =
  | "draft" | "published" | "on-hold" | "complete" | "archived" | "suspended";

export type StatusAction =
  | "publish" | "unpublish" | "set_in_progress"
  | "set_complete" | "set_on_hold" | "archive";

export interface ManageAdventure {
  id: number;
  slug: string;
  title: string;
  description: string;
  state: AdventureState;
  visibility: string;
  updated_at: string;
  writing_guidelines: string;
}

export interface ActivityRecord {
  id: number;
  action: string;
  from_state: string | null;
  to_state: string | null;
  actor: string | null;
  created_at: string;
}

export interface ManagePayload {
  adventure: ManageAdventure;
  role: "owner" | "editor" | "administrator";
  read_only: boolean;
  has_valid_opening: boolean;
  available_actions: StatusAction[];
  confirm_actions: StatusAction[];
  activity: ActivityRecord[];
}

export interface PreviewScene {
  id: number;
  slug: string;
  sceneNumber: number;
  chapter: string | null;
  title: string;
  body: string;
  isEnding: boolean;
  state: string;
  isStart: boolean;
}

export interface PreviewPayload {
  adventure: { slug: string; title: string; state: AdventureState };
  role: string;
  noindex: boolean;
  scenes: PreviewScene[];
}

export type ManageOutcome = "ok" | "unauthenticated" | "forbidden" | "not_found" | "error";

export async function fetchManageAdventure(
  slug: string,
): Promise<{ outcome: ManageOutcome; data: ManagePayload | null }> {
  return manageGet<ManagePayload>(`/adventures/${encodeURIComponent(slug)}/manage`);
}

export async function fetchAdventurePreview(
  slug: string,
): Promise<{ outcome: ManageOutcome; data: PreviewPayload | null }> {
  return manageGet<PreviewPayload>(`/adventures/${encodeURIComponent(slug)}/preview`);
}

async function manageGet<T>(
  path: string,
): Promise<{ outcome: ManageOutcome; data: T | null }> {
  try {
    const res = await fetch(apiUrl(path), {
      credentials: "same-origin",
      headers: { Accept: "application/json" },
    });
    if (res.ok) return { outcome: "ok", data: (await res.json()) as T };
    if (res.status === 401) return { outcome: "unauthenticated", data: null };
    if (res.status === 403) return { outcome: "forbidden", data: null };
    if (res.status === 404) return { outcome: "not_found", data: null };
    return { outcome: "error", data: null };
  } catch {
    return { outcome: "error", data: null };
  }
}

export async function changeAdventureStatus(slug: string, action: StatusAction) {
  return accountMutate<{ status: string; state: AdventureState; available_actions?: StatusAction[] }>(
    `/adventures/${encodeURIComponent(slug)}/status`, "POST", { action },
  );
}

export async function saveAdventureDraft(
  slug: string,
  input: { title?: string; description?: string; writing_guidelines?: string; opening_body?: string },
) {
  return accountMutate<{ status: string }>(
    `/adventures/${encodeURIComponent(slug)}/draft`, "PUT", input,
  );
}


/* ------------------------------------------------------------------ */
/* Branch submissions (v0.18.0)                                        */
/*                                                                     */
/* The server owns every rule below — mode, passcode, branch limit,    */
/* rate limit, blocks, sanitisation. `fetchBranchContext` reports what */
/* the form should show; `submitBranch` re-checks all of it anyway.    */
/* ------------------------------------------------------------------ */

export type ContributionMode = "immediate" | "approval" | "closed";
export type BranchAttribution = "username" | "display_name" | "anonymous";
export type BranchSceneType = "story" | "ending";

export interface BranchContext {
  adventure: { slug: string; title: string; state: string; writing_guidelines: string };
  scene: { id: number; slug: string; title: string; published: boolean; locked: boolean };
  contribution_mode: ContributionMode;
  contributions_enabled: boolean;
  requires_passcode: boolean;
  allows_anonymous: boolean;
  signed_in: boolean;
  can_submit: boolean;
  blocked: boolean;
  rate_limited: boolean;
  branch_limit: { limit: number; used: number; remaining: number };
  attribution_options: BranchAttribution[];
  limits: { choice_max: number; title_max: number; body_max: number; note_max: number };
}

export interface BranchDraftInput {
  choice_text: string;
  scene_title: string;
  scene_body: string;
  scene_type: BranchSceneType;
  attribution: BranchAttribution;
  private_note?: string;
  passcode?: string;
  /** Honeypot — always submitted empty by the real form. */
  website?: string;
}

export interface BranchSubmissionResult {
  status: string;
  submission_id: number;
  state: "approved" | "pending";
  mode: ContributionMode;
  published: boolean;
  scene_id: number | null;
  choice_id: number | null;
  attribution: BranchAttribution;
}

export interface ContributionHistoryEntry {
  id: number;
  state: string;
  attribution: BranchAttribution;
  choice_text: string;
  scene_title: string;
  scene_body: string;
  scene_type: BranchSceneType;
  private_note: string | null;
  moderator_note: string | null;
  feedback: string | null;
  revision: number;
  can_edit: boolean;
  can_withdraw: boolean;
  created_at: string;
  adventure_slug: string;
  adventure_title: string;
  source_scene_slug: string;
  source_scene_title: string;
}

export async function fetchBranchContext(
  slug: string,
  sceneRef: string,
  signal?: AbortSignal,
): Promise<BranchContext | null> {
  return getJson<BranchContext>(
    `/adventures/${encodeURIComponent(slug)}/scenes/${encodeURIComponent(sceneRef)}/branch`,
    signal,
  );
}

export function submitBranch(slug: string, sceneRef: string, input: BranchDraftInput) {
  return accountMutate<BranchSubmissionResult>(
    `/adventures/${encodeURIComponent(slug)}/scenes/${encodeURIComponent(sceneRef)}/branch`,
    "POST",
    input,
  );
}

export const fetchContributionHistory = () =>
  accountGet<{ contributions: ContributionHistoryEntry[] }>("/account/contributions");


/* ------------------------------------------------------------------ */
/* Moderation and owner controls (v0.19.0)                             */
/*                                                                     */
/* The management console reads one section at a time. Roles and       */
/* capabilities always arrive from the server — the UI hides controls  */
/* the caller may not use, and the server refuses them regardless.     */
/* ------------------------------------------------------------------ */

export type ManagementSection =
  | "overview" | "story" | "submissions" | "reports" | "collaborators" | "settings";

export type SubmissionState =
  | "pending" | "changes_requested" | "approved" | "rejected" | "withdrawn";

export type DecisionAction = "approve" | "reject" | "request_changes" | "edit_approve";

export type TeamRole = "owner" | "editor" | "reviewer" | "administrator";

export type PermissionLevel = "trusted" | "approval_required" | "blocked";

export interface ModerationCapabilities {
  view: boolean;
  decide: boolean;
  configure: boolean;
  review: boolean;
  read_only: boolean;
}

export interface SubmissionCounts {
  pending: number;
  changes_requested: number;
  approved: number;
  rejected: number;
  withdrawn: number;
  open_reports: number;
}

export interface ModerationOverview {
  adventure: { slug: string; title: string; state: AdventureState };
  role: TeamRole;
  capabilities: ModerationCapabilities;
  sections: ManagementSection[];
  counts: SubmissionCounts;
  settings: AdventureSettings;
  activity: ActivityRecord[];
}

export interface SubmissionReview {
  id: number;
  reviewer: string | null;
  note: string;
  recommendation: "approve" | "reject" | null;
  created_at: string;
}

export interface ModerationSubmission {
  id: number;
  state: SubmissionState;
  choice_text: string;
  scene_title: string;
  scene_body: string;
  scene_type: BranchSceneType;
  attribution: BranchAttribution;
  contributor: string | null;
  private_note: string | null;
  feedback: string | null;
  revision: number;
  created_at: string;
  source_scene_id: number;
  source_scene_title: string;
  reviews: SubmissionReview[];
}

export interface ManagedChoice {
  id: number;
  label: string;
  position: number;
  target_scene_id: number;
  target_title: string | null;
}

export interface ManagedScene {
  id: number;
  slug: string;
  number: number;
  title: string;
  body: string;
  scene_type: BranchSceneType;
  state: string;
  locked: boolean;
  is_start: boolean;
  choices: ManagedChoice[];
  branch_slots: { limit: number; used: number };
}

export interface AdventureSettings {
  contribution_mode: ContributionMode;
  anonymous_contributions: boolean;
  max_branches_per_scene: number;
  requires_passcode: boolean;
  contributions_paused: boolean;
  allow_branching: boolean;
  notify_on_submission: boolean;
  notify_on_report: boolean;
}

export interface AdventurePermission {
  id: number;
  user_id: number;
  username: string | null;
  display_name: string | null;
  level: PermissionLevel;
  note: string | null;
  created_at: string;
}

export interface AdventureCollaborator {
  id: number;
  user_id: number;
  role: "owner" | "editor" | "reviewer";
  username: string | null;
  display_name: string | null;
}

export interface ContentReport {
  id: number;
  reason: string;
  details: string | null;
  state: "open" | "resolved" | "dismissed";
  scene_id: number | null;
  scene_title: string | null;
  reporter: string | null;
  created_at: string;
}

const moderationBase = (slug: string) =>
  `/adventures/${encodeURIComponent(slug)}/moderation`;

export const fetchModerationOverview = (slug: string) =>
  manageGet<ModerationOverview>(moderationBase(slug));

export const fetchModerationSubmissions = (slug: string, state: SubmissionState) =>
  manageGet<{
    role: TeamRole;
    capabilities: ModerationCapabilities;
    state: SubmissionState;
    submissions: ModerationSubmission[];
  }>(`${moderationBase(slug)}/submissions?state=${encodeURIComponent(state)}`);

export const fetchModerationStory = (slug: string) =>
  manageGet<{ role: TeamRole; capabilities: ModerationCapabilities; scenes: ManagedScene[] }>(
    `${moderationBase(slug)}/story`,
  );

export const fetchModerationPermissions = (slug: string) =>
  manageGet<{
    role: TeamRole;
    capabilities: ModerationCapabilities;
    permissions: AdventurePermission[];
    collaborators: AdventureCollaborator[];
    levels: PermissionLevel[];
  }>(`${moderationBase(slug)}/permissions`);

export const fetchModerationReports = (
  slug: string,
  state: "open" | "resolved" | "dismissed" = "open",
) =>
  manageGet<{ role: TeamRole; capabilities: ModerationCapabilities; reports: ContentReport[] }>(
    `${moderationBase(slug)}/reports?state=${encodeURIComponent(state)}`,
  );

export function decideSubmission(
  slug: string,
  submissionId: number,
  action: DecisionAction,
  input: {
    feedback?: string;
    choice_text?: string;
    scene_title?: string;
    scene_body?: string;
    scene_type?: BranchSceneType;
  } = {},
) {
  return accountMutate<{ status: string; state: SubmissionState; action: DecisionAction }>(
    `${moderationBase(slug)}/submissions/${submissionId}/decision`,
    "POST",
    { action, ...input },
  );
}

export const addSubmissionReview = (
  slug: string,
  submissionId: number,
  note: string,
  recommendation: "approve" | "reject" | null,
) =>
  accountMutate<{ status: string; reviews: SubmissionReview[] }>(
    `${moderationBase(slug)}/submissions/${submissionId}/review`,
    "POST",
    { note, recommendation: recommendation ?? "" },
  );

export const updateManagedScene = (
  slug: string,
  sceneId: number,
  input: {
    title?: string;
    body?: string;
    scene_type?: BranchSceneType;
    choices?: Array<{ id: number; label: string; position?: number }>;
  },
) =>
  accountMutate<{ status: string }>(`${moderationBase(slug)}/scenes/${sceneId}`, "PUT", input);

export const runSceneAction = (
  slug: string,
  sceneId: number,
  action: "lock" | "unlock" | "hide" | "restore",
) =>
  accountMutate<{ status: string; state: string; locked: boolean }>(
    `${moderationBase(slug)}/scenes/${sceneId}/action`, "POST", { action },
  );

export const createOwnerBranch = (
  slug: string,
  sceneId: number,
  input: {
    choice_text: string;
    scene_title: string;
    scene_body: string;
    scene_type: BranchSceneType;
  },
) =>
  accountMutate<{ status: string; scene_id: number; choice_id: number }>(
    `${moderationBase(slug)}/scenes/${sceneId}/branch`, "POST", input,
  );

export const updateAdventureDetails = (
  slug: string,
  input: { title?: string; description?: string; writing_guidelines?: string },
) => accountMutate<{ status: string }>(`${moderationBase(slug)}/details`, "PUT", input);

export const updateAdventureSettings = (
  slug: string,
  input: Partial<Omit<AdventureSettings, "requires_passcode">> & {
    contribution_passcode?: string;
  },
) =>
  accountMutate<{ status: string; settings: AdventureSettings }>(
    `${moderationBase(slug)}/settings`, "PUT", input,
  );

export const setAdventurePermission = (
  slug: string,
  userId: number,
  level: PermissionLevel | null,
  note = "",
) =>
  accountMutate<{ status: string; permissions: AdventurePermission[] }>(
    `${moderationBase(slug)}/permissions`, "POST",
    { user_id: userId, level: level ?? "", note },
  );

export const setAdventureCollaborator = (
  slug: string,
  userId: number,
  role: "owner" | "editor" | "reviewer" | null,
) =>
  accountMutate<{ status: string; collaborators: AdventureCollaborator[] }>(
    `${moderationBase(slug)}/collaborators`, "POST",
    { user_id: userId, role: role ?? "" },
  );

export const resolveContentReport = (
  slug: string,
  reportId: number,
  action: "resolve" | "dismiss",
  note = "",
) =>
  accountMutate<{ status: string }>(
    `${moderationBase(slug)}/reports/${reportId}/resolve`, "POST", { action, note },
  );

export const reportAdventureContent = (
  slug: string,
  input: { reason: string; details?: string; scene_id?: number | null },
) => accountMutate<{ status: string; report_id: number }>(
  `/adventures/${encodeURIComponent(slug)}/reports`, "POST", input,
);

/* Contributor-side actions on their own submissions. */

export const updateOwnSubmission = (
  submissionId: number,
  input: {
    choice_text: string;
    scene_title: string;
    scene_body: string;
    scene_type: BranchSceneType;
  },
  resubmit = true,
) =>
  accountMutate<{ status: string; state: SubmissionState }>(
    `/account/contributions/${submissionId}`, "PUT", { ...input, resubmit },
  );

export const withdrawOwnSubmission = (submissionId: number) =>
  accountMutate<{ status: string; state: SubmissionState }>(
    `/account/contributions/${submissionId}/withdraw`, "POST", {},
  );
