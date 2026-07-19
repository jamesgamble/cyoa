import type { ReactNode } from "react";

interface Props {
  children: ReactNode;
  /** Optional chapter or scene meta label rendered above the title. */
  meta?: string;
}

/**
 * Story page container.
 *
 * Wraps a single scene: meta, scene title, story body, and optional
 * choices or ending panel. The container fixes width, alignment, and
 * background — user-supplied content cannot override these because
 * it is rendered only through the sanitizing StoryBody / SceneTitle
 * components below.
 */
export function StoryPage({ children, meta }: Props) {
  return (
    <article className="bp-story bp-story-page" data-testid="story-page">
      {meta && <p className="bp-story__meta">{meta}</p>}
      {children}
    </article>
  );
}
