import { useId, type InputHTMLAttributes, type ReactNode } from "react";

interface Props extends Omit<InputHTMLAttributes<HTMLInputElement>, "type"> {
  label: ReactNode;
  help?: string;
}

export function Checkbox({ label, help, id, className, ...rest }: Props) {
  const auto = useId();
  const boxId = id ?? auto;
  const helpId = help ? `${boxId}-help` : undefined;
  return (
    <div className="bp-check-field">
      <label className="bp-check" htmlFor={boxId}>
        <input
          id={boxId}
          type="checkbox"
          aria-describedby={helpId}
          className={className}
          {...rest}
        />
        <span>{label}</span>
      </label>
      {help && <span id={helpId} className="bp-help">{help}</span>}
    </div>
  );
}
