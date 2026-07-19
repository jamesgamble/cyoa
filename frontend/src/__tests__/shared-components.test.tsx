import { describe, it, expect } from "vitest";
import { render, screen, within, fireEvent } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter } from "react-router-dom";
import { useState } from "react";

import {
  Wordmark,
  Masthead,
  MobileNav,
  Colophon,
  StoryPage,
  SceneTitle,
  StoryBody,
  Choice,
  ChoiceList,
  EndingPanel,
  AdventureCard,
  FeaturedAdventureCard,
  SearchField,
  Select,
  Checkbox,
  RadioGroup,
  Toggle,
  TextArea,
  PasswordField,
  FormSection,
  StepIndicator,
  ValidationMessage,
  InlineHelp,
  ManageNav,
  QueueItem,
  ActivityItem,
  WarningPanel,
  DangerZone,
  AdminTable,
  SearchFilterBar,
  EmptyState,
  ErrorState,
  Badge,
} from "../components";
import type { AdminColumn } from "../components";
import App from "../App";

function router(ui: React.ReactNode, path = "/") {
  return render(<MemoryRouter initialEntries={[path]}>{ui}</MemoryRouter>);
}

describe("shared components — presence on design system route", () => {
  it("exhibits every documented component", () => {
    router(<App />, "/design-system");
    // section anchors
    for (const id of [
      "ds-wordmark",
      "ds-colophon",
      "ds-buttons",
      "ds-inputs",
      "ds-choice-controls",
      "ds-form-composition",
      "ds-story",
      "ds-ending",
      "ds-cards",
      "ds-badges",
      "ds-cards-alerts",
      "ds-dialogs",
      "ds-manage-nav",
      "ds-panels",
      "ds-queue",
      "ds-warning-danger",
      "ds-search-filter",
      "ds-tables",
      "ds-states",
    ]) {
      expect(document.getElementById(id)).not.toBeNull();
    }
    // named components
    expect(screen.getAllByTestId("story-page").length).toBeGreaterThan(0);
    expect(screen.getByTestId("ending-panel")).toBeInTheDocument();
    expect(screen.getByTestId("featured-adventure-card")).toBeInTheDocument();
    expect(screen.getAllByTestId("adventure-card").length).toBeGreaterThan(0);
    expect(screen.getByTestId("step-indicator")).toBeInTheDocument();
    expect(screen.getByTestId("manage-nav")).toBeInTheDocument();
    expect(screen.getByTestId("queue-item")).toBeInTheDocument();
    expect(screen.getAllByTestId("activity-item").length).toBeGreaterThan(0);
    expect(screen.getByTestId("warning-panel")).toBeInTheDocument();
    expect(screen.getByTestId("danger-zone")).toBeInTheDocument();
    expect(screen.getByTestId("search-filter-bar")).toBeInTheDocument();
    expect(screen.getByTestId("admin-table")).toBeInTheDocument();
    expect(screen.getByTestId("empty-state")).toBeInTheDocument();
    expect(screen.getByTestId("error-state")).toBeInTheDocument();
  });
});

describe("wordmark and masthead", () => {
  it("wordmark links to home with an accessible label", () => {
    router(<Wordmark to="/" />);
    expect(screen.getByRole("link", { name: /branching paths, home/i })).toHaveAttribute("href", "/");
  });

  it("masthead exposes desktop navigation with current page marking", () => {
    router(<Masthead />, "/discover");
    // Two navs exist — desktop and mobile drawer.
    const primary = screen.getAllByRole("navigation", { name: /primary/i })[0];
    expect(within(primary).getByRole("link", { name: /discover/i })).toHaveAttribute("aria-current", "page");
  });

  it("masthead toggle opens the mobile drawer and toggles aria-expanded", async () => {
    const user = userEvent.setup();
    router(<Masthead />);
    const toggle = screen.getByTestId("masthead-toggle");
    expect(toggle).toHaveAttribute("aria-expanded", "false");
    await user.click(toggle);
    expect(toggle).toHaveAttribute("aria-expanded", "true");
    expect(screen.getByTestId("mobile-nav").getAttribute("data-open")).toBe("true");
  });
});

