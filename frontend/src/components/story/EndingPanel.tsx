interface Props {
  /** The ending's title — rendered as plain text. */
  title: string;
  /** Optional flavor label ("An ending", "The good ending", etc.). */
  kind?: string;
  /** Prose body of the ending. Sanitized identically to StoryBody. */
  body: string;
}

function toParagraphs(text: string): string[] {
  return text
    .replace(/\r\n/g, "\n")
    .split(/\n\s*\n+/)
    .map((p) => p.trim())
    .filter(Boolean);
}

/**
 * Ending panel — closes a story branch with an editorial finality.
 * Uses ornament rule and centered title. User content is rendered as
 * plain paragraphs; styling is fully owned by CSS.
 */
export function EndingPanel({ title, kind = "An ending", body }: Props) {
  const paragraphs = toParagraphs(body);
  return (
    <section className="bp-ending" aria-labelledby="bp-ending-title" data-testid="ending-panel">
      <span className="bp-ornament" aria-hidden="true" />
      <p className="bp-ending__kind">{kind}</p>
      <h2 id="bp-ending-title" className="bp-ending__title">{title}</h2>
      <div className="bp-ending__body" data-testid="ending-body">
        {paragraphs.map((p, i) => (
          <p key={i}>{p}</p>
        ))}
      </div>
    </section>
  );
}
