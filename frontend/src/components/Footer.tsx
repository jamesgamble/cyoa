import { Link } from "react-router-dom";
import { Version } from "./Version";

export function Footer() {
  return (
    <footer className="bp-footer">
      <div className="bp-container bp-footer-inner">
        <div>
          Branching Paths <Version />
        </div>
        <ul>
          <li><Link to="/changelog">Changelog</Link></li>
          <li><Link to="/help">Help</Link></li>
          <li><Link to="/help/community-guidelines">Community Guidelines</Link></li>
          <li><Link to="/help/privacy">Privacy</Link></li>
          <li><Link to="/help/about">About</Link></li>
        </ul>
      </div>
    </footer>
  );
}
