/**
 * Focused tests for the v0.14.0 account dashboard shell.
 * The heavy authorization coverage lives in tests/php/account_test.php;
 * these cases pin the shape of the client so a regression to the layout
 * or route wiring surfaces in `bunx vitest run`.
 */
import { render, screen } from "@testing-library/react";
import { MemoryRouter, Route, Routes } from "react-router-dom";
import { describe, it, expect } from "vitest";
import { AccountLayout } from "../layouts/AccountLayout";
import {
  AccountOverview,
  AccountProfilePage,
  AccountSecurityPage,
  AccountNotificationsPage,
  AccountAdventuresPage,
  AccountContributionsPage,
  AccountBookmarksPage,
} from "../pages/AccountPages";

function renderAt(path: string) {
  return render(
    <MemoryRouter initialEntries={[path]}>
      <Routes>
        <Route element={<AccountLayout />}>
          <Route path="/account" element={<AccountOverview />} />
          <Route path="/account/profile" element={<AccountProfilePage />} />
          <Route path="/account/security" element={<AccountSecurityPage />} />
          <Route path="/account/notifications" element={<AccountNotificationsPage />} />
          <Route path="/account/adventures" element={<AccountAdventuresPage />} />
          <Route path="/account/contributions" element={<AccountContributionsPage />} />
          <Route path="/account/bookmarks" element={<AccountBookmarksPage />} />
        </Route>
      </Routes>
    </MemoryRouter>,
  );
}

describe("account dashboard — v0.14.0", () => {
  it("renders the account sub-navigation with every section", () => {
    renderAt("/account");
    const nav = screen.getByRole("navigation", { name: /account sections/i });
    for (const label of [
      "Overview", "Profile", "Security", "Notifications",
      "My adventures", "Contributions", "Bookmarks",
    ]) {
      expect(nav).toHaveTextContent(label);
    }
  });

  it("marks only the current sub-route as active", () => {
    renderAt("/account/profile");
    const active = screen
      .getByRole("navigation", { name: /account sections/i })
      .querySelectorAll("a.is-active");
    expect(active).toHaveLength(1);
    expect(active[0].getAttribute("href")).toBe("/account/profile");
  });

  it("mounts each sub-page under the /account prefix", () => {
    for (const path of [
      "/account", "/account/profile", "/account/security",
      "/account/notifications", "/account/adventures",
      "/account/contributions", "/account/bookmarks",
    ]) {
      const { unmount } = renderAt(path);
      expect(
        screen.getByRole("navigation", { name: /account sections/i }),
      ).toBeInTheDocument();
      unmount();
    }
  });
});
