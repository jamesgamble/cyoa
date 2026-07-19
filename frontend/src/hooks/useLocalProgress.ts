import { useCallback, useEffect, useState } from "react";

/**
 * Local reading progress for a given adventure slug.
 *
 * Progress is intentionally client-only: reading does not require an
 * account, and the resume marker plus reading history are scoped to the
 * visitor's browser. Later releases can layer server-side progress on top
 * for signed-in readers; the storage key and shape are stable so a
 * migration can read them.
 *
 * v0.7.0 extends the record with:
 *   - `history`: scene-id trail powering the reader's Back button.
 *   - `bookmarks`: scenes the reader flagged to revisit.
 *   - `discoveredEndings`: ids of ending scenes the reader has reached.
 *
 * The older { sceneSlug, updatedAtIso } shape written in v0.6.0 is still
 * accepted so a returning reader keeps their resume marker.
 */
const KEY_PREFIX = "bp-progress:";

export interface LocalProgress {
  /** Scene id the visitor was reading last. */
  sceneSlug: string;
  /** ISO-8601 timestamp when the marker was last written. */
  updatedAtIso: string;
  /** Ordered scene-id trail (oldest first); the last entry equals sceneSlug. */
  history: string[];
  /** Scenes the reader flagged with the bookmark action. */
  bookmarks: string[];
  /** Ending scene ids the reader has reached. */
  discoveredEndings: string[];
}

export function progressKey(adventureSlug: string): string {
  return KEY_PREFIX + adventureSlug;
}

function isStringArray(v: unknown): v is string[] {
  return Array.isArray(v) && v.every((x) => typeof x === "string");
}

/** Parse a stored blob into a full LocalProgress, tolerating the v0.6.0 shape. */
function coerce(raw: string | null): LocalProgress | null {
  if (!raw) return null;
  try {
    const parsed = JSON.parse(raw) as Partial<LocalProgress>;
    if (!parsed || typeof parsed.sceneSlug !== "string") return null;
    if (typeof parsed.updatedAtIso !== "string") return null;
    const history =
      isStringArray(parsed.history) && parsed.history.length > 0
        ? parsed.history
        : [parsed.sceneSlug];
    return {
      sceneSlug: parsed.sceneSlug,
      updatedAtIso: parsed.updatedAtIso,
      history,
      bookmarks: isStringArray(parsed.bookmarks) ? parsed.bookmarks : [],
      discoveredEndings: isStringArray(parsed.discoveredEndings)
        ? parsed.discoveredEndings
        : [],
    };
  } catch {
    return null;
  }
}

export function readLocalProgress(
  adventureSlug: string,
): LocalProgress | null {
  if (typeof window === "undefined") return null;
  return coerce(window.localStorage.getItem(progressKey(adventureSlug)));
}

function writeLocalProgress(
  adventureSlug: string,
  progress: LocalProgress,
): void {
  if (typeof window === "undefined") return;
  window.localStorage.setItem(progressKey(adventureSlug), JSON.stringify(progress));
}

function clearLocalProgress(adventureSlug: string): void {
  if (typeof window === "undefined") return;
  window.localStorage.removeItem(progressKey(adventureSlug));
}

/**
 * Read-only React hook. Re-reads storage when the slug changes.
 * Kept for existing callers (Adventure landing page) that only need to
 * know whether a resume marker exists.
 */
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

export interface AdventureProgressApi {
  progress: LocalProgress | null;
  /** Record that the reader viewed a scene (extends history, updates marker). */
  visit: (sceneId: string, options?: { isEnding?: boolean }) => void;
  /** Pop the current scene from history and return the previous scene id. */
  goBack: () => string | null;
  /** Clear history and reset the marker to the starting scene. */
  restart: (startSceneId: string) => void;
  /** Toggle a bookmark for a specific scene. */
  toggleBookmark: (sceneId: string) => void;
  isBookmarked: (sceneId: string) => boolean;
  /** Erase every local trace of progress for this adventure. */
  clear: () => void;
}

