import { Outlet, NavLink } from "react-router-dom";
import { PrimaryNav } from "../components/PrimaryNav";
import { Footer } from "../components/Footer";
import { useLayoutMode } from "../lib/layoutMode";

const links: Array<{ to: string; label: string; end?: boolean }> = [
  { to: "/account", label: "Overview", end: true },
  { to: "/account/profile", label: "Profile" },
  { to: "/account/security", label: "Security" },
  { to: "/account/notifications", label: "Notifications" },
  { to: "/account/adventures", label: "My adventures" },
  { to: "/account/contributions", label: "Contributions" },
  { to: "/account/bookmarks", label: "Bookmarks" },
];

export function AccountLayout() {
  useLayoutMode("manage");
  return (
    <div className="bp-app">
      <a href="#main" className="bp-skip-link">Skip to main content</a>
      <header className="bp-header"><PrimaryNav /></header>
      <main id="main" className="bp-main bp-container bp-container--app">
        <nav aria-label="Account sections" className="bp-panel bp-account-nav">
          <div className="bp-panel__header">
            <h2 className="bp-panel__title">Account</h2>
          </div>
          <ul>
            {links.map((l) => (
              <li key={l.to}>
                <NavLink
                  to={l.to}
                  end={l.end}
                  className={({ isActive }) => (isActive ? "is-active" : undefined)}
                >
                  {l.label}
                </NavLink>
              </li>
            ))}
          </ul>
        </nav>
        <Outlet />
      </main>
      <Footer />
    </div>
  );
}
