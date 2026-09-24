import { useEffect, useId, useState } from "react";

/**
 * Local reader preferences (v0.28.0). Stored only in this browser and
 * applied to the <html> element so every page honours them.
 */
export interface ReaderPreferences {
  textSize: "normal" | "large" | "x-large";
  lineSpacing: "normal" | "relaxed" | "loose";
  columnWidth: "narrow" | "normal" | "wide";
  highContrast: boolean;
  reducedMotion: boolean;
}

export const DEFAULT_PREFERENCES: ReaderPreferences = {
  textSize: "normal",
  lineSpacing: "normal",
  columnWidth: "normal",
  highContrast: false,
  reducedMotion: false,
};

const KEY = "bp:reader-preferences";

export function loadPreferences(): ReaderPreferences {
  try {
    const raw = window.localStorage.getItem(KEY);
    if (!raw) return DEFAULT_PREFERENCES;
    const p = JSON.parse(raw) as Partial<ReaderPreferences>;
    const pick = <T extends string>(v: unknown, allowed: readonly T[], d: T): T =>
      allowed.includes(v as T) ? (v as T) : d;
    return {
      textSize: pick(p.textSize, ["normal", "large", "x-large"] as const, "normal"),
      lineSpacing: pick(p.lineSpacing, ["normal", "relaxed", "loose"] as const, "normal"),
      columnWidth: pick(p.columnWidth, ["narrow", "normal", "wide"] as const, "normal"),
      highContrast: p.highContrast === true,
      reducedMotion: p.reducedMotion === true,
    };
  } catch {
    return DEFAULT_PREFERENCES;
  }
}

export function applyPreferences(p: ReaderPreferences): void {
  const el = document.documentElement;
  const set = (name: string, value: string | null) =>
    value === null ? el.removeAttribute(name) : el.setAttribute(name, value);
  set("data-text-size", p.textSize === "normal" ? null : p.textSize);
  set("data-line-spacing", p.lineSpacing === "normal" ? null : p.lineSpacing);
  set("data-column-width", p.columnWidth === "normal" ? null : p.columnWidth);
  set("data-contrast", p.highContrast ? "high" : null);
  set("data-motion", p.reducedMotion ? "reduce" : null);
}

export function savePreferences(p: ReaderPreferences): void {
  try {
    window.localStorage.setItem(KEY, JSON.stringify(p));
  } catch {
    /* storage unavailable — preferences still apply for this visit */
  }
  applyPreferences(p);
}

function Choice<T extends string>({
  legend, name, value, options, onChange,
}: {
  legend: string; name: string; value: T;
  options: { value: T; label: string }[]; onChange: (v: T) => void;
}) {
  const id = useId();
  return (
    <fieldset className="bp-prefs__group">
      <legend>{legend}</legend>
      {options.map((o) => (
        <label key={o.value} className="bp-prefs__option" htmlFor={`${id}-${o.value}`}>
          <input
            id={`${id}-${o.value}`}
            type="radio"
            name={`${name}-${id}`}
            value={o.value}
            checked={value === o.value}
            onChange={() => onChange(o.value)}
          />
          {o.label}
        </label>
      ))}
    </fieldset>
  );
}

export function ReaderPreferencesPanel() {
  const [prefs, setPrefs] = useState<ReaderPreferences>(DEFAULT_PREFERENCES);
  const [status, setStatus] = useState("");
  useEffect(() => setPrefs(loadPreferences()), []);

  const update = (patch: Partial<ReaderPreferences>) => {
    const next = { ...prefs, ...patch };
    setPrefs(next);
    savePreferences(next);
    setStatus("Reading settings saved in this browser.");
  };

  return (
    <details className="bp-reader__local bp-prefs" data-testid="reader-preferences">
      <summary>Reading settings</summary>
      <Choice legend="Text size" name="size" value={prefs.textSize}
        options={[{ value: "normal", label: "Standard" }, { value: "large", label: "Large" }, { value: "x-large", label: "Extra large" }]}
        onChange={(v) => update({ textSize: v })} />
      <Choice legend="Line spacing" name="spacing" value={prefs.lineSpacing}
        options={[{ value: "normal", label: "Standard" }, { value: "relaxed", label: "Relaxed" }, { value: "loose", label: "Loose" }]}
        onChange={(v) => update({ lineSpacing: v })} />
      <Choice legend="Column width" name="width" value={prefs.columnWidth}
        options={[{ value: "narrow", label: "Narrow" }, { value: "normal", label: "Standard" }, { value: "wide", label: "Wide" }]}
        onChange={(v) => update({ columnWidth: v })} />
      <fieldset className="bp-prefs__group">
        <legend>Display</legend>
        <label className="bp-prefs__option">
          <input type="checkbox" checked={prefs.highContrast}
            onChange={(e) => update({ highContrast: e.target.checked })} />
          High contrast
        </label>
        <label className="bp-prefs__option">
          <input type="checkbox" checked={prefs.reducedMotion}
            onChange={(e) => update({ reducedMotion: e.target.checked })} />
          Reduce motion
        </label>
      </fieldset>
      <p className="bp-visually-hidden" role="status" aria-live="polite">{status}</p>
    </details>
  );
}