describe("mobile navigation — keyboard and containment", () => {
  it("hides mobile nav links from the tab order when closed", () => {
    router(
      <MobileNav
        id="m"
        open={false}
        onClose={() => {}}
        items={[
          { to: "/", label: "Home", end: true },
          { to: "/discover", label: "Discover" },
        ]}
      />,
    );
    const anchors = Array.from(
      screen.getByTestId("mobile-nav").querySelectorAll("a"),
    ) as HTMLAnchorElement[];
    expect(anchors.length).toBe(2);
    for (const link of anchors) {
      expect(link.getAttribute("tabindex")).toBe("-1");
    }
    expect(screen.getByTestId("mobile-nav")).toHaveAttribute("aria-hidden", "true");
  });

  it("closes on Escape", async () => {
    let closed = false;
    router(
      <MobileNav
        id="m"
        open={true}
        onClose={() => (closed = true)}
        items={[{ to: "/", label: "Home", end: true }]}
      />,
    );
    fireEvent.keyDown(window, { key: "Escape" });
    expect(closed).toBe(true);
  });

  it("closes on link activation", async () => {
    const user = userEvent.setup();
    let closed = false;
    router(
      <MobileNav
        id="m"
        open={true}
        onClose={() => (closed = true)}
        items={[{ to: "/discover", label: "Discover" }]}
      />,
    );
    const link = screen.getByTestId("mobile-nav").querySelector("a") as HTMLAnchorElement;
    await user.click(link);
    expect(closed).toBe(true);
  });
});

describe("colophon footer", () => {
  it("renders as a contentinfo landmark with attribution and directory", () => {
    router(<Colophon />);
    const footer = screen.getByRole("contentinfo");
    expect(within(footer).getByText(/a modest press/i)).toBeInTheDocument();
    expect(within(footer).getByRole("link", { name: /help/i })).toBeInTheDocument();
    expect(within(footer).getByRole("navigation", { name: /colophon/i })).toBeInTheDocument();
  });
});

/* -------------------------- Story containment ------------------------- */

describe("story containment — user content cannot control presentation", () => {
  const hostile =
    '<script>alert(1)</script><span style="color:red;background:blue;border:1px solid green;width:1px;position:absolute;text-align:right;font-family:Comic Sans MS">MALICIOUS</span>' +
    '\n\n' +
    'A quiet second paragraph.';

  it("StoryBody renders raw text as escaped paragraphs — no live HTML", () => {
    render(<StoryBody text={hostile} />);
    const body = screen.getByTestId("story-body");
    // No injected elements.
    expect(body.querySelector("script")).toBeNull();
    expect(body.querySelector("span")).toBeNull();
    expect(body.querySelector('[style]')).toBeNull();
    // The literal text appears somewhere.
    expect(body.textContent).toContain("MALICIOUS");
    // Two paragraphs, produced by blank-line splitting.
    expect(body.querySelectorAll("p").length).toBe(2);
  });

  it("SceneTitle only accepts string children and escapes markup", () => {
    render(<SceneTitle>{'<img src=x onerror=alert(1) />'}</SceneTitle>);
    const h1 = screen.getByRole("heading", { level: 1 });
    expect(h1.querySelector("img")).toBeNull();
    expect(h1.textContent).toContain("<img");
  });

  it("EndingPanel renders body as escaped paragraphs", () => {
    render(<EndingPanel title="The tide" body={hostile} />);
    const panel = screen.getByTestId("ending-panel");
    expect(panel.querySelector("script")).toBeNull();
    // No user-injected <span> ended up inside the rendered body copy.
    const body = panel.querySelector('[data-testid="ending-body"]') ?? panel;
    expect(body.querySelector("span")).toBeNull();
    expect(body.querySelector("[style]")).toBeNull();
    expect(body.textContent).toContain("MALICIOUS");
  });

  it("Choice renders label as text, never as markup", () => {
    render(
      <ol>
        <li>
          <Choice href="#">{'<b>bold</b>'}</Choice>
        </li>
      </ol>,
    );
    const link = screen.getByRole("link");
    expect(link.querySelector("b")).toBeNull();
    expect(link.textContent).toContain("<b>bold</b>");
  });
});

