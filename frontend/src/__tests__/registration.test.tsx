/**
 * registration.test.tsx — v0.11.0 focused frontend tests.
 *
 * These tests drive the real <Register /> page and stub the network
 * layer so we can assert client validation, the honeypot, and the
 * server-error paths without depending on the PHP process.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from "vitest";
import { render, screen, within, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter } from "react-router-dom";
import { Register } from "../pages/RegisterPage";

type FetchArgs = Parameters<typeof fetch>;

function stubFetch(
  handler: (input: FetchArgs[0], init?: FetchArgs[1]) => Response | Promise<Response>,
): ReturnType<typeof vi.fn> {
  const fn = vi.fn(async (input: FetchArgs[0], init?: FetchArgs[1]) => {
    return await handler(input, init);
  });
  vi.stubGlobal("fetch", fn as unknown as typeof fetch);
  return fn;
}

function jsonResponse(body: unknown, init: ResponseInit = {}): Response {
  return new Response(JSON.stringify(body), {
    status: init.status ?? 200,
    headers: { "Content-Type": "application/json" },
  });
}

function renderRegister() {
  return render(
    <MemoryRouter initialEntries={["/register"]}>
      <Register />
    </MemoryRouter>,
  );
}

describe("registration form — v0.11.0", () => {
  beforeEach(() => {
    // Default network: settings + CSRF token always available; /register
    // is overridden per test.
    stubFetch((input) => {
      const url = String(input);
      if (url.endsWith("/registration/settings")) {
        return jsonResponse({
          registration_enabled: true,
          minimum_password_length: 12,
          requires_email_verification: true,
          requires_admin_approval: false,
        });
      }
      if (url.endsWith("/csrf-token")) {
        return jsonResponse({ token: "a".repeat(64) });
      }
      return jsonResponse({ status: "accepted" }, { status: 202 });
    });
  });

  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it("renders the accessible form skeleton", () => {
    renderRegister();
    expect(
      screen.getByRole("heading", { level: 1, name: /create an account/i }),
    ).toBeInTheDocument();
    expect(screen.getByLabelText(/^email$/i)).toBeInTheDocument();
    expect(screen.getByLabelText(/^username$/i)).toBeInTheDocument();
    expect(screen.getByLabelText(/display name/i)).toBeInTheDocument();
    // Two password fields exist; both are exposed via unique labels.
    expect(screen.getByLabelText("Password", { selector: "input" })).toBeInTheDocument();
    expect(screen.getByLabelText("Confirm password", { selector: "input" })).toBeInTheDocument();
  });

  it("blocks submission when required fields are empty", async () => {
    const user = userEvent.setup();
    renderRegister();
    await user.click(screen.getByRole("button", { name: /create account/i }));
    // Client-side validation shows at least one alert.
    const alerts = await screen.findAllByRole("alert");
    expect(alerts.length).toBeGreaterThan(0);
  });

  it("requires terms acceptance even when other fields are valid", async () => {
    const user = userEvent.setup();
    renderRegister();
    await user.type(screen.getByLabelText(/^email$/i), "alice@example.com");
    await user.type(screen.getByLabelText(/^username$/i), "alice");
    await user.type(screen.getByLabelText(/display name/i), "Alice");
    await user.type(screen.getByLabelText("Password", { selector: "input" }), "a very long password value");
    await user.type(
      screen.getByLabelText("Confirm password", { selector: "input" }),
      "a very long password value",
    );
    await user.click(screen.getByRole("button", { name: /create account/i }));
    expect(
      await screen.findByText(/must accept the guidelines/i),
    ).toBeInTheDocument();
  });

  it("submits with CSRF header and shows the accepted view", async () => {
    const user = userEvent.setup();
    const registerCalls: FetchArgs[] = [];
    stubFetch((input, init) => {
      const url = String(input);
      if (url.endsWith("/registration/settings")) {
        return jsonResponse({
          registration_enabled: true,
          minimum_password_length: 12,
          requires_email_verification: true,
          requires_admin_approval: false,
        });
      }
      if (url.endsWith("/csrf-token")) {
        return jsonResponse({ token: "b".repeat(64) });
      }
      if (url.endsWith("/register")) {
        registerCalls.push([input, init]);
        return jsonResponse({ status: "accepted" }, { status: 202 });
      }
      return new Response("", { status: 404 });
    });

    renderRegister();

    await user.type(screen.getByLabelText(/^email$/i), "alice@example.com");
    await user.type(screen.getByLabelText(/^username$/i), "alice_reader");
    await user.type(screen.getByLabelText(/display name/i), "Alice");
    await user.type(
      screen.getByLabelText("Password", { selector: "input" }),
      "a very long password value",
    );
    await user.type(
      screen.getByLabelText("Confirm password", { selector: "input" }),
      "a very long password value",
    );
    await user.click(
      screen.getByRole("checkbox", { name: /community guidelines/i }),
    );
    await user.click(screen.getByRole("button", { name: /create account/i }));

    await waitFor(() => expect(registerCalls.length).toBe(1));
    const [, init] = registerCalls[0];
    const headers = (init?.headers ?? {}) as Record<string, string>;
    expect(headers["X-CSRF-Token"]).toBe("b".repeat(64));
    const body = JSON.parse(String(init?.body ?? "{}"));
    expect(body.email).toBe("alice@example.com");
    expect(body.username).toBe("alice_reader");
    expect(body.terms_accepted).toBe(true);
    // The honeypot must be present and empty.
    expect(body.nickname_url).toBe("");

    expect(
      await screen.findByRole("heading", { name: /thank you/i }),
    ).toBeInTheDocument();
  });

  it("surfaces server-side field errors on 422", async () => {
    stubFetch((input) => {
      const url = String(input);
      if (url.endsWith("/registration/settings")) {
        return jsonResponse({
          registration_enabled: true,
          minimum_password_length: 12,
          requires_email_verification: false,
          requires_admin_approval: false,
        });
      }
      if (url.endsWith("/csrf-token")) return jsonResponse({ token: "c".repeat(64) });
      if (url.endsWith("/register")) {
        return jsonResponse(
          { error: "invalid", fields: { username: "invalid" } },
          { status: 422 },
        );
      }
      return new Response("", { status: 404 });
    });

    const user = userEvent.setup();
    renderRegister();

    await user.type(screen.getByLabelText(/^email$/i), "bob@example.com");
    await user.type(screen.getByLabelText(/^username$/i), "bob_reader");
    await user.type(screen.getByLabelText(/display name/i), "Bob");
    await user.type(
      screen.getByLabelText("Password", { selector: "input" }),
      "another long password value",
    );
    await user.type(
      screen.getByLabelText("Confirm password", { selector: "input" }),
      "another long password value",
    );
    await user.click(
      screen.getByRole("checkbox", { name: /community guidelines/i }),
    );
    await user.click(screen.getByRole("button", { name: /create account/i }));

    // Server error surfaces as an alert-tone validation message under the field.
    expect(
      await screen.findByText(/please enter a valid value/i),
    ).toBeInTheDocument();
  });

  it("shows a friendly banner when the server is rate-limited", async () => {
    stubFetch((input) => {
      const url = String(input);
      if (url.endsWith("/registration/settings")) {
        return jsonResponse({
          registration_enabled: true,
          minimum_password_length: 12,
          requires_email_verification: false,
          requires_admin_approval: false,
        });
      }
      if (url.endsWith("/csrf-token")) return jsonResponse({ token: "d".repeat(64) });
      if (url.endsWith("/register")) {
        return jsonResponse({ error: "rate_limited" }, { status: 429 });
      }
      return new Response("", { status: 404 });
    });

    const user = userEvent.setup();
    renderRegister();
    await user.type(screen.getByLabelText(/^email$/i), "cara@example.com");
    await user.type(screen.getByLabelText(/^username$/i), "cara_reader");
    await user.type(screen.getByLabelText(/display name/i), "Cara");
    await user.type(
      screen.getByLabelText("Password", { selector: "input" }),
      "a very long password value",
    );
    await user.type(
      screen.getByLabelText("Confirm password", { selector: "input" }),
      "a very long password value",
    );
    await user.click(
      screen.getByRole("checkbox", { name: /community guidelines/i }),
    );
    await user.click(screen.getByRole("button", { name: /create account/i }));
    expect(
      await screen.findByText(/too many recent registration attempts/i),
    ).toBeInTheDocument();
  });

  it("hides the form when registration is disabled site-wide", async () => {
    stubFetch((input) => {
      const url = String(input);
      if (url.endsWith("/registration/settings")) {
        return jsonResponse({
          registration_enabled: false,
          minimum_password_length: 12,
          requires_email_verification: true,
          requires_admin_approval: false,
        });
      }
      if (url.endsWith("/csrf-token")) return jsonResponse({ token: "e".repeat(64) });
      return new Response("", { status: 404 });
    });
    renderRegister();
    await waitFor(() =>
      expect(screen.getByText(/registration is temporarily closed/i)).toBeInTheDocument(),
    );
    expect(
      screen.queryByRole("button", { name: /create account/i }),
    ).not.toBeInTheDocument();
  });

  it("keeps the honeypot visually hidden from users", () => {
    renderRegister();
    const heading = screen.getByRole("heading", { level: 1 });
    const section = heading.closest("section")!;
    const honeypot = within(section)
      .getByLabelText(/website/i, { selector: "input" }) as HTMLInputElement;
    expect(honeypot.name).toBe("nickname_url");
    expect(honeypot.tabIndex).toBe(-1);
    const wrapper = honeypot.parentElement!;
    expect(wrapper.getAttribute("aria-hidden")).toBe("true");
  });
});
