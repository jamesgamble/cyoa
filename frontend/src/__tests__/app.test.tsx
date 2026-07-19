import { describe, it, expect } from "vitest";
import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter } from "react-router-dom";
import App from "../App";
import { APP_VERSION } from "../lib/version";

function renderAt(path: string) {
  return render(
    <MemoryRouter initialEntries={[path]}>
      <App />
    </MemoryRouter>,
  );
}

describe("routing", () => {
  const routes: Array<[string, RegExp]> = [
    ["/", /Branching Paths/i],
    ["/discover", /Discover/i],
    ["/start", /Create an adventure/i],
    ["/help", /Help/i],
    ["/help/getting-started", /Getting started/i],
    ["/changelog", /Changelog/i],
    ["/changelog/0.1.0", /Version 0\.1\.0/i],
    ["/login", /Sign in/i],
    ["/register", /Create an account/i],
    ["/account", /Account/i],
    ["/manage/example-adventure", /Manage example-adventure/i],
    ["/master/login", /Master sign in/i],
    ["/master", /Master administration/i],
  ];

  it.each(routes)("renders %s", (path, matcher) => {
    renderAt(path);
    expect(screen.getAllByText(matcher).length).toBeGreaterThan(0);
  });
});

describe("primary navigation", () => {
  it("renders all nav items", () => {
    renderAt("/");
    const nav = screen.getByRole("navigation", { name: /primary/i });
    for (const label of ["Home", "Discover", "Create", "Help", "Changelog", "Sign In"]) {
      expect(within(nav).getByRole("link", { name: label })).toBeInTheDocument();
    }
  });

  it("marks the active link", () => {
    renderAt("/help");
    const nav = screen.getByRole("navigation", { name: /primary/i });
    const helpLink = within(nav).getByRole("link", { name: "Help" });
    expect(helpLink).toHaveAttribute("aria-current", "page");
  });
});

describe("changelog links", () => {
  it("index has direct link to the version page", () => {
    renderAt("/changelog");
    const link = screen.getByRole("link", { name: /Version 0\.1\.0/i });
    expect(link).toHaveAttribute("href", "/changelog/0.1.0");
  });

  it("unknown version renders a not-found notice", () => {
    renderAt("/changelog/9.9.9");
    expect(screen.getByText(/Version not found/i)).toBeInTheDocument();
  });
});

describe("version display", () => {
  it("footer shows current VERSION", () => {
    renderAt("/");
    const footer = screen.getByRole("contentinfo");
    expect(within(footer).getByTestId("app-version")).toHaveTextContent(`v${APP_VERSION}`);
    expect(APP_VERSION).toBe("0.1.0");
  });
});

describe("not-found page", () => {
  it("renders on unknown routes", () => {
    renderAt("/this-route-does-not-exist");
    expect(screen.getByTestId("not-found")).toBeInTheDocument();
  });
});

describe("responsive navigation", () => {
  it("mobile toggle exposes the nav and toggles aria-expanded", async () => {
    const user = userEvent.setup();
    renderAt("/");
    const toggle = screen.getByRole("button", { name: /toggle navigation menu/i });
    expect(toggle).toHaveAttribute("aria-expanded", "false");
    await user.click(toggle);
    expect(toggle).toHaveAttribute("aria-expanded", "true");
    const nav = screen.getByRole("navigation", { name: /primary/i });
    expect(nav).toHaveAttribute("data-open", "true");
    // Same items exposed
    for (const label of ["Home", "Discover", "Create", "Help", "Changelog", "Sign In"]) {
      expect(within(nav).getByRole("link", { name: label })).toBeInTheDocument();
    }
  });
});

describe("keyboard navigation", () => {
  it("nav links and the menu toggle are reachable by tabbing", async () => {
    const user = userEvent.setup();
    renderAt("/");
    // Tab through and collect focused element labels; assert nav items are in the sequence.
    const seen: string[] = [];
    for (let i = 0; i < 12; i++) {
      await user.tab();
      const el = document.activeElement as HTMLElement | null;
      if (el && el !== document.body) {
        seen.push((el.getAttribute("aria-label") || el.textContent || "").trim());
      }
    }
    expect(seen).toEqual(expect.arrayContaining(["Home", "Discover", "Create", "Help", "Changelog", "Sign In"]));
    expect(seen.some((s) => /toggle navigation menu/i.test(s))).toBe(true);
  });
});
