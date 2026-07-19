import { useEffect, useState } from "react";

/**
 * Local reading progress for a given adventure slug.
 *
 * Progress is intentionally client-only: reading does not require an
 * account, and the resume marker is scoped to the visitor's browser.
 * Later releases can layer server-side progress on top for signed-in
 * readers; the storage key is stable so a migration can read it.
 */
const KEY_PREFIX = "bp-progress:";

export interface LocalProgress {
  /** Slug of the last scene the visitor was reading. */
  sceneSlug: string;
  /** ISO-8601 timestamp when the marker was last written. */
  updatedAtIso: string;
}

export function progressKey(adventureSlug: string): string {
  return KEY_PREFIX + adventureSlug;
}

export function readLocalProgress(
  adventureSlug: string,
): LocalProgress | null {
  if (typeof window === "undefined") return null;
  try {
    const raw = window.localStorage.getItem(progressKey(adventureSlug));
    if (!raw) return null;
    const parsed = JSON.parse(raw) as Partial<LocalProgress>;
    if (
      parsed &&
      typeof parsed.sceneSlug === "string" &&
      typeof parsed.updatedAtIso === "string"
    ) {
      return { sceneSlug: parsed.sceneSlug, updatedAtIso: parsed.updatedAtIso };
    }
    return null;
  } catch {
    return null;
  }
}

/** React hook wrapper. Re-reads storage when the slug changes. */
export function useLocalProgress(
  adventureSlug: string,
): LocalProgress | null {
  const [progress, setProgress] = useState<LocalProgress | null>(() =>
    readLocalProgress(adventureSlug),
  );
  useEffect(() => {
    setProgress(readLocalProgress(adventureSlug));
  }, [adventureSlug]);
  return progress;
}
