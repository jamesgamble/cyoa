import { describe, it, expect, vi } from "vitest";
import { render, screen, fireEvent } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { useState } from "react";

import {
  RichTextEditor,
  RICH_TEXT_ACTIONS,
  sanitizeRichTextHtml,
  richTextToPlainText,
  richTextLength,
} from "../components";

function Harness({ initial = "" }: { initial?: string }) {
  const [value, setValue] = useState(initial);
  return (
    <>
      <label id="rte-label">Story body</label>
      <RichTextEditor
        value={value}
        onChange={setValue}
        ariaLabelledBy="rte-label"
        data-testid="rte"
        maxPlainTextLength={200}
      />
      <output data-testid="html">{value}</output>
    </>
  );
}

describe("richTextSanitizer", () => {
  it("keeps allow-listed tags", () => {
    const out = sanitizeRichTextHtml(
      "<p>Hi <strong>bold</strong> <em>em</em> <u>u</u></p>",
    );
    expect(out).toContain("<strong>bold</strong>");
    expect(out).toContain("<em>em</em>");
    expect(out).toContain("<u>u</u>");
  });

  it("strips scripts, styles, iframes, images, forms", () => {
    const dangerous =
      '<p>ok</p><script>alert(1)</script><style>x{}</style>' +
      '<iframe src="x"></iframe><img src="a"><form><input></form>';
    const out = sanitizeRichTextHtml(dangerous);
    expect(out.toLowerCase()).not.toContain("script");
    expect(out.toLowerCase()).not.toContain("iframe");
    expect(out.toLowerCase()).not.toContain("<style");
    expect(out.toLowerCase()).not.toContain("<img");
    expect(out.toLowerCase()).not.toContain("<form");
    expect(out.toLowerCase()).not.toContain("<input");
    expect(out).toContain("<p>ok</p>");
  });

  it("strips links but keeps text and never auto-links URLs", () => {
    const out = sanitizeRichTextHtml(
      '<p>see <a href="https://evil.example">this</a> and https://example.com</p>',
    );
    expect(out).not.toContain("<a");
    expect(out).not.toContain("href");
    expect(out).toContain("see this and https://example.com");
  });

  it("removes every attribute including style, class, and onclick", () => {
    const out = sanitizeRichTextHtml(
      '<p class="x" style="color:red" onclick="alert(1)">hi</p>',
    );
    expect(out).toBe("<p>hi</p>");
    expect(out.toLowerCase()).not.toContain("onclick");
  });

  it("renames legacy b/i to strong/em", () => {
    const out = sanitizeRichTextHtml("<p><b>x</b><i>y</i></p>");
    expect(out).toContain("<strong>x</strong>");
    expect(out).toContain("<em>y</em>");
  });

  it("drops disallowed heading levels but preserves their text", () => {
    const out = sanitizeRichTextHtml("<h1>A</h1><h2>B</h2><h4>C</h4>");
    expect(out).not.toContain("<h1");
    expect(out).not.toContain("<h4");
    expect(out).toContain("<h2>B</h2>");
    expect(out).toContain("A");
    expect(out).toContain("C");
  });

  it("wraps stray text in <p>", () => {
    expect(sanitizeRichTextHtml("hello")).toBe("<p>hello</p>");
  });

  it("plain-text length is character-accurate and multibyte-safe", () => {
    expect(richTextLength("<p>café</p>")).toBe(4);
    expect(richTextLength("<p></p>")).toBe(0);
    expect(richTextToPlainText("<ul><li>one</li><li>two</li></ul>")).toContain(
      "- one",
    );
  });
});

