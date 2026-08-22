/**
 * Focused tests for the v0.16.0 adventure creation wizard.
 *
 * Authorization, transactional writes, rollback, and the real
 * rate-limit accounting are covered server-side in
 * tests/php/adventure_creation_test.php. These cases pin the client:
 * step flow, template behaviour, validation, limit messaging, the
 * unauthenticated state, and the shape of the submitted payload.
 */
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter, Route, Routes } from "react-router-dom";
import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { CreateAdventure, validateStep } from "../pages/CreateAdventure";
import { HelpProvider } from "../components/GlobalHelp";

const SETTINGS = {
  templates: {
    solo: {
      label: "Solo story", visibility: "public", contribution_mode: "closed",
      anonymous_contributions: false, max_branches_per_scene: 4, requires_passcode: false,
    },
    "open-community": {
      label: "Open community story", visibility: "public", contribution_mode: "immediate",
      anonymous_contributions: true, max_branches_per_scene: 6, requires_passcode: false,
    },
    "moderated-community": {
      label: "Moderated community story", visibility: "public", contribution_mode: "approval",
      anonymous_contributions: false, max_branches_per_scene: 4, requires_passcode: false,
    },
    "private-group": {
      label: "Private group story", visibility: "unlisted", contribution_mode: "approval",
      anonymous_contributions: false, max_branches_per_scene: 3, requires_passcode: true,
    },
  },
  genres: ["fantasy", "mystery"],
  content_ratings: ["everyone", "teen", "mature"],
  visibilities: ["public", "unlisted"],
  contribution_modes: ["immediate", "approval", "closed"],
  statuses: ["draft", "published"],
  max_branches_min: 2,
  max_branches_max: 10,
  limits: {
    max_adventures_per_user: 20,
    adventures_per_user_per_hour: 5,
    owned: 3,
    recent: 1,
  },
  can_create: true,
} as const;

interface FakeResponse {
  status: number;
  body: unknown;
}

let posted: Array<{ path: string; body: Record<string, unknown> }> = [];
let settingsResponse: FakeResponse = { status: 200, body: SETTINGS };
let createResponse: FakeResponse = {
  status: 201,
  body: { status: "ok", adventure: { id: 7, slug: "the-lantern-road", title: "The Lantern Road", state: "draft" } },
};

function installFetch() {
  vi.stubGlobal("fetch", vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
    const url = String(input);
    const make = (r: FakeResponse) =>
      ({ ok: r.status >= 200 && r.status < 300, status: r.status, json: async () => r.body }) as Response;
    if (url.includes("/csrf-token")) return make({ status: 200, body: { token: "test-token" } });
    if (url.includes("/adventures/creation-settings")) return make(settingsResponse);
    if (url.endsWith("/adventures") && init?.method === "POST") {
      posted.push({ path: url, body: JSON.parse(String(init.body)) as Record<string, unknown> });
      return make(createResponse);
    }
    return make({ status: 404, body: {} });
  }));
}

function renderWizard() {
  return render(
    <MemoryRouter initialEntries={["/start"]}>
      <HelpProvider>
        <Routes>
          <Route path="/start" element={<CreateAdventure />} />
          <Route path="/manage/:slug" element={<p>Manage page</p>} />
        </Routes>
      </HelpProvider>
    </MemoryRouter>,
  );
}

/** Walk the wizard to the review step with a valid draft. */
async function fillToReview(user: ReturnType<typeof userEvent.setup>) {
  await user.type(screen.getByLabelText("Title"), "The Lantern Road");
  await user.type(screen.getByLabelText("Description"), "A winter journey.");
  await user.click(screen.getByRole("button", { name: "Continue" }));

  await user.type(await screen.findByLabelText("Scene title"), "The gate at dusk");
  const surface = screen.getByRole("textbox", { name: /scene text/i });
  surface.innerHTML = "<p>Snow gathers on the iron gate.</p>";
  surface.dispatchEvent(new Event("input", { bubbles: true }));
  await user.click(screen.getByRole("button", { name: "Continue" }));

  await screen.findByRole("radiogroup", { name: /template/i });
  await user.click(screen.getByRole("button", { name: "Continue" }));
  await screen.findByText(/Optional\. Shown to anyone/i);
  await user.click(screen.getByRole("button", { name: "Continue" }));
  await screen.findByTestId("creation-review");
}

beforeEach(() => {
  posted = [];
  settingsResponse = { status: 200, body: SETTINGS };
  createResponse = {
    status: 201,
    body: { status: "ok", adventure: { id: 7, slug: "the-lantern-road", title: "The Lantern Road", state: "draft" } },
  };
  installFetch();
});
afterEach(() => vi.unstubAllGlobals());

