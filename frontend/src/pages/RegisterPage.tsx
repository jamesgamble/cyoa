/**
 * Register — v0.11.0 registration form.
 *
 * A single-page form that collects email, username, display name,
 * password, password confirmation, and terms acceptance. The
 * hidden `nickname_url` field is a honeypot: real users never see
 * it, but a scripted form filler fills every input and gets a
 * silent 202 "accepted" response.
 *
 * All validation runs both client- and server-side. The server is
 * the source of truth; the client mirrors it for a nicer UX and to
 * cut down on obvious bad submissions.
 */

import { useEffect, useId, useMemo, useState } from "react";
import { Link } from "react-router-dom";
import { PasswordField } from "../../components/PasswordField";
import { Checkbox } from "../../components/Checkbox";
import { ValidationMessage } from "../../components/ValidationMessage";
import { FormSection } from "../../components/FormSection";
import { Button } from "../../components/Button";
import {
  fetchCsrfToken,
  fetchRegistrationSettings,
  submitRegistration,
  type RegistrationSettings,
} from "../../lib/apiClient";

interface FormState {
  email: string;
  username: string;
  display_name: string;
  password: string;
  password_confirmation: string;
  terms_accepted: boolean;
  nickname_url: string; // honeypot
}

const INITIAL: FormState = {
  email: "",
  username: "",
  display_name: "",
  password: "",
  password_confirmation: "",
  terms_accepted: false,
  nickname_url: "",
};

const DEFAULT_SETTINGS: RegistrationSettings = {
  registration_enabled: true,
  minimum_password_length: 12,
  requires_email_verification: true,
  requires_admin_approval: false,
};

type FieldErrors = Partial<Record<keyof FormState, string>>;