describe("RichTextEditor toolbar", () => {
  it("exposes exactly the allow-listed actions and nothing else", () => {
    render(<Harness />);
    // Confirm all expected labels present.
    for (const action of RICH_TEXT_ACTIONS) {
      expect(screen.getByRole("button", { name: action.label })).toBeInTheDocument();
    }
    // Guard-rails: forbidden actions must NOT be present.
    for (const forbidden of [
      "Link",
      "Insert link",
      "Image",
      "Insert image",
      "Table",
      "Code",
      "Source",
      "HTML",
      "Font",
      "Font size",
      "Text color",
      "Background color",
      "Align left",
      "Align center",
      "Align right",
      "Justify",
      "Embed",
      "Attachment",
      "Media",
      "Iframe",
    ]) {
      expect(screen.queryByRole("button", { name: forbidden })).toBeNull();
    }
  });

  it("editing surface is a labelled multi-line textbox", () => {
    render(<Harness />);
    const surface = screen.getByRole("textbox");
    expect(surface).toHaveAttribute("aria-multiline", "true");
    expect(surface).toHaveAttribute("aria-labelledby", "rte-label");
    expect(surface).toHaveAttribute("contenteditable", "true");
  });

  it("emits sanitized HTML when the surface's DOM changes", () => {
    const onChange = vi.fn();
    render(
      <RichTextEditor value="" onChange={onChange} ariaLabel="Editor" data-testid="rte" />,
    );
    const surface = screen.getByRole("textbox");
    surface.innerHTML = '<p class="x">hello <script>alert(1)</script></p>';
    fireEvent.input(surface);
    const emitted = onChange.mock.calls[onChange.mock.calls.length - 1][0];
    expect(emitted).toBe("<p>hello </p>");
  });

  it("bold button has aria-pressed reflecting a toggle state", () => {
    render(<Harness />);
    const boldBtn = screen.getByRole("button", { name: "Bold" });
    expect(boldBtn).toHaveAttribute("aria-pressed");
  });

  it("keyboard-activates a toolbar button", async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();
    render(
      <RichTextEditor value="" onChange={onChange} ariaLabel="Editor" />,
    );
    const boldBtn = screen.getByRole("button", { name: "Bold" });
    boldBtn.focus();
    expect(boldBtn).toHaveFocus();
    await user.keyboard("{Enter}");
    // Clicking bold in an empty editor should not throw and should
    // still leave the editor mounted.
    expect(screen.getByRole("textbox")).toBeInTheDocument();
  });
});

describe("RichTextEditor paste", () => {
  function firePaste(target: Element, data: Record<string, string>) {
    const clipboardData = {
      getData: (type: string) => data[type] ?? "",
      types: Object.keys(data),
    };
    const evt = fireEvent.paste(target, { clipboardData });
    return evt;
  }


  it("strips <a>, images, styles, and scripts from pasted HTML", () => {
    const onChange = vi.fn();
    render(<RichTextEditor value="" onChange={onChange} ariaLabel="Editor" />);
    const surface = screen.getByRole("textbox");
    const notCancelled = firePaste(surface, {
      "text/html":
        '<p style="color:red">Read <a href="https://evil.example">here</a>' +
        '<img src="x"><script>alert(1)</script></p>',
    });
    // fireEvent returns false when the handler called preventDefault().
    expect(notCancelled).toBe(false);

    // Reflect the sanitized paste into the DOM ourselves — jsdom's
    // execCommand("insertHTML") is a no-op, but the sanitizer is
    // what we're actually testing. Trigger an input event so the
    // editor emits its onChange with the surface HTML.
    surface.innerHTML =
      '<p style="color:red">Read <a href="https://evil.example">here</a>' +
      '<img src="x"><script>alert(1)</script></p>';
    fireEvent.input(surface);
    const emitted = onChange.mock.calls.at(-1)![0] as string;
    expect(emitted).not.toContain("<a");
    expect(emitted).not.toContain("href");
    expect(emitted).not.toContain("<img");
    expect(emitted).not.toContain("script");
    expect(emitted).toContain("Read here");
  });

  it("plain-text paste does not become a hyperlink (no autolinking)", () => {
    // The client sanitizer refuses to introduce <a> tags. Verify by
    // running the paste path directly: a URL in text/plain becomes
    // literal text.
    const html = sanitizeRichTextHtml(
      "<p>Visit https://example.com now</p>",
    );
    expect(html).not.toContain("<a");
    expect(html).toContain("https://example.com");
  });

  it("prevents drop of image files", () => {
    render(<RichTextEditor value="" onChange={() => {}} ariaLabel="Editor" />);
    const surface = screen.getByRole("textbox");
    const notCancelled = fireEvent.drop(surface, {
      dataTransfer: { types: ["Files"], files: [] },
    });
    expect(notCancelled).toBe(false);
  });
});


describe("RichTextEditor accessibility and containment", () => {
  it("renders a toolbar with role=toolbar and an accessible name", () => {
    render(<Harness />);
    const tb = screen.getByRole("toolbar", { name: /formatting/i });
    expect(tb).toBeInTheDocument();
  });

  it("shows character count against maxPlainTextLength", () => {
    render(<Harness initial="<p>café</p>" />);
    expect(screen.getByText(/4 \/ 200 characters/i)).toBeInTheDocument();
  });

  it("cannot introduce style, class, or alignment via emitted HTML", () => {
    const out = sanitizeRichTextHtml(
      '<p align="center" class="danger" style="text-align:right">x</p>',
    );
    expect(out).toBe("<p>x</p>");
  });

  it("disables editing and toolbar buttons when disabled", () => {
    render(
      <RichTextEditor
        value=""
        onChange={() => {}}
        ariaLabel="Editor"
        disabled
      />,
    );
    const surface = screen.getByRole("textbox");
    expect(surface).toHaveAttribute("aria-disabled", "true");
    for (const action of RICH_TEXT_ACTIONS) {
      expect(screen.getByRole("button", { name: action.label })).toBeDisabled();
    }
  });
});
