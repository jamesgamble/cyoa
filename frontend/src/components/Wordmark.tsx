import { Link } from "react-router-dom";

interface Props {
  /** Rendering context — inline (nav) or block (footer/masthead). */
  as?: "inline" | "block";
  /** Optional link target; when omitted the wordmark is a plain span. */
  to?: string;
  /** Optional aria-label override. */
  ariaLabel?: string;
}

/**
 * Text-based wordmark for Branching Paths.
 *
 * Pure typography — no images, no icons rendered outside of a single
 * decorative fleuron (::before, aria-hidden by convention). The mark
 * inherits editorial styling via the `.bp-wordmark` class.
 */
export function Wordmark({ as = "inline", to, ariaLabel }: Props) {
  const cls = `bp-wordmark bp-wordmark--${as}`;
  const inner = (
    <>
      <span className="bp-wordmark__mark" aria-hidden="true">❦</span>
      <span className="bp-wordmark__text">Branching Paths</span>
    </>
  );
  if (to) {
    return (
      <Link to={to} className={cls} aria-label={ariaLabel ?? "Branching Paths, home"}>
        {inner}
      </Link>
    );
  }
  return (
    <span className={cls} aria-label={ariaLabel}>
      {inner}
    </span>
  );
}
