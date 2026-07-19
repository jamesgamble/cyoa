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
