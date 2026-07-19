import { Link } from "react-router-dom";

export { Home } from "./Home";
export { Discover } from "./Discover";


export function Start() {
  return (
    <section>
      <h1>Create an adventure</h1>
      <p>Adventure creation opens in a later release. Sign in to be ready.</p>
    </section>
  );
}

export function Login() {
  return (
    <section>
      <h1>Sign in</h1>
      <p>Sign-in is not yet available. It arrives with the account system.</p>
      <p><Link to="/register">Create an account</Link></p>
    </section>
  );
}

export function Register() {
  return (
    <section>
      <h1>Create an account</h1>
      <p>Registration opens in a later release.</p>
    </section>
  );
}
