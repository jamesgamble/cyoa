import { useId, useState, type InputHTMLAttributes } from "react";

interface Props extends Omit<InputHTMLAttributes<HTMLInputElement>, "type"> {
  label: string;
  help?: string;
  error?: string;
}

export function PasswordField({ label, help, error, id, className, ...rest }: Props) {
  const auto = useId();
  const [reveal, setReveal] = useState(false);
  const inputId = id ?? auto;
  const helpId = help ? `${inputId}-help` : undefined;
  const errorId = error ? `${inputId}-error` : undefined;
  const describedBy = [helpId, errorId].filter(Boolean).join(" ") || undefined;
  return (
    <div className="bp-field">
      <label htmlFor={inputId} className="bp-label">{label}</label>
      <div className="bp-password-field">
        <input
          id={inputId}
          type={reveal ? "text" : "password"}
          className={["bp-input bp-password-field__input", className].filter(Boolean).join(" ")}
          aria-invalid={error ? "true" : undefined}
          aria-describedby={describedBy}
          autoComplete="current-password"
          {...rest}
        />
        <button
          type="button"
          className="bp-password-field__toggle"
          aria-pressed={reveal}
          aria-label={reveal ? "Hide password" : "Show password"}
          onClick={() => setReveal((v) => !v)}
        >
          {reveal ? "Hide" : "Show"}
        </button>
      </div>
      {help && !error && <span id={helpId} className="bp-help">{help}</span>}
      {error && <span id={errorId} className="bp-error" role="alert">{error}</span>}
    </div>
  );
}
