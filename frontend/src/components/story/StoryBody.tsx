interface Props {
  /**
   * Raw story text from a user-authored passage. Rendered as plain
   * paragraphs — split on one-or-more blank lines. React escapes each
   * chunk, so the string cannot introduce HTML, script, style, class,
   * or inline attributes.
   *
   * Consequence: user-provided content cannot control font, color,
   * width, alignment, background, border, or position. Presentation
   * is owned entirely by the .bp-story* CSS.
   */
  text: string;
}

/** Split on blank lines (one or more empty lines between blocks). */
function toParagraphs(text: string): string[] {
  return text
    .replace(/\r\n/g, "\n")
    .split(/\n\s*\n+/)
    .map((p) => p.trim())
    .filter(Boolean);
}

export function StoryBody({ text }: Props) {
  const paragraphs = toParagraphs(text);
  return (
    <div className="bp-story__body" data-testid="story-body">
      {paragraphs.map((p, i) => (
        <p key={i}>{p}</p>
      ))}
    </div>
  );
}
