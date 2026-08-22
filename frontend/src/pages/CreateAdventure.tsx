/**
 * Create an adventure — v0.16.0.
 *
 * A five-step wizard (Basics, Opening scene, Contributions, Writing
 * guidelines, Review) available to active, signed-in accounts. The
 * server is the source of truth: it re-validates every field, re-runs
 * the HTML sanitizer, enforces the per-user cap and the hourly rate
 * limit, and writes the adventure plus its opening scene inside one
 * serialized transaction. The client mirrors the same rules only to
 * give faster feedback.
 *
 * Templates configure settings (visibility, contribution mode,
 * anonymous contributions, branch limit) — they never write story
 * content for you, and they never skip validation.
 */

import { useEffect, useMemo, useRef, useState } from "react";
import { Link, useNavigate } from "react-router-dom";
import { StepIndicator } from "../components/StepIndicator";
import { FormSection } from "../components/FormSection";
import { RadioGroup } from "../components/RadioGroup";
import { Select } from "../components/Select";
import { Checkbox } from "../components/Checkbox";
import { TextArea } from "../components/TextArea";
import { PasswordField } from "../components/PasswordField";
import { RichTextEditor } from "../components/RichTextEditor";
import { ValidationMessage } from "../components/ValidationMessage";
import { Button } from "../components/Button";
import { Badge } from "../components/Badge";
import { Alert } from "../components/Alert";
import { InlineHelp } from "../components/InlineHelp";
import { UnauthorizedState, ServiceUnavailableState } from "../states";
import { useHelpContext } from "../components/GlobalHelp";
import { sanitizeRichTextHtml, richTextToPlainText } from "../lib/richTextSanitizer";
import {
  fetchCreationSettings,
  createAdventure,
  type CreationSettings,
  type AdventureDraftInput,
} from "../lib/apiClient";

/* ─────────────────────────── Constants ─────────────────────── */

const STEPS = [
  { label: "Basics" },
  { label: "Opening scene" },
  { label: "Contributions" },
  { label: "Writing guidelines" },
  { label: "Review" },
] as const;

export const TITLE_MAX = 120;
export const DESCRIPTION_MAX = 2000;
export const OPENING_TITLE_MAX = 120;
export const OPENING_BODY_MAX = 20000;
export const GUIDELINES_MAX = 4000;
export const PASSCODE_MIN = 6;

const GENRE_LABELS: Record<string, string> = {
  fantasy: "Fantasy",
  "science-fiction": "Science fiction",
  mystery: "Mystery",
  horror: "Horror",
  historical: "Historical",
  adventure: "Adventure",
  romance: "Romance",
  contemporary: "Contemporary",
  humour: "Humour",
  other: "Other",
};

const RATING_LABELS: Record<string, string> = {
  everyone: "Everyone",
  teen: "Teen",
  mature: "Mature",
};

const MODE_LABELS: Record<string, string> = {
  immediate: "Contributions publish immediately",
  approval: "Contributions wait for my approval",
  closed: "Only I can add branches",
};

const VISIBILITY_LABELS: Record<string, string> = {
  public: "Public — listed on Discover",
  unlisted: "Unlisted — reachable only by link",
};

const FIELD_MESSAGES: Record<string, string> = {
  required: "This is required.",
  too_short: "This is too short.",
  too_long: "This is too long.",
  invalid: "This value isn't one of the available options.",
  out_of_range: "Choose a number inside the allowed range.",
  empty: "Write something before continuing.",
};

/** Map a server or client error code to a reader-facing sentence. */
function label(code?: string): string | undefined {
  if (!code) return undefined;
  return FIELD_MESSAGES[code] ?? code.replace(/_/g, " ");
}

/* ─────────────────────────── Draft shape ───────────────────── */

interface Draft extends AdventureDraftInput {
  content_warning_input: string;
}

