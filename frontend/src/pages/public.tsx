export { Home } from "./Home";
export { Discover } from "./Discover";
export { Register } from "./RegisterPage";
export {
  Login,
  ForgotPassword,
  ResetPassword,
  VerifyEmail,
  ChangePassword,
} from "./AuthPages";

export function Start() {
  return (
    <section>
      <h1>Create an adventure</h1>
      <p>Adventure creation opens in a later release. Sign in to be ready.</p>
    </section>
  );
}
