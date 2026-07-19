import { Outlet, useParams } from "react-router-dom";
import { PrimaryNav } from "../components/PrimaryNav";
import { Footer } from "../components/Footer";
import { useLayoutMode } from "../lib/layoutMode";

export function ManageLayout() {
  useLayoutMode("manage");
  const { slug } = useParams();
  return (
    <div className="bp-app">
      <a href="#main" className="bp-skip-link">Skip to main content</a>
      <header className="bp-header"><PrimaryNav /></header>
      <main id="main" className="bp-main bp-container bp-container--app">
        <nav aria-label="Adventure management" className="bp-panel">
          <div className="bp-panel__header">
            <h2 className="bp-panel__title">Manage adventure</h2>
            <span style={{ fontSize: "var(--bp-fs-sm)", color: "var(--bp-muted)" }}>
              {slug ?? "(unspecified)"}
            </span>
          </div>
        </nav>
        <Outlet />
      </main>
      <Footer />
    </div>
  );
}
