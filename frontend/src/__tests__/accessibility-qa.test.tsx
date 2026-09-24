import { describe, it, expect, beforeEach } from "vitest";
import { render, screen, fireEvent } from "@testing-library/react";
import { readFileSync, readdirSync, statSync } from "node:fs";
import { join } from "node:path";
import { useState } from "react";
import {
  ReaderPreferencesPanel, loadPreferences, applyPreferences, DEFAULT_PREFERENCES,
} from "../components/ReaderPreferences";
import { Dialog } from "../components/Dialog";
import { HelpDrawer } from "../components/HelpDrawer";
import { HELP_TOPICS, findTopic } from "../data/helpTopics";
import { CHANGELOG } from "../data/changelog";

const SRC = join(__dirname, "..");
function files(dir: string): string[] {
  return readdirSync(dir).flatMap((f) => {
    const p = join(dir, f);
    if (statSync(p).isDirectory()) return f === "__tests__" ? [] : files(p);
    return /\.(tsx?|css)$/.test(f) ? [p] : [];
  });
}
const SOURCES = files(SRC).map((p) => ({ p, s: readFileSync(p, "utf8") }));

describe("Reader preferences", () => {
  beforeEach(() => {
    localStorage.clear();
    applyPreferences(DEFAULT_PREFERENCES);
  });

  it("saves every preference locally and applies it to the page", () => {
    render(<ReaderPreferencesPanel />);
    fireEvent.click(screen.getByLabelText("Extra large"));
    fireEvent.click(screen.getByLabelText("Loose"));
    fireEvent.click(screen.getByLabelText("Wide"));
    fireEvent.click(screen.getByLabelText("High contrast"));
    fireEvent.click(screen.getByLabelText("Reduce motion"));
    const el = document.documentElement;
    expect(el.getAttribute("data-text-size")).toBe("x-large");
    expect(el.getAttribute("data-line-spacing")).toBe("loose");
    expect(el.getAttribute("data-column-width")).toBe("wide");
    expect(el.getAttribute("data-contrast")).toBe("high");
    expect(el.getAttribute("data-motion")).toBe("reduce");
    expect(loadPreferences()).toEqual({
      textSize: "x-large", lineSpacing: "loose", columnWidth: "wide", highContrast: true, reducedMotion: true,
    });
    expect(screen.getByRole("status")).toHaveTextContent("saved");
  });

  it("ignores corrupt or unknown stored values", () => {
    localStorage.setItem("bp:reader-preferences", JSON.stringify({ textSize: "huge", highContrast: "yes" }));
    expect(loadPreferences()).toEqual(DEFAULT_PREFERENCES);
    localStorage.setItem("bp:reader-preferences", "{not json");
    expect(loadPreferences()).toEqual(DEFAULT_PREFERENCES);
  });

  it("every option has a label and groups have legends", () => {
    render(<ReaderPreferencesPanel />);
    for (const legend of ["Text size", "Line spacing", "Column width", "Display"]) {
      expect(screen.getByRole("group", { name: legend })).toBeInTheDocument();
    }
    for (const input of screen.getAllByRole("radio").concat(screen.getAllByRole("checkbox"))) {
      expect(input).toHaveAccessibleName();
    }
  });
});

describe("Dialogs and drawers", () => {
  it("two dialogs never share a title id", () => {
    render(<><Dialog open={false} onClose={() => {}} title="A">a</Dialog><Dialog open={false} onClose={() => {}} title="B">b</Dialog></>);
    const ids = Array.from(document.querySelectorAll(".bp-dialog__title")).map((h) => h.id);
    expect(new Set(ids).size).toBe(2);
  });

  it("closed drawer is inert; open drawer focuses close, Escape closes and focus returns", () => {
    function Host() {
      const [open, setOpen] = useState(false);
      return (<>
        <button onClick={() => setOpen(true)}>Open help</button>
        <HelpDrawer open={open} onClose={() => setOpen(false)} title="Help"><a href="/help">Topics</a></HelpDrawer>
      </>);
    }
    render(<Host />);
    const drawer = document.querySelector(".bp-drawer")!;
    expect(drawer.hasAttribute("inert")).toBe(true);
    const opener = screen.getByText("Open help");
    opener.focus();
    fireEvent.click(opener);
    expect(drawer.hasAttribute("inert")).toBe(false);
    expect(document.activeElement).toHaveTextContent("Close help");
    fireEvent.keyDown(window, { key: "Escape" });
    expect(drawer.hasAttribute("inert")).toBe(true);
    expect(document.activeElement).toBe(opener);
  });
});

