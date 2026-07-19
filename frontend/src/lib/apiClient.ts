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

