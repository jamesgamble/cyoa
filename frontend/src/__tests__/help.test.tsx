import { describe, it, expect, beforeEach } from "vitest";
import { render, screen, fireEvent, within, waitFor } from "@testing-library/react";
import { MemoryRouter, Routes, Route } from "react-router-dom";
import { HelpProvider } from "../components/GlobalHelp";
import { Help, HelpTopic } from "../pages/help";
import {
  HELP_TOPICS,
  pickContextualTopics,
  sanitizeHelpPath,
  searchHelpTopics,
} from "../data/helpTopics";
import { Reader } from "../pages/Reader";
import { Discover } from "../pages/Discover";
import { Home } from "../pages/Home";

const REQUIRED = [
  "getting-started",
  "discovering",
  "reading",
  "creating",
  "contributing",
  "accounts",
  "managing-adventures",
  "moderation",
  "privacy-and-security",
  "changelog",
  "common-errors",
];

function renderApp(url: string) {
  return render(
    <MemoryRouter initialEntries={[url]}>
      <HelpProvider>
        <Routes>
          <Route path="/" element={<Home />} />
          <Route path="/help" element={<Help />} />
          <Route path="/help/:topic" element={<HelpTopic />} />
          <Route path="/discover" element={<Discover />} />
          <Route path="/adventure/:slug/read/:sceneId" element={<Reader />} />
          <Route path="*" element={<p>fallback</p>} />
        </Routes>
      </HelpProvider>
    </MemoryRouter>,
  );
}

beforeEach(() => window.localStorage.clear());

describe("Help — canonical topics", () => {
  it("includes every required topic slug", () => {
    const slugs = HELP_TOPICS.map((t) => t.slug);
    for (const s of REQUIRED) expect(slugs).toContain(s);
  });

  it("references only defined slugs in each topic's `related` list", () => {
    const slugs = new Set(HELP_TOPICS.map((t) => t.slug));
    for (const t of HELP_TOPICS) {
      for (const r of t.related) expect(slugs, `${t.slug}→${r}`).toContain(r);
    }
  });

  it("mentions only implemented behaviour (no forbidden words)", () => {
    const forbidden = /\b(likes?|comments?|followers?|rankings?|popularity|leaderboards?)\b/i;
    for (const t of HELP_TOPICS) {
      const text = [t.title, t.summary, ...t.body].join(" ");
      expect(text, `${t.slug}: ${text}`).not.toMatch(forbidden);
    }
  });
});

describe("Help center", () => {
  it("lists every topic on /help", () => {
    renderApp("/help");
    const index = screen.getByTestId("help-index");
    for (const t of HELP_TOPICS) {
      expect(within(index).getByRole("link", { name: t.title })).toBeInTheDocument();
    }
  });

  it("filters topics with the search field", () => {
    renderApp("/help");
    const input = screen.getByTestId("help-search-input") as HTMLInputElement;
    fireEvent.change(input, { target: { value: "moderation" } });
    const index = screen.getByTestId("help-index");
    expect(within(index).getByRole("link", { name: "Moderation" })).toBeInTheDocument();
    expect(
      within(index).queryByRole("link", { name: "Discovering adventures" }),
    ).not.toBeInTheDocument();
  });

  it("shows an empty state when no topic matches", () => {
    renderApp("/help");
    fireEvent.change(screen.getByTestId("help-search-input"), {
      target: { value: "zzzzzz-nomatch" },
    });
    expect(screen.getByTestId("help-search-empty")).toBeInTheDocument();
  });
});

describe("Help topic page", () => {
  it("renders title, summary, body, and related links", () => {
    renderApp("/help/reading");
    expect(screen.getByRole("heading", { level: 1 })).toHaveTextContent(
      "Reading an adventure",
    );
    const related = screen.getByTestId("help-related");
    // "reading" lists three related topics.
    expect(within(related).getAllByRole("listitem")).toHaveLength(3);
  });

  it("shows a not-found panel for an unknown slug", () => {
    renderApp("/help/does-not-exist");
    expect(screen.getByTestId("help-topic-missing")).toBeInTheDocument();
  });
});