/* -------------------------- Story choices ---------------------------- */

describe("choices", () => {
  it("renders a numbered ordered list with accessible label", () => {
    render(
      <ChoiceList
        choices={[
          { label: "A", href: "#" },
          { label: "B", href: "#" },
          { label: "C", onClick: () => {} },
        ]}
      />,
    );
    const list = screen.getByTestId("choice-list");
    expect(list.tagName.toLowerCase()).toBe("ol");
    expect(list.getAttribute("aria-label")).toMatch(/choose your path/i);
    expect(list.querySelectorAll("li").length).toBe(3);
  });

  it("choice buttons are focusable and activate via keyboard", async () => {
    const user = userEvent.setup();
    let clicked = false;
    render(<Choice onClick={() => (clicked = true)}>Pick me</Choice>);
    const btn = screen.getByRole("button", { name: /pick me/i });
    btn.focus();
    expect(document.activeElement).toBe(btn);
    await user.keyboard("{Enter}");
    expect(clicked).toBe(true);
  });
});

/* -------------------------- Forms ------------------------------------ */

describe("form controls — labels, keyboard, and errors", () => {
  it("SearchField associates label and accepts input", async () => {
    const user = userEvent.setup();
    render(<SearchField label="Search adventures" placeholder="Search…" />);
    const input = screen.getByLabelText(/search adventures/i);
    await user.type(input, "lantern");
    expect((input as HTMLInputElement).value).toBe("lantern");
    expect(input).toHaveAttribute("type", "search");
  });

  it("Select renders error and marks aria-invalid", () => {
    render(
      <Select label="Visibility" error="Pick a visibility.">
        <option>Draft</option>
      </Select>,
    );
    const select = screen.getByLabelText(/visibility/i);
    expect(select).toHaveAttribute("aria-invalid", "true");
    expect(screen.getByRole("alert")).toHaveTextContent(/pick a visibility/i);
  });

  it("Checkbox toggles via keyboard (Space)", async () => {
    const user = userEvent.setup();
    function Host() {
      const [v, setV] = useState(false);
      return <Checkbox label="Agree" checked={v} onChange={(e) => setV(e.target.checked)} />;
    }
    render(<Host />);
    const box = screen.getByLabelText(/agree/i) as HTMLInputElement;
    box.focus();
    await user.keyboard(" ");
    expect(box.checked).toBe(true);
  });

  it("RadioGroup changes selection via keyboard arrows", async () => {
    const user = userEvent.setup();
    function Host() {
      const [v, setV] = useState("a");
      return (
        <RadioGroup
          legend="Pick"
          name="pick"
          value={v}
          onChange={setV}
          options={[
            { value: "a", label: "A" },
            { value: "b", label: "B" },
          ]}
        />
      );
    }
    render(<Host />);
    const a = screen.getByLabelText("A") as HTMLInputElement;
    a.focus();
    await user.keyboard("{ArrowDown}");
    expect((screen.getByLabelText("B") as HTMLInputElement).checked).toBe(true);
  });

  it("Toggle exposes switch role", () => {
    render(<Toggle label="Notify" defaultChecked />);
    expect(screen.getByRole("switch", { name: /notify/i })).toBeInTheDocument();
  });

  it("TextArea shows a character count when maxLength is set", () => {
    render(<TextArea label="Bio" maxLength={10} showCount defaultValue="hi" />);
    expect(screen.getByText(/2 \/ 10/)).toBeInTheDocument();
  });

  it("PasswordField reveal toggles input type and aria-pressed", async () => {
    const user = userEvent.setup();
    render(<PasswordField label="Password" defaultValue="secret" />);
    const input = screen.getByLabelText(/password/i) as HTMLInputElement;
    expect(input.type).toBe("password");
    const btn = screen.getByRole("button", { name: /show password/i });
    expect(btn).toHaveAttribute("aria-pressed", "false");
    await user.click(btn);
    expect(input.type).toBe("text");
    expect(screen.getByRole("button", { name: /hide password/i })).toHaveAttribute("aria-pressed", "true");
  });

  it("FormSection is a landmark region labelled by title", () => {
    render(
      <FormSection title="Details">
        <p>content</p>
      </FormSection>,
    );
    expect(screen.getByRole("region", { name: /details/i })).toBeInTheDocument();
  });

  it("StepIndicator marks the current step with aria-current", () => {
    render(
      <StepIndicator
        steps={[{ label: "one" }, { label: "two" }, { label: "three" }]}
        current={2}
      />,
    );
    const items = screen.getByTestId("step-indicator").querySelectorAll("li");
    expect(items[0].className).toMatch(/bp-step--done/);
    expect(items[1].className).toMatch(/bp-step--current/);
    expect(items[1].getAttribute("aria-current")).toBe("step");
    expect(items[2].className).toMatch(/bp-step--todo/);
  });

  it("ValidationMessage error uses alert role", () => {
    render(<ValidationMessage tone="error">Bad</ValidationMessage>);
    expect(screen.getByRole("alert")).toHaveTextContent(/bad/i);
  });

  it("InlineHelp renders as plain paragraph", () => {
    render(<InlineHelp>Use short titles.</InlineHelp>);
    expect(screen.getByText(/use short titles/i)).toBeInTheDocument();
  });
});

