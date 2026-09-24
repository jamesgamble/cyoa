import { describe, it, expect } from "vitest";
import { render, screen } from "@testing-library/react";
import { ExportPanel, exportUrl } from "../components/ExportPanel";

describe("ExportPanel (v0.26.0)", () => {
  it("offers all four formats through the manager-only export endpoint", () => {
    render(<ExportPanel slug="salt-road" />);
    for (const [label, fmt] of [["JSON data", "json"], ["Plain text", "text"], ["Printable page", "print"], ["Playable page", "play"]]) {
      const a = screen.getByRole("link", { name: label });
      expect(a.getAttribute("href")).toBe(exportUrl("salt-road", fmt));
      expect(a.getAttribute("href")).toContain("/moderation/export?format=");
      expect(a.hasAttribute("download")).toBe(true);
    }
  });

  it("encodes the slug", () => {
    expect(exportUrl("a b", "json")).toContain("/adventures/a%20b/");
  });
});
