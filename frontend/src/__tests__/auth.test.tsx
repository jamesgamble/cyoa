/**
 * auth.test.tsx — v0.13.0 focused frontend tests for the authentication
 * pages. The server layer is stubbed at `fetch`; these tests exercise
 * only client behaviour (redirect safety, enumeration-safe messaging,
 * CSRF fetch, session gating, token-missing state).
 */

import { describe, it, expect, beforeEach, afterEach, vi } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter, Routes, Route } from "react-router-dom";
import {
  Login, ForgotPassword, ResetPassword, VerifyEmail, ChangePassword,
} from "../pages/AuthPages";

type FetchArgs = Parameters<typeof fetch>;

interface Recorded {
  url: string;
  method: string;
  body: unknown;
  headers: Record<string, string>;
}

interface StubOptions {
  session?: { authenticated: boolean; user_id: number | null };
  responses?: Record<string, (body: unknown) => Response | Promise<Response>>;
}

const CSRF_HEADERS = { "Content-Type": "application/json", "Set-Cookie": "bp_csrf=abcd; Path=/" };

function jsonRes(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), { status, headers: { "Content-Type": "application/json" } });
}

function stubFetch(opts: StubOptions = {}): Recorded[] {
  const recorded: Recorded[] = [];
  const handler = async (input: FetchArgs[0], init?: FetchArgs[1]) => {
    const url = typeof input === "string" ? input : (input as Request).url ?? String(input);
    const method = (init?.method ?? "GET").toUpperCase();
    const rawBody = init?.body ? JSON.parse(String(init.body)) : null;
    const headers: Record<string, string> = {};
    if (init?.headers) {
      const h = init.headers as Record<string, string>;
      Object.entries(h).forEach(([k, v]) => (headers[k] = String(v)));
    }
    recorded.push({ url, method, body: rawBody, headers });

    if (url.includes("/csrf-token")) {
      return new Response(JSON.stringify({ token: "csrf-token-value" }), {
        status: 200, headers: CSRF_HEADERS,
      });
    }
    if (url.includes("/auth/session")) {
      return jsonRes(opts.session ?? { authenticated: false, user_id: null });
    }
    for (const [pat, fn] of Object.entries(opts.responses ?? {})) {
      if (url.includes(pat)) return await fn(rawBody);
    }
    return jsonRes({ error: "no_stub" }, 500);
  };
  vi.stubGlobal("fetch", vi.fn(handler) as unknown as typeof fetch);
  return recorded;
}

beforeEach(() => { vi.stubGlobal("localStorage", window.localStorage); });
afterEach(() => { vi.unstubAllGlobals(); vi.restoreAllMocks(); });

describe("Login page", () => {
  it("sends CSRF header, credentials, and clamps a hostile ?next to the safe default", async () => {
    const rec = stubFetch({
      responses: {
        "/auth/login": () => jsonRes({ status: "ok", redirect: "/adventures" }),
      },
    });
    render(
      <MemoryRouter initialEntries={["/login?next=https://evil.example/x"]}>
        <Routes>
          <Route path="/login" element={<Login />} />
          <Route path="/adventures" element={<div>ADV</div>} />
          <Route path="/" element={<div>HOME</div>} />
        </Routes>
      </MemoryRouter>,
    );
    await userEvent.type(screen.getByLabelText(/email/i), "reader@example.com");
    await userEvent.type(screen.getByLabelText(/password/i), "correct horse battery staple");
    await userEvent.click(screen.getByRole("button", { name: /sign in/i }));

    await waitFor(() => expect(screen.getByText("ADV")).toBeInTheDocument());
    const loginCall = rec.find((c) => c.url.includes("/auth/login"))!;
    expect(loginCall.headers["X-CSRF-Token"]).toBe("csrf-token-value");
    // Client rewrote the hostile next to "/" before sending
    expect((loginCall.body as { redirect: string }).redirect).toBe("/");
  });

  it("shows a pending-verification message and does not redirect", async () => {
    stubFetch({
      responses: {
        "/auth/login": () => jsonRes({ error: "pending_verification" }, 403),
      },
    });
    render(
      <MemoryRouter initialEntries={["/login"]}>
        <Login />
      </MemoryRouter>,
    );
    await userEvent.type(screen.getByLabelText(/email/i), "a@b.c");
    await userEvent.type(screen.getByLabelText(/password/i), "correct horse battery staple");
    await userEvent.click(screen.getByRole("button", { name: /sign in/i }));
    expect(await screen.findByRole("alert")).toHaveTextContent(/verify your email/i);
  });

  it("redirects away when the visitor already has a session", async () => {
    stubFetch({ session: { authenticated: true, user_id: 7 } });
    render(
      <MemoryRouter initialEntries={["/login?next=/discover"]}>
        <Routes>
          <Route path="/login" element={<Login />} />
          <Route path="/discover" element={<div>DISC</div>} />
        </Routes>
      </MemoryRouter>,
    );
    await waitFor(() => expect(screen.getByText("DISC")).toBeInTheDocument());
  });
});

describe("Forgot password", () => {
  it("returns the same friendly message regardless of whether the account exists", async () => {
    // Server always answers ok — the point of the test is that the UI treats
    // an "unknown" account and a real one identically.
    stubFetch({
      responses: {
        "/auth/forgot-password": () => jsonRes({ status: "ok" }),
      },
    });
    render(<MemoryRouter><ForgotPassword /></MemoryRouter>);
    await userEvent.type(screen.getByLabelText(/email/i), "ghost@example.com");
    await userEvent.click(screen.getByRole("button", { name: /send reset link/i }));
    expect(await screen.findByRole("status")).toHaveTextContent(/if an active account/i);
  });
});

