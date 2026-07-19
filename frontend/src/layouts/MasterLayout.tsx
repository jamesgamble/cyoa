import { Outlet, Link } from "react-router-dom";
import { Footer } from "../components/Footer";

export function MasterLayout() {
  return (
    <div className="bp-app">
      <header className="bp-header">
        <div className="bp-header-inner bp-container">
          <Link to="/master" className="bp-brand">Master administration</Link>
        </div>
      </header>
      <main className="bp-main bp-container">
        <Outlet />
      </main>
      <Footer />
    </div>
  );
}
