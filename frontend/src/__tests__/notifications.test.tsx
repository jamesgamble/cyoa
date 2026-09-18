/**
 * Focused tests for v0.22.0 notifications, email preferences, and follows.
 *
 * The delivery rules themselves are proven in
 * tests/php/notifications_test.php; these cases pin the client
 * behaviour a reader can actually see: what the inbox offers, which
 * switches are locked, and that a follow is not a bookmark.
 */
import { render, screen, fireEvent, waitFor } from "@testing-library/react";
import { MemoryRouter, Route, Routes } from "react-router-dom";
import { describe, it, expect, beforeEach, afterEach, vi } from "vitest";
import { AccountInboxPage } from "../pages/Inbox";
import { AccountNotificationsPage } from "../pages/AccountPages";

const INBOX = {
  status: "ok",
  unread: 2,
  notifications: [
    {
      id: 1, kind: "submission_approved", label: "Your branch was published",
      title: "Your branch was published — The Tidewatch Light",
      body: "Your contribution is now part of the story.",
      url: "/account/contributions", adventure_id: 4, adventure_slug: "tidewatch",
      read_at: null, created_at: "2026-01-04T10:00:00Z", routine: true,
    },
    {
      id: 2, kind: "account_security", label: "Account security",
      title: "New sign-in to your account",
      body: "If this was not you, change your password.",
      url: "/account/security", adventure_id: null, adventure_slug: null,
      read_at: null, created_at: "2026-01-03T10:00:00Z", routine: false,
    },
  ],
};

const PREFERENCES = {
  status: "ok",
  preferences: [
    { kind: "submission_received", label: "A branch was submitted to your adventure", email: true, locked: false, aggregated: false },
    { kind: "followed_adventure_updated", label: "An adventure you follow was updated", email: true, locked: false, aggregated: true },
    { kind: "account_security", label: "Account security", email: true, locked: true, aggregated: false },
  ],
  following: [{ slug: "tidewatch", title: "The Tidewatch Light", created_at: "2026-01-02T09:00:00Z" }],
};

const requests: Array<{ url: string; method: string }> = [];

function json(body: unknown, status = 200) {
  return new Response(JSON.stringify(body), {
    status, headers: { "Content-Type": "application/json" },
  });
}

beforeEach(() => {
  requests.length = 0;
  vi.stubGlobal("fetch", (async (input: RequestInfo | URL, init?: RequestInit) => {
    const url = String(input);
    const method = init?.method ?? "GET";
    requests.push({ url, method });
    if (url.includes("/csrf")) return json({ token: "t" });
    if (url.includes("/notifications/preferences")) {
      return method === "GET" ? json(PREFERENCES) : json({ status: "ok", preferences: PREFERENCES.preferences });
    }
    if (url.includes("/notifications")) {
      if (method === "GET") return json(INBOX);
      return json({ status: "ok", unread: 0, deleted: 1 });
    }
    if (url.includes("/follow")) return json({ status: "ok", following: false });
    return json({}, 404);
  }) as typeof fetch);
});

afterEach(() => { vi.unstubAllGlobals(); });

function renderInbox() {
  return render(
    <MemoryRouter initialEntries={["/account/inbox"]}>
      <Routes><Route path="/account/inbox" element={<AccountInboxPage />} /></Routes>
    </MemoryRouter>,
  );
}

function renderPreferences() {
  return render(
    <MemoryRouter initialEntries={["/account/notifications"]}>
      <Routes><Route path="/account/notifications" element={<AccountNotificationsPage />} /></Routes>
    </MemoryRouter>,
  );
}

describe("inbox — v0.22.0", () => {
  it("lists notifications and marks the unread ones", async () => {
    renderInbox();
    const items = await screen.findAllByTestId("inbox-item");
    expect(items).toHaveLength(2);
    expect(screen.getByText(/Your branch was published —/)).toBeInTheDocument();
    expect(screen.getAllByText("New")).toHaveLength(2);
  });

  it("offers delete for routine items only", async () => {
    renderInbox();
    await screen.findAllByTestId("inbox-item");
    const deletes = screen.getAllByRole("button", { name: /^Delete notification/ });
    expect(deletes).toHaveLength(1);
    expect(deletes[0]).toHaveAttribute(
      "aria-label",
      expect.stringContaining("Your branch was published"),
    );
    expect(screen.getByText("Security")).toBeInTheDocument();
  });

  it("marks everything read in one call", async () => {
    renderInbox();
    await screen.findAllByTestId("inbox-item");
    fireEvent.click(screen.getByRole("button", { name: /mark all read/i }));
    await waitFor(() =>
      expect(requests.some((r) => r.method === "POST" && r.url.endsWith("/notifications/read"))).toBe(true),
    );
  });

  it("clears read notifications", async () => {
    renderInbox();
    await screen.findAllByTestId("inbox-item");
    fireEvent.click(screen.getByRole("button", { name: /clear read/i }));
    await waitFor(() =>
      expect(requests.some((r) => r.method === "DELETE" && r.url.endsWith("/notifications/read"))).toBe(true),
    );
  });
});

describe("email preferences — v0.22.0", () => {
  it("locks security email on", async () => {
    renderPreferences();
    const security = await screen.findByTestId("pref-account_security");
    expect(security).toBeChecked();
    expect(security).toBeDisabled();
    expect(
      screen.getByText(/Security and recovery email cannot be switched off/i),
    ).toBeInTheDocument();
  });

  it("says routine followed-adventure email is bundled", async () => {
    renderPreferences();
    await screen.findByTestId("pref-followed_adventure_updated");
    expect(
      screen.getByText(/one bundled update, not one email per change/i),
    ).toBeInTheDocument();
  });

  it("saves only the switches a reader may change", async () => {
    renderPreferences();
    const submissions = await screen.findByTestId("pref-submission_received");
    fireEvent.click(submissions);
    fireEvent.click(screen.getByRole("button", { name: /save preferences/i }));
    await waitFor(() =>
      expect(requests.some((r) => r.method === "PUT" && r.url.includes("/notifications/preferences"))).toBe(true),
    );
    expect(await screen.findByText("Preferences saved.")).toBeInTheDocument();
  });

  it("separates following from bookmarks in the reader's own words", async () => {
    renderPreferences();
    expect(await screen.findByTestId("following-list")).toHaveTextContent("The Tidewatch Light");
    expect(
      screen.getByText(/separate from a bookmark, which remembers where you stopped reading/i),
    ).toBeInTheDocument();
  });

  it("unfollows an adventure without touching reading progress", async () => {
    window.localStorage.setItem(
      "bp-progress:tidewatch",
      JSON.stringify({ sceneSlug: "the-lamp-room", bookmarks: [], history: [] }),
    );
    renderPreferences();
    await screen.findByTestId("following-list");
    fireEvent.click(screen.getByRole("button", { name: /unfollow/i }));
    await waitFor(() =>
      expect(requests.some((r) => r.method === "DELETE" && r.url.includes("/follow"))).toBe(true),
    );
    expect(window.localStorage.getItem("bp-progress:tidewatch")).not.toBeNull();
  });
});
