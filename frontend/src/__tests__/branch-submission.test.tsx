/**
 * Version 0.18.0 — branch submission frontend tests.
 *
 * Focus: the three contribution modes, the passcode field, rate-limit
 * and branch-limit notices, sanitisation before submit, attribution
 * options (including the anonymous-visitor case), browser autosave,
 * and the contribution history list.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter, Route, Routes } from "react-router-dom";
import {
  SubmitBranch,
  branchDraftKey,
  readBranchDraft,
  validateBranchDraft,
  type BranchDraft,
} from "../pages/SubmitBranch";
import { AccountContributionsPage } from "../pages/AccountPages";
import * as api from "../lib/apiClient";
import type { BranchContext, ContributionHistoryEntry } from "../lib/apiClient";

const SLUG = "the-lantern-road";
const SCENE = "12";

function context(over: Partial<BranchContext> = {}): BranchContext {
  return {
    adventure: {
      slug: SLUG,
      title: "The Lantern Road",
      state: "published",
      writing_guidelines: "",
    },
    scene: { id: 12, slug: "the-gate-at-dusk", title: "The gate at dusk", published: true, locked: false },
    contribution_mode: "immediate",
    contributions_enabled: true,
    requires_passcode: false,
    allows_anonymous: false,
    signed_in: true,
    can_submit: true,
    blocked: false,
    rate_limited: false,
    branch_limit: { limit: 4, used: 1, remaining: 3 },
    attribution_options: ["username", "display_name", "anonymous"],
    limits: { choice_max: 120, title_max: 120, body_max: 20000, note_max: 1000 },
    ...over,
  };
}

function renderBranch() {
  return render(
    <MemoryRouter initialEntries={[`/adventure/${SLUG}/branch?from=${SCENE}`]}>
      <Routes>
        <Route path="/adventure/:slug/branch" element={<SubmitBranch />} />
      </Routes>
    </MemoryRouter>,
  );
}

function draft(over: Partial<BranchDraft> = {}): BranchDraft {
  return {
    choiceText: "Follow the lantern north",
    sceneTitle: "The frozen mile",
    sceneBody: "<p>The road narrows.</p>",
    sceneType: "story",
    attribution: "username",
    privateNote: "",
    ...over,
  };
}

beforeEach(() => {
  vi.restoreAllMocks();
  window.localStorage.clear();
});
afterEach(() => {
  vi.restoreAllMocks();
});

/** Fill the visible fields; the rich editor is driven through its surface. */
async function fillForm(user: ReturnType<typeof userEvent.setup>) {
  await user.type(screen.getByLabelText("Choice text"), "Follow the lantern north");
  await user.type(screen.getByLabelText("Scene title"), "The frozen mile");
  const editor = screen.getByTestId("branch-body-editor").querySelector('[role="textbox"]');
  (editor as HTMLElement).innerHTML = "<p>The road narrows.</p>";
  await user.click(editor as HTMLElement);
  await user.type(editor as HTMLElement, " ");
}

/* ─────────────────────────── Modes ──────────────────────────── */

