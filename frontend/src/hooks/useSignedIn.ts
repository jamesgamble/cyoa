import { useEffect, useState } from "react";

/**
 * Stub for "signed-in" state used by placeholder UI (e.g. Follow button
 * on the adventure landing page) before real auth ships.
 *
 * Reads `localStorage["bp-signed-in"]` on mount. Any truthy value
 * (`"1"`, `"true"`, a JSON blob) counts as signed in. Real authentication
 * arrives in a later release and will replace this hook.
 */
export function useSignedIn(): boolean {
  const [signedIn, setSignedIn] = useState(false);
  useEffect(() => {
    try {
      const v = window.localStorage.getItem("bp-signed-in");
      setSignedIn(!!v && v !== "0" && v.toLowerCase() !== "false");
    } catch {
      setSignedIn(false);
    }
  }, []);
  return signedIn;
}