/* ── Pure validation mirror ───────────────────────────────────── */

describe("creation validation mirror — v0.16.0", () => {
  const base = {
    template: "solo", title: "", description: "", genre: "fantasy",
    content_rating: "everyone", content_warnings: [], content_warning_input: "",
    visibility: "public", opening_title: "", opening_body: "", status: "draft",
    contribution_mode: "closed", anonymous_contributions: false,
    max_branches_per_scene: 4, contribution_passcode: "", writing_guidelines: "",
  };

  it("requires a title and a description on step one", () => {
    const e = validateStep(1, base, null);
    expect(e.title).toBe("required");
    expect(e.description).toBe("required");
  });

  it("rejects a two-character title", () => {
    expect(validateStep(1, { ...base, title: "ab", description: "x" }, null).title).toBe("too_short");
  });

  it("requires opening text that is more than markup", () => {
    const e = validateStep(2, { ...base, opening_title: "Gate", opening_body: "<p>   </p>" }, null);
    expect(e.opening_body).toBe("empty");
  });

  it("bounds the per-scene branch limit", () => {
    const e = validateStep(3, { ...base, max_branches_per_scene: 99 }, SETTINGS);
    expect(e.max_branches_per_scene).toBe("out_of_range");
  });

  it("rejects a short passcode but allows an empty one", () => {
    expect(validateStep(3, { ...base, contribution_passcode: "123" }, SETTINGS).contribution_passcode)
      .toBe("too_short");
    expect(validateStep(3, base, SETTINGS).contribution_passcode).toBeUndefined();
  });
});

/* ── Wizard behaviour ─────────────────────────────────────────── */

