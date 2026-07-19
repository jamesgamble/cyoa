import {
  forwardRef,
  useCallback,
  useEffect,
  useId,
  useImperativeHandle,
  useLayoutEffect,
  useMemo,
  useRef,
  useState,
} from "react";
import {
  richTextLength,
  richTextToPlainText,
  sanitizeRichTextHtml,
} from "../lib/richTextSanitizer";

/**
 * RichTextEditor — a bundled, dependency-free React WYSIWYG editor for
 * user-authored story content. Deliberately restricted: only
 * paragraphs, bold, italic, underline, H2, H3, bulleted lists,
 * numbered lists, blockquotes, horizontal rules, and undo/redo. There
 * is no link tool, image tool, embed tool, alignment tool, colour
 * picker, font selector, source-view, or "clear formatting" that would
 * let a user step outside the allow-list.
 *
 * Paste is filtered through the same client sanitizer as the on-change
 * serialization: links unwrap to their text, images/embeds/styles are
 * removed, URLs are pasted as literal text (never auto-linked), and
 * spacing is normalised. The PHP `App\HtmlSanitizer` re-runs the same
 * filter on the server, so a hostile client bypassing this UI cannot
 * store disallowed HTML.
 *
 * Accessibility: the toolbar exposes buttons with visible labels and
 * `aria-label`s; each toggle carries `aria-pressed` reflecting the
 * current selection state; the editing surface has `role="textbox"`,
 * `aria-multiline="true"`, and an optional `aria-labelledby` linking
 * it to a caller-provided label.
 */

export interface RichTextEditorProps {
  /** Sanitized HTML value. Updates from the parent replace the DOM. */
  value: string;
  /** Called with sanitized HTML whenever the editor emits a change. */
  onChange: (html: string) => void;
  /** Optional plain-text observer (character limits, snippets). */
  onPlainTextChange?: (plain: string) => void;
  /** Optional label id linked via `aria-labelledby`. */
  ariaLabelledBy?: string;
  /** Optional accessible label if no visible label exists. */
  ariaLabel?: string;
  /** Optional character limit computed against the plain-text projection. */
  maxPlainTextLength?: number;
  /** Disables the toolbar and editing surface. */
  disabled?: boolean;
  /** Placeholder shown when the editor is empty. */
  placeholder?: string;
  /** Additional class name applied to the outer wrapper. */
  className?: string;
  /** Test hook. */
  "data-testid"?: string;
}

export interface RichTextEditorHandle {
  /** Force-focus the editing surface. */
  focus: () => void;
  /** Return the current sanitized HTML value. */
  getHtml: () => string;
  /** Return the current plain-text projection. */
  getPlainText: () => string;
}

type ToolbarAction =
  | { kind: "inline"; tag: "strong" | "em" | "u"; label: string; shortLabel: string; command: string }
  | { kind: "block"; tag: "p" | "h2" | "h3" | "blockquote"; label: string; shortLabel: string }
  | { kind: "list"; tag: "ul" | "ol"; label: string; shortLabel: string; command: string }
  | { kind: "hr"; label: string; shortLabel: string }
  | { kind: "history"; direction: "undo" | "redo"; label: string; shortLabel: string; command: string };

/** The full allow-list of toolbar actions — nothing else is exposed. */
export const RICH_TEXT_ACTIONS: readonly ToolbarAction[] = [
  { kind: "inline", tag: "strong", label: "Bold", shortLabel: "B", command: "bold" },
  { kind: "inline", tag: "em", label: "Italic", shortLabel: "I", command: "italic" },
  { kind: "inline", tag: "u", label: "Underline", shortLabel: "U", command: "underline" },
  { kind: "block", tag: "p", label: "Paragraph", shortLabel: "¶" },
  { kind: "block", tag: "h2", label: "Heading 2", shortLabel: "H2" },
  { kind: "block", tag: "h3", label: "Heading 3", shortLabel: "H3" },
  { kind: "list", tag: "ul", label: "Bulleted list", shortLabel: "• List", command: "insertUnorderedList" },
  { kind: "list", tag: "ol", label: "Numbered list", shortLabel: "1. List", command: "insertOrderedList" },
  { kind: "block", tag: "blockquote", label: "Blockquote", shortLabel: "❝" },
  { kind: "hr", label: "Horizontal rule", shortLabel: "―" },
  { kind: "history", direction: "undo", label: "Undo", shortLabel: "↶", command: "undo" },
  { kind: "history", direction: "redo", label: "Redo", shortLabel: "↷", command: "redo" },
];

