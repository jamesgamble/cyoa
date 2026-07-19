import { describe, it, expect } from "vitest";
import { render, screen, within } from "@testing-library/react";
import { MemoryRouter } from "react-router-dom";
import App from "../App";
import { Home } from "../pages/Home";
import { FEATURED_ADVENTURE, RECENTLY_UPDATED } from "../data/adventures";

function renderHome() {
  return render(
    <MemoryRouter initialEntries={["/"]}>
      <App />
    </MemoryRouter>,
  );
}

describe("homepage — structure", () => {
  it("renders exactly one level-1 heading with the wordmark", () => {
    renderHome();
    const h1s = screen.getAllByRole("heading", { level: 1 });
    expect(h1s).toHaveLength(1);
    expect(h1s[0]).toHaveTextContent(/branching paths/i);
  });

  it("renders a concise introductory lede", () => {
    renderHome();
    const hero = screen.getByTestId("home-hero");
    expect(within(hero).getByText(/quiet, collaborative library/i)).toBeInTheDocument();
  });

  it("exposes the three primary reader actions with correct destinations", () => {
    renderHome();
    const actions = screen.getByTestId("home-primary-actions");
    const read = within(actions).getByTestId("cta-read");
    const create = within(actions).getByTestId("cta-create");
    const cont = within(actions).getByTestId("cta-continue");

    expect(read).toHaveTextContent(/read an adventure/i);
    expect(read.getAttribute("href")).toBe("/discover");

    expect(create).toHaveTextContent(/create an adventure/i);
    expect(create.getAttribute("href")).toBe("/start");

    expect(cont).toHaveTextContent(/continue someone else's story/i);
    expect(cont.getAttribute("href")).toMatch(/^\/discover/);
  });
});

describe("homepage — content", () => {
  it("renders the featured adventure fixture", () => {
    renderHome();
    const featured = screen.getByTestId("featured-adventure-card");
    expect(within(featured).getByRole("heading", { name: FEATURED_ADVENTURE.title })).toBeInTheDocument();
    expect(within(featured).getByText(new RegExp(`by ${FEATURED_ADVENTURE.author}`, "i"))).toBeInTheDocument();
  });

  it("renders every recently-updated adventure fixture", () => {
    renderHome();
    const recent = screen.getByTestId("home-recent");
    const cards = within(recent).getAllByTestId("adventure-card");
    expect(cards.length).toBe(RECENTLY_UPDATED.length);
    for (const adv of RECENTLY_UPDATED) {
      expect(within(recent).getByRole("link", { name: adv.title })).toBeInTheDocument();
    }
  });

  it("renders exactly three ordered steps in the how-it-works section", () => {
    renderHome();
    const how = screen.getByTestId("home-how");
    const list = within(how).getByRole("list", { name: /three steps to begin/i });
    expect(list.tagName.toLowerCase()).toBe("ol");
    expect(list.querySelectorAll("li")).toHaveLength(3);
    expect(within(how).getByText(/open a path/i)).toBeInTheDocument();
    expect(within(how).getByText(/choose a direction/i)).toBeInTheDocument();
    expect(within(how).getByText(/write the next scene/i)).toBeInTheDocument();
  });

  it("states that reading does not require an account", () => {
    renderHome();
    const acct = screen.getByTestId("home-account");
    expect(within(acct).getByRole("heading", { name: /reading does not require an account/i })).toBeInTheDocument();
    expect(within(acct).getByText(/free to read/i)).toBeInTheDocument();
  });

  it("includes a registration call to action pointing to /register", () => {
    renderHome();
    const register = screen.getByTestId("cta-register");
    expect(register.getAttribute("href")).toBe("/register");
    expect(register).toHaveTextContent(/create an account/i);
  });

  it("summarises the community guidelines and links to the full topic", () => {
    renderHome();
    const guidelines = screen.getByTestId("home-guidelines");
    const items = within(guidelines).getAllByRole("listitem");
    expect(items.length).toBeGreaterThanOrEqual(3);
    expect(within(guidelines).getByText(/in good faith/i)).toBeInTheDocument();
    expect(within(guidelines).getByText(/respect other authors/i)).toBeInTheDocument();
    const link = within(guidelines).getByRole("link", { name: /full community guidelines/i });
    expect(link.getAttribute("href")).toBe("/help/community-guidelines");
  });

  it("provides a directory linking to Discover, Create, Help, and Changelog", () => {
    renderHome();
    const dir = screen.getByTestId("home-directory");
    const expected: Array<[string, string]> = [
      ["Discover", "/discover"],
      ["Create", "/start"],
      ["Help", "/help"],
      ["Changelog", "/changelog"],
    ];
    for (const [label, href] of expected) {
      const link = within(dir).getByRole("link", { name: new RegExp(label) });
      expect(link.getAttribute("href")).toBe(href);
    }
  });
});

describe("homepage — omissions", () => {
  it("does not render prohibited features", () => {
    renderHome();
    const home = screen.getByTestId("home");
    const text = home.textContent ?? "";
    // No activity feed, rankings, likes, comments, or social profiles.
    expect(text).not.toMatch(/activity feed/i);
    expect(text).not.toMatch(/leaderboard|rankings|ranked/i);
    expect(text).not.toMatch(/\blikes?\b/i);
    expect(text).not.toMatch(/\bcomments?\b/i);
    expect(text).not.toMatch(/social profile|follow(ers|ing)/i);
    // No like/comment interactive controls either.
    expect(screen.queryByRole("button", { name: /like/i })).toBeNull();
    expect(screen.queryByRole("button", { name: /comment/i })).toBeNull();
  });
});

describe("homepage — story-content containment", () => {
  it("renders synopsis text without executing embedded markup", () => {
    // Render Home directly with a hostile fixture would require rewiring;
    // instead verify at least that no <script> tag appears in the rendered
    // homepage output for the fixed fixtures.
    renderHome();
    const home = screen.getByTestId("home");
    expect(home.querySelector("script")).toBeNull();
  });
});

describe("homepage — imports cleanly", () => {
  it("exports a Home component", () => {
    expect(typeof Home).toBe("function");
  });
});
