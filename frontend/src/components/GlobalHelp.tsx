import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useRef,
  useState,
  type ReactNode,
} from "react";
import { Link, useLocation } from "react-router-dom";
import {
  pickContextualTopics,
  sanitizeHelpPath,
  type HelpContext,
  type HelpRole,
} from "../data/helpTopics";
import { useSignedIn } from "../hooks/useSignedIn";
import type { StoryStatus } from "./AdventureCard";

/** Per-page overrides pushed by pages via `useHelpContext`. */
interface HelpOverride {
  section?: string;
  adventureStatus?: StoryStatus;
  setting?: string;
  role?: HelpRole;
}

interface HelpApi {
  open: () => void;
  close: () => void;
  isOpen: boolean;
  pushOverride: (o: HelpOverride) => () => void;
}

const HelpCtx = createContext<HelpApi | null>(null);

export function HelpProvider({ children }: { children: ReactNode }) {
  const [isOpen, setOpen] = useState(false);
  const [stack, setStack] = useState<HelpOverride[]>([]);
  const location = useLocation();
  const signedIn = useSignedIn();
  const triggerRef = useRef<HTMLElement | null>(null);

  const open = useCallback(() => {
    triggerRef.current = (document.activeElement as HTMLElement) ?? null;
    setOpen(true);
  }, []);
  const close = useCallback(() => {
    setOpen(false);
    // Return focus to the element that opened the drawer.
    // Deferred so the drawer's own effect doesn't race the focus return.
    queueMicrotask(() => triggerRef.current?.focus?.());
  }, []);

  const pushOverride = useCallback((o: HelpOverride) => {
    setStack((s) => [...s, o]);
    return () => {
      setStack((s) => {
        // Remove the last occurrence of this exact object reference.
        const i = s.lastIndexOf(o);
        if (i < 0) return s;
        return [...s.slice(0, i), ...s.slice(i + 1)];
      });
    };
  }, []);

  const role: HelpRole = useMemo(() => {
    const top = stack[stack.length - 1];
    if (top?.role) return top.role;
    if (location.pathname.startsWith("/master")) return "master";
    return signedIn ? "author" : "reader";
  }, [stack, location.pathname, signedIn]);

  const context: HelpContext = useMemo(() => {
    const top = stack[stack.length - 1] ?? {};
    return {
      pathname: sanitizeHelpPath(location.pathname),
      signedIn,
      role,
      section: top.section,
      adventureStatus: top.adventureStatus,
      setting: top.setting,
    };
  }, [stack, location.pathname, signedIn, role]);

  const suggestions = useMemo(
    () => pickContextualTopics(context, 4),
    [context],
  );

  const api = useMemo<HelpApi>(
    () => ({ open, close, isOpen, pushOverride }),
    [open, close, isOpen, pushOverride],
  );

  return (
    <HelpCtx.Provider value={api}>
      {children}
      <HelpButton onOpen={open} />
      <ContextualHelpDrawer
        open={isOpen}
        onClose={close}
        context={context}
        suggestions={suggestions}
      />
    </HelpCtx.Provider>
  );
}

export function useHelp(): HelpApi {
  const ctx = useContext(HelpCtx);
  if (!ctx) throw new Error("useHelp must be used inside <HelpProvider>");
  return ctx;
}

/**
 * Push contextual overrides while a component is mounted. Safe to call
 * outside a HelpProvider (e.g. in narrow test wrappers): the hook
 * silently no-ops instead of throwing.
 */
export function useHelpContext(override: HelpOverride): void {
  const ctx = useContext(HelpCtx);
  const key = JSON.stringify(override);
  useEffect(() => {
    if (!ctx) return;
    const cleanup = ctx.pushOverride(override);
    return cleanup;
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [key, ctx]);
}

// ── UI ────────────────────────────────────────────────────────────────

function HelpButton({ onOpen }: { onOpen: () => void }) {
  return (
    <button
      type="button"
      className="bp-help-button"
      onClick={onOpen}
      data-testid="help-button"
      aria-haspopup="dialog"
    >
      <span aria-hidden="true">?</span>
      <span>Help</span>
    </button>
  );
}

interface DrawerProps {
  open: boolean;
  onClose: () => void;
  context: HelpContext;
  suggestions: ReturnType<typeof pickContextualTopics>;
}

function ContextualHelpDrawer({
  open,
  onClose,
  context,
  suggestions,
}: DrawerProps) {
  const rootRef = useRef<HTMLDivElement>(null);
  const closeRef = useRef<HTMLButtonElement>(null);

  useEffect(() => {
    if (!open) return;
    // Remember what had focus so closing returns the reader there.
    const previous = document.activeElement as HTMLElement | null;
    // Move focus to the close button once the drawer mounts.
    closeRef.current?.focus();

    function onKey(e: KeyboardEvent) {
      if (e.key === "Escape") {
        e.preventDefault();
        onClose();
        return;
      }
      if (e.key !== "Tab") return;
      const root = rootRef.current;
      if (!root) return;
      const focusables = root.querySelectorAll<HTMLElement>(
        'a[href], button:not([disabled]), input:not([disabled]), [tabindex]:not([tabindex="-1"])',
      );
      if (focusables.length === 0) return;
      const first = focusables[0];
      const last = focusables[focusables.length - 1];
      const active = document.activeElement as HTMLElement | null;
      if (e.shiftKey && active === first) {
        e.preventDefault();
        last.focus();
      } else if (!e.shiftKey && active === last) {
        e.preventDefault();
        first.focus();
      }
    }
    document.addEventListener("keydown", onKey);
    return () => {
      document.removeEventListener("keydown", onKey);
      previous?.focus?.();
    };
  }, [open, onClose]);

  if (!open) return null;

  // Ensure every constructed help link is safe: only pathname, never
  // the raw location.search or hash, ever appears in a help URL.
  const safePath = context.pathname; // already sanitized

  return (
    <div
      className="bp-help-drawer"
      role="dialog"
      aria-modal="true"
      aria-labelledby="bp-help-drawer-title"
      data-testid="help-drawer"
      data-open="true"
      ref={rootRef}
    >
      <div className="bp-help-drawer__panel">
        <header className="bp-help-drawer__header">
          <h2 id="bp-help-drawer-title" className="bp-help-drawer__title">
            Help
          </h2>
          <button
            type="button"
            ref={closeRef}
            className="bp-btn bp-btn--ghost bp-btn--sm"
            onClick={onClose}
            data-testid="help-drawer-close"
            aria-label="Close help drawer"
          >
            Close
          </button>
        </header>
        <p className="bp-help-drawer__lede" data-testid="help-drawer-lede">
          Suggested topics for{" "}
          <code data-testid="help-drawer-path">{safePath || "/"}</code>.
        </p>
        <ul
          className="bp-help-drawer__list"
          data-testid="help-drawer-suggestions"
        >
          {suggestions.map((t) => (
            <li key={t.slug}>
              <Link to={`/help/${t.slug}`} onClick={onClose}>
                {t.title}
              </Link>
              <p className="bp-help-drawer__summary">{t.summary}</p>
            </li>
          ))}
        </ul>
        <p className="bp-help-drawer__all">
          <Link to="/help" onClick={onClose}>
            Browse all help topics
          </Link>
        </p>
      </div>
      <button
        type="button"
        className="bp-help-drawer__scrim"
        onClick={onClose}
        aria-label="Close help drawer"
        tabIndex={-1}
      />
    </div>
  );
}
