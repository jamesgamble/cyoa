import { NavLink } from "react-router-dom";
import { useEffect, useRef } from "react";

interface Item {
  to: string;
  label: string;
  end?: boolean;
}

interface Props {
  id: string;
  items: Item[];
  open: boolean;
  onClose: () => void;
}

/**
 * Mobile navigation drawer — expands beneath the masthead on narrow
 * viewports. Closes on Escape and on link activation.
 */
export function MobileNav({ id, items, open, onClose }: Props) {
  const rootRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!open) return;
    function onKey(e: KeyboardEvent) {
      if (e.key === "Escape") onClose();
    }
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [open, onClose]);

  return (
    <div
      ref={rootRef}
      id={id}
      className="bp-nav bp-nav--mobile"
      data-open={open ? "true" : "false"}
      aria-hidden={open ? "false" : "true"}
      data-testid="mobile-nav"
    >
      <nav aria-label="Primary (mobile)">
        <ul>
          {items.map((item) => (
            <li key={item.to}>
              <NavLink
                to={item.to}
                end={item.end}
                onClick={onClose}
                tabIndex={open ? 0 : -1}
              >
                {item.label}
              </NavLink>
            </li>
          ))}
        </ul>
      </nav>
    </div>
  );
}
