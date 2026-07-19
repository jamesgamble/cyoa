import { NavLink, Link } from "react-router-dom";
import { useState, useId } from "react";

const ITEMS = [
  { to: "/", label: "Home", end: true },
  { to: "/discover", label: "Discover" },
  { to: "/start", label: "Create" },
  { to: "/help", label: "Help" },
  { to: "/changelog", label: "Changelog" },
  { to: "/login", label: "Sign In" },
];

export function PrimaryNav() {
  const [open, setOpen] = useState(false);
  const navId = useId();

  return (
    <div className="bp-header-inner bp-container">
      <Link to="/" className="bp-brand" aria-label="Branching Paths, home">
        Branching Paths
      </Link>
      <button
        type="button"
        className="bp-nav-toggle"
        aria-expanded={open}
        aria-controls={navId}
        aria-label="Toggle navigation menu"
        onClick={() => setOpen((v) => !v)}
      >
        Menu
      </button>
      <nav
        id={navId}
        className="bp-nav"
        data-open={open ? "true" : "false"}
        aria-label="Primary"
      >
        <ul>
          {ITEMS.map((item) => (
            <li key={item.to}>
              <NavLink
                to={item.to}
                end={item.end}
                onClick={() => setOpen(false)}
              >
                {item.label}
              </NavLink>
            </li>
          ))}
        </ul>
      </nav>
    </div>
  );
}
