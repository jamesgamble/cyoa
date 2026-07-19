import { useId, type TextareaHTMLAttributes } from "react";

interface Props extends TextareaHTMLAttributes<HTMLTextAreaElement> {
  label: string;
  help?: string;
  error?: string;
  /** Show a character counter when maxLength is set. */
  showCount?: boolean;
}

export function TextArea({
  label,
  help,
  error,
  showCount,
  id,
  className,
  maxLength,
  value,
  defaultValue,
  ...rest
}: Props) {
  const auto = useId();
  const inputId = id ?? auto;
  const helpId = help ? `${inputId}-help` : undefined;
  const errorId = error ? `${inputId}-error` : undefined;
  const describedBy = [helpId, errorId].filter(Boolean).join(" ") || undefined;
  const currentLength =
    typeof value === "string"
      ? value.length
      : typeof defaultValue === "string"
        ? defaultValue.length
        : 0;
  return (
    <div className="bp-field">
      <label htmlFor={inputId} className="bp-label">{label}</label>
      <textarea
        id={inputId}
        className={["bp-textarea", className].filter(Boolean).join(" ")}
        aria-invalid={error ? "true" : undefined}
        aria-describedby={describedBy}
        maxLength={maxLength}
        value={value}
        defaultValue={defaultValue}
        {...rest}
      />
      <div className="bp-field__foot">
        {help && !error && <span id={helpId} className="bp-help">{help}</span>}
        {error && <span id={errorId} className="bp-error" role="alert">{error}</span>}
        {showCount && maxLength && (
          <span className="bp-help bp-textarea__count" aria-live="polite">
            {currentLength} / {maxLength}
          </span>
        )}
      </div>
    </div>
  );
}
