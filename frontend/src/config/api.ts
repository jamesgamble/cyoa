/**
 * Runtime API base URL configuration.
 *
 * The React app talks to a plain PHP backend served under `/api/`.
 *
 * - In development the Vite dev server proxies `/api` to the local
 *   PHP built-in server (see `frontend/vite.config.ts`). Callers use
 *   the relative prefix `/api` so the browser origin is preserved.
 * - In production the PHP entry point at `public/api/index.php` is
 *   served from the same origin as the built React bundle, so the
 *   same relative prefix works.
 *
 * `VITE_API_BASE_URL` may be set to point at a different origin for
 * staging or when running the frontend standalone.
 */
const RAW_BASE = import.meta.env?.VITE_API_BASE_URL as string | undefined;

export const API_BASE_URL: string =
  RAW_BASE && RAW_BASE.trim() !== "" ? RAW_BASE.replace(/\/+$/, "") : "/api";

/** Build a fully qualified URL for an API path (leading slash optional). */
export function apiUrl(path: string): string {
  const suffix = path.startsWith("/") ? path : `/${path}`;
  return `${API_BASE_URL}${suffix}`;
}

/** Convenience: the health endpoint shipped in v0.9.0. */
export const HEALTH_URL = apiUrl("/health");