/* -------------------------- Management ------------------------------- */

describe("management components", () => {
  it("ManageNav marks active route with aria-current", () => {
    router(
      <ManageNav
        items={[
          { to: "/manage/x", label: "Overview", end: true },
          { to: "/manage/x/scenes", label: "Scenes" },
        ]}
      />,
      "/manage/x/scenes",
    );
    expect(screen.getByRole("link", { name: /scenes/i })).toHaveAttribute("aria-current", "page");
  });

  it("QueueItem shows a status badge and actions", () => {
    router(
      <QueueItem
        title="A submission"
        submitter="marisol"
        submittedAt="today"
        status="review"
        actions={<button>Approve</button>}
      />,
    );
    expect(screen.getByTestId("queue-item")).toBeInTheDocument();
    expect(screen.getByText(/review/i)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /approve/i })).toBeInTheDocument();
  });

  it("ActivityItem renders actor, action, and time", () => {
    render(
      <ul>
        <ActivityItem actor="rowan" action="published" target="Chapter 3" at="1h" />
      </ul>,
    );
    const item = screen.getByTestId("activity-item");
    expect(within(item).getByText("rowan")).toBeInTheDocument();
    expect(within(item).getByText("Chapter 3")).toBeInTheDocument();
    expect(within(item).getByText("1h")).toBeInTheDocument();
  });

  it("WarningPanel is a region labelled by its title", () => {
    render(
      <WarningPanel title="Paused">
        <p>Details.</p>
      </WarningPanel>,
    );
    expect(screen.getByRole("region", { name: /paused/i })).toBeInTheDocument();
  });

  it("DangerZone is a region labelled by its title", () => {
    render(<DangerZone>Content</DangerZone>);
    expect(screen.getByRole("region", { name: /danger zone/i })).toBeInTheDocument();
  });

  it("AdminTable renders caption, headers, rows, and an empty state", () => {
    interface R { id: string; name: string }
    const cols: AdminColumn<R>[] = [
      { key: "n", header: "Name", cell: (r) => r.name },
    ];
    const { rerender } = render(
      <AdminTable caption="Names" columns={cols} rows={[{ id: "1", name: "Fen" }]} rowKey={(r) => r.id} />,
    );
    expect(screen.getByText("Names").tagName.toLowerCase()).toBe("caption");
    expect(screen.getByRole("columnheader", { name: "Name" })).toBeInTheDocument();
    expect(screen.getByText("Fen")).toBeInTheDocument();
    rerender(
      <AdminTable caption="Names" columns={cols} rows={[]} rowKey={(r) => r.id} emptyMessage="No people." />,
    );
    expect(screen.getByText(/no people/i)).toBeInTheDocument();
  });

  it("SearchFilterBar is a search landmark with a search input", () => {
    render(
      <SearchFilterBar
        searchLabel="Search"
        searchPlaceholder="Find…"
        filters={<span>filter</span>}
      />,
    );
    expect(screen.getByRole("search")).toBeInTheDocument();
    expect(screen.getByRole("searchbox", { name: /search/i })).toBeInTheDocument();
  });
});

