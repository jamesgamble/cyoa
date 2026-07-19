/**
 * Client-side sanitizer for the restricted WYSIWYG editor.
 *
 * The PHP `App\HtmlSanitizer` is the sole authoritative filter — every
 * write path re-sanitizes on the server. This client copy exists only
 * so paste behaviour and the editor's own on-change output can enforce
 * the same allow-list *before* the string ever hits the wire, giving
 * the user immediate visual feedback that (for example) a pasted link
 * lost its href.
 *
 * Allow-list:
 *   p, strong, em, u, h2, h3, ul, ol, li, blockquote, hr, br
 *
 * Every attribute is stripped. `<a>` unwraps (text kept, link discarded);
 * `<b>` / `<i>` / `<ins>` rename to strong / em / u; everything else in
 * the drop-subtree list (script, style, iframe, svg, math, form,
 * img, video, audio, object, embed, link, meta, ...) is discarded
 * whole — including its text — because those tags never carry body
 * text a reader would want.
 */

const BLOCK_TAGS = new Set(["p", "h2", "h3", "blockquote"]);
const INLINE_TAGS = new Set(["strong", "em", "u"]);
const LIST_TAGS = new Set(["ul", "ol"]);
const DROP_SUBTREE = new Set([
  "script", "style", "iframe", "object", "embed", "noscript",
  "svg", "math", "form", "template", "button", "input",
  "textarea", "select", "option", "link", "meta", "title",
  "head", "canvas", "audio", "video", "source", "track",
  "picture", "map", "area", "img", "frame", "frameset",
  "applet", "base",
]);
const ALIAS: Record<string, string> = {
  b: "strong",
  i: "em",
  strong: "strong",
  em: "em",
  u: "u",
  ins: "u",
};

/**
 * Sanitize a raw HTML fragment (as pasted or as read from a
 * `contentEditable` surface) into the restricted subset.
 *
 * Returns a canonical HTML string composed only of block-level
 * elements from the allow-list. Free text at the top level is wrapped
 * in `<p>`. Empty input returns an empty string.
 */
export function sanitizeRichTextHtml(html: string): string {
  const source = (html ?? "").replace(/\r\n?/g, "\n");
  if (!source.trim()) return "";
  const doc = new DOMParser().parseFromString(
    "<div id=\"bp-root\">" + source + "</div>",
    "text/html",
  );
  const root = doc.getElementById("bp-root");
  if (!root) return "";
  const outDoc = document.implementation.createHTMLDocument("");
  const container = outDoc.createElement("div");
  processContainer(root, container, outDoc);
  return container.innerHTML;
}

/** Plain-text projection used for character limits, snippets, search. */
export function richTextToPlainText(html: string): string {
  if (!html || !html.trim()) return "";
  const doc = new DOMParser().parseFromString(
    "<div id=\"bp-root\">" + html + "</div>",
    "text/html",
  );
  const root = doc.getElementById("bp-root");
  if (!root) return "";
  const lines: string[] = [];
  collect(root, lines, 0);
  const out: string[] = [];
  let prevBlank = true;
  for (const raw of lines) {
    const trim = raw.replace(/[ \t]+/g, " ").trim();
    if (trim === "") {
      if (!prevBlank) {
        out.push("");
        prevBlank = true;
      }
      continue;
    }
    out.push(trim);
    prevBlank = false;
  }
  return out.join("\n").trim();
}

/** Character count on the plain-text projection. */
export function richTextLength(html: string): number {
  return Array.from(richTextToPlainText(html)).length;
}

// ── internals ─────────────────────────────────────────────────

