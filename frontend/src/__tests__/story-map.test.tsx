/**
 * Focused tests for v0.23.0 — the story map.
 *
 * The visibility and integrity rules are proven server-side in
 * tests/php/story_map_test.php. These cases pin what a person can see
 * and do: the nested outline, collapsing a branch, loading a deep
 * branch on demand, searching, the labels only the team sees, the
 * story check, and the keyboard/assistive-tech contract.
 */
import { render, screen, fireEvent, waitFor, within } from "@testing-library/react";
import { MemoryRouter, Route, Routes } from "react-router-dom";
import { describe, it, expect, beforeEach, afterEach, vi } from "vitest";
import { StoryOutline, StoryValidationPanel } from "../components";

interface NodeSpec {
  id: string;
  title: string;
  children?: NodeSpec[];
  choice?: string;
  has_more?: boolean;
  child_count?: number;
  label?: string;
  start?: boolean;
  ending?: boolean;
}

let sceneId = 0;
function node(spec: NodeSpec, depth = 0): Record<string, unknown> {
  const children = (spec.children ?? []).map((c) => node(c, depth + 1));
  return {
    id: spec.id,
    scene_id: ++sceneId,
    number: sceneId,
    title: spec.title,
    type: spec.ending ? "ending" : "story",
    is_start: Boolean(spec.start),
    depth,
    choice_label: spec.choice ?? null,
    child_count: spec.child_count ?? children.length,
    has_more: Boolean(spec.has_more),
    children,
    ...(spec.label ? { state: spec.label.toLowerCase(), label: spec.label } : {}),
  };
}

const TREE = node({
  id: "the-jetty",
  title: "The jetty",
  start: true,
  children: [
    { id: "salt-flats", title: "The salt flats", choice: "Walk out along the flats", has_more: true, child_count: 2 },
    { id: "chapel", title: "The chapel", choice: "Shelter in the chapel", ending: true },
  ],
});

function mapPayload(extra: Record<string, unknown> = {}) {
  return {
    status: "ok",
    adventure: { slug: "salt-road", title: "The Salt Road", state: "published" },
    scope: "public",
    role: null,
    root: "the-jetty",
    depth: 3,
    max_depth: 8,
    total_scenes: 6,
    loaded_scenes: 3,
    truncated: false,
    tree: [TREE],
    ...extra,
  };
}

const requests: string[] = [];

function json(body: unknown, status = 200) {
  return new Response(JSON.stringify(body), {
    status,
    headers: { "Content-Type": "application/json" },
  });
}

let responder: (url: string) => Response = () => json(mapPayload());

beforeEach(() => {
  requests.length = 0;
  vi.stubGlobal("fetch", (async (input: RequestInfo | URL) => {
    const url = String(input);
    requests.push(url);
    return responder(url);
  }) as typeof fetch);
});

afterEach(() => {
  vi.unstubAllGlobals();
  responder = () => json(mapPayload());
});

function renderOutline(props: Record<string, unknown> = {}) {
  return render(
    <MemoryRouter initialEntries={["/adventure/salt-road/map"]}>
      <Routes>
        <Route
          path="/adventure/:slug/map"
          element={<StoryOutline slug="salt-road" {...props} />}
        />
      </Routes>
    </MemoryRouter>,
  );
}

describe("story outline", () => {
  it("nests each scene under the choice that leads to it", async () => {
    renderOutline();
    await screen.findByText("The salt flats");
    const rows = screen.getAllByTestId("outline-node");
    expect(rows).toHaveLength(3);
    expect(rows[0]).toHaveAttribute("data-level", "0");
    expect(rows[1]).toHaveAttribute("data-level", "1");
    expect(within(rows[1]).getByTestId("outline-choice")).toHaveTextContent(
      "Walk out along the flats",
    );
  });

  it("marks the opening scene and the endings", async () => {
    renderOutline();
    await screen.findByText("The chapel");
    expect(screen.getByText("Opening scene")).toBeInTheDocument();
    expect(screen.getByText("Ending")).toBeInTheDocument();
  });

  it("asks the public endpoint, not the owner one", async () => {
    renderOutline();
    await screen.findByText("The jetty");
    expect(requests[0]).toContain("/adventures/salt-road/map");
    expect(requests[0]).not.toContain("moderation");
  });

  it("says so plainly when the outline cannot be loaded", async () => {
    responder = () => json({ error: "not_found" }, 404);
    renderOutline();
    expect(
      await screen.findByText(/could not be loaded/i),
    ).toBeInTheDocument();
  });
});

