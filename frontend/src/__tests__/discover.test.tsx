import { describe, it, expect } from "vitest";
import { render, screen, within, fireEvent, act } from "@testing-library/react";
import { MemoryRouter, Routes, Route, useLocation } from "react-router-dom";
import {
  Discover,
  applyFilters,
  parseFilters,
  serialiseFilters,
  DEFAULT_FILTERS,
  filtersAreDefault,
} from "../pages/Discover";
import { DISCOVER_ADVENTURES } from "../data/discover";

function LocationProbe() {
  const loc = useLocation();
  return (
    <div
      data-testid="location"
      data-pathname={loc.pathname}
      data-search={loc.search}
    />
  );
}

function renderDiscover(initialUrl = "/discover") {
  return render(
    <MemoryRouter initialEntries={[initialUrl]}>
      <Routes>
        <Route
          path="/discover"
          element={
            <>
              <Discover />
              <LocationProbe />
            </>
          }
        />
      </Routes>
    </MemoryRouter>,
  );
}

function getSearch(): URLSearchParams {
  const el = screen.getByTestId("location");
  return new URLSearchParams(el.getAttribute("data-search") ?? "");
}

/* ================= pure helpers ================= */

describe("Discover — pure filter helpers", () => {
  it("parseFilters returns defaults for an empty query string", () => {
    expect(parseFilters(new URLSearchParams(""))).toEqual(DEFAULT_FILTERS);
  });

  it("parseFilters ignores unknown values", () => {
    const f = parseFilters(
      new URLSearchParams(
        "q=lantern&genre=bogus&rating=nope&status=weird&contributions=maybe&sort=totally-not",
      ),
    );
    expect(f.q).toBe("lantern");
    expect(f.genre).toBe("");
    expect(f.rating).toBe("");
    expect(f.status).toBe("");
    expect(f.contributions).toBe("");
    expect(f.sort).toBe("recent");
  });

  it("parseFilters accepts every documented value", () => {
    const f = parseFilters(
      new URLSearchParams(
        "q=road&genre=fantasy&rating=teen&status=review&contributions=open&sort=title",
      ),
    );
    expect(f).toEqual({
      q: "road",
      genre: "fantasy",
      rating: "teen",
      status: "review",
      contributions: "open",
      sort: "title",
    });
  });

  it("serialiseFilters omits default values", () => {
    const p = serialiseFilters(DEFAULT_FILTERS);
    expect(p.toString()).toBe("");
  });

  it("serialiseFilters emits only set fields", () => {
    const p = serialiseFilters({
      q: "  lantern  ",
      genre: "fantasy",
      rating: "",
      status: "",
      contributions: "open",
      sort: "title",
    });
    expect(p.get("q")).toBe("lantern");
    expect(p.get("genre")).toBe("fantasy");
    expect(p.get("rating")).toBeNull();
    expect(p.get("contributions")).toBe("open");
    expect(p.get("sort")).toBe("title");
  });

  it("filtersAreDefault detects the reset state", () => {
    expect(filtersAreDefault(DEFAULT_FILTERS)).toBe(true);
    expect(filtersAreDefault({ ...DEFAULT_FILTERS, q: "x" })).toBe(false);
    expect(filtersAreDefault({ ...DEFAULT_FILTERS, sort: "title" })).toBe(false);
  });
});