const INITIAL: Draft = {
  template: "solo",
  title: "",
  description: "",
  genre: "fantasy",
  content_rating: "everyone",
  content_warnings: [],
  content_warning_input: "",
  visibility: "public",
  opening_title: "",
  opening_body: "",
  status: "draft",
  contribution_mode: "closed",
  anonymous_contributions: false,
  max_branches_per_scene: 4,
  contribution_passcode: "",
  writing_guidelines: "",
};

type Errors = Record<string, string>;

/** Client mirror of the server's per-step validation. */
export function validateStep(step: number, d: Draft, s: CreationSettings | null): Errors {
  const e: Errors = {};
  const min = s?.max_branches_min ?? 2;
  const max = s?.max_branches_max ?? 10;
  if (step === 1) {
    const title = d.title.trim();
    if (!title) e.title = "required";
    else if (title.length < 3) e.title = "too_short";
    else if (title.length > TITLE_MAX) e.title = "too_long";
    const desc = d.description.trim();
    if (!desc) e.description = "required";
    else if (desc.length > DESCRIPTION_MAX) e.description = "too_long";
    if (s && !s.genres.includes(d.genre)) e.genre = "invalid";
    if (s && !s.content_ratings.includes(d.content_rating)) e.content_rating = "invalid";
  }
  if (step === 2) {
    const t = d.opening_title.trim();
    if (!t) e.opening_title = "required";
    else if (t.length > OPENING_TITLE_MAX) e.opening_title = "too_long";
    const plain = richTextToPlainText(d.opening_body).trim();
    if (!plain) e.opening_body = "empty";
    else if (plain.length > OPENING_BODY_MAX) e.opening_body = "too_long";
  }
  if (step === 3) {
    if (s && !s.contribution_modes.includes(d.contribution_mode)) e.contribution_mode = "invalid";
    if (s && !s.visibilities.includes(d.visibility)) e.visibility = "invalid";
    if (d.max_branches_per_scene < min || d.max_branches_per_scene > max)
      e.max_branches_per_scene = "out_of_range";
    if (d.contribution_passcode !== "" && d.contribution_passcode.length < PASSCODE_MIN)
      e.contribution_passcode = "too_short";
  }
  if (step === 4) {
    if (richTextToPlainText(d.writing_guidelines).length > GUIDELINES_MAX)
      e.writing_guidelines = "too_long";
  }
  return e;
}

/* ─────────────────────────── Page ──────────────────────────── */

