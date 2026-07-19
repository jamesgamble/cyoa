import { Link } from "react-router-dom";
import { Version } from "./Version";
import { Wordmark } from "./Wordmark";

/**
 * Colophon-style footer.
 *
 * Editorial closing plate: wordmark, brief attribution line, a fine
 * rule, and a small directory of secondary links. No promotional
 * copy, no marketing slogans, no gradients.
 */
export function Colophon() {
  return (
    <footer className="bp-footer bp-colophon" role="contentinfo">
      <div className="bp-container bp-colophon__inner">
        <div className="bp-colophon__mast">
          <Wordmark as="block" />
          <p className="bp-colophon__attribution">
            A modest press for reader-directed fiction.
            <span aria-hidden="true"> · </span>
            <Version />
          </p>
        </div>
        <nav className="bp-colophon__nav" aria-label="Colophon">
          <ul>
            <li><Link to="/changelog">Changelog</Link></li>
            <li><Link to="/help">Help</Link></li>
            <li><Link to="/help/community-guidelines">Community Guidelines</Link></li>
            <li><Link to="/help/privacy">Privacy</Link></li>
            <li><Link to="/help/about">About</Link></li>
          </ul>
        </nav>
      </div>
    </footer>
  );
}