/* -------------------------- Discovery cards -------------------------- */

describe("adventure cards", () => {
  const adv = {
    slug: "x",
    title: "The Lantern Path",
    author: "Rowan",
    synopsis: "A quiet river.",
    status: "published" as const,
    sceneCount: 5,
  };

  it("AdventureCard links to the adventure and shows the byline", () => {
    router(<AdventureCard adventure={adv} />);
    expect(screen.getByRole("link", { name: /the lantern path/i })).toHaveAttribute("href", "/adventure/x");
    expect(screen.getByText(/by rowan/i)).toBeInTheDocument();
  });

  it("FeaturedAdventureCard uses a level-2 heading and CTA", () => {
    router(<FeaturedAdventureCard adventure={adv} />);
    expect(screen.getByRole("heading", { level: 2, name: /the lantern path/i })).toBeInTheDocument();
    expect(screen.getByRole("link", { name: /enter the story/i })).toBeInTheDocument();
  });
});

/* -------------------------- States ----------------------------------- */

describe("state components", () => {
  it("EmptyState shows title, message, and action", () => {
    render(<EmptyState title="Nothing" message="Yet." action={<button>Add</button>} />);
    const s = screen.getByTestId("empty-state");
    expect(within(s).getByText(/nothing/i)).toBeInTheDocument();
    expect(within(s).getByText(/yet\./i)).toBeInTheDocument();
    expect(within(s).getByRole("button", { name: /add/i })).toBeInTheDocument();
  });

  it("ErrorState is announced with alert role", () => {
    render(<ErrorState title="Boom" />);
    expect(screen.getByRole("alert")).toHaveTextContent(/boom/i);
  });
});

/* -------------------------- Badge ------------------------------------ */

describe("status badge", () => {
  it("renders a tone-specific class name", () => {
    render(<Badge tone="review">In review</Badge>);
    expect(document.querySelector(".bp-badge--review")).not.toBeNull();
  });
});

/* -------------------------- Focus visibility ------------------------- */

describe("focus visibility", () => {
  it("buttons expose a focus ring style hook via focus-visible", () => {
    render(<button className="bp-btn bp-btn--primary">Save</button>);
    const btn = screen.getByRole("button", { name: /save/i });
    btn.focus();
    expect(document.activeElement).toBe(btn);
  });
});

/* -------------------------- Mobile viewport marker ------------------- */

describe("mobile viewport containment", () => {
  it("primary desktop nav is present alongside a mobile nav drawer", () => {
    router(
      <StoryPage meta="Preview">
        <SceneTitle>Preview</SceneTitle>
        <StoryBody text="Just a paragraph." />
      </StoryPage>,
    );
    // Story components render on any width; body is preserved.
    expect(screen.getByTestId("story-body")).toBeInTheDocument();
  });
});
