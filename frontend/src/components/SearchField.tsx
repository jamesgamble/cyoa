import { useId, type InputHTMLAttributes } from "react";

interface Props extends Omit<InputHTMLAttributes<HTMLInputElement>, "type" | "size"> {
  label?: string;
  hideLabel?: boolean;
}

/**
 * Search field — a labelled text search input with an editorial
 * caret indicator. Always associated with a visible or visually
 * hidden label for assistive tech.
 */
export function SearchField({ label = "Search", hideLabel = false, id, className, ...rest }: Props) {
  const auto = useId();
  const inputId = id ?? auto;
  return (
    <div className="bp-search-field">
      <label htmlFor={inputId} className={hideLabel ? "bp-visually-hidden" : "bp-label"}>
        {label}
      </label>
      <div className="bp-search-field__wrap">
        <span className="bp-search-field__icon" aria-hidden="true">⚲</span>
        <input
          id={inputId}
          type="search"
          className={["bp-input bp-search-field__input", className].filter(Boolean).join(" ")}
          {...rest}
        />
      </div>
    </div>
  );
}
