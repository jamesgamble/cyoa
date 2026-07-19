import { Link } from "react-router-dom";

interface Props {
  message?: string;
}

export function LoadingState({ message = "Loading…" }: Props) {
  return (
    <div className="bp-state" role="status" aria-live="polite">
      <p>{message}</p>
    </div>
  );
}

export function EmptyState({ title = "Nothing here yet", message }: { title?: string; message?: string }) {
  return (
    <div className="bp-state">
      <h2>{title}</h2>
      {message && <p>{message}</p>}
    </div>
  );
}

export function ErrorState({ title = "Something went wrong", message }: { title?: string; message?: string }) {
  return (
    <div className="bp-state" role="alert">
      <h2>{title}</h2>
      {message && <p>{message}</p>}
    </div>
  );
}

export function UnauthorizedState() {
  return (
    <div className="bp-state">
      <h2>Sign in required</h2>
      <p>You need to be signed in to view this page.</p>
      <p><Link to="/login">Go to sign in</Link></p>
    </div>
  );
}

export function NotFoundState() {
  return (
    <div className="bp-state" data-testid="not-found">
      <h2>Page not found</h2>
      <p>The page you are looking for does not exist or has moved.</p>
      <p><Link to="/">Return home</Link></p>
    </div>
  );
}

export function ServiceUnavailableState() {
  return (
    <div className="bp-state">
      <h2>Service unavailable</h2>
      <p>Branching Paths is temporarily unavailable. Please try again shortly.</p>
    </div>
  );
}
