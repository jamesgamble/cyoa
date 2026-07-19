import { useId, type InputHTMLAttributes, type ReactNode } from "react";

interface Props extends Omit<InputHTMLAttributes<HTMLInputElement>, "type"> {
  label: ReactNode;
  description?: string;
}

/**
 * Toggle — a labelled on/off switch. Semantics remain a native
 * checkbox for assistive tech reliability; visual is provided by
 * `.bp-toggle-track` / `.bp-toggle-thumb`.
 */
export function Toggle({ label, description, id, className, ...rest }: Props) {
  const auto = useId();
  const toggleId = id ?? auto;
  const descId = description ? `${toggleId}-desc` : undefined;
  return (
    <div className="bp-toggle-field">
      <label className={["bp-toggle", className].filter(Boolean).join(" ")} htmlFor={toggleId}>
        <input
          id={toggleId}
          type="checkbox"
          aria-describedby={descId}
          role="switch"
          {...rest}
        />
        <span className="bp-toggle-track"><span className="bp-toggle-thumb" /></span>
        <span className="bp-toggle__label">{label}</span>
      </label>
      {description && <span id={descId} className="bp-help">{description}</span>}
    </div>
  );
}