describe("Reset password", () => {
  it("refuses to submit without a token in the URL", () => {
    render(
      <MemoryRouter initialEntries={["/reset-password"]}>
        <Routes>
          <Route path="/reset-password" element={<ResetPassword />} />
        </Routes>
      </MemoryRouter>,
    );
    expect(screen.getByRole("alert")).toHaveTextContent(/missing its token/i);
    expect(screen.queryByRole("button", { name: /update password/i })).toBeNull();
  });

  it("posts the token and new password, then shows the success message", async () => {
    const rec = stubFetch({
      responses: {
        "/auth/reset-password": () => jsonRes({ status: "ok" }),
      },
    });
    render(
      <MemoryRouter initialEntries={["/reset-password?token=deadbeef"]}>
        <Routes>
          <Route path="/reset-password" element={<ResetPassword />} />
        </Routes>
      </MemoryRouter>,
    );
    const [pw, confirm] = screen.getAllByLabelText(/password/i);
    await userEvent.type(pw, "a-long-fresh-password");
    await userEvent.type(confirm, "a-long-fresh-password");
    await userEvent.click(screen.getByRole("button", { name: /update password/i }));
    expect(await screen.findByRole("heading", { name: /password updated/i })).toBeInTheDocument();
    const call = rec.find((c) => c.url.includes("/auth/reset-password"))!;
    expect((call.body as { token: string }).token).toBe("deadbeef");
    expect(call.headers["X-CSRF-Token"]).toBe("csrf-token-value");
  });

  it("shows a token-invalid message when the server refuses", async () => {
    stubFetch({
      responses: {
        "/auth/reset-password": () => jsonRes({ error: "token_invalid" }, 400),
      },
    });
    render(
      <MemoryRouter initialEntries={["/reset-password?token=stale"]}>
        <Routes>
          <Route path="/reset-password" element={<ResetPassword />} />
        </Routes>
      </MemoryRouter>,
    );
    const [pw, confirm] = screen.getAllByLabelText(/password/i);
    await userEvent.type(pw, "a-long-fresh-password");
    await userEvent.type(confirm, "a-long-fresh-password");
    await userEvent.click(screen.getByRole("button", { name: /update password/i }));
    expect(await screen.findByRole("alert")).toHaveTextContent(/invalid or has already been used/i);
  });
});

describe("Verify email", () => {
  it("consumes the token on mount and reports success", async () => {
    const rec = stubFetch({
      responses: {
        "/auth/verify-email": () => jsonRes({ status: "ok" }),
      },
    });
    render(
      <MemoryRouter initialEntries={["/verify?token=abc123"]}>
        <Routes><Route path="/verify" element={<VerifyEmail />} /></Routes>
      </MemoryRouter>,
    );
    expect(await screen.findByText(/address is now confirmed/i)).toBeInTheDocument();
    const call = rec.find((c) => c.url.includes("/auth/verify-email"))!;
    expect((call.body as { token: string }).token).toBe("abc123");
  });

  it("shows a resend form (no token in URL) and treats the response as enumeration-safe", async () => {
    stubFetch({
      responses: {
        "/auth/resend-verification": () => jsonRes({ status: "ok" }),
      },
    });
    render(
      <MemoryRouter initialEntries={["/verify"]}>
        <Routes><Route path="/verify" element={<VerifyEmail />} /></Routes>
      </MemoryRouter>,
    );
    await userEvent.type(screen.getByLabelText(/email/i), "someone@example.com");
    await userEvent.click(screen.getByRole("button", { name: /send a new verification link/i }));
    expect(await screen.findByRole("status")).toHaveTextContent(/if an unverified account/i);
  });
});

describe("Change password", () => {
  it("redirects to /login with a next URL when unauthenticated", async () => {
    stubFetch({ session: { authenticated: false, user_id: null } });
    render(
      <MemoryRouter initialEntries={["/change-password"]}>
        <Routes>
          <Route path="/change-password" element={<ChangePassword />} />
          <Route path="/login" element={<div>LOGIN PAGE</div>} />
        </Routes>
      </MemoryRouter>,
    );
    await waitFor(() => expect(screen.getByText("LOGIN PAGE")).toBeInTheDocument());
  });

  it("submits current + new password with the CSRF header once authenticated", async () => {
    const rec = stubFetch({
      session: { authenticated: true, user_id: 42 },
      responses: {
        "/auth/change-password": () => jsonRes({ status: "ok" }),
      },
    });
    render(
      <MemoryRouter initialEntries={["/change-password"]}>
        <Routes><Route path="/change-password" element={<ChangePassword />} /></Routes>
      </MemoryRouter>,
    );
    const [current, next, confirm] = await screen.findAllByLabelText(/password/i);
    await userEvent.type(current, "old-password-value");
    await userEvent.type(next, "a-long-fresh-password");
    await userEvent.type(confirm, "a-long-fresh-password");
    await userEvent.click(screen.getByRole("button", { name: /update password/i }));
    expect(await screen.findByText(/all previous sessions have been signed out/i)).toBeInTheDocument();
    const call = rec.find((c) => c.url.includes("/auth/change-password"))!;
    expect(call.headers["X-CSRF-Token"]).toBe("csrf-token-value");
    expect((call.body as { current_password: string; new_password: string }).current_password)
      .toBe("old-password-value");
  });
});
