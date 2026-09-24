import { describe, it, expect, vi, afterEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import { MemoryRouter } from "react-router-dom";
import { MasterOverview, MasterSettings } from "../pages/master";

const allPerms = (on: string[]) => Object.fromEntries([
  "view_console","view_users","view_adventures","view_submissions","review_reports","hide_content",
  "suspend_adventure","escalate_account","view_activity","manage_roles","suspend_user",
  "configure_registration","configure_anonymous","configure_limits","configure_smtp",
  "manage_email_queue","configure_maintenance","trigger_reset","transfer_ownership","view_security_activity",
].map((p) => [p, on.includes(p)]));

const MOD = ["view_console","view_users","view_adventures","view_submissions","review_reports","hide_content","suspend_adventure","escalate_account","view_activity"];

function mockApi(role: "admin" | "moderator") {
  const perms = role === "admin" ? allPerms(Object.keys(allPerms([]))) : allPerms(MOD);
  vi.stubGlobal("fetch", vi.fn(async (url: string) => {
    const body = url.includes("/master/me")
      ? { status: "ok", user_id: 1, display_name: "Ada", role, permissions: perms }
      : url.includes("/master/overview")
      ? { users: 3, suspended_users: 0, adventures: 2, suspended_adventures: 0, pending_submissions: 1, open_reports: 1, open_escalations: 0, maintenance_mode: false }
      : {};
    return new Response(JSON.stringify(body), { status: 200, headers: { "Content-Type": "application/json" } });
  }));
}

afterEach(() => vi.unstubAllGlobals());

describe("master console", () => {
  it("shows administrators every section", async () => {
    mockApi("admin");
    render(<MemoryRouter><MasterOverview /></MemoryRouter>);
    const nav = await screen.findByRole("navigation", { name: "Master sections" });
    for (const label of ["Users", "Adventures", "Submissions", "Reports", "Email queue", "Settings", "Activity"]) {
      expect(nav.textContent).toContain(label);
    }
  });

  it("hides admin-only sections from moderators and blocks the settings page", async () => {
    mockApi("moderator");
    render(<MemoryRouter><MasterSettings /></MemoryRouter>);
    const nav = await screen.findByRole("navigation", { name: "Master sections" });
    expect(nav.textContent).not.toContain("Settings");
    expect(nav.textContent).not.toContain("Email queue");
    await waitFor(() => expect(screen.getByText(/Your role cannot open this section/)).toBeTruthy());
  });
});
