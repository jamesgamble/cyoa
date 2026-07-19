interface Props {
  /**
   * Scene title from user content. Rendered as plain text only — any
   * markup embedded in the string is escaped by React and displayed
   * verbatim. Styling comes from `.bp-story h1` alone.
   */
  children: string;
}

export function SceneTitle({ children }: Props) {
  // React string children are escaped; we accept `string` only to make
  // the containment guarantee explicit at the type level.
  return <h1 className="bp-scene-title">{children}</h1>;
}
