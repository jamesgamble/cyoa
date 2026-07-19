import { Outlet } from "react-router-dom";
import { PrimaryNav } from "../components/PrimaryNav";
import { Footer } from "../components/Footer";
import { useLayoutMode } from "../lib/layoutMode";

export function PublicLayout() {
  useLayoutMode("reading");
  return (
    <div className="bp-app">
      <a href="#main" className="bp-skip-link">Skip to main content</a>
      <header className="bp-header">
        <PrimaryNav />
      </header>
      <main id="main" className="bp-main bp-container">
        <Outlet />
      </main>
      <Footer />
    </div>
  );
}
