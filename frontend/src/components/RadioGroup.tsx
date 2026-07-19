import { useId } from "react";

export interface RadioOption {
  value: string;
  label: string;
  description?: string;
}

interface Props {
  legend: string;
  name: string;
  value?: string;
  onChange?: (value: string) => void;
  options: RadioOption[];
  /** Layout — inline (row) or stacked (column). */
  orientation?: "inline" | "stacked";
  error?: string;
}

export function RadioGroup({
  legend,
  name,
  value,
  onChange,
  options,
  orientation = "inline",
  error,
}: Props) {
  const groupId = useId();
  const errorId = error ? `${groupId}-error` : undefined;
  return (
    <fieldset
      className={`bp-radio-group bp-radio-group--${orientation}`}
      aria-describedby={errorId}
      aria-invalid={error ? "true" : undefined}
    >
      <legend className="bp-label">{legend}</legend>
      <div className="bp-radio-group__options" role="radiogroup" aria-label={legend}>
        {options.map((o) => {
          const id = `${groupId}-${o.value}`;
          return (
            <label className="bp-radio" htmlFor={id} key={o.value}>
              <input
                type="radio"
                id={id}
                name={name}
                value={o.value}
                checked={value === o.value}
                onChange={() => onChange?.(o.value)}
              />
              <span>
                <span className="bp-radio__label">{o.label}</span>
                {o.description && (
                  <span className="bp-radio__desc">{o.description}</span>
                )}
              </span>
            </label>
          );
        })}
      </div>
      {error && <span id={errorId} className="bp-error" role="alert">{error}</span>}
    </fieldset>
  );
}
