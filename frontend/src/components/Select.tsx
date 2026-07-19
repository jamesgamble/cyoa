import { useId, type SelectHTMLAttributes, type ReactNode } from "react";

interface Props extends SelectHTMLAttributes<HTMLSelectElement> {
  label: string;
  help?: string;
  error?: string;
  children: ReactNode;
}

export function Select({ label, help, error, id, className, children, ...rest }: Props) {
  const auto = useId();
  const selectId = id ?? auto;
  const helpId = help ? `${selectId}-help` : undefined;
  const errorId = error ? `${selectId}-error` : undefined;
  const describedBy = [helpId, errorId].filter(Boolean).join(" ") || undefined;
  return (
    <div className="bp-field">
      <label htmlFor={selectId} className="bp-label">{label}</label>
      <select
        id={selectId}
        className={["bp-select", className].filter(Boolean).join(" ")}
        aria-invalid={error ? "true" : undefined}
        aria-describedby={describedBy}
        {...rest}
      >
        {children}
      </select>
      {help && !error && <span id={helpId} className="bp-help">{help}</span>}
      {error && <span id={errorId} className="bp-error" role="alert">{error}</span>}
    </div>
  );
}
