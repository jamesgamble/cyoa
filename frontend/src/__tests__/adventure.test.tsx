import { beforeEach, describe, it, expect } from "vitest";
import { render, screen, fireEvent } from "@testing-library/react";
import { MemoryRouter, Routes, Route } from "react-router-dom";
import { Adventure } from "../pages/Adventure";
import { DISCOVER_ADVENTURES, findAdventureBySlug } from "../data/discover";
import { progressKey } from "../hooks/useLocalProgress";

function renderAt(url: string) {
  return render(
    <MemoryRouter initialEntries={[url]}>
      <Routes>
        <Route path="/adventure/:slug" element={<Adventure />} />
        <Route path="/discover" element={<p>discover</p>} />
      </Routes>
    </MemoryRouter>,
  );
}

const FIRST = DISCOVER_ADVENTURES[0]; // lantern-road, approval, in-progress
const CLOSED = DISCOVER_ADVENTURES.find(
  (a) => a.contributionState === "closed",
)!;
const IMMEDIATE = DISCOVER_ADVENTURES.find(
  (a) => a.contributionState === "immediate",
)!;
const ARCHIVED = DISCOVER_ADVENTURES.find(
  (a) => a.storyStatus === "archived",
)!;
const COMPLETE = DISCOVER_ADVENTURES.find(
  (a) => a.storyStatus === "complete",
)!;
const ONHOLD = DISCOVER_ADVENTURES.find(
  (a) => a.storyStatus === "on-hold",
)!;

beforeEach(() => {
  window.localStorage.clear();
});

describe("Adventure landing — routing", () => {
  it("renders the not-found state for an unknown slug", () => {
    renderAt("/adventure/no-such-story");
    expect(screen.getByText(/Adventure not found/i)).toBeInTheDocument();
    expect(
      screen.queryByTestId("adventure-landing"),
    ).not.toBeInTheDocument();
  });

  it("resolves a known slug from fixtures", () => {
    renderAt(`/adventure/${FIRST.slug}`);
    expect(screen.getByTestId("adventure-landing")).toHaveAttribute(
      "data-slug",
      FIRST.slug,
    );
  });

  it("findAdventureBySlug returns the matching record", () => {
    expect(findAdventureBySlug(FIRST.slug)?.title).toBe(FIRST.title);
    expect(findAdventureBySlug("nope")).toBeUndefined();
  });
});

describe("Adventure landing — displayed fields", () => {
  it("shows title, creator, description, genre, content rating, and last updated", () => {
    renderAt(`/adventure/${FIRST.slug}`);
    expect(screen.getByTestId("adv-title")).toHaveTextContent(FIRST.title);
    expect(screen.getByTestId("adv-author")).toHaveTextContent(FIRST.author);
    expect(screen.getByTestId("adv-description")).toHaveTextContent(
      FIRST.description!.slice(0, 30),
    );
    expect(screen.getByTestId("fact-genre")).toHaveTextContent(/Fantasy/i);
    expect(screen.getByTestId("fact-rating")).toHaveTextContent(/Everyone/i);
    expect(screen.getByTestId("fact-scene-count")).toHaveTextContent(
      String(FIRST.sceneCount),
    );
    expect(screen.getByTestId("fact-ending-count")).toHaveTextContent(
      String(FIRST.endingCount),
    );
    expect(screen.getByTestId("fact-updated")).toHaveTextContent(
      FIRST.updatedAt!,
    );
  });

  it("shows content warnings as a plain list when present", () => {
    renderAt(`/adventure/${FIRST.slug}`);
    const warnings = screen.getByTestId("adv-content-warnings");
    for (const w of FIRST.contentWarnings!) {
      expect(warnings).toHaveTextContent(w);
    }
  });

  it("omits the content warnings panel when the list is empty", () => {
    const noWarn = DISCOVER_ADVENTURES.find(
      (a) => a.contentWarnings && a.contentWarnings.length === 0,
    )!;
    renderAt(`/adventure/${noWarn.slug}`);
    expect(
      screen.queryByTestId("adv-content-warnings"),
    ).not.toBeInTheDocument();
  });

  it("shows writing guidelines when the author provided them", () => {
    renderAt(`/adventure/${FIRST.slug}`);
    expect(screen.getByTestId("adv-writing-guidelines")).toHaveTextContent(
      FIRST.writingGuidelines!.slice(0, 20),
    );
  });
});

