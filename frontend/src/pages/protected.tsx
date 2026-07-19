import { useParams } from "react-router-dom";
import { UnauthorizedState } from "../states";

export function Account() {
  return (
    <section>
      <h1>Account</h1>
      <UnauthorizedState />
    </section>
  );
}

export function Manage() {
  const { slug } = useParams();
  return (
    <section>
      <h1>Manage {slug}</h1>
      <UnauthorizedState />
    </section>
  );
}

export function MasterLogin() {
  return (
    <section>
      <h1>Master sign in</h1>
      <p>Administrator sign-in opens in a later release.</p>
    </section>
  );
}

export function Master() {
  return (
    <section>
      <h1>Master administration</h1>
      <UnauthorizedState />
    </section>
  );
}
