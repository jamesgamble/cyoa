import { Outlet, Link } from "react-router-dom";
import { PrimaryNav } from "../components/PrimaryNav";
import { Footer } from "../components/Footer";

export function AccountLayout() {
  return (
    <div className="bp-app">
      <header className="bp-header"><PrimaryNav /></header>
      <main className="bp-main bp-container">
        <nav aria-label="Account sections" className="bp-card">
          <strong>Account</strong>{" "}
          <Link to="/account">Overview</Link>
        </nav>
        <Outlet />
      </main>
      <Footer />
    </div>
  );
}
