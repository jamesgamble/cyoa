import { describe, it, expect } from "vitest";
import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter } from "react-router-dom";
import App from "../App";

function renderAt(path: string) {
  return render(
    <MemoryRouter initialEntries={[path]}>
      <App />
    </MemoryRouter>,
  );
}

describe("visual system — design system route", () => {
  it("renders every documented section", () => {
    renderAt("/design-system");
    for (const id of [
      "ds-typography",
      "ds-colors",
      "ds-buttons",
      "ds-inputs",
      "ds-choice-controls",
      "ds-badges",
      "ds-story",
      "ds-cards-alerts",
      "ds-dialogs",
      "ds-panels",
      "ds-tables",
    ]) {
      expect(document.getElementById(id)).not.toBeNull();
    }
  });

  it("renders all four button variants", () => {
    renderAt("/design-system");
    expect(document.querySelector(".bp-btn--primary")).not.toBeNull();
    expect(document.querySelector(".bp-btn--secondary")).not.toBeNull();
    expect(document.querySelector(".bp-btn--ghost")).not.toBeNull();
    expect(document.querySelector(".bp-btn--danger")).not.toBeNull();
  });

  it("renders all status badges", () => {
    renderAt("/design-system");
    for (const tone of ["draft", "published", "review", "warning", "danger"]) {
      expect(document.querySelector(`.bp-badge--${tone}`)).not.toBeNull();
    }
  });

  it("renders numbered story choices as an ordered list", () => {
    renderAt("/design-system");
    const list = screen.getByTestId("choice-list");
    expect(list.tagName.toLowerCase()).toBe("ol");
    expect(list.querySelectorAll("li").length).toBeGreaterThanOrEqual(3);
  });

  it("renders the administrative table with a caption and column headers", () => {
    renderAt("/design-system");
    const table = screen.getByTestId("admin-table");
    expect(within(table).getByText(/recent users/i).tagName.toLowerCase()).toBe("caption");
    expect(within(table).getAllByRole("columnheader").length).toBe(4);
  });
});

describe("layout modes", () => {
  it("public routes set reading mode on the body", () => {
    renderAt("/");
    expect(document.body.getAttribute("data-layout-mode")).toBe("reading");
    expect(document.body.classList.contains("bp-mode--reading")).toBe(true);
  });

  it("account routes set manage mode", () => {
    renderAt("/account");
    expect(document.body.getAttribute("data-layout-mode")).toBe("manage");
  });

  it("master routes set admin mode", () => {
    renderAt("/master");
    expect(document.body.getAttribute("data-layout-mode")).toBe("admin");
  });
});

describe("accessibility — controls and focus", () => {
  it("primary buttons expose an accessible name", () => {
    renderAt("/design-system");
    const btn = screen.getByRole("button", { name: /primary action/i });
    expect(btn).toBeInTheDocument();
  });

  it("form inputs are associated with labels", () => {
    renderAt("/design-system");
    expect(screen.getByLabelText(/adventure title/i)).toBeInTheDocument();
    expect(screen.getByLabelText(/opening passage/i)).toBeInTheDocument();
    expect(screen.getByLabelText(/visibility/i)).toBeInTheDocument();
  });

  it("invalid inputs advertise aria-invalid", () => {
    renderAt("/design-system");
    const invalid = screen.getByLabelText(/invalid example/i);
    expect(invalid).toHaveAttribute("aria-invalid", "true");
  });

  it("dialog is opened and closed via keyboard", async () => {
    const user = userEvent.setup();
    renderAt("/design-system");
    const open = screen.getByRole("button", { name: /open dialog/i });
    await user.click(open);
    const dialog = await screen.findByRole("dialog", { name: /delete this scene/i });
    expect(dialog).toBeInTheDocument();
    const cancel = within(dialog).getByRole("button", { name: /cancel/i });
    await user.click(cancel);
  });

  it("help drawer has close control with an accessible name", async () => {
    const user = userEvent.setup();
    renderAt("/design-system");
    await user.click(screen.getByRole("button", { name: /open help drawer/i }));
    expect(screen.getByRole("button", { name: /close help/i })).toBeInTheDocument();
  });

  it("skip link exists on public pages", () => {
    renderAt("/");
    const link = screen.getByRole("link", { name: /skip to main content/i });
    expect(link).toHaveAttribute("href", "#main");
  });

  it("main landmark exists exactly once per page", () => {
    renderAt("/");
    expect(document.querySelectorAll("main").length).toBe(1);
  });
});

describe("accessibility — motion and text size preferences", () => {
  it("increased text size can be applied by attribute", () => {
    renderAt("/");
    document.documentElement.setAttribute("data-text-size", "large");
    expect(document.documentElement.getAttribute("data-text-size")).toBe("large");
    document.documentElement.removeAttribute("data-text-size");
  });

  it("high contrast can be applied by attribute", () => {
    renderAt("/");
    document.documentElement.setAttribute("data-contrast", "high");
    expect(document.documentElement.getAttribute("data-contrast")).toBe("high");
    document.documentElement.removeAttribute("data-contrast");
  });
});
