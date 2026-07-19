import type { ReactNode } from "react";

interface ChoiceProps {
  /** The choice text — rendered as plain text. */
  children: string;
  /** Optional href. When present the choice renders as a link. */
  href?: string;
  /** Optional click handler for button-mode choices. */
  onClick?: () => void;
  disabled?: boolean;
}

/**
 * Numbered choice — either a link or a button.
 *
 * Numbering, ordering, spacing, and the leading numeral are provided by
 * CSS counters on `.bp-choice`; the surrounding <ol> assigns numbers.
 * User content cannot alter the numeral or its typography.
 */
export function Choice({ children, href, onClick, disabled }: ChoiceProps) {
  if (href && !disabled) {
    return (
      <a className="bp-choice" href={href}>
        <span className="bp-choice__text">{children}</span>
      </a>
    );
  }
  return (
    <button
      type="button"
      className="bp-choice bp-choice--button"
      onClick={onClick}
      disabled={disabled}
    >
      <span className="bp-choice__text">{children}</span>
    </button>
  );
}

interface ChoiceListProps {
  /**
   * Ordered list of choice labels. Each label is escaped as plain text.
   */
  choices: Array<{ label: string; href?: string; onClick?: () => void; disabled?: boolean }>;
  ariaLabel?: string;
}

export function ChoiceList({ choices, ariaLabel = "Choose your path" }: ChoiceListProps) {
  return (
    <ol className="bp-choices" data-testid="choice-list" aria-label={ariaLabel}>
      {choices.map((c, i) => (
        <li key={i}>
          <Choice href={c.href} onClick={c.onClick} disabled={c.disabled}>
            {c.label}
          </Choice>
        </li>
      ))}
    </ol>
  );
}

/** Named export for the section heading that precedes a ChoiceList. */
export function ChoicesHeading({ children }: { children: ReactNode }) {
  return <p className="bp-story__meta bp-choices__heading">{children}</p>;
}
