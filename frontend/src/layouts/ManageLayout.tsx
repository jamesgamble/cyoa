import { Outlet, useParams } from "react-router-dom";
import { PrimaryNav } from "../components/PrimaryNav";
import { Footer } from "../components/Footer";

export function ManageLayout() {
  const { slug } = useParams();
  return (
    <div className="bp-app">
      <header className="bp-header"><PrimaryNav /></header>
      <main className="bp-main bp-container">
        <nav aria-label="Adventure management" className="bp-card">
          <strong>Manage adventure:</strong> {slug ?? "(unspecified)"}
        </nav>
        <Outlet />
      </main>
      <Footer />
    </div>
  );
}
