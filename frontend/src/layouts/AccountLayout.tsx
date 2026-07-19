import { Outlet, Link } from "react-router-dom";
import { PrimaryNav } from "../components/PrimaryNav";
import { Footer } from "../components/Footer";
import { useLayoutMode } from "../lib/layoutMode";

export function AccountLayout() {
  useLayoutMode("manage");
  return (
    <div className="bp-app">
      <a href="#main" className="bp-skip-link">Skip to main content</a>
      <header className="bp-header"><PrimaryNav /></header>
      <main id="main" className="bp-main bp-container bp-container--app">
        <nav aria-label="Account sections" className="bp-panel">
          <div className="bp-panel__header">
            <h2 className="bp-panel__title">Account</h2>
            <Link to="/account">Overview</Link>
          </div>
        </nav>
        <Outlet />
      </main>
      <Footer />
    </div>
  );
}
