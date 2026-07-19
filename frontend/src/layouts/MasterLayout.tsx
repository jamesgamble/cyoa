import { Outlet, Link } from "react-router-dom";
import { Footer } from "../components/Footer";
import { useLayoutMode } from "../lib/layoutMode";

export function MasterLayout() {
  useLayoutMode("admin");
  return (
    <div className="bp-app">
      <a href="#main" className="bp-skip-link">Skip to main content</a>
      <header className="bp-header">
        <div className="bp-header-inner">
          <Link to="/master" className="bp-brand">Master administration</Link>
        </div>
      </header>
      <main id="main" className="bp-main bp-container bp-container--admin">
        <Outlet />
      </main>
      <Footer />
    </div>
  );
}