describe("collapsing branches", () => {
  it("folds a branch away and reports how many choices it holds", async () => {
    renderOutline();
    await screen.findByText("The salt flats");
    const root = screen.getAllByTestId("outline-node")[0];
    fireEvent.click(within(root).getByTestId("outline-toggle"));
    expect(screen.queryByText("The salt flats")).not.toBeInTheDocument();
    expect(root).toHaveAttribute("aria-expanded", "false");
    expect(root.textContent).toMatch(/2 branches/);
  });

  it("unfolds it again without a second request", async () => {
    renderOutline();
    await screen.findByText("The salt flats");
    const before = requests.length;
    const root = screen.getAllByTestId("outline-node")[0];
    fireEvent.click(within(root).getByTestId("outline-toggle"));
    fireEvent.click(within(root).getByTestId("outline-toggle"));
    await screen.findByText("The salt flats");
    expect(requests.length).toBe(before);
  });
});

describe("lazy loading", () => {
  it("fetches a branch only when it is opened", async () => {
    responder = (url) => {
      if (url.includes("root=salt-flats")) {
        return json(
          mapPayload({
            root: "salt-flats",
            tree: [
              node({
                id: "salt-flats",
                title: "The salt flats",
                children: [{ id: "mile-one", title: "Mile one", choice: "Walk on" }],
              }),
            ],
          }),
        );
      }
      return json(mapPayload());
    };
    renderOutline();
    const branch = await screen.findByText("The salt flats");
    expect(screen.queryByText("Mile one")).not.toBeInTheDocument();
    expect(requests.some((u) => u.includes("root="))).toBe(false);

    const row = branch.closest('[data-testid="outline-node"]')!;
    fireEvent.click(within(row as HTMLElement).getByTestId("outline-toggle"));
    expect(await screen.findByText("Mile one")).toBeInTheDocument();
    expect(requests.some((u) => u.includes("root=salt-flats"))).toBe(true);
  });

  it("tells the reader when a large story is only partly shown", async () => {
    responder = () => json(mapPayload({ truncated: true, total_scenes: 400 }));
    renderOutline();
    expect(await screen.findByTestId("outline-truncated")).toHaveTextContent(
      /only part of it is shown/i,
    );
  });
});

describe("search", () => {
  it("lists matches with the path back to the opening scene", async () => {
    responder = (url) =>
      url.includes("q=")
        ? json(
            mapPayload({
              query: "mile",
              matches: [
                {
                  id: "mile-three",
                  title: "Mile three",
                  number: 4,
                  type: "story",
                  path: ["The jetty", "The salt flats"],
                  reachable: true,
                },
              ],
            }),
          )
        : json(mapPayload());
    renderOutline();
    await screen.findByText("The jetty");
    fireEvent.change(screen.getByTestId("outline-search-input"), {
      target: { value: "mile" },
    });
    fireEvent.click(screen.getByTestId("outline-search"));
    const match = await screen.findByTestId("outline-match");
    expect(match).toHaveTextContent("Mile three");
    expect(match).toHaveTextContent("The jetty → The salt flats");
  });

  it("says clearly when nothing matches", async () => {
    responder = (url) =>
      url.includes("q=")
        ? json(mapPayload({ query: "submarine", matches: [] }))
        : json(mapPayload());
    renderOutline();
    await screen.findByText("The jetty");
    fireEvent.change(screen.getByTestId("outline-search-input"), {
      target: { value: "submarine" },
    });
    fireEvent.click(screen.getByTestId("outline-search"));
    expect(await screen.findByText(/No scene title matches/)).toBeInTheDocument();
  });
});

describe("what each audience sees", () => {
  it("shows no draft or hidden labels on the public outline", async () => {
    renderOutline();
    await screen.findByText("The jetty");
    expect(screen.queryByTestId("outline-state-label")).not.toBeInTheDocument();
  });

  it("labels drafts and hidden scenes on the owner map", async () => {
    responder = () =>
      json(
        mapPayload({
          scope: "manage",
          role: "owner",
          tree: [
            node({
              id: "the-jetty",
              title: "The jetty",
              start: true,
              label: "Published",
              children: [
                { id: "unfinished", title: "An unfinished turn", choice: "Turn", label: "Draft" },
                { id: "retired", title: "A retired ending", choice: "Retire", label: "Hidden" },
              ],
            }),
          ],
        }),
      );
    renderOutline({ scope: "manage", linkToReader: false });
    await screen.findByText("An unfinished turn");
    const labels = screen.getAllByTestId("outline-state-label").map((n) => n.textContent);
    expect(labels).toContain("Draft");
    expect(labels).toContain("Hidden");
    expect(requests[0]).toContain("/moderation/map");
    expect(screen.getByTestId("story-outline")).toHaveAttribute("data-scope", "manage");
  });
});