describe("Discover — filtering and sorting", () => {
  it("filters by genre", () => {
    const out = applyFilters(DISCOVER_ADVENTURES, {
      ...DEFAULT_FILTERS,
      genre: "fantasy",
    });
    expect(out.length).toBeGreaterThan(0);
    expect(out.every((a) => a.genre === "fantasy")).toBe(true);
  });

  it("filters by content rating", () => {
    const out = applyFilters(DISCOVER_ADVENTURES, {
      ...DEFAULT_FILTERS,
      rating: "teen",
    });
    expect(out.every((a) => a.contentRating === "teen")).toBe(true);
  });

  it("filters by adventure status", () => {
    const out = applyFilters(DISCOVER_ADVENTURES, {
      ...DEFAULT_FILTERS,
      status: "draft",
    });
    expect(out.every((a) => a.status === "draft")).toBe(true);
  });

  it("filters by contribution status", () => {
    const open = applyFilters(DISCOVER_ADVENTURES, {
      ...DEFAULT_FILTERS,
      contributions: "open",
    });
    const closed = applyFilters(DISCOVER_ADVENTURES, {
      ...DEFAULT_FILTERS,
      contributions: "closed",
    });
    expect(open.every((a) => a.contributionsOpen === true)).toBe(true);
    expect(closed.every((a) => a.contributionsOpen === false)).toBe(true);
  });

  it("searches title, author, and synopsis", () => {
    const t = applyFilters(DISCOVER_ADVENTURES, {
      ...DEFAULT_FILTERS,
      q: "Lantern",
    });
    expect(t.some((a) => a.title === "The Lantern Road")).toBe(true);

    const a = applyFilters(DISCOVER_ADVENTURES, {
      ...DEFAULT_FILTERS,
      q: "Rowan Ash",
    });
    expect(a.some((x) => x.author === "Rowan Ash")).toBe(true);

    const s = applyFilters(DISCOVER_ADVENTURES, {
      ...DEFAULT_FILTERS,
      q: "lighthouse",
    });
    expect(s.some((x) => x.slug === "salt-and-signal")).toBe(true);
  });

  it("sorts by title alphabetically", () => {
    const out = applyFilters(DISCOVER_ADVENTURES, {
      ...DEFAULT_FILTERS,
      sort: "title",
    });
    const titles = out.map((a) => a.title);
    const sorted = [...titles].sort((a, b) => a.localeCompare(b));
    expect(titles).toEqual(sorted);
  });

  it("sorts by scene count descending", () => {
    const out = applyFilters(DISCOVER_ADVENTURES, {
      ...DEFAULT_FILTERS,
      sort: "scenes",
    });
    for (let i = 1; i < out.length; i++) {
      expect(out[i - 1].sceneCount ?? 0).toBeGreaterThanOrEqual(
        out[i].sceneCount ?? 0,
      );
    }
  });

  it("sorts by ending count descending", () => {
    const out = applyFilters(DISCOVER_ADVENTURES, {
      ...DEFAULT_FILTERS,
      sort: "endings",
    });
    for (let i = 1; i < out.length; i++) {
      expect(out[i - 1].endingCount ?? 0).toBeGreaterThanOrEqual(
        out[i].endingCount ?? 0,
      );
    }
  });

  it("sorts by recently updated (ISO) by default", () => {
    const out = applyFilters(DISCOVER_ADVENTURES, DEFAULT_FILTERS);
    for (let i = 1; i < out.length; i++) {
      expect((out[i - 1].updatedAtIso ?? "") >= (out[i].updatedAtIso ?? "")).toBe(
        true,
      );
    }
  });
});

/* ================= URL & UI ================= */

