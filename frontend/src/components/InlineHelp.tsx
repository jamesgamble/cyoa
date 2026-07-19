import type { ReactNode } from "react";

interface Props {
  id?: string;
  children: ReactNode;
}

/**
 * Inline help — a short passage of guidance shown adjacent to a form
 * field or action, using the muted UI voice.
 */
export function InlineHelp({ id, children }: Props) {
  return (
    <p className="bp-inline-help" id={id}>
      <span className="bp-inline-help__marker" aria-hidden="true">?</span>
      <span>{children}</span>
    </p>
  );
}