export function CreateAdventure() {
  useHelpContext({ section: "authoring", role: "author" });

  const [settings, setSettings] = useState<CreationSettings | null>(null);
  const [load, setLoad] = useState<"loading" | "ready" | "unauth" | "down">("loading");
  const [step, setStep] = useState(1);
  const [draft, setDraft] = useState<Draft>(INITIAL);
  const [errors, setErrors] = useState<Errors>({});
  const [submitState, setSubmitState] = useState<
    "idle" | "submitting" | "rate_limited" | "limit_reached" | "not_active" | "failed"
  >("idle");
  const headingRef = useRef<HTMLHeadingElement | null>(null);
  const navigate = useNavigate();

  useEffect(() => {
    let cancelled = false;
    void fetchCreationSettings().then((r) => {
      if (cancelled) return;
      if (r.status === 401) { setLoad("unauth"); return; }
      if (!r.settings) { setLoad("down"); return; }
      setSettings(r.settings);
      setLoad("ready");
    });
    return () => { cancelled = true; };
  }, []);

  // Move focus to the step heading whenever the step changes, so a
  // keyboard or screen-reader user lands in the new panel.
  useEffect(() => {
    if (load === "ready") headingRef.current?.focus();
  }, [step, load]);

  const set = <K extends keyof Draft>(key: K, value: Draft[K]) =>
    setDraft((d) => ({ ...d, [key]: value }));

  /** Applying a template overwrites only the settings it owns. */
  const applyTemplate = (key: string) => {
    const tpl = settings?.templates[key];
    setDraft((d) => ({
      ...d,
      template: key,
      ...(tpl
        ? {
            visibility: tpl.visibility,
            contribution_mode: tpl.contribution_mode,
            anonymous_contributions: tpl.anonymous_contributions,
            max_branches_per_scene: tpl.max_branches_per_scene,
          }
        : {}),
    }));
  };

  const openingPlainLength = useMemo(
    () => richTextToPlainText(draft.opening_body).trim().length,
    [draft.opening_body],
  );

  const goNext = () => {
    const e = validateStep(step, draft, settings);
    setErrors(e);
    if (Object.keys(e).length > 0) return;
    setStep((s) => Math.min(STEPS.length, s + 1));
  };
  const goBack = () => {
    setErrors({});
    setStep((s) => Math.max(1, s - 1));
  };

  const addWarning = () => {
    const w = draft.content_warning_input.trim();
    if (!w) return;
    if (draft.content_warnings.includes(w)) { set("content_warning_input", ""); return; }
    setDraft((d) => ({
      ...d,
      content_warnings: [...d.content_warnings, w].slice(0, 12),
      content_warning_input: "",
    }));
  };

  const onSubmit = async () => {
    if (submitState === "submitting") return;
    // Re-run every step's rules before the network call.
    const all: Errors = {};
    for (let i = 1; i <= 4; i += 1) Object.assign(all, validateStep(i, draft, settings));
    if (Object.keys(all).length > 0) {
      setErrors(all);
      setStep(all.title || all.description || all.genre ? 1 : all.opening_title || all.opening_body ? 2 : 3);
      return;
    }
    setErrors({});
    setSubmitState("submitting");
    const result = await createAdventure({
      template: draft.template,
      title: draft.title.trim(),
      description: draft.description.trim(),
      genre: draft.genre,
      content_rating: draft.content_rating,
      content_warnings: draft.content_warnings,
      visibility: draft.visibility,
      opening_title: draft.opening_title.trim(),
      opening_body: sanitizeRichTextHtml(draft.opening_body),
      status: draft.status,
      contribution_mode: draft.contribution_mode,
      anonymous_contributions: draft.anonymous_contributions,
      max_branches_per_scene: draft.max_branches_per_scene,
      contribution_passcode: draft.contribution_passcode,
      writing_guidelines: sanitizeRichTextHtml(draft.writing_guidelines),
    });
    if (result.outcome === "ok" && result.adventure) {
      navigate(`/manage/${result.adventure.slug}`);
      return;
    }
    if (result.outcome === "unauthenticated") { setLoad("unauth"); return; }
    if (result.outcome === "invalid") {
      setErrors(result.fields ?? {});
      setSubmitState("idle");
      return;
    }
    if (
      result.outcome === "rate_limited"
      || result.outcome === "limit_reached"
      || result.outcome === "not_active"
    ) {
      setSubmitState(result.outcome);
      return;
    }
    setSubmitState("failed");
  };

  if (load === "loading") return <p role="status">Loading…</p>;
  if (load === "unauth") {
    return (
      <section>
        <h1>Create an adventure</h1>
        <UnauthorizedState />
        <p>
          <Link to="/login?redirect=/start">Sign in</Link> or{" "}
          <Link to="/register">create an account</Link> to start an adventure.
          Reading never requires an account.
        </p>
      </section>
    );
  }
  if (load === "down" || !settings) {
    return (
      <section>
        <h1>Create an adventure</h1>
        <ServiceUnavailableState />
      </section>
    );
  }

  const { limits } = settings;
  const remaining = Math.max(0, limits.max_adventures_per_user - limits.owned);

  return (
    <section className="bp-create" data-testid="create-adventure">
      <h1>Create an adventure</h1>
      <p className="bp-lede">
        Five short steps. Nothing is written to the library until you finish the
        last one — the adventure and its opening scene are saved together.
      </p>

      <StepIndicator steps={STEPS as unknown as Array<{ label: string }>} current={step} ariaLabel="Creation steps" />

      <p className="bp-help" data-testid="creation-budget">
        You own {limits.owned} of {limits.max_adventures_per_user} adventures
        ({remaining} remaining). You can start {limits.adventures_per_user_per_hour} per hour.
      </p>

      {!settings.can_create && (
        <Alert tone="warning" title="You've reached a creation limit">
          You can't start another adventure right now. Publish, finish, or remove
          one of your existing adventures, or try again later.
        </Alert>
      )}
      {submitState === "rate_limited" && (
        <Alert tone="warning" title="Too many new adventures">
          You've started several adventures in the last hour. Please wait a while
          before starting another. Nothing you typed has been lost.
        </Alert>
      )}
      {submitState === "limit_reached" && (
        <Alert tone="warning" title="Adventure limit reached">
          You've reached the maximum number of adventures for one account.
        </Alert>
      )}
      {submitState === "not_active" && (
        <Alert tone="warning" title="Your account can't create adventures">
          Only verified, active accounts can create adventures. Check your email
          for a verification link, or visit your account security page.
        </Alert>
      )}
      {submitState === "failed" && (
        <Alert tone="warning" title="We couldn't save that">
          Something went wrong saving the adventure. Nothing was written. Please
          try again.
        </Alert>
      )}

      <h2 tabIndex={-1} ref={headingRef} className="bp-create__step-heading">
        Step {step} of {STEPS.length}: {STEPS[step - 1].label}
      </h2>

      {/* ── Step 1 — Basics ───────────────────────────────────── */}
      {step === 1 && (
        <FormSection
          title="Basics"
          description="What the adventure is called and who it's for."
        >
          <div className="bp-field">
            <label className="bp-label" htmlFor="adv-title">Title</label>
            <input
              id="adv-title"
              className="bp-input"
              value={draft.title}
              maxLength={TITLE_MAX}
              aria-invalid={errors.title ? "true" : undefined}
              onChange={(e) => set("title", e.target.value)}
            />
            {label(errors.title) ? (
              <ValidationMessage tone="error">{label(errors.title)}</ValidationMessage>
            ) : null}
          </div>

          <TextArea
            label="Description"
            help="A short blurb shown on Discover and the adventure page."
            rows={5}
            maxLength={DESCRIPTION_MAX}
            showCount
            value={draft.description}
            error={label(errors.description)}
            onChange={(e) => set("description", e.target.value)}
          />

          <Select
            label="Genre"
            value={draft.genre}
            error={label(errors.genre)}
            onChange={(e) => set("genre", e.target.value)}
          >
            {settings.genres.map((g) => (
              <option key={g} value={g}>{GENRE_LABELS[g] ?? g}</option>
            ))}
          </Select>

          <Select
            label="Content rating"
            help="Readers filter by rating on Discover."
            value={draft.content_rating}
            error={label(errors.content_rating)}
            onChange={(e) => set("content_rating", e.target.value)}
          >
            {settings.content_ratings.map((r) => (
              <option key={r} value={r}>{RATING_LABELS[r] ?? r}</option>
            ))}
          </Select>

          <div className="bp-field">
            <label className="bp-label" htmlFor="adv-warning">Content warnings</label>
            <div className="bp-inline-add">
              <input
                id="adv-warning"
                className="bp-input"
                value={draft.content_warning_input}
                maxLength={60}
                onChange={(e) => set("content_warning_input", e.target.value)}
                onKeyDown={(e) => {
                  if (e.key === "Enter") { e.preventDefault(); addWarning(); }
                }}
              />
              <Button type="button" variant="secondary" onClick={addWarning}>
                Add warning
              </Button>
            </div>
            <ul className="bp-tag-list" aria-label="Content warnings">
              {draft.content_warnings.map((w) => (
                <li key={w}>
                  <Badge tone="warning">{w}</Badge>
                  <button
                    type="button"
                    className="bp-tag-remove"
                    aria-label={`Remove warning ${w}`}
                    onClick={() =>
                      setDraft((d) => ({
                        ...d,
                        content_warnings: d.content_warnings.filter((x) => x !== w),
                      }))
                    }
                  >
                    ×
                  </button>
                </li>
              ))}
            </ul>
            <InlineHelp>
              Warnings are shown before the first scene so readers can opt out.
            </InlineHelp>
          </div>
        </FormSection>
      )}

      {/* ── Step 2 — Opening scene ────────────────────────────── */}
      {step === 2 && (
        <FormSection
          title="Opening scene"
          description="Every adventure starts with one scene. You can add branches after it exists."
        >
          <div className="bp-field">
            <label className="bp-label" htmlFor="adv-opening-title">Scene title</label>
            <input
              id="adv-opening-title"
              className="bp-input"
              value={draft.opening_title}
              maxLength={OPENING_TITLE_MAX}
              aria-invalid={errors.opening_title ? "true" : undefined}
              onChange={(e) => set("opening_title", e.target.value)}
            />
            {label(errors.opening_title) ? (
              <ValidationMessage tone="error">{label(errors.opening_title)}</ValidationMessage>
            ) : null}
          </div>

          <div className="bp-field">
            <span className="bp-label" id="adv-opening-body-label">Scene text</span>
            <RichTextEditor
              value={draft.opening_body}
              onChange={(html) => set("opening_body", html)}
              ariaLabelledBy="adv-opening-body-label"
              maxPlainTextLength={OPENING_BODY_MAX}
              placeholder="The lantern swings once, and the gate opens…"
              data-testid="opening-body-editor"
            />
            {label(errors.opening_body) ? (
              <ValidationMessage tone="error">{label(errors.opening_body)}</ValidationMessage>
            ) : null}
            <InlineHelp>
              The editor allows paragraphs, emphasis, headings, lists, quotes, and
              rules only. Pasted links, images, styles, and custom HTML are removed
              here and again on the server.
            </InlineHelp>
            <p className="bp-help" data-testid="opening-body-count">
              {openingPlainLength} characters
            </p>
          </div>
        </FormSection>
      )}

      {/* ── Step 3 — Contributions ────────────────────────────── */}
      {step === 3 && (
        <FormSection
          title="Contributions"
          description="Templates set these for you; change anything you like."
        >
          <RadioGroup
            legend="Template"
            name="template"
            orientation="stacked"
            value={draft.template}
            onChange={applyTemplate}
            options={Object.entries(settings.templates).map(([key, t]) => ({
              value: key,
              label: t.label,
              description: `${MODE_LABELS[t.contribution_mode] ?? t.contribution_mode}. ${
                VISIBILITY_LABELS[t.visibility] ?? t.visibility
              }.`,
            }))}
            error={label(errors.template)}
          />

          <Select
            label="Who can add branches"
            value={draft.contribution_mode}
            error={label(errors.contribution_mode)}
            onChange={(e) => set("contribution_mode", e.target.value)}
          >
            {settings.contribution_modes.map((m) => (
              <option key={m} value={m}>{MODE_LABELS[m] ?? m}</option>
            ))}
          </Select>

          <Select
            label="Visibility"
            value={draft.visibility}
            error={label(errors.visibility)}
            onChange={(e) => set("visibility", e.target.value)}
          >
            {settings.visibilities.map((v) => (
              <option key={v} value={v}>{VISIBILITY_LABELS[v] ?? v}</option>
            ))}
          </Select>

          <div className="bp-field">
            <label className="bp-label" htmlFor="adv-branches">Branches allowed per scene</label>
            <input
              id="adv-branches"
              className="bp-input"
              type="number"
              min={settings.max_branches_min}
              max={settings.max_branches_max}
              value={draft.max_branches_per_scene}
              aria-invalid={errors.max_branches_per_scene ? "true" : undefined}
              onChange={(e) => set("max_branches_per_scene", Number(e.target.value))}
            />
            {label(errors.max_branches_per_scene) ? (
              <ValidationMessage tone="error">{label(errors.max_branches_per_scene)}</ValidationMessage>
            ) : null}
          </div>

          <Checkbox
            label="Allow contributions without a display name"
            help="Contributors are still recorded internally for moderation."
            checked={draft.anonymous_contributions}
            onChange={(e) => set("anonymous_contributions", e.target.checked)}
          />

          <PasswordField
            label="Contribution passcode (optional)"
            help={`Leave blank for no passcode. Minimum ${PASSCODE_MIN} characters.`}
            value={draft.contribution_passcode}
            error={label(errors.contribution_passcode)}
            onChange={(e) => set("contribution_passcode", e.target.value)}
          />
        </FormSection>
      )}

      {/* ── Step 4 — Writing guidelines ───────────────────────── */}
      {step === 4 && (
        <FormSection
          title="Writing guidelines"
          description="Optional. Shown to anyone who adds a branch to your story."
        >
          <div className="bp-field">
            <span className="bp-label" id="adv-guidelines-label">Guidelines</span>
            <RichTextEditor
              value={draft.writing_guidelines}
              onChange={(html) => set("writing_guidelines", html)}
              ariaLabelledBy="adv-guidelines-label"
              maxPlainTextLength={GUIDELINES_MAX}
              placeholder="Tone, tense, what's off-limits…"
              data-testid="guidelines-editor"
            />
            {label(errors.writing_guidelines) ? (
              <ValidationMessage tone="error">{label(errors.writing_guidelines)}</ValidationMessage>
            ) : null}
          </div>
        </FormSection>
      )}

      {/* ── Step 5 — Review ───────────────────────────────────── */}
      {step === 5 && (
        <FormSection
          title="Review"
          description="Check the details, then choose how to save."
        >
          <dl className="bp-review" data-testid="creation-review">
            <dt>Title</dt><dd>{draft.title}</dd>
            <dt>Genre</dt><dd>{GENRE_LABELS[draft.genre] ?? draft.genre}</dd>
            <dt>Content rating</dt><dd>{RATING_LABELS[draft.content_rating] ?? draft.content_rating}</dd>
            <dt>Content warnings</dt>
            <dd>{draft.content_warnings.length ? draft.content_warnings.join(", ") : "None"}</dd>
            <dt>Visibility</dt><dd>{VISIBILITY_LABELS[draft.visibility] ?? draft.visibility}</dd>
            <dt>Contributions</dt>
            <dd>{MODE_LABELS[draft.contribution_mode] ?? draft.contribution_mode}</dd>
            <dt>Branches per scene</dt><dd>{draft.max_branches_per_scene}</dd>
            <dt>Passcode</dt><dd>{draft.contribution_passcode ? "Set" : "None"}</dd>
            <dt>Opening scene</dt><dd>{draft.opening_title}</dd>
            <dt>Opening length</dt><dd>{openingPlainLength} characters</dd>
          </dl>

          <RadioGroup
            legend="How should this be saved?"
            name="status"
            orientation="stacked"
            value={draft.status}
            onChange={(v) => set("status", v)}
            options={[
              {
                value: "draft",
                label: "Save as a draft",
                description: "Only you can see it. Nothing appears on Discover.",
              },
              {
                value: "published",
                label: "Publish now",
                description: "The opening scene becomes readable straight away.",
              },
            ]}
          />

          {Object.keys(errors).length > 0 && (
            <ValidationMessage tone="error">Some details need fixing — check the earlier steps.</ValidationMessage>
          )}
        </FormSection>
      )}

      <div className="bp-wizard-actions">
        {step > 1 && (
          <Button type="button" variant="secondary" onClick={goBack}>
            Back
          </Button>
        )}
        {step < STEPS.length && (
          <Button type="button" onClick={goNext}>
            Continue
          </Button>
        )}
        {step === STEPS.length && (
          <Button
            type="button"
            onClick={() => void onSubmit()}
            disabled={submitState === "submitting" || !settings.can_create}
          >
            {submitState === "submitting" ? "Saving…" : "Create adventure"}
          </Button>
        )}
        <Link to="/account/adventures" className="bp-link-quiet">Cancel</Link>
      </div>
    </section>
  );
}