describe("Help drawer — persistent trigger and keyboard behaviour", () => {
  it("opens from the persistent Help button", () => {
    renderApp("/");
    expect(screen.queryByTestId("help-drawer")).not.toBeInTheDocument();
    fireEvent.click(screen.getByTestId("help-button"));
    expect(screen.getByTestId("help-drawer")).toBeInTheDocument();
  });

  it("moves focus to the close button when opened", async () => {
    renderApp("/");
    fireEvent.click(screen.getByTestId("help-button"));
    const close = screen.getByTestId("help-drawer-close");
    await waitFor(() => expect(document.activeElement).toBe(close));
  });

  it("closes on Escape and returns focus to the trigger", async () => {
    renderApp("/");
    const trigger = screen.getByTestId("help-button");
    trigger.focus();
    fireEvent.click(trigger);
    fireEvent.keyDown(document, { key: "Escape" });
    await waitFor(() =>
      expect(screen.queryByTestId("help-drawer")).not.toBeInTheDocument(),
    );
    await waitFor(() => expect(document.activeElement).toBe(trigger));
  });

  it("cycles focus with Shift+Tab from the first focusable to the last", () => {
    renderApp("/");
    fireEvent.click(screen.getByTestId("help-button"));
    const drawer = screen.getByTestId("help-drawer");
    const focusables = drawer.querySelectorAll<HTMLElement>(
      'a[href], button:not([disabled])',
    );
    const first = focusables[0];
    const last = focusables[focusables.length - 1];
    first.focus();
    fireEvent.keyDown(document, { key: "Tab", shiftKey: true });
    expect(document.activeElement).toBe(last);
  });

  it("cycles focus with Tab from the last focusable to the first", () => {
    renderApp("/");
    fireEvent.click(screen.getByTestId("help-button"));
    const drawer = screen.getByTestId("help-drawer");
    const focusables = drawer.querySelectorAll<HTMLElement>(
      'a[href], button:not([disabled])',
    );
    const first = focusables[0];
    const last = focusables[focusables.length - 1];
    last.focus();
    fireEvent.keyDown(document, { key: "Tab" });
    expect(document.activeElement).toBe(first);
  });

  it("clicking Close closes the drawer", async () => {
    renderApp("/");
    fireEvent.click(screen.getByTestId("help-button"));
    fireEvent.click(screen.getByTestId("help-drawer-close"));
    await waitFor(() =>
      expect(screen.queryByTestId("help-drawer")).not.toBeInTheDocument(),
    );
  });
});

describe("Help drawer — contextual selection", () => {
  it("promotes the Discovering topic when the drawer opens on /discover", () => {
    renderApp("/discover");
    fireEvent.click(screen.getByTestId("help-button"));
    const list = screen.getByTestId("help-drawer-suggestions");
    const first = within(list).getAllByRole("link")[0];
    expect(first).toHaveTextContent("Discovering adventures");
  });

  it("promotes the Reading topic when the drawer opens in the reader", () => {
    renderApp("/adventure/the-lantern-road/read/start");
    fireEvent.click(screen.getByTestId("help-button"));
    const first = within(
      screen.getByTestId("help-drawer-suggestions"),
    ).getAllByRole("link")[0];
    expect(first).toHaveTextContent("Reading an adventure");
  });

  it("promotes Getting started on the home route", () => {
    renderApp("/");
    fireEvent.click(screen.getByTestId("help-button"));
    const first = within(
      screen.getByTestId("help-drawer-suggestions"),
    ).getAllByRole("link")[0];
    expect(first).toHaveTextContent("Getting started");
  });

  it("caps the drawer's suggestion list at four entries", () => {
    renderApp("/discover");
    fireEvent.click(screen.getByTestId("help-button"));
    const items = within(
      screen.getByTestId("help-drawer-suggestions"),
    ).getAllByRole("listitem");
    expect(items.length).toBeLessThanOrEqual(4);
  });
});

describe("Help — URL sanitisation and credential safety", () => {
  it("strips query strings and hash fragments before showing the current path", () => {
    expect(sanitizeHelpPath("/login?token=abc&next=/x")).toBe("/login");
    expect(sanitizeHelpPath("/master/login#password=hunter2")).toBe(
      "/master/login",
    );
    expect(sanitizeHelpPath("/help")).toBe("/help");
  });

  it("never shows raw query strings inside the drawer path badge", () => {
    renderApp("/discover");
    fireEvent.click(screen.getByTestId("help-button"));
    const shown = screen.getByTestId("help-drawer-path").textContent ?? "";
    expect(shown).not.toContain("?");
    expect(shown).not.toContain("#");
  });

  it("does not construct any help topic link containing credential-shaped fragments", () => {
    renderApp("/help");
    const anchors = document.querySelectorAll<HTMLAnchorElement>('a[href^="/help"]');
    expect(anchors.length).toBeGreaterThan(0);
    for (const a of anchors) {
      const href = a.getAttribute("href") ?? "";
      expect(href).not.toMatch(/[?#]/);
      expect(href).not.toMatch(/token|password|secret/i);
    }
  });
});

describe("Help — programmatic context selection", () => {
  it("prefers route matches over general topics", () => {
    const t = pickContextualTopics({
      pathname: "/master",
      signedIn: false,
      role: "master",
    });
    expect(t[0]?.slug).toBe("moderation");
  });

  it("uses the section override to disambiguate similar routes", () => {
    const t = pickContextualTopics({
      pathname: "/account",
      signedIn: true,
      role: "author",
      section: "privacy",
    });
    expect(t[0]?.slug).toBe("privacy-and-security");
  });

  it("returns a fallback ordering when nothing matches", () => {
    const t = pickContextualTopics({
      pathname: "/nowhere-in-particular",
      signedIn: false,
      role: "reader",
    });
    expect(t[0]?.slug).toBe("getting-started");
  });
});

describe("Help search — programmatic", () => {
  it("returns every topic for an empty query", () => {
    expect(searchHelpTopics("").length).toBe(HELP_TOPICS.length);
  });

  it("matches on body content, not only titles", () => {
    const results = searchHelpTopics("bookmark");
    expect(results.map((r) => r.slug)).toContain("reading");
  });

  it("requires every whitespace-separated term to appear", () => {
    expect(searchHelpTopics("contribution approval").map((r) => r.slug)).toContain(
      "contributing",
    );
    expect(searchHelpTopics("contribution zzz-none").length).toBe(0);
  });
});