/**
 * Full read/write hook backing the reader page.
 *
 * All mutations write synchronously to localStorage so a page refresh
 * shows the same state, and update the in-memory copy so React renders
 * the change on the same tick.
 */
export function useAdventureProgress(
  adventureSlug: string,
): AdventureProgressApi {
  const [progress, setProgress] = useState<LocalProgress | null>(() =>
    readLocalProgress(adventureSlug),
  );

  useEffect(() => {
    setProgress(readLocalProgress(adventureSlug));
  }, [adventureSlug]);

  const persist = useCallback(
    (next: LocalProgress | null) => {
      setProgress(next);
      if (next) writeLocalProgress(adventureSlug, next);
      else clearLocalProgress(adventureSlug);
    },
    [adventureSlug],
  );

  const visit = useCallback<AdventureProgressApi["visit"]>(
    (sceneId, options) => {
      const now = new Date().toISOString();
      setProgress((prev) => {
        const base: LocalProgress = prev ?? {
          sceneSlug: sceneId,
          updatedAtIso: now,
          history: [],
          bookmarks: [],
          discoveredEndings: [],
        };
        // Extend history only when moving to a different scene.
        const lastScene = base.history[base.history.length - 1];
        const history =
          lastScene === sceneId ? base.history : [...base.history, sceneId];
        const endings =
          options?.isEnding && !base.discoveredEndings.includes(sceneId)
            ? [...base.discoveredEndings, sceneId]
            : base.discoveredEndings;
        const next: LocalProgress = {
          ...base,
          sceneSlug: sceneId,
          updatedAtIso: now,
          history,
          discoveredEndings: endings,
        };
        writeLocalProgress(adventureSlug, next);
        return next;
      });
    },
    [adventureSlug],
  );

  const goBack = useCallback<AdventureProgressApi["goBack"]>(() => {
    let target: string | null = null;
    setProgress((prev) => {
      if (!prev || prev.history.length < 2) return prev;
      const history = prev.history.slice(0, -1);
      target = history[history.length - 1] ?? null;
      const next: LocalProgress = {
        ...prev,
        history,
        sceneSlug: target ?? prev.sceneSlug,
        updatedAtIso: new Date().toISOString(),
      };
      writeLocalProgress(adventureSlug, next);
      return next;
    });
    return target;
  }, [adventureSlug]);

  const restart = useCallback<AdventureProgressApi["restart"]>(
    (startSceneId) => {
      const now = new Date().toISOString();
      setProgress((prev) => {
        const next: LocalProgress = {
          sceneSlug: startSceneId,
          updatedAtIso: now,
          history: [startSceneId],
          bookmarks: prev?.bookmarks ?? [],
          discoveredEndings: prev?.discoveredEndings ?? [],
        };
        writeLocalProgress(adventureSlug, next);
        return next;
      });
    },
    [adventureSlug],
  );

  const toggleBookmark = useCallback<AdventureProgressApi["toggleBookmark"]>(
    (sceneId) => {
      const now = new Date().toISOString();
      setProgress((prev) => {
        const base: LocalProgress = prev ?? {
          sceneSlug: sceneId,
          updatedAtIso: now,
          history: [sceneId],
          bookmarks: [],
          discoveredEndings: [],
        };
        const bookmarks = base.bookmarks.includes(sceneId)
          ? base.bookmarks.filter((b) => b !== sceneId)
          : [...base.bookmarks, sceneId];
        const next: LocalProgress = { ...base, bookmarks, updatedAtIso: now };
        writeLocalProgress(adventureSlug, next);
        return next;
      });
    },
    [adventureSlug],
  );

  const isBookmarked = useCallback(
    (sceneId: string) => !!progress?.bookmarks.includes(sceneId),
    [progress],
  );

  const clear = useCallback(() => {
    persist(null);
  }, [persist]);

  return { progress, visit, goBack, restart, toggleBookmark, isBookmarked, clear };
}