describe("branch submission — contribution modes", () => {
  it("offers a publish action in immediate mode", async () => {
    vi.spyOn(api, "fetchBranchContext").mockResolvedValue(context());
    renderBranch();
    expect(await screen.findByTestId("submit-branch")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Publish this branch" })).toBeInTheDocument();
    expect(screen.getByTestId("branch-mode")).toHaveTextContent("Publishes immediately");
  });

  it("offers a review action in approval mode", async () => {
    vi.spyOn(api, "fetchBranchContext").mockResolvedValue(
      context({ contribution_mode: "approval" }),
    );
    renderBranch();
    expect(await screen.findByRole("button", { name: "Submit for review" })).toBeInTheDocument();
    expect(screen.getByTestId("branch-mode")).toHaveTextContent("Reviewed before publishing");
  });

  it("shows no form when contributions are closed", async () => {
    vi.spyOn(api, "fetchBranchContext").mockResolvedValue(
      context({ contribution_mode: "closed", contributions_enabled: false }),
    );
    renderBranch();
    expect(await screen.findByTestId("branch-closed")).toBeInTheDocument();
    expect(screen.queryByTestId("branch-form")).not.toBeInTheDocument();
  });

  it("reports an unreachable scene instead of an empty form", async () => {
    vi.spyOn(api, "fetchBranchContext").mockResolvedValue(null);
    renderBranch();
    expect(await screen.findByTestId("branch-error")).toBeInTheDocument();
  });
});

/* ─────────────────────── Blocking conditions ────────────────── */

describe("branch submission — conditions that stop the form", () => {
  it("asks a signed-out visitor to sign in when anonymous contributions are off", async () => {
    vi.spyOn(api, "fetchBranchContext").mockResolvedValue(
      context({ signed_in: false, allows_anonymous: false, can_submit: false, attribution_options: ["anonymous"] }),
    );
    renderBranch();
    expect(await screen.findByTestId("branch-signin")).toBeInTheDocument();
    expect(screen.queryByTestId("branch-form")).not.toBeInTheDocument();
  });

  it("hides the form from a blocked contributor", async () => {
    vi.spyOn(api, "fetchBranchContext").mockResolvedValue(context({ blocked: true }));
    renderBranch();
    expect(await screen.findByTestId("branch-blocked")).toBeInTheDocument();
    expect(screen.queryByTestId("branch-form")).not.toBeInTheDocument();
  });

  it("hides the form when the scene has no free branch slot", async () => {
    vi.spyOn(api, "fetchBranchContext").mockResolvedValue(
      context({ branch_limit: { limit: 4, used: 4, remaining: 0 } }),
    );
    renderBranch();
    expect(await screen.findByTestId("branch-full")).toBeInTheDocument();
    expect(screen.queryByTestId("branch-form")).not.toBeInTheDocument();
  });

  it("hides the form when the scene is locked", async () => {
    vi.spyOn(api, "fetchBranchContext").mockResolvedValue(
      context({ scene: { id: 12, slug: "s", title: "The gate", published: true, locked: true } }),
    );
    renderBranch();
    expect(await screen.findByTestId("branch-locked")).toBeInTheDocument();
    expect(screen.queryByTestId("branch-form")).not.toBeInTheDocument();
  });

  it("warns a rate-limited contributor", async () => {
    vi.spyOn(api, "fetchBranchContext").mockResolvedValue(context({ rate_limited: true }));
    renderBranch();
    expect(await screen.findByTestId("branch-rate-limited")).toBeInTheDocument();
  });

  it("reports the remaining branch slots", async () => {
    vi.spyOn(api, "fetchBranchContext").mockResolvedValue(context());
    renderBranch();
    expect(await screen.findByTestId("branch-slots")).toHaveTextContent("3 of 4 branch slots free");
  });
});

/* ─────────────────────────── Passcode ───────────────────────── */

describe("branch submission — passcode", () => {
  it("hides the passcode field when the adventure does not require one", async () => {
    vi.spyOn(api, "fetchBranchContext").mockResolvedValue(context());
    renderBranch();
    await screen.findByTestId("branch-form");
    expect(screen.queryByLabelText("Contribution passcode")).not.toBeInTheDocument();
  });

  it("shows the passcode field when the adventure requires one", async () => {
    vi.spyOn(api, "fetchBranchContext").mockResolvedValue(context({ requires_passcode: true }));
    renderBranch();
    await screen.findByTestId("branch-form");
    expect(screen.getByLabelText("Contribution passcode")).toBeInTheDocument();
  });

  it("refuses to submit without a required passcode", async () => {
    vi.spyOn(api, "fetchBranchContext").mockResolvedValue(context({ requires_passcode: true }));
    const submit = vi.spyOn(api, "submitBranch");
    const user = userEvent.setup();
    renderBranch();
    await screen.findByTestId("branch-form");
    await fillForm(user);
    await user.click(screen.getByRole("button", { name: "Publish this branch" }));
    expect(submit).not.toHaveBeenCalled();
    expect(await screen.findByText("This adventure requires a contribution passcode.")).toBeInTheDocument();
  });

  it("reports a rejected passcode from the server", async () => {
    vi.spyOn(api, "fetchBranchContext").mockResolvedValue(context());
    vi.spyOn(api, "submitBranch").mockResolvedValue({
      ok: false, status: 422, data: null, error: "passcode_invalid",
    });
    const user = userEvent.setup();
    renderBranch();
    await screen.findByTestId("branch-form");
    await fillForm(user);
    await user.click(screen.getByRole("button", { name: "Publish this branch" }));
    expect(await screen.findByTestId("branch-outcome")).toHaveTextContent("That passcode does not match.");
  });
});

/* ─────────────────────────── Submission ─────────────────────── */

describe("branch submission — submitting", () => {
  it("sends sanitized HTML and an empty honeypot", async () => {
    vi.spyOn(api, "fetchBranchContext").mockResolvedValue(context());
    const submit = vi.spyOn(api, "submitBranch").mockResolvedValue({
      ok: true, status: 201, data: {
        status: "ok", submission_id: 1, state: "approved", mode: "immediate",
        published: true, scene_id: 30, choice_id: 40, attribution: "username",
      },
    });
    const user = userEvent.setup();
    renderBranch();
    await screen.findByTestId("branch-form");
    await user.type(screen.getByLabelText("Choice text"), "Follow the lantern north");
    await user.type(screen.getByLabelText("Scene title"), "The frozen mile");
    const surface = screen.getByTestId("branch-body-editor").querySelector('[role="textbox"]') as HTMLElement;
    await user.click(surface);
    await user.type(surface, "The road narrows.");
    await user.click(screen.getByRole("button", { name: "Publish this branch" }));

    await waitFor(() => expect(submit).toHaveBeenCalled());
    const payload = submit.mock.calls[0]![2];
    expect(payload.website).toBe("");
    expect(payload.scene_body).not.toMatch(/<script|style=|<img|href=/);
    expect(payload.choice_text).toBe("Follow the lantern north");
  });

  it("confirms an immediate publication", async () => {
    vi.spyOn(api, "fetchBranchContext").mockResolvedValue(context());
    vi.spyOn(api, "submitBranch").mockResolvedValue({
      ok: true, status: 201, data: {
        status: "ok", submission_id: 1, state: "approved", mode: "immediate",
        published: true, scene_id: 30, choice_id: 40, attribution: "username",
      },
    });
    const user = userEvent.setup();
    renderBranch();
    await screen.findByTestId("branch-form");
    await fillForm(user);
    await user.click(screen.getByRole("button", { name: "Publish this branch" }));
    expect(await screen.findByTestId("branch-result")).toHaveTextContent("Your branch is live");
  });

  it("explains that an approval-mode branch is queued", async () => {
    vi.spyOn(api, "fetchBranchContext").mockResolvedValue(context({ contribution_mode: "approval" }));
    vi.spyOn(api, "submitBranch").mockResolvedValue({
      ok: true, status: 201, data: {
        status: "ok", submission_id: 2, state: "pending", mode: "approval",
        published: false, scene_id: null, choice_id: null, attribution: "username",
      },
    });
    const user = userEvent.setup();
    renderBranch();
    await screen.findByTestId("branch-form");
    await fillForm(user);
    await user.click(screen.getByRole("button", { name: "Submit for review" }));
    expect(await screen.findByTestId("branch-result")).toHaveTextContent("awaiting review");
  });

  it("surfaces a duplicate choice from the server", async () => {
    vi.spyOn(api, "fetchBranchContext").mockResolvedValue(context());
    vi.spyOn(api, "submitBranch").mockResolvedValue({
      ok: false, status: 409, data: null, error: "duplicate",
      fields: { choice_text: "duplicate" },
    });
    const user = userEvent.setup();
    renderBranch();
    await screen.findByTestId("branch-form");
    await fillForm(user);
    await user.click(screen.getByRole("button", { name: "Publish this branch" }));
    expect(
      await screen.findAllByText("A branch with that same choice already leaves this scene."),
    ).not.toHaveLength(0);
  });

  it("surfaces a rate-limit refusal from the server", async () => {
    vi.spyOn(api, "fetchBranchContext").mockResolvedValue(context());
    vi.spyOn(api, "submitBranch").mockResolvedValue({
      ok: false, status: 429, data: null, error: "rate_limited",
    });
    const user = userEvent.setup();
    renderBranch();
    await screen.findByTestId("branch-form");
    await fillForm(user);
    await user.click(screen.getByRole("button", { name: "Publish this branch" }));
    expect(await screen.findByTestId("branch-outcome")).toHaveTextContent("several branches recently");
  });
});

/* ─────────────────────────── Attribution ────────────────────── */

describe("branch submission — attribution", () => {
  it("offers all three options to a signed-in contributor", async () => {
    vi.spyOn(api, "fetchBranchContext").mockResolvedValue(context());
    renderBranch();
    await screen.findByTestId("branch-form");
    expect(screen.getByLabelText("My username")).toBeInTheDocument();
    expect(screen.getByLabelText("My display name")).toBeInTheDocument();
    expect(screen.getByLabelText("Anonymous (public)")).toBeInTheDocument();
  });

  it("offers only anonymous attribution to a signed-out visitor", async () => {
    vi.spyOn(api, "fetchBranchContext").mockResolvedValue(
      context({ signed_in: false, allows_anonymous: true, attribution_options: ["anonymous"] }),
    );
    renderBranch();
    await screen.findByTestId("branch-form");
    expect(screen.getByLabelText("Anonymous (public)")).toBeInTheDocument();
    expect(screen.queryByLabelText("My username")).not.toBeInTheDocument();
  });

  it("says that anonymous attribution is public only", async () => {
    vi.spyOn(api, "fetchBranchContext").mockResolvedValue(context());
    renderBranch();
    await screen.findByTestId("branch-form");
    expect(screen.getByText(/moderators/i)).toBeInTheDocument();
  });

  it("sends the chosen attribution", async () => {
    vi.spyOn(api, "fetchBranchContext").mockResolvedValue(context());
    const submit = vi.spyOn(api, "submitBranch").mockResolvedValue({
      ok: true, status: 201, data: {
        status: "ok", submission_id: 1, state: "approved", mode: "immediate",
        published: true, scene_id: 1, choice_id: 1, attribution: "anonymous",
      },
    });
    const user = userEvent.setup();
    renderBranch();
    await screen.findByTestId("branch-form");
    await fillForm(user);
    await user.click(screen.getByLabelText("Anonymous (public)"));
    await user.click(screen.getByRole("button", { name: "Publish this branch" }));
    await waitFor(() => expect(submit).toHaveBeenCalled());
    expect(submit.mock.calls[0]![2].attribution).toBe("anonymous");
  });
});

/* ─────────────────────────── Autosave ───────────────────────── */

describe("branch submission — autosave", () => {
  it("saves the draft to this browser as it is typed", async () => {
    vi.spyOn(api, "fetchBranchContext").mockResolvedValue(context());
    const user = userEvent.setup();
    renderBranch();
    await screen.findByTestId("branch-form");
    await user.type(screen.getByLabelText("Choice text"), "Follow the lantern north");
    await waitFor(() =>
      expect(readBranchDraft(SLUG, SCENE)?.choiceText).toBe("Follow the lantern north"),
    );
    expect(screen.getByTestId("branch-autosave")).toHaveTextContent("Draft saved in this browser.");
  });

  it("restores an autosaved draft when the page reopens", async () => {
    window.localStorage.setItem(
      branchDraftKey(SLUG, SCENE),
      JSON.stringify(draft({ choiceText: "Turn back toward the town" })),
    );
    vi.spyOn(api, "fetchBranchContext").mockResolvedValue(context());
    renderBranch();
    await screen.findByTestId("branch-form");
    await waitFor(() =>
      expect(screen.getByLabelText("Choice text")).toHaveValue("Turn back toward the town"),
    );
  });

  it("clears the saved draft once the branch is accepted", async () => {
    vi.spyOn(api, "fetchBranchContext").mockResolvedValue(context());
    vi.spyOn(api, "submitBranch").mockResolvedValue({
      ok: true, status: 201, data: {
        status: "ok", submission_id: 1, state: "approved", mode: "immediate",
        published: true, scene_id: 1, choice_id: 1, attribution: "username",
      },
    });
    const user = userEvent.setup();
    renderBranch();
    await screen.findByTestId("branch-form");
    await fillForm(user);
    await user.click(screen.getByRole("button", { name: "Publish this branch" }));
    await screen.findByTestId("branch-result");
    expect(window.localStorage.getItem(branchDraftKey(SLUG, SCENE))).toBeNull();
  });

  it("keeps drafts for different scenes apart", () => {
    expect(branchDraftKey(SLUG, "1")).not.toBe(branchDraftKey(SLUG, "2"));
  });
});

/* ─────────────────────────── Validation ─────────────────────── */

describe("branch submission — client validation mirror", () => {
  it("accepts a complete draft", () => {
    expect(validateBranchDraft(draft(), false, "")).toEqual({});
  });
  it("rejects a short choice", () => {
    expect(validateBranchDraft(draft({ choiceText: "no" }), false, "")).toHaveProperty("choice_text");
  });
  it("rejects a short title", () => {
    expect(validateBranchDraft(draft({ sceneTitle: "x" }), false, "")).toHaveProperty("scene_title");
  });
  it("rejects an empty body", () => {
    expect(validateBranchDraft(draft({ sceneBody: "<p>  </p>" }), false, "")).toHaveProperty("scene_body");
  });
  it("rejects an overlong private note", () => {
    expect(validateBranchDraft(draft({ privateNote: "n".repeat(1200) }), false, ""))
      .toHaveProperty("private_note");
  });
  it("requires a passcode when the adventure asks for one", () => {
    expect(validateBranchDraft(draft(), true, "")).toHaveProperty("passcode");
    expect(validateBranchDraft(draft(), true, "winter-gate")).toEqual({});
  });
});

/* ──────────────────── Contribution history ──────────────────── */

function historyEntry(over: Partial<ContributionHistoryEntry> = {}): ContributionHistoryEntry {
  // eslint-disable-next-line @typescript-eslint/consistent-type-assertions
  return {
    id: 1,
    state: "pending",
    attribution: "anonymous",
    choice_text: "Follow the lantern north",
    scene_title: "The frozen mile",
    scene_type: "story",
    private_note: null,
    moderator_note: null,
    created_at: "2026-09-01T10:00:00Z",
    adventure_slug: SLUG,
    adventure_title: "The Lantern Road",
    source_scene_slug: "the-gate-at-dusk",
    source_scene_title: "The gate at dusk",
    ...over,
  };
}

describe("contribution history", () => {
  it("lists submissions with their review state", async () => {
    vi.spyOn(api, "fetchContributionHistory").mockResolvedValue({
      status: 200,
      contributions: [historyEntry(), historyEntry({ id: 2, state: "published" })],
    });
    render(
      <MemoryRouter>
        <AccountContributionsPage />
      </MemoryRouter>,
    );
    const items = await screen.findAllByTestId("contribution-item");
    expect(items).toHaveLength(2);
    expect(items[0]).toHaveTextContent("Awaiting review");
    expect(items[1]).toHaveTextContent("Published");
  });

  it("shows an anonymous submission in the contributor's own history", async () => {
    vi.spyOn(api, "fetchContributionHistory").mockResolvedValue({
      status: 200,
      contributions: [historyEntry({ attribution: "anonymous" })],
    });
    render(
      <MemoryRouter>
        <AccountContributionsPage />
      </MemoryRouter>,
    );
    expect(await screen.findByTestId("contribution-item")).toHaveTextContent("credited as Anonymous");
  });

  it("shows an empty state when nothing has been submitted", async () => {
    vi.spyOn(api, "fetchContributionHistory").mockResolvedValue({ status: 200, contributions: [] });
    render(
      <MemoryRouter>
        <AccountContributionsPage />
      </MemoryRouter>,
    );
    expect(await screen.findByTestId("contributions-empty")).toBeInTheDocument();
  });
});