describe("creation wizard — v0.16.0", () => {
  it("shows all five steps with Basics current", async () => {
    renderWizard();
    const steps = await screen.findByTestId("step-indicator");
    for (const label of ["Basics", "Opening scene", "Contributions", "Writing guidelines", "Review"]) {
      expect(within(steps).getByText(label)).toBeInTheDocument();
    }
    expect(within(steps).getByText("Basics").closest("li")).toHaveAttribute("aria-current", "step");
  });

  it("reports the caller's remaining creation budget", async () => {
    renderWizard();
    expect(await screen.findByTestId("creation-budget"))
      .toHaveTextContent("You own 3 of 20 adventures (17 remaining)");
  });

  it("blocks Continue and shows an error when the title is missing", async () => {
    const user = userEvent.setup();
    renderWizard();
    await user.click(await screen.findByRole("button", { name: "Continue" }));
    expect(await screen.findAllByRole("alert")).not.toHaveLength(0);
    expect(screen.getByRole("heading", { name: /Step 1 of 5/ })).toBeInTheDocument();
  });

  it("moves forward and back between steps", async () => {
    const user = userEvent.setup();
    renderWizard();
    await user.type(await screen.findByLabelText("Title"), "The Lantern Road");
    await user.type(screen.getByLabelText("Description"), "A winter journey.");
    await user.click(screen.getByRole("button", { name: "Continue" }));
    expect(await screen.findByRole("heading", { name: /Step 2 of 5/ })).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "Back" }));
    expect(await screen.findByRole("heading", { name: /Step 1 of 5/ })).toBeInTheDocument();
    expect(screen.getByLabelText("Title")).toHaveValue("The Lantern Road");
  });

  it("keeps the restricted editor on the opening scene step", async () => {
    const user = userEvent.setup();
    renderWizard();
    await user.type(await screen.findByLabelText("Title"), "The Lantern Road");
    await user.type(screen.getByLabelText("Description"), "A winter journey.");
    await user.click(screen.getByRole("button", { name: "Continue" }));
    const toolbar = await screen.findByRole("toolbar");
    expect(within(toolbar).getByRole("button", { name: "Bold" })).toBeInTheDocument();
    expect(within(toolbar).queryByRole("button", { name: /link/i })).toBeNull();
    expect(within(toolbar).queryByRole("button", { name: /image/i })).toBeNull();
  });

  it("applies a template to the contribution settings only", async () => {
    const user = userEvent.setup();
    renderWizard();
    await user.type(await screen.findByLabelText("Title"), "The Lantern Road");
    await user.type(screen.getByLabelText("Description"), "A winter journey.");
    await user.click(screen.getByRole("button", { name: "Continue" }));
    await user.type(await screen.findByLabelText("Scene title"), "The gate");
    const surface = screen.getByRole("textbox", { name: /scene text/i });
    surface.innerHTML = "<p>Snow.</p>";
    surface.dispatchEvent(new Event("input", { bubbles: true }));
    await user.click(screen.getByRole("button", { name: "Continue" }));

    await user.click(await screen.findByRole("radio", { name: /Open community story/i }));
    expect(screen.getByLabelText("Who can add branches")).toHaveValue("immediate");
    expect(screen.getByLabelText("Branches allowed per scene")).toHaveValue(6);
    expect(screen.getByLabelText(/without a display name/i)).toBeChecked();
    // Story content is untouched by the template.
    expect(screen.getByLabelText("Visibility")).toHaveValue("public");

    await user.click(screen.getByRole("radio", { name: /Private group story/i }));
    expect(screen.getByLabelText("Visibility")).toHaveValue("unlisted");
    expect(screen.getByLabelText("Who can add branches")).toHaveValue("approval");
  });

  it("summarises the draft on the review step", async () => {
    const user = userEvent.setup();
    renderWizard();
    await fillToReview(user);
    const review = screen.getByTestId("creation-review");
    expect(within(review).getByText("The Lantern Road")).toBeInTheDocument();
    expect(within(review).getByText("The gate at dusk")).toBeInTheDocument();
  });

  it("submits sanitized content and the chosen status", async () => {
    const user = userEvent.setup();
    renderWizard();
    await fillToReview(user);
    await user.click(screen.getByRole("radio", { name: /Publish now/i }));
    await user.click(screen.getByRole("button", { name: "Create adventure" }));
    await waitFor(() => expect(posted).toHaveLength(1));
    const body = posted[0].body;
    expect(body.title).toBe("The Lantern Road");
    expect(body.status).toBe("published");
    expect(body.opening_title).toBe("The gate at dusk");
    expect(String(body.opening_body)).toContain("iron gate");
    // The client never nominates an owner — the server derives it.
    expect(body).not.toHaveProperty("author_id");
  });

  it("navigates to the management page after a successful create", async () => {
    const user = userEvent.setup();
    renderWizard();
    await fillToReview(user);
    await user.click(screen.getByRole("button", { name: "Create adventure" }));
    expect(await screen.findByText("Manage page")).toBeInTheDocument();
  });

  it("surfaces server field errors without losing the draft", async () => {
    createResponse = { status: 422, body: { error: "invalid", fields: { title: "too_long" } } };
    const user = userEvent.setup();
    renderWizard();
    await fillToReview(user);
    await user.click(screen.getByRole("button", { name: "Create adventure" }));
    expect(await screen.findByText(/Some details need fixing/i)).toBeInTheDocument();
    expect(screen.getByTestId("creation-review")).toBeInTheDocument();
  });

  it("explains an hourly rate limit rather than failing silently", async () => {
    createResponse = { status: 429, body: { error: "rate_limited" } };
    const user = userEvent.setup();
    renderWizard();
    await fillToReview(user);
    await user.click(screen.getByRole("button", { name: "Create adventure" }));
    expect(await screen.findByText(/Too many new adventures/i)).toBeInTheDocument();
  });

  it("explains an inactive account", async () => {
    createResponse = { status: 403, body: { error: "not_active" } };
    const user = userEvent.setup();
    renderWizard();
    await fillToReview(user);
    await user.click(screen.getByRole("button", { name: "Create adventure" }));
    expect(await screen.findByText(/account can't create adventures/i)).toBeInTheDocument();
  });

  it("disables submission when the per-user cap is already reached", async () => {
    settingsResponse = {
      status: 200,
      body: { ...SETTINGS, can_create: false, limits: { ...SETTINGS.limits, owned: 20 } },
    };
    const user = userEvent.setup();
    renderWizard();
    await fillToReview(user);
    expect(screen.getByRole("button", { name: "Create adventure" })).toBeDisabled();
    expect(screen.getByText(/reached a creation limit/i)).toBeInTheDocument();
  });

  it("prompts unauthenticated visitors to sign in instead of showing the form", async () => {
    settingsResponse = { status: 401, body: { error: "unauthenticated" } };
    renderWizard();
    expect(await screen.findByRole("link", { name: /sign in/i })).toHaveAttribute(
      "href", "/login?redirect=/start",
    );
    expect(screen.queryByTestId("step-indicator")).toBeNull();
  });

  it("moves focus to the step heading when the step changes", async () => {
    const user = userEvent.setup();
    renderWizard();
    await user.type(await screen.findByLabelText("Title"), "The Lantern Road");
    await user.type(screen.getByLabelText("Description"), "A winter journey.");
    await user.click(screen.getByRole("button", { name: "Continue" }));
    await waitFor(() =>
      expect(document.activeElement).toBe(screen.getByRole("heading", { name: /Step 2 of 5/ })),
    );
  });
});