export const RichTextEditor = forwardRef<RichTextEditorHandle, RichTextEditorProps>(
  function RichTextEditor(
    {
      value,
      onChange,
      onPlainTextChange,
      ariaLabelledBy,
      ariaLabel,
      maxPlainTextLength,
      disabled,
      placeholder = "Start writing…",
      className,
      "data-testid": testId,
    },
    ref,
  ) {
    const editorRef = useRef<HTMLDivElement | null>(null);
    const lastEmittedRef = useRef<string>(value);
    const [isEmpty, setIsEmpty] = useState<boolean>(() => richTextLength(value) === 0);
    const helpId = useId();

    // Sync incoming `value` prop to the DOM when it differs from the
    // last sanitized string we emitted. Avoids clobbering caret while
    // the user is typing.
    useLayoutEffect(() => {
      const el = editorRef.current;
      if (!el) return;
      if (value !== lastEmittedRef.current) {
        el.innerHTML = value;
        lastEmittedRef.current = value;
        setIsEmpty(richTextLength(value) === 0);
      }
    }, [value]);

    useImperativeHandle(
      ref,
      () => ({
        focus: () => editorRef.current?.focus(),
        getHtml: () => lastEmittedRef.current,
        getPlainText: () => richTextToPlainText(lastEmittedRef.current),
      }),
      [],
    );

    const emit = useCallback((): void => {
      const el = editorRef.current;
      if (!el) return;
      const sanitized = sanitizeRichTextHtml(el.innerHTML);
      lastEmittedRef.current = sanitized;
      setIsEmpty(richTextLength(sanitized) === 0);
      onChange(sanitized);
      if (onPlainTextChange) {
        onPlainTextChange(richTextToPlainText(sanitized));
      }
    }, [onChange, onPlainTextChange]);

    const runCommand = useCallback((command: string): void => {
      const el = editorRef.current;
      if (!el) return;
      el.focus();
      // `document.execCommand` is deprecated but remains the only
      // portable way to hook into the browser's native undo stack
      // from a contentEditable surface. We only invoke it for
      // commands that map exactly to allow-listed output; everything
      // is re-sanitized on `emit`, so a browser producing extra
      // markup (font tags, inline styles) is neutralised.
      try {
        document.execCommand(command, false);
      } catch {
        // no-op — some engines refuse execCommand outside a user
        // gesture. The user will simply re-try from the toolbar.
      }
      emit();
    }, [emit]);

    const applyBlock = useCallback((tag: "p" | "h2" | "h3" | "blockquote"): void => {
      const el = editorRef.current;
      if (!el) return;
      el.focus();
      try {
        // execCommand's formatBlock accepts an HTML tag name.
        document.execCommand("formatBlock", false, tag);
      } catch {
        // ignore
      }
      emit();
    }, [emit]);

    const insertHorizontalRule = useCallback((): void => {
      const el = editorRef.current;
      if (!el) return;
      el.focus();
      try {
        document.execCommand("insertHorizontalRule", false);
      } catch {
        // Fallback: append an <hr> at the end.
        el.appendChild(document.createElement("hr"));
      }
      emit();
    }, [emit]);

    /**
     * Paste handler. Reads text/html when present, text/plain
     * otherwise, sanitizes through the same filter as onChange, and
     * inserts the result as HTML. Plain-text pastes are wrapped so a
     * URL is inserted as literal text (never auto-linked).
     */
    const onPaste = useCallback((event: React.ClipboardEvent<HTMLDivElement>): void => {
      event.preventDefault();
      if (disabled) return;
      const cd = event.clipboardData;
      const raw = cd.getData("text/html") || escapePlainText(cd.getData("text/plain"));
      const sanitized = sanitizeRichTextHtml(raw);
      // Insert sanitized HTML at the current selection.
      try {
        document.execCommand("insertHTML", false, sanitized);
      } catch {
        const el = editorRef.current;
        if (el) {
          el.innerHTML = sanitizeRichTextHtml(el.innerHTML + sanitized);
        }
      }
      emit();
    }, [disabled, emit]);

    /** Prevent drag-and-drop of images or files into the surface. */
    const onDrop = useCallback((event: React.DragEvent<HTMLDivElement>): void => {
      const types = Array.from(event.dataTransfer?.types ?? []);
      if (types.includes("Files") || types.some((t) => t.startsWith("image/"))) {
        event.preventDefault();
      }
    }, []);

    const onInput = useCallback((): void => {
      emit();
    }, [emit]);

    const onKeyDown = useCallback((event: React.KeyboardEvent<HTMLDivElement>): void => {
      // Keep the native Tab focus order — do not let a rogue Tab key
      // insert a tab character into the surface.
      if (event.key === "Tab") return;
    }, []);

    const plainLength = useMemo(() => richTextLength(value), [value]);
    const overLimit =
      typeof maxPlainTextLength === "number" && plainLength > maxPlainTextLength;

    return (
      <div
        className={"bp-rte" + (className ? " " + className : "")}
        data-testid={testId}
        data-disabled={disabled ? "true" : "false"}
      >
        <div className="bp-rte__toolbar" role="toolbar" aria-label="Formatting toolbar">
          {RICH_TEXT_ACTIONS.map((action) => (
            <ToolbarButton
              key={buttonKey(action)}
              action={action}
              disabled={disabled}
              onInline={runCommand}
              onBlock={applyBlock}
              onList={runCommand}
              onHr={insertHorizontalRule}
              onHistory={runCommand}
            />
          ))}
        </div>
        <div
          ref={editorRef}
          className="bp-rte__surface"
          role="textbox"
          aria-multiline="true"
          aria-labelledby={ariaLabelledBy}
          aria-label={ariaLabelledBy ? undefined : ariaLabel ?? "Rich text editor"}
          aria-describedby={helpId}
          aria-disabled={disabled ? "true" : "false"}
          contentEditable={!disabled}
          suppressContentEditableWarning
          spellCheck
          data-empty={isEmpty ? "true" : "false"}
          data-placeholder={placeholder}
          onInput={onInput}
          onPaste={onPaste}
          onDrop={onDrop}
          onKeyDown={onKeyDown}
          data-testid={testId ? testId + "-surface" : undefined}
        />
        <p id={helpId} className="bp-rte__help">
          Formatting is limited to paragraphs, bold, italic, underline, headings, lists, blockquotes, and horizontal rules. Pasted links, images, and styles are removed.
        </p>
        {typeof maxPlainTextLength === "number" && (
          <p
            className={"bp-rte__count" + (overLimit ? " bp-rte__count--over" : "")}
            aria-live="polite"
          >
            {plainLength} / {maxPlainTextLength} characters
          </p>
        )}
      </div>
    );
  },
);