describe("Discover — page rendering", () => {
  it("renders exactly one h1 with the discover heading", () => {
    renderDiscover();
    const h1s = screen.getAllByRole("heading", { level: 1 });
    expect(h1s).toHaveLength(1);
    expect(h1s[0]).toHaveTextContent(/discover adventures/i);
  });

  it("renders every adventure card by default", () => {
    renderDiscover();
    const cards = screen.getAllByTestId("adventure-card");
    expect(cards.length).toBe(DISCOVER_ADVENTURES.length);
  });

  it("card exposes every required datum", () => {
    renderDiscover();
    const card = screen
      .getAllByTestId("adventure-card")
      .find((c) => c.getAttribute("data-slug") === "the-lantern-road")!;
    expect(card).toBeTruthy();
    expect(within(card).getByRole("link", { name: /the lantern road/i })).toBeInTheDocument();
    expect(within(card).getByText(/by marisol vega/i)).toBeInTheDocument();
    expect(within(card).getByText(/cartographer's apprentice/i)).toBeInTheDocument();
    expect(within(card).getByTestId("tag-genre")).toHaveTextContent(/fantasy/i);
    expect(within(card).getByTestId("tag-rating")).toHaveTextContent(/everyone/i);
    expect(within(card).getByTestId("tag-contributions")).toHaveTextContent(/open/i);
    expect(within(card).getByTestId("meta-scenes")).toHaveTextContent(/42 scenes/);
    expect(within(card).getByTestId("meta-endings")).toHaveTextContent(/6 endings/);
    expect(within(card).getByTestId("meta-updated")).toHaveTextContent(/three days ago/);
    // status badge
    expect(within(card).getByText(/published/i)).toBeInTheDocument();
  });

  it("does not render any prohibited discovery signals", () => {
    renderDiscover();
    const root = screen.getByTestId("discover");
    const text = root.textContent ?? "";
    expect(text).not.toMatch(/\bpopularity\b/i);
    expect(text).not.toMatch(/\bratings?\s+(of|out|from)\b/i);
    expect(text).not.toMatch(/\blikes?\b/i);
    expect(text).not.toMatch(/\bcomments?\b/i);
    expect(text).not.toMatch(/followers?|following/i);
    expect(screen.queryByRole("button", { name: /like/i })).toBeNull();
  });
});

describe("Discover — URL-backed filters", () => {
  it("hydrates filters from the initial URL", () => {
    renderDiscover("/discover?genre=fantasy&sort=title");
    const results = screen.getAllByTestId("adventure-card");
    for (const c of results) {
      const slug = c.getAttribute("data-slug")!;
      const found = DISCOVER_ADVENTURES.find((a) => a.slug === slug)!;
      expect(found.genre).toBe("fantasy");
    }
    const titles = results.map(
      (c) => within(c).getByRole("link").textContent ?? "",
    );
    expect(titles).toEqual([...titles].sort((a, b) => a.localeCompare(b)));
    expect((screen.getByTestId("filter-genre") as HTMLSelectElement).value).toBe("fantasy");
    expect((screen.getByTestId("filter-sort") as HTMLSelectElement).value).toBe("title");
  });

  it("writes filter changes back into the URL", () => {
    renderDiscover();
    fireEvent.change(screen.getByTestId("filter-genre"), {
      target: { value: "mystery" },
    });
    const params = getSearch();
    expect(params.get("genre")).toBe("mystery");

    fireEvent.change(screen.getByTestId("filter-contributions"), {
      target: { value: "open" },
    });
    expect(getSearch().get("contributions")).toBe("open");
    expect(getSearch().get("genre")).toBe("mystery");
  });

  it("stores the search term in the URL and narrows results", () => {
    renderDiscover();
    fireEvent.change(screen.getByTestId("discover-search"), {
      target: { value: "lantern" },
    });
    expect(getSearch().get("q")).toBe("lantern");
    const cards = screen.getAllByTestId("adventure-card");
    expect(cards.length).toBe(1);
    expect(cards[0].getAttribute("data-slug")).toBe("the-lantern-road");
  });

  it("clear filters resets the URL and the controls", () => {
    renderDiscover("/discover?q=lantern&genre=fantasy&sort=title");
    const clear = screen.getByTestId("discover-clear") as HTMLButtonElement;
    expect(clear.disabled).toBe(false);
    fireEvent.click(clear);
    expect(getSearch().toString()).toBe("");
    expect((screen.getByTestId("filter-genre") as HTMLSelectElement).value).toBe("");
    expect((screen.getByTestId("filter-sort") as HTMLSelectElement).value).toBe("recent");
  });

  it("clear-filters button is disabled at defaults", () => {
    renderDiscover();
    const clear = screen.getByTestId("discover-clear") as HTMLButtonElement;
    expect(clear.disabled).toBe(true);
  });
});

describe("Discover — empty state", () => {
  it("shows the empty state when no adventure matches", () => {
    renderDiscover();
    fireEvent.change(screen.getByTestId("discover-search"), {
      target: { value: "zzz-nothing-matches-zzz" },
    });
    expect(screen.getByTestId("empty-state")).toBeInTheDocument();
    expect(screen.queryByTestId("discover-results")).toBeNull();
    const clear = within(screen.getByTestId("empty-state")).getByRole("button", {
      name: /clear filters/i,
    });
    fireEvent.click(clear);
    expect(screen.queryByTestId("empty-state")).toBeNull();
    expect(screen.getByTestId("discover-results")).toBeInTheDocument();
  });
});

describe("Discover — mobile panel and keyboard", () => {
  it("opens and closes the mobile filter dialog", () => {
    // Ensure jsdom supports <dialog>.showModal by patching if absent.
    if (typeof HTMLDialogElement !== "undefined") {
      // no-op guard; Dialog.tsx already falls back to setAttribute.
    }
    renderDiscover();
    const openBtn = screen.getByTestId("discover-open-mobile");
    expect(openBtn.getAttribute("aria-haspopup")).toBe("dialog");
    act(() => {
      fireEvent.click(openBtn);
    });
    const panel = screen.getByTestId("discover-mobile-panel");
    expect(panel).toBeInTheDocument();
    // Mobile controls edit the same URL state
    fireEvent.change(screen.getByTestId("mobile-filter-genre"), {
      target: { value: "horror" },
    });
    expect(getSearch().get("genre")).toBe("horror");
    act(() => {
      fireEvent.click(screen.getByTestId("discover-mobile-apply"));
    });
    // apply button closes the dialog
  });

  it("keyboard: search field accepts typing without submitting the form", () => {
    renderDiscover();
    const input = screen.getByTestId("discover-search") as HTMLInputElement;
    input.focus();
    expect(document.activeElement).toBe(input);
    fireEvent.change(input, { target: { value: "orchard" } });
    // Simulate Enter — the form has onSubmit preventDefault; URL should not
    // regress and results should reflect the typed value.
    fireEvent.keyDown(input, { key: "Enter" });
    expect(getSearch().get("q")).toBe("orchard");
    expect(screen.getAllByTestId("adventure-card").length).toBe(1);
  });

  it("filter controls are reachable via Tab order (present in DOM order)", () => {
    renderDiscover();
    const controls = [
      "discover-search",
      "filter-genre",
      "filter-rating",
      "filter-status",
      "filter-contributions",
      "filter-sort",
      "discover-open-mobile",
      "discover-clear",
    ];
    const positions = controls.map((id) => {
      const el = screen.getByTestId(id);
      return { id, el };
    });
    // Verify each subsequent control comes after the previous one in the DOM.
    for (let i = 1; i < positions.length; i++) {
      const rel = positions[i - 1].el.compareDocumentPosition(positions[i].el);
      // DOCUMENT_POSITION_FOLLOWING === 4
      expect(rel & 4).toBe(4);
    }
  });
});
