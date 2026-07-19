import { NavLink } from "react-router-dom";
import { useEffect, useId, useState } from "react";
import { Wordmark } from "./Wordmark";
import { MobileNav } from "./MobileNav";

interface Item {
  to: string;
  label: string;
  end?: boolean;
}

const DEFAULT_ITEMS: Item[] = [
  { to: "/", label: "Home", end: true },
  { to: "/discover", label: "Discover" },
  { to: "/start", label: "Create" },
  { to: "/help", label: "Help" },
  { to: "/changelog", label: "Changelog" },
  { to: "/login", label: "Sign In" },
];

interface Props {
  items?: Item[];
}

/**
 * Literary masthead — an editorial header with a text-based wordmark
 * on the left, a fine rule beneath, and inline navigation on the right.
 * Falls back to a mobile navigation drawer at narrow viewports.
 */
export function Masthead({ items = DEFAULT_ITEMS }: Props) {
  const [open, setOpen] = useState(false);
  const navId = useId();

  // Close mobile nav on route change or resize wider than mobile breakpoint.
  useEffect(() => {
    function onResize() {
      if (window.innerWidth > 720) setOpen(false);
    }
    window.addEventListener("resize", onResize);
    return () => window.removeEventListener("resize", onResize);
  }, []);

  return (
    <div className="bp-masthead bp-header-inner bp-container">
      <Wordmark to="/" />
      <button
        type="button"
        className="bp-nav-toggle"
        aria-expanded={open}
        aria-controls={navId}
        aria-label={open ? "Close navigation menu" : "Open navigation menu"}
        onClick={() => setOpen((v) => !v)}
        data-testid="masthead-toggle"
      >
        Menu
      </button>
      <nav className="bp-nav bp-nav--desktop" aria-label="Primary">
        <ul>
          {items.map((item) => (
            <li key={item.to}>
              <NavLink to={item.to} end={item.end}>
                {item.label}
              </NavLink>
            </li>
          ))}
        </ul>
      </nav>
      <MobileNav
        id={navId}
        items={items}
        open={open}
        onClose={() => setOpen(false)}
      />
    </div>
  );
}
