import { NavLink } from "react-router-dom";

export interface ManageNavItem {
  to: string;
  label: string;
  end?: boolean;
  badge?: string;
}

interface Props {
  items: ManageNavItem[];
  label?: string;
}

/**
 * Management navigation — vertical rail for adventure management and
 * master administration screens. Serves as a secondary landmark.
 */
export function ManageNav({ items, label = "Management" }: Props) {
  return (
    <nav className="bp-manage-nav" aria-label={label} data-testid="manage-nav">
      <ul>
        {items.map((it) => (
          <li key={it.to}>
            <NavLink to={it.to} end={it.end} className="bp-manage-nav__link">
              <span>{it.label}</span>
              {it.badge && <span className="bp-manage-nav__badge">{it.badge}</span>}
            </NavLink>
          </li>
        ))}
      </ul>
    </nav>
  );
}
