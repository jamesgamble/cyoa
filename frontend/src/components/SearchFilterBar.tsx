import type { ReactNode } from "react";
import { SearchField } from "./SearchField";

interface Props {
  searchLabel?: string;
  searchPlaceholder?: string;
  searchValue?: string;
  onSearchChange?: (value: string) => void;
  /** Filters area — typically Select components. */
  filters?: ReactNode;
  /** Optional right-aligned actions. */
  actions?: ReactNode;
}

/**
 * Search and filter bar — a horizontal control strip used above
 * catalogues and administrative tables. Wraps at narrow widths.
 */
export function SearchFilterBar({
  searchLabel = "Search",
  searchPlaceholder,
  searchValue,
  onSearchChange,
  filters,
  actions,
}: Props) {
  return (
    <div className="bp-search-filter-bar" role="search" data-testid="search-filter-bar">
      <div className="bp-search-filter-bar__search">
        <SearchField
          label={searchLabel}
          hideLabel
          placeholder={searchPlaceholder ?? "Search…"}
          value={searchValue}
          onChange={(e) => onSearchChange?.(e.target.value)}
        />
      </div>
      {filters && <div className="bp-search-filter-bar__filters">{filters}</div>}
      {actions && <div className="bp-search-filter-bar__actions">{actions}</div>}
    </div>
  );
}