function buttonKey(action: ToolbarAction): string {
  if (action.kind === "history") return "history-" + action.direction;
  if (action.kind === "hr") return "hr";
  return action.kind + "-" + action.tag;
}

function escapePlainText(text: string): string {
  if (!text) return "";
  const escaped = text
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;");
  // Split on blank lines to keep paragraph breaks.
  const paragraphs = escaped
    .replace(/\r\n?/g, "\n")
    .split(/\n\s*\n+/)
    .map((p) => p.replace(/\n/g, "<br>"))
    .filter((p) => p.trim() !== "");
  return paragraphs.map((p) => "<p>" + p + "</p>").join("");
}

interface ToolbarButtonProps {
  action: ToolbarAction;
  disabled?: boolean;
  onInline: (command: string) => void;
  onBlock: (tag: "p" | "h2" | "h3" | "blockquote") => void;
  onList: (command: string) => void;
  onHr: () => void;
  onHistory: (command: string) => void;
}

function ToolbarButton({
  action,
  disabled,
  onInline,
  onBlock,
  onList,
  onHr,
  onHistory,
}: ToolbarButtonProps) {
  const [pressed, setPressed] = useState(false);

  // Reflect the selection's format for inline toggles so screen
  // readers announce the active state as it changes. Wrapped in
  // useEffect so we can attach a document-level `selectionchange`
  // listener instead of polling.
  useEffect(() => {
    if (action.kind !== "inline") return;
    const handler = () => {
      try {
        setPressed(document.queryCommandState(action.command));
      } catch {
        // no-op
      }
    };
    handler();
    document.addEventListener("selectionchange", handler);
    return () => document.removeEventListener("selectionchange", handler);
  }, [action]);

  const label = action.label;
  const onClick = () => {
    if (disabled) return;
    switch (action.kind) {
      case "inline":
        onInline(action.command);
        break;
      case "block":
        onBlock(action.tag);
        break;
      case "list":
        onList(action.command);
        break;
      case "hr":
        onHr();
        break;
      case "history":
        onHistory(action.command);
        break;
    }
  };

  return (
    <button
      type="button"
      className="bp-rte__btn"
      onClick={onClick}
      onMouseDown={(e) => e.preventDefault()}
      disabled={disabled}
      aria-label={label}
      aria-pressed={action.kind === "inline" ? pressed : undefined}
      data-action={buttonKey(action)}
      title={label}
    >
      <span aria-hidden="true">{action.shortLabel}</span>
    </button>
  );
}