export function Register() {
  const [form, setForm] = useState<FormState>(INITIAL);
  const [settings, setSettings] = useState<RegistrationSettings>(DEFAULT_SETTINGS);
  const [errors, setErrors] = useState<FieldErrors>({});
  const [status, setStatus] = useState<
    | "idle"
    | "submitting"
    | "accepted"
    | "rate_limited"
    | "csrf_failed"
    | "registration_disabled"
    | "service_unavailable"
  >("idle");
  const [csrfToken, setCsrfToken] = useState<string | null>(null);
  const honeyId = useId();

  useEffect(() => {
    const ac = new AbortController();
    fetchRegistrationSettings(ac.signal).then((s) => {
      if (s) setSettings(s);
    });
    fetchCsrfToken(ac.signal).then((t) => {
      if (t) setCsrfToken(t);
    });
    return () => ac.abort();
  }, []);

  const minLen = settings.minimum_password_length;

  const clientErrors = useMemo<FieldErrors>(() => {
    const e: FieldErrors = {};
    if (!form.email.trim()) e.email = "required";
    else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.email.trim()))
      e.email = "invalid";
    if (!form.username.trim()) e.username = "required";
    else if (!/^[A-Za-z0-9_-]{3,32}$/.test(form.username.trim()))
      e.username = "invalid";
    if (!form.display_name.trim()) e.display_name = "required";
    if (!form.password) e.password = "required";
    else if (form.password.length < minLen) e.password = "too_short";
    if (form.password !== form.password_confirmation)
      e.password_confirmation = "mismatch";
    if (!form.terms_accepted) e.terms_accepted = "required";
    return e;
  }, [form, minLen]);

  const onSubmit = async (event: React.FormEvent) => {
    event.preventDefault();
    if (status === "submitting") return;
    setErrors({});
    if (Object.keys(clientErrors).length > 0) {
      setErrors(clientErrors);
      return;
    }
    setStatus("submitting");
    const token = csrfToken ?? (await fetchCsrfToken());
    if (!token) {
      setStatus("csrf_failed");
      return;
    }
    const result = await submitRegistration(
      {
        email: form.email.trim(),
        username: form.username.trim(),
        display_name: form.display_name.trim(),
        password: form.password,
        password_confirmation: form.password_confirmation,
        terms_accepted: form.terms_accepted,
        nickname_url: form.nickname_url,
      },
      token,
    );
    if (result.outcome === "invalid") {
      setErrors(result.fields as FieldErrors);
      setStatus("idle");
      return;
    }
    setStatus(result.outcome);
  };

  if (!settings.registration_enabled) {
    return (
      <section aria-labelledby="register-heading">
        <h1 id="register-heading">Create an account</h1>
        <p>
          Registration is temporarily closed. Reading Branching Paths does not
          require an account — visit <Link to="/discover">Discover</Link> to
          continue.
        </p>
      </section>
    );
  }

  if (status === "accepted") {
    return (
      <section aria-labelledby="register-heading">
        <h1 id="register-heading">Thank you</h1>
        <p>
          If the details you provided form a new account, we have accepted
          the registration.
          {settings.requires_email_verification
            ? " Email verification will follow in a later release."
            : settings.requires_admin_approval
              ? " An administrator will review the account before it is activated."
              : " You will be able to sign in once the sign-in flow ships."}
        </p>
        <p>
          <Link to="/">Return home</Link>
        </p>
      </section>
    );
  }

  const combinedErrors: FieldErrors = { ...clientErrors, ...errors };
  const anyBanner =
    status === "rate_limited"
      ? "Too many recent registration attempts from this network. Please try again later."
      : status === "csrf_failed"
        ? "The form has expired. Reload the page and try again."
        : status === "service_unavailable"
          ? "The registration service is temporarily unavailable."
          : null;

  return (
    <section aria-labelledby="register-heading">
      <h1 id="register-heading">Create an account</h1>
      <p>
        Reading adventures does not require an account. An account lets you
        publish adventures and contribute branches once those features open.
      </p>

      {anyBanner && (
        <ValidationMessage tone="warning">{anyBanner}</ValidationMessage>
      )}

      <form onSubmit={onSubmit} noValidate>
        <FormSection title="Your details">
          <div className="bp-field">
            <label htmlFor="reg-email" className="bp-label">Email</label>
            <input
              id="reg-email"
              type="email"
              className="bp-input"
              autoComplete="email"
              required
              value={form.email}
              aria-invalid={combinedErrors.email ? "true" : undefined}
              onChange={(e) => setForm({ ...form, email: e.target.value })}
            />
            {combinedErrors.email && (
              <ValidationMessage>{fieldLabel(combinedErrors.email)}</ValidationMessage>
            )}
          </div>

          <div className="bp-field">
            <label htmlFor="reg-username" className="bp-label">Username</label>
            <input
              id="reg-username"
              type="text"
              className="bp-input"
              autoComplete="username"
              inputMode="text"
              pattern="[A-Za-z0-9_\-]{3,32}"
              required
              value={form.username}
              aria-invalid={combinedErrors.username ? "true" : undefined}
              onChange={(e) => setForm({ ...form, username: e.target.value })}
            />
            <span className="bp-help">
              3–32 characters. Letters, digits, hyphen, and underscore.
              Case-insensitive: <em>Alice</em> and <em>alice</em> are the same.
            </span>
            {combinedErrors.username && (
              <ValidationMessage>{fieldLabel(combinedErrors.username)}</ValidationMessage>
            )}
          </div>

          <div className="bp-field">
            <label htmlFor="reg-display" className="bp-label">Display name</label>
            <input
              id="reg-display"
              type="text"
              className="bp-input"
              autoComplete="nickname"
              required
              value={form.display_name}
              aria-invalid={combinedErrors.display_name ? "true" : undefined}
              onChange={(e) => setForm({ ...form, display_name: e.target.value })}
            />
            {combinedErrors.display_name && (
              <ValidationMessage>{fieldLabel(combinedErrors.display_name)}</ValidationMessage>
            )}
          </div>
        </FormSection>

        <FormSection title="Password">
          <PasswordField
            label="Password"
            autoComplete="new-password"
            required
            value={form.password}
            help={`At least ${minLen} characters.`}
            error={combinedErrors.password ? fieldLabel(combinedErrors.password) : undefined}
            onChange={(e) => setForm({ ...form, password: e.target.value })}
          />
          <PasswordField
            label="Confirm password"
            autoComplete="new-password"
            required
            value={form.password_confirmation}
            error={
              combinedErrors.password_confirmation
                ? fieldLabel(combinedErrors.password_confirmation)
                : undefined
            }
            onChange={(e) =>
              setForm({ ...form, password_confirmation: e.target.value })
            }
          />
        </FormSection>

        <FormSection title="Terms">
          <Checkbox
            checked={form.terms_accepted}
            label={
              <>
                I have read and accept the{" "}
                <Link to="/help/community-guidelines">community guidelines</Link>.
              </>
            }
            onChange={(e) =>
              setForm({ ...form, terms_accepted: e.target.checked })
            }
          />
          {combinedErrors.terms_accepted && (
            <ValidationMessage>You must accept the guidelines to continue.</ValidationMessage>
          )}
        </FormSection>

        {/* Honeypot — visible only to bots. Real users leave it empty. */}
        <div
          aria-hidden="true"
          style={{
            position: "absolute",
            left: "-10000px",
            width: "1px",
            height: "1px",
            overflow: "hidden",
          }}
        >
          <label htmlFor={honeyId}>Website</label>
          <input
            id={honeyId}
            type="text"
            name="nickname_url"
            tabIndex={-1}
            autoComplete="off"
            value={form.nickname_url}
            onChange={(e) => setForm({ ...form, nickname_url: e.target.value })}
          />
        </div>

        <div className="bp-form-actions">
          <Button
            type="submit"
            disabled={status === "submitting"}
          >
            {status === "submitting" ? "Submitting…" : "Create account"}
          </Button>
          <Link to="/login">Already registered? Sign in</Link>
        </div>
      </form>
    </section>
  );
}

function fieldLabel(code: string): string {
  switch (code) {
    case "required": return "This field is required.";
    case "invalid": return "Please enter a valid value.";
    case "too_short": return "Password is too short.";
    case "mismatch": return "Passwords do not match.";
    default: return "Please review this field.";
  }
}
