import { describe, it, expect } from "vitest";
import { API_BASE_URL, apiUrl, HEALTH_URL } from "../config/api";

describe("api config", () => {
  it("defaults to a same-origin /api prefix in tests", () => {
    // VITE_API_BASE_URL is unset in the test environment; default applies.
    expect(API_BASE_URL).toBe("/api");
  });

  it("apiUrl accepts paths with or without a leading slash", () => {
    expect(apiUrl("/health")).toBe("/api/health");
    expect(apiUrl("health")).toBe("/api/health");
  });

  it("exports a HEALTH_URL that points at the health endpoint", () => {
    expect(HEALTH_URL).toBe("/api/health");
  });

  it("never keeps a trailing slash on the base", () => {
    // Guard the invariant that apiUrl composition would double slashes if
    // the base ended in one. Even under an override we strip trailing
    // slashes, so a concatenation is always clean.
    expect(API_BASE_URL.endsWith("/")).toBe(false);
  });
});