function processContainer(
  source: Node,
  target: HTMLElement,
  doc: Document,
): void {
  const inlineBuf: Node[] = [];
  const flush = () => {
    if (!inlineBuf.length) return;
    const hasContent = inlineBuf.some(
      (n) =>
        (n.nodeType === Node.TEXT_NODE && (n.nodeValue ?? "").trim() !== "") ||
        n.nodeType === Node.ELEMENT_NODE,
    );
    if (hasContent) {
      const p = doc.createElement("p");
      for (const n of inlineBuf) p.appendChild(n);
      target.appendChild(p);
    }
    inlineBuf.length = 0;
  };

  const children = Array.from(source.childNodes);
  for (const child of children) {
    if (child.nodeType === Node.COMMENT_NODE) continue;
    if (child.nodeType === Node.TEXT_NODE) {
      inlineBuf.push(doc.createTextNode(child.nodeValue ?? ""));
      continue;
    }
    if (child.nodeType !== Node.ELEMENT_NODE) continue;
    const el = child as Element;
    const tag = el.tagName.toLowerCase();

    if (DROP_SUBTREE.has(tag)) continue;

    if (BLOCK_TAGS.has(tag)) {
      flush();
      const block = doc.createElement(tag);
      fillInline(el, block, doc);
      if (block.textContent && block.textContent.trim() !== "") {
        target.appendChild(block);
      }
      continue;
    }

    if (LIST_TAGS.has(tag)) {
      flush();
      const list = doc.createElement(tag);
      for (const li of Array.from(el.children)) {
        if (li.tagName.toLowerCase() !== "li") continue;
        const outLi = doc.createElement("li");
        const scratch = doc.createElement("div");
        processContainer(li, scratch, doc);
        for (const b of Array.from(scratch.childNodes)) {
          if (b.nodeType === Node.ELEMENT_NODE) {
            const btag = (b as Element).tagName.toLowerCase();
            if (btag === "p") {
              while (b.firstChild) outLi.appendChild(b.firstChild);
              continue;
            }
            if (LIST_TAGS.has(btag)) {
              outLi.appendChild(b);
              continue;
            }
            outLi.appendChild(doc.createTextNode(b.textContent ?? ""));
          }
        }
        if ((outLi.textContent ?? "").trim() !== "") {
          list.appendChild(outLi);
        }
      }
      if (list.childNodes.length) target.appendChild(list);
      continue;
    }

    if (tag === "hr") {
      flush();
      target.appendChild(doc.createElement("hr"));
      continue;
    }

    if (tag === "br") {
      inlineBuf.push(doc.createElement("br"));
      continue;
    }

    const canonical = ALIAS[tag];
    if (canonical && INLINE_TAGS.has(canonical)) {
      const inline = doc.createElement(canonical);
      fillInline(el, inline, doc);
      if (inline.childNodes.length) inlineBuf.push(inline);
      continue;
    }

    // Unknown tag (a, span, div, table, custom-*): unwrap.
    // Recurse into a scratch container in the same target context.
    const scratch = doc.createElement("div");
    processContainer(el, scratch, doc);
    // Blocks go straight to target; leading/trailing inline content
    // is merged back into the inline buffer via textContent.
    for (const n of Array.from(scratch.childNodes)) {
      if (n.nodeType === Node.ELEMENT_NODE) {
        const nt = (n as Element).tagName.toLowerCase();
        if (
          BLOCK_TAGS.has(nt) ||
          LIST_TAGS.has(nt) ||
          nt === "hr"
        ) {
          flush();
          target.appendChild(n);
          continue;
        }
      }
      inlineBuf.push(n);
    }
  }
  flush();
}

function fillInline(source: Element, target: HTMLElement, doc: Document): void {
  for (const child of Array.from(source.childNodes)) {
    if (child.nodeType === Node.TEXT_NODE) {
      target.appendChild(doc.createTextNode(child.nodeValue ?? ""));
      continue;
    }
    if (child.nodeType !== Node.ELEMENT_NODE) continue;
    const el = child as Element;
    const tag = el.tagName.toLowerCase();
    if (DROP_SUBTREE.has(tag)) continue;
    if (tag === "br") {
      target.appendChild(doc.createElement("br"));
      continue;
    }
    const canonical = ALIAS[tag];
    if (canonical && INLINE_TAGS.has(canonical)) {
      const inline = doc.createElement(canonical);
      fillInline(el, inline, doc);
      if (inline.childNodes.length) target.appendChild(inline);
      continue;
    }
    // Anything else: flatten to text (recurse into inline children).
    fillInline(el, target, doc);
  }
}

function collect(node: Node, lines: string[], listDepth: number): void {
  for (const child of Array.from(node.childNodes)) {
    if (child.nodeType === Node.TEXT_NODE) {
      const v = child.nodeValue ?? "";
      if (v === "") continue;
      if (!lines.length) lines.push(v);
      else lines[lines.length - 1] += v;
      continue;
    }
    if (child.nodeType !== Node.ELEMENT_NODE) continue;
    const el = child as Element;
    const tag = el.tagName.toLowerCase();
    if (DROP_SUBTREE.has(tag)) continue;
    if (tag === "br") {
      lines.push("");
      continue;
    }
    if (tag === "hr") {
      lines.push("");
      lines.push("");
      continue;
    }
    if (tag === "p" || tag === "h2" || tag === "h3" || tag === "blockquote") {
      lines.push("");
      collect(el, lines, listDepth);
      lines.push("");
      continue;
    }
    if (LIST_TAGS.has(tag)) {
      collect(el, lines, listDepth + 1);
      continue;
    }
    if (tag === "li") {
      const prefix = "  ".repeat(Math.max(0, listDepth - 1)) + "- ";
      lines.push(prefix);
      collect(el, lines, listDepth);
      lines.push("");
      continue;
    }
    collect(el, lines, listDepth);
  }
}