describe("Production QA", () => {
  it("has no debug output in shipped source", () => {
    for (const { p, s } of SOURCES) {
      expect(/console\.(log|debug|trace)\(|debugger;/.test(s), p).toBe(false);
    }
  });

  it("has no placeholder links or controls", () => {
    for (const { p, s } of SOURCES) {
      expect(/href="#"|onClick=\{\(\) => \{\}\}|[Ll]orem ipsum|[Cc]oming soon/.test(s), p).toBe(false);
    }
  });

  it("every internal help link resolves to a real topic", () => {
    const slugs = new Set<string>();
    for (const { s } of SOURCES) for (const m of s.matchAll(/["'`]\/help\/([a-z0-9-]+)["'`]/g)) slugs.add(m[1]);
    for (const slug of slugs) expect(findTopic(slug), slug).toBeDefined();
    for (const t of HELP_TOPICS) for (const r of t.related) expect(findTopic(r), `${t.slug} → ${r}`).toBeDefined();
  });

  it("every static internal link points at a defined route", () => {
    const app = readFileSync(join(SRC, "App.tsx"), "utf8");
    const routes = Array.from(app.matchAll(/path="([^"]+)"/g)).map((m) => m[1]).filter((r) => r !== "*");
    const toRegex = (r: string) => new RegExp("^" + r.replace(/:[a-zA-Z]+/g, "[^/]+") + "$");
    const patterns = routes.map(toRegex);
    for (const { p, s } of SOURCES) {
      if (p.endsWith("DesignSystem.tsx")) continue; // specimen page, links are illustrative
      for (const m of s.matchAll(/(?:to|href)[=:] ?"(\/[a-zA-Z0-9/_-]*)/g)) {
        const path = m[1];
        if (path.startsWith("/api")) continue;
        expect(patterns.some((re) => re.test(path)), `${p}: ${path}`).toBe(true);
      }
    }
  });

  it("help covers every shipped feature area", () => {
    for (const slug of [
      "about", "community-guidelines", "privacy", "reading", "discovering", "creating", "contributing",
      "managing-adventures", "moderation", "collaborators", "notifications", "story-map", "story-check",
      "revision-history", "exports", "master-administration", "maintenance-and-backups", "email-preferences",
    ]) expect(findTopic(slug), slug).toBeDefined();
  });

  it("help text no longer promises a reader Report button", () => {
    for (const t of HELP_TOPICS) expect(t.body.join(" ")).not.toMatch(/Report and Help are always available|remain placeholders|planned for (a )?later release/);
  });

  it("the public changelog lists every release from 0.1.0 to the current version", () => {
    const versions = CHANGELOG.map((e) => e.version);
    const [, minor] = versions[0].split(".").map(Number);
    for (let i = 1; i <= minor; i++) expect(versions).toContain(`0.${i}.0`);
  });

  it("sample content is gated out of production builds", () => {
    const gate = readFileSync(join(SRC, "data/fixtureGate.ts"), "utf8");
    expect(gate).toContain("!import.meta.env.PROD");
    for (const f of ["data/discover.ts", "data/scenes.ts", "data/adventures.ts"]) {
      expect(readFileSync(join(SRC, f), "utf8"), f).toContain("FIXTURES_ENABLED ?");
    }
  });
});