describe("accessibility", () => {
  it("is exposed as a tree with levels and expansion state", async () => {
    renderOutline();
    await screen.findByText("The salt flats");
    expect(screen.getByRole("tree")).toBeInTheDocument();
    const items = screen.getAllByRole("treeitem");
    expect(items).toHaveLength(3);
    expect(items[0]).toHaveAttribute("aria-level", "1");
    expect(items[0]).toHaveAttribute("aria-expanded", "true");
    expect(items[1]).toHaveAttribute("aria-level", "2");
    // A leaf must not claim to be collapsible.
    expect(items[2]).not.toHaveAttribute("aria-expanded");
  });

  it("uses a roving tab stop so the tree is one stop in the page", async () => {
    renderOutline();
    await screen.findByText("The salt flats");
    const items = screen.getAllByRole("treeitem");
    expect(items.filter((i) => i.getAttribute("tabindex") === "0")).toHaveLength(1);
  });

  it("moves with the arrow keys and closes a branch with the left arrow", async () => {
    renderOutline();
    await screen.findByText("The salt flats");
    const items = screen.getAllByRole("treeitem");
    items[0].focus();
    fireEvent.keyDown(items[0], { key: "ArrowDown" });
    await waitFor(() =>
      expect(document.activeElement).toBe(screen.getAllByRole("treeitem")[1]),
    );
    fireEvent.keyDown(screen.getAllByRole("treeitem")[0], { key: "ArrowLeft" });
    await waitFor(() => expect(screen.getAllByRole("treeitem")).toHaveLength(1));
    fireEvent.keyDown(screen.getAllByRole("treeitem")[0], { key: "ArrowRight" });
    await waitFor(() => expect(screen.getAllByRole("treeitem")).toHaveLength(3));
  });

  it("gives the fold control a name that says what it will do", async () => {
    renderOutline();
    await screen.findByText("The salt flats");
    expect(
      screen.getByRole("button", { name: /Collapse The jetty/i }),
    ).toBeInTheDocument();
  });
});

describe("story check", () => {
  function renderCheck(payload: unknown) {
    responder = () => json(payload);
    return render(
      <MemoryRouter>
        <StoryValidationPanel slug="salt-road" />
      </MemoryRouter>,
    );
  }

  it("reports a clean story plainly", async () => {
    renderCheck({
      status: "ok",
      adventure: { slug: "salt-road", title: "The Salt Road", state: "published" },
      issues: [],
      summary: { errors: 0, warnings: 0, total: 0 },
    });
    expect(await screen.findByTestId("validation-summary")).toHaveTextContent(
      /No problems found/i,
    );
  });

  it("lists each problem with the scene it belongs to", async () => {
    renderCheck({
      status: "ok",
      adventure: { slug: "salt-road", title: "The Salt Road", state: "published" },
      issues: [
        {
          code: "missing_destination", severity: "error", scene: "the-jetty",
          scene_title: "The jetty", choice: "Turn back",
          message: "The choice “Turn back” points at a scene that no longer exists.",
        },
        {
          code: "unreachable_scene", severity: "warning", scene: "orphan",
          scene_title: "An orphaned room", choice: null,
          message: "Nothing leads to this scene.",
        },
      ],
      summary: { errors: 1, warnings: 1, total: 2 },
    });
    const issues = await screen.findAllByTestId("validation-issue");
    expect(issues).toHaveLength(2);
    expect(issues[0]).toHaveAttribute("data-code", "missing_destination");
    expect(issues[0]).toHaveTextContent("Choice leads nowhere");
    expect(issues[1]).toHaveAttribute("data-severity", "warning");
    expect(screen.getByTestId("validation-summary")).toHaveTextContent(
      "1 to fix, 1 to look at",
    );
  });

  it("asks the manager-only endpoint", async () => {
    renderCheck({
      status: "ok",
      adventure: { slug: "salt-road", title: "The Salt Road", state: "published" },
      issues: [],
      summary: { errors: 0, warnings: 0, total: 0 },
    });
    await screen.findByTestId("validation-summary");
    expect(requests[0]).toContain("/moderation/validation");
  });
});