describe("Adventure landing — status rendering", () => {
  it("labels 'in-progress' story status", () => {
    renderAt(`/adventure/${FIRST.slug}`);
    expect(screen.getByTestId("fact-story-status")).toHaveTextContent(
      "In progress",
    );
  });
  it("labels 'complete' story status", () => {
    renderAt(`/adventure/${COMPLETE.slug}`);
    expect(screen.getByTestId("fact-story-status")).toHaveTextContent(
      "Complete",
    );
  });
  it("labels 'on-hold' story status", () => {
    renderAt(`/adventure/${ONHOLD.slug}`);
    expect(screen.getByTestId("fact-story-status")).toHaveTextContent(
      "On hold",
    );
  });
  it("labels 'archived' story status", () => {
    renderAt(`/adventure/${ARCHIVED.slug}`);
    expect(screen.getByTestId("fact-story-status")).toHaveTextContent(
      "Archived",
    );
  });

  it("labels 'immediate' contribution state", () => {
    renderAt(`/adventure/${IMMEDIATE.slug}`);
    expect(screen.getByTestId("fact-contribution-state")).toHaveTextContent(
      "Immediate publishing",
    );
  });
  it("labels 'approval' contribution state", () => {
    renderAt(`/adventure/${FIRST.slug}`);
    expect(screen.getByTestId("fact-contribution-state")).toHaveTextContent(
      "Approval required",
    );
  });
  it("labels 'closed' contribution state", () => {
    renderAt(`/adventure/${CLOSED.slug}`);
    expect(screen.getByTestId("fact-contribution-state")).toHaveTextContent(
      /Closed/,
    );
  });
});

describe("Adventure landing — actions", () => {
  it("always shows Read from beginning and View story map", () => {
    renderAt(`/adventure/${FIRST.slug}`);
    expect(screen.getByTestId("action-read")).toHaveAttribute(
      "href",
      `/adventure/${FIRST.slug}/read`,
    );
    expect(screen.getByTestId("action-map")).toHaveAttribute(
      "href",
      `/adventure/${FIRST.slug}/map`,
    );
  });

  it("hides Resume reading when no local progress exists", () => {
    renderAt(`/adventure/${FIRST.slug}`);
    expect(screen.queryByTestId("action-resume")).not.toBeInTheDocument();
  });

  it("shows Resume reading with the stored scene when local progress exists", () => {
    window.localStorage.setItem(
      progressKey(FIRST.slug),
      JSON.stringify({
        sceneSlug: "the-tin-lantern",
        updatedAtIso: "2026-07-18T09:00:00Z",
      }),
    );
    renderAt(`/adventure/${FIRST.slug}`);
    const resume = screen.getByTestId("action-resume");
    expect(resume).toHaveAttribute(
      "href",
      `/adventure/${FIRST.slug}/read/the-tin-lantern`,
    );
  });

  it("shows Add a branch when contributions publish immediately", () => {
    renderAt(`/adventure/${IMMEDIATE.slug}`);
    expect(screen.getByTestId("action-add-branch")).toHaveAttribute(
      "href",
      `/adventure/${IMMEDIATE.slug}/branch`,
    );
  });

  it("shows Add a branch when contributions require approval", () => {
    renderAt(`/adventure/${FIRST.slug}`);
    expect(screen.getByTestId("action-add-branch")).toBeInTheDocument();
  });

  it("hides Add a branch and shows a notice when contributions are closed", () => {
    renderAt(`/adventure/${CLOSED.slug}`);
    expect(
      screen.queryByTestId("action-add-branch"),
    ).not.toBeInTheDocument();
    expect(
      screen.getByTestId("add-branch-unavailable"),
    ).toBeInTheDocument();
  });
});

describe("Adventure landing — follow placeholder", () => {
  it("is hidden by default (signed-out)", () => {
    renderAt(`/adventure/${FIRST.slug}`);
    expect(screen.queryByTestId("action-follow")).not.toBeInTheDocument();
  });

  it("appears for signed-in visitors and toggles state", () => {
    window.localStorage.setItem("bp-signed-in", "1");
    renderAt(`/adventure/${FIRST.slug}`);
    const btn = screen.getByTestId("action-follow");
    expect(btn).toHaveAttribute("aria-pressed", "false");
    expect(btn).toHaveTextContent(/^Follow/);
    fireEvent.click(btn);
    expect(btn).toHaveAttribute("aria-pressed", "true");
    expect(btn).toHaveTextContent(/^Following/);
    expect(screen.getByTestId("follow-help")).toBeInTheDocument();
  });
});
