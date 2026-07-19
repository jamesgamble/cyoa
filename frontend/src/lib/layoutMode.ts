import { useEffect } from "react";

export type LayoutMode = "reading" | "manage" | "admin";

/**
 * Sets a body-level data attribute + class so global.css can adapt layout
 * chrome per mode. Reads on mount, cleans up on unmount.
 */
export function useLayoutMode(mode: LayoutMode) {
  useEffect(() => {
    const cls = `bp-mode--${mode}`;
    document.body.classList.add(cls);
    document.body.setAttribute("data-layout-mode", mode);
    return () => {
      document.body.classList.remove(cls);
      if (document.body.getAttribute("data-layout-mode") === mode) {
        document.body.removeAttribute("data-layout-mode");
      }
    };
  }, [mode]);
}
