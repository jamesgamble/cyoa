/** Version 0.24.0 — revision history panel. */
import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { RevisionHistoryPanel } from "../components/RevisionHistoryPanel";
import * as api from "../lib/apiClient";
import type { RevisionList } from "../lib/apiClient";

const list: RevisionList = {
  status: "ok",
  retain: 20,
  read_only: false,
  revisions: [
    { id: 7, target_type: "scene", target_id: 1, target_label: "Scene: The jetty", field: "body",
      reason: "edit", editor: "Owner", created_at: "2026-09-24T10:00:00Z" },
  ],
};

beforeEach(() => vi.restoreAllMocks());

describe("revision history", () => {
  it("lists revisions with editor, date, and retention", async () => {
    vi.spyOn(api, "fetchRevisions").mockResolvedValue(list);
    render(<RevisionHistoryPanel slug="salt" />);
    const item = await screen.findByTestId("revision-item");
    expect(item).toHaveTextContent("Scene: The jetty");
    expect(item).toHaveTextContent("Owner");
    expect(item.querySelector("time")).toHaveAttribute("dateTime", "2026-09-24T10:00:00Z");
    expect(screen.getByText(/latest 20 versions/i)).toBeInTheDocument();
  });

  it("shows a comparison", async () => {
    const user = userEvent.setup();
    vi.spyOn(api, "fetchRevisions").mockResolvedValue(list);
    vi.spyOn(api, "compareRevision").mockResolvedValue({
      status: "ok", revision_id: 7, field: "body", older_label: "Revision #7", newer_label: "Current",
      older: "a", newer: "b",
      diff: [{ op: "removed", text: "The tide is out." }, { op: "added", text: "The tide is in." }],
    });
    render(<RevisionHistoryPanel slug="salt" />);
    await user.click(await screen.findByRole("button", { name: "Compare" }));
    const diff = await screen.findByTestId("revision-diff");
    expect(diff.querySelector("del")).toHaveTextContent("The tide is out.");
    expect(diff.querySelector("ins")).toHaveTextContent("The tide is in.");
  });

  it("confirms before restoring", async () => {
    const user = userEvent.setup();
    vi.spyOn(api, "fetchRevisions").mockResolvedValue(list);
    const restore = vi.spyOn(api, "restoreRevision").mockResolvedValue({
      ok: true, status: 200, data: { status: "ok", restored_from: 7, revision_id: 8 },
    } as Awaited<ReturnType<typeof api.restoreRevision>>);
    render(<RevisionHistoryPanel slug="salt" />);
    await user.click(await screen.findByRole("button", { name: "Restore" }));
    expect(restore).not.toHaveBeenCalled();
    const dialog = screen.getByText(/restore this version\?/i).closest("dialog")!;
    await user.click(within(dialog).getByRole("button", { name: "Restore version" }));
    await waitFor(() => expect(restore).toHaveBeenCalledWith("salt", 7));
    expect(await screen.findByText(/saved as a new revision/i)).toBeInTheDocument();
  });

  it("hides restore on read-only adventures", async () => {
    vi.spyOn(api, "fetchRevisions").mockResolvedValue({ ...list, read_only: true });
    render(<RevisionHistoryPanel slug="salt" />);
    await screen.findByTestId("revision-item");
    expect(screen.queryByRole("button", { name: "Restore" })).not.toBeInTheDocument();
  });

  it("explains access when not authorized", async () => {
    vi.spyOn(api, "fetchRevisions").mockResolvedValue(null);
    render(<RevisionHistoryPanel slug="salt" />);
    expect(await screen.findByText(/owners, editors, and administrators/i)).toBeInTheDocument();
  });
});
