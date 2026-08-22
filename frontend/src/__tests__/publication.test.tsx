/**
 * Version 0.17.0 — manage / preview / publication frontend tests.
 *
 * Focus: authorization states, confirmation dialogs before publish,
 * unpublish and archive, read-only archives, the noindex marker on
 * preview, and the activity list.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter, Route, Routes } from "react-router-dom";
import { ManageAdventure } from "../pages/ManageAdventure";
import { Preview } from "../pages/Preview";
import * as api from "../lib/apiClient";
import type { ManagePayload, PreviewPayload } from "../lib/apiClient";

function managePayload(over: Partial<ManagePayload> = {}): ManagePayload {
  return {
    adventure: {
      id: 1,
      slug: "the-lantern-road",
      title: "The Lantern Road",
      description: "A winter journey.",
      state: "draft",
      visibility: "public",
      updated_at: "2026-08-22T10:00:00Z",
      writing_guidelines: "",
    },
    role: "owner",
    read_only: false,
    has_valid_opening: true,
    available_actions: ["publish", "archive"],
    confirm_actions: ["publish", "unpublish", "archive"],
    activity: [],
    ...over,
  };
}

function renderManage() {
  return render(
    <MemoryRouter initialEntries={["/manage/the-lantern-road"]}>
      <Routes>
        <Route path="/manage/:slug" element={<ManageAdventure />} />
      </Routes>
    </MemoryRouter>,
  );
}

function renderPreview() {
  return render(
    <MemoryRouter initialEntries={["/adventure/the-lantern-road/preview"]}>
      <Routes>
        <Route path="/adventure/:slug/preview" element={<Preview />} />
      </Routes>
    </MemoryRouter>,
  );
}

beforeEach(() => {
  vi.restoreAllMocks();
});
afterEach(() => {
  vi.restoreAllMocks();
});

describe("manage authorization", () => {
  it("asks unauthenticated visitors to sign in", async () => {
    vi.spyOn(api, "fetchManageAdventure").mockResolvedValue({
      outcome: "unauthenticated", data: null,
    });
    renderManage();
    expect(await screen.findByText(/sign in required/i)).toBeInTheDocument();
  });

  it("refuses visitors without a role", async () => {
    vi.spyOn(api, "fetchManageAdventure").mockResolvedValue({
      outcome: "forbidden", data: null,
    });
    renderManage();
    expect(await screen.findByText(/cannot manage this adventure/i)).toBeInTheDocument();
  });

  it("shows not found for an unknown adventure", async () => {
    vi.spyOn(api, "fetchManageAdventure").mockResolvedValue({
      outcome: "not_found", data: null,
    });
    renderManage();
    expect(await screen.findByTestId("not-found")).toBeInTheDocument();
  });

  it("renders the console for an owner", async () => {
    vi.spyOn(api, "fetchManageAdventure").mockResolvedValue({
      outcome: "ok", data: managePayload(),
    });
    renderManage();
    expect(await screen.findByTestId("manage-adventure")).toBeInTheDocument();
    expect(screen.getByRole("heading", { name: "The Lantern Road" })).toBeInTheDocument();
    expect(screen.getByText(/your role: owner/i)).toBeInTheDocument();
  });

  it("offers a preview link", async () => {
    vi.spyOn(api, "fetchManageAdventure").mockResolvedValue({
      outcome: "ok", data: managePayload(),
    });
    renderManage();
    const link = await screen.findByTestId("preview-link");
    expect(link).toHaveAttribute("href", "/adventure/the-lantern-road/preview");
  });
});

describe("publication controls", () => {
  it("confirms before publishing and only then calls the API", async () => {
    const user = userEvent.setup();
    vi.spyOn(api, "fetchManageAdventure").mockResolvedValue({
      outcome: "ok", data: managePayload(),
    });
    const change = vi.spyOn(api, "changeAdventureStatus").mockResolvedValue({
      ok: true, status: 200, data: { status: "ok", state: "published" },
    });
    renderManage();
    await user.click(await screen.findByRole("button", { name: "Publish" }));
    expect(change).not.toHaveBeenCalled();
    expect(await screen.findByText(/publish this adventure\?/i)).toBeInTheDocument();
    const dialog = screen.getByText(/publish this adventure\?/i).closest("dialog")!;
    await user.click(within(dialog).getByRole("button", { name: "Publish" }));
    await waitFor(() => expect(change).toHaveBeenCalledWith("the-lantern-road", "publish"));
  });

  it("cancelling the confirmation makes no change", async () => {
    const user = userEvent.setup();
    vi.spyOn(api, "fetchManageAdventure").mockResolvedValue({
      outcome: "ok", data: managePayload(),
    });
    const change = vi.spyOn(api, "changeAdventureStatus");
    renderManage();
    await user.click(await screen.findByRole("button", { name: "Publish" }));
    await user.click(screen.getByRole("button", { name: "Cancel" }));
    expect(change).not.toHaveBeenCalled();
  });

  it("confirms before unpublishing and says nothing is deleted", async () => {
    const user = userEvent.setup();
    vi.spyOn(api, "fetchManageAdventure").mockResolvedValue({
      outcome: "ok",
      data: managePayload({
        adventure: { ...managePayload().adventure, state: "published" },
        available_actions: ["unpublish", "set_complete", "set_on_hold", "archive"],
      }),
    });
    renderManage();
    await user.click(await screen.findByRole("button", { name: "Unpublish" }));
    expect(await screen.findByText(/unpublish this adventure\?/i)).toBeInTheDocument();
    expect(screen.getByText(/nothing is deleted/i)).toBeInTheDocument();
  });

  it("confirms before archiving from the danger zone", async () => {
    const user = userEvent.setup();
    vi.spyOn(api, "fetchManageAdventure").mockResolvedValue({
      outcome: "ok", data: managePayload(),
    });
    renderManage();
    const zone = await screen.findByTestId("danger-zone");
    await user.click(within(zone).getByRole("button", { name: "Archive" }));
    expect(await screen.findByText(/archive this adventure\?/i)).toBeInTheDocument();
  });

  it("applies non-confirmed actions immediately", async () => {
    const user = userEvent.setup();
    vi.spyOn(api, "fetchManageAdventure").mockResolvedValue({
      outcome: "ok",
      data: managePayload({
        adventure: { ...managePayload().adventure, state: "published" },
        available_actions: ["set_complete"],
      }),
    });
    const change = vi.spyOn(api, "changeAdventureStatus").mockResolvedValue({
      ok: true, status: 200, data: { status: "ok", state: "complete" },
    });
    renderManage();
    await user.click(await screen.findByRole("button", { name: "Set complete" }));
    await waitFor(() => expect(change).toHaveBeenCalledWith("the-lantern-road", "set_complete"));
  });

  it("surfaces the missing opening scene rule", async () => {
    vi.spyOn(api, "fetchManageAdventure").mockResolvedValue({
      outcome: "ok",
      data: managePayload({ has_valid_opening: false, available_actions: ["archive"] }),
    });
    renderManage();
    expect(await screen.findByText(/needs a valid opening scene/i)).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Publish" })).not.toBeInTheDocument();
  });

  it("treats archived adventures as read-only", async () => {
    vi.spyOn(api, "fetchManageAdventure").mockResolvedValue({
      outcome: "ok",
      data: managePayload({
        adventure: { ...managePayload().adventure, state: "archived" },
        read_only: true,
        available_actions: [],
      }),
    });
    renderManage();
    expect(await screen.findByText(/this adventure is archived/i)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /save draft/i })).toBeDisabled();
    expect(screen.getByText(/no status changes are available/i)).toBeInTheDocument();
    expect(screen.queryByTestId("danger-zone")).not.toBeInTheDocument();
  });

  it("saves draft edits", async () => {
    const user = userEvent.setup();
    vi.spyOn(api, "fetchManageAdventure").mockResolvedValue({
      outcome: "ok", data: managePayload(),
    });
    const save = vi.spyOn(api, "saveAdventureDraft").mockResolvedValue({
      ok: true, status: 200, data: { status: "ok" },
    });
    renderManage();
    await user.click(await screen.findByRole("button", { name: /save draft/i }));
    await waitFor(() => expect(save).toHaveBeenCalled());
    expect(await screen.findByText(/draft saved/i)).toBeInTheDocument();
  });

  it("lists recorded activity", async () => {
    vi.spyOn(api, "fetchManageAdventure").mockResolvedValue({
      outcome: "ok",
      data: managePayload({
        activity: [
          {
            id: 2, action: "unpublish", from_state: "published",
            to_state: "draft", actor: "Owner", created_at: "2026-08-22T11:00:00Z",
          },
          {
            id: 1, action: "publish", from_state: "draft",
            to_state: "published", actor: "Owner", created_at: "2026-08-22T10:00:00Z",
          },
        ],
      }),
    });
    renderManage();
    const list = await screen.findByTestId("activity-list");
    expect(within(list).getAllByTestId("activity-item")).toHaveLength(2);
    expect(within(list).getByText(/published → draft/)).toBeInTheDocument();
  });
});

describe("preview", () => {
  const preview: PreviewPayload = {
    adventure: { slug: "the-lantern-road", title: "The Lantern Road", state: "draft" },
    role: "owner",
    noindex: true,
    scenes: [
      {
        id: 1, slug: "the-gate-at-dusk", sceneNumber: 1, chapter: null,
        title: "The gate at dusk",
        body: "<p>Snow gathers on the <strong>iron gate</strong>.</p>",
        isEnding: false, state: "draft", isStart: true,
      },
    ],
  };

  it("requires authorization", async () => {
    vi.spyOn(api, "fetchAdventurePreview").mockResolvedValue({
      outcome: "forbidden", data: null,
    });
    renderPreview();
    expect(await screen.findByText(/cannot preview this adventure/i)).toBeInTheDocument();
  });

  it("asks anonymous visitors to sign in", async () => {
    vi.spyOn(api, "fetchAdventurePreview").mockResolvedValue({
      outcome: "unauthenticated", data: null,
    });
    renderPreview();
    expect(await screen.findByText(/sign in required/i)).toBeInTheDocument();
  });

  it("shows unpublished scenes to an authorized collaborator", async () => {
    vi.spyOn(api, "fetchAdventurePreview").mockResolvedValue({
      outcome: "ok", data: preview,
    });
    renderPreview();
    expect(await screen.findByTestId("adventure-preview")).toBeInTheDocument();
    expect(screen.getByText("The gate at dusk")).toBeInTheDocument();
    expect(screen.getByTestId("preview-scene-state-the-gate-at-dusk"))
      .toHaveTextContent(/unpublished scene/i);
    expect(screen.getByTestId("story-body")).toHaveTextContent(/Snow gathers on the iron gate/);
  });

  it("is marked noindex while mounted", async () => {
    vi.spyOn(api, "fetchAdventurePreview").mockResolvedValue({
      outcome: "ok", data: preview,
    });
    const view = renderPreview();
    await screen.findByTestId("adventure-preview");
    const meta = document.head.querySelector('meta[name="robots"]');
    expect(meta?.getAttribute("content")).toBe("noindex, nofollow");
    view.unmount();
    expect(document.head.querySelector('meta[name="robots"]')).toBeNull();
  });

  it("renders story text without user-controlled markup", async () => {
    vi.spyOn(api, "fetchAdventurePreview").mockResolvedValue({
      outcome: "ok",
      data: {
        ...preview,
        scenes: [{ ...preview.scenes[0], body: "<p>Plain <em>words</em> only.</p>" }],
      },
    });
    renderPreview();
    const body = await screen.findByTestId("story-body");
    expect(body.querySelector("em")).toBeNull();
    expect(body).toHaveTextContent("Plain words only.");
  });
});
