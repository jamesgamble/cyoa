/**
 * Submit a branch — v0.18.0.
 *
 * A branch is one choice added to a published scene plus the scene it
 * leads to. Who may add one, and what happens when they do, is decided
 * entirely by the server: this page asks `/branch` for the context
 * (mode, passcode requirement, remaining branch slots, which
 * attribution options apply) and renders accordingly. Every rule is
 * re-checked on submit, so a stale or tampered form cannot get past it.
 *
 * Attribution is a PUBLIC display preference. Choosing "Anonymous"
 * hides the contributor's name from readers; it never hides it from
 * the adventure's moderators, who always see the internal record.
 *
 * Drafts autosave to this browser only (localStorage), so a closed tab
 * or a failed submission does not lose the writing. The draft is
 * cleared once the branch is accepted.
 */

import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { Link, useNavigate, useParams, useSearchParams } from "react-router-dom";
import { FormSection } from "../components/FormSection";
import { RadioGroup } from "../components/RadioGroup";
import { TextArea } from "../components/TextArea";
import { PasswordField } from "../components/PasswordField";
import { RichTextEditor } from "../components/RichTextEditor";
import { ValidationMessage } from "../components/ValidationMessage";
import { Button } from "../components/Button";
import { Badge } from "../components/Badge";
import { Alert } from "../components/Alert";
import { InlineHelp } from "../components/InlineHelp";
import { WarningPanel } from "../components/WarningPanel";
import { useHelpContext } from "../components/GlobalHelp";
import { richTextToPlainText, sanitizeRichTextHtml } from "../lib/richTextSanitizer";
import {
  fetchBranchContext,
  submitBranch,
  type BranchAttribution,
  type BranchContext,
  type BranchSceneType,
} from "../lib/apiClient";

export const CHOICE_MAX = 120;
export const TITLE_MAX = 120;
export const BODY_MAX = 20000;
export const NOTE_MAX = 1000;
const AUTOSAVE_DELAY_MS = 600;

export interface BranchDraft {
  choiceText: string;
  sceneTitle: string;
  sceneBody: string;
  sceneType: BranchSceneType;
  attribution: BranchAttribution;
  privateNote: string;
}

const EMPTY_DRAFT: BranchDraft = {
  choiceText: "",
  sceneTitle: "",
  sceneBody: "",
  sceneType: "story",
  attribution: "username",
  privateNote: "",
};

/** Per-adventure, per-scene autosave key — drafts never collide. */
export function branchDraftKey(slug: string, sceneRef: string): string {
  return `bp:branch-draft:${slug}:${sceneRef}`;
}

export function readBranchDraft(slug: string, sceneRef: string): BranchDraft | null {
  try {
    const raw = window.localStorage.getItem(branchDraftKey(slug, sceneRef));
    if (!raw) return null;
    const parsed = JSON.parse(raw) as Partial<BranchDraft>;
    return { ...EMPTY_DRAFT, ...parsed };
  } catch {
    return null;
  }
}

export function writeBranchDraft(slug: string, sceneRef: string, draft: BranchDraft): void {
  try {
    window.localStorage.setItem(branchDraftKey(slug, sceneRef), JSON.stringify(draft));
  } catch {
    /* storage unavailable — the form still works, it just won't persist */
  }
}

export function clearBranchDraft(slug: string, sceneRef: string): void {
  try {
    window.localStorage.removeItem(branchDraftKey(slug, sceneRef));
  } catch {
    /* ignore */
  }
}

/** Client-side mirror of the server rules — feedback only, never trust. */
export function validateBranchDraft(
  draft: BranchDraft,
  requiresPasscode: boolean,
  passcode: string,
): Record<string, string> {
  const errors: Record<string, string> = {};
  const choice = draft.choiceText.trim();
  if (choice.length < 3) errors["choice_text"] = "Write the choice readers will click (at least 3 characters).";
  else if (choice.length > CHOICE_MAX) errors["choice_text"] = `Keep the choice under ${CHOICE_MAX} characters.`;

  const title = draft.sceneTitle.trim();
  if (title.length < 3) errors["scene_title"] = "Give the next scene a title (at least 3 characters).";
  else if (title.length > TITLE_MAX) errors["scene_title"] = `Keep the title under ${TITLE_MAX} characters.`;

  const plain = richTextToPlainText(sanitizeRichTextHtml(draft.sceneBody)).trim();
  if (plain.length < 1) errors["scene_body"] = "Write the scene this choice leads to.";
  else if (plain.length > BODY_MAX) errors["scene_body"] = `Keep the scene under ${BODY_MAX} characters.`;

  if (draft.privateNote.length > NOTE_MAX) {
    errors["private_note"] = `Keep the note under ${NOTE_MAX} characters.`;
  }
  if (requiresPasscode && passcode.trim() === "") {
    errors["passcode"] = "This adventure requires a contribution passcode.";
  }
  return errors;
}

const ATTRIBUTION_LABELS: Record<BranchAttribution, string> = {
  username: "My username",
  display_name: "My display name",
  anonymous: "Anonymous (public)",
};

/** Server outcomes rendered as sentences a contributor can act on. */
const OUTCOME_MESSAGES: Record<string, string> = {
  adventure_unavailable: "This adventure is not accepting readers or contributions right now.",
  contributions_closed: "Contributions are closed for this adventure.",
  source_scene_unavailable: "The scene you are branching from is not published.",
  scene_locked: "This scene has been locked by the adventure's team.",
  branch_limit_reached: "This scene already has all the branches it allows.",
  contributor_blocked: "You cannot contribute to this adventure.",
  passcode_required: "A contribution passcode is required.",
  passcode_invalid: "That passcode does not match.",
  rate_limited: "You have submitted several branches recently. Try again in an hour.",
  duplicate: "A branch with that same choice already leaves this scene.",
  unauthenticated: "Sign in to contribute to this adventure.",
  not_found: "That scene could not be found.",
  invalid: "Please correct the fields below.",
  csrf_failed: "Your session expired. Reload the page and try again.",
  csrf_unavailable: "The server is unavailable. Try again shortly.",
  network_error: "The server is unavailable. Try again shortly.",
};

export function SubmitBranch() {
  const { slug = "" } = useParams();
  const [params] = useSearchParams();
  const sceneRef = params.get("from") ?? "";
  const navigate = useNavigate();
  useHelpContext({ section: "contributing", adventureSlug: slug });

  const [context, setContext] = useState<BranchContext | null>(null);
  const [loading, setLoading] = useState(true);
  const [draft, setDraft] = useState<BranchDraft>(EMPTY_DRAFT);
  const [passcode, setPasscode] = useState("");
  const [honeypot, setHoneypot] = useState("");
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [outcome, setOutcome] = useState<string | null>(null);
  const [result, setResult] = useState<"published" | "pending" | null>(null);
  const [saved, setSaved] = useState(false);
  const [busy, setBusy] = useState(false);
  const restored = useRef(false);

  /* ── Context ─────────────────────────────────────────────── */
  useEffect(() => {
    const controller = new AbortController();
    void (async () => {
      const ctx = await fetchBranchContext(slug, sceneRef, controller.signal);
      setContext(ctx);
      setLoading(false);
      if (ctx && !ctx.signed_in) {
        setDraft((d) => ({ ...d, attribution: "anonymous" }));
      }
    })();
    return () => controller.abort();
  }, [slug, sceneRef]);

  /* ── Restore an autosaved draft once ─────────────────────── */
  useEffect(() => {
    if (restored.current || !slug || !sceneRef) return;
    restored.current = true;
    const stored = readBranchDraft(slug, sceneRef);
    if (stored) setDraft(stored);
  }, [slug, sceneRef]);

  /* ── Autosave (debounced, this browser only) ─────────────── */
  useEffect(() => {
    if (!restored.current || result !== null) return;
    if (draft === EMPTY_DRAFT) return;
    const id = window.setTimeout(() => {
      writeBranchDraft(slug, sceneRef, draft);
      setSaved(true);
    }, AUTOSAVE_DELAY_MS);
    return () => window.clearTimeout(id);
  }, [draft, slug, sceneRef, result]);

  const set = useCallback(<K extends keyof BranchDraft>(key: K, value: BranchDraft[K]) => {
    setSaved(false);
    setDraft((d) => ({ ...d, [key]: value }));
  }, []);

  const attributionOptions = useMemo(
    () =>
      (context?.attribution_options ?? ["anonymous"]).map((value) => ({
        value,
        label: ATTRIBUTION_LABELS[value],
      })),
    [context],
  );

  async function handleSubmit(event: React.FormEvent) {
    event.preventDefault();
    if (!context) return;
    const found = validateBranchDraft(draft, context.requires_passcode, passcode);
    setErrors(found);
    setOutcome(null);
    if (Object.keys(found).length > 0) return;

    setBusy(true);
    const response = await submitBranch(slug, sceneRef, {
      choice_text: draft.choiceText.trim(),
      scene_title: draft.sceneTitle.trim(),
      scene_body: sanitizeRichTextHtml(draft.sceneBody),
      scene_type: draft.sceneType,
      attribution: draft.attribution,
      private_note: draft.privateNote.trim(),
      passcode,
      website: honeypot,
    });
    setBusy(false);

    if (response.ok && response.data) {
      clearBranchDraft(slug, sceneRef);
      setResult(response.data.published ? "published" : "pending");
      return;
    }
    if (response.fields) setErrors(mapServerFields(response.fields));
    setOutcome(response.error ?? "invalid");
  }

  if (loading) {
    return <p data-testid="branch-loading">Loading the contribution form…</p>;
  }

  if (!context) {
    return (
      <section aria-labelledby="branch-error-h" data-testid="branch-error">
        <h1 id="branch-error-h">This branch cannot be opened</h1>
        <p>
          The scene you are branching from is unavailable, or the server could not be reached.
        </p>
        <p>
          <Link to={`/adventure/${slug}`}>Back to the adventure</Link>
        </p>
      </section>
    );
  }

  if (result !== null) {
    return (
      <section aria-labelledby="branch-done-h" data-testid="branch-result">
        <h1 id="branch-done-h">
          {result === "published" ? "Your branch is live" : "Your branch is awaiting review"}
        </h1>
        <p>
          {result === "published"
            ? "Readers can follow your choice from this scene right away."
            : "The adventure's team reviews contributions before they appear. You can follow its progress in your contribution history."}
        </p>
        <p>
          <Link to={`/adventure/${slug}`}>Back to {context.adventure.title}</Link>
          {" · "}
          <Link to="/account/contributions">Your contributions</Link>
        </p>
      </section>
    );
  }

  const closed = !context.contributions_enabled;
  const blocked = context.blocked;
  const full = context.branch_limit.remaining < 1;
  const needsSignIn = !context.signed_in && !context.allows_anonymous;
  const canWrite =
    !closed && !blocked && !full && !needsSignIn && context.scene.published && !context.scene.locked;

  return (
    <section aria-labelledby="branch-h" data-testid="submit-branch">
      <h1 id="branch-h">Add a branch</h1>
      <p>
        Continuing <strong>{context.adventure.title}</strong> from the scene “
        {context.scene.title}”.
      </p>
      <p data-testid="branch-mode">
        <Badge tone={context.contribution_mode === "immediate" ? "published" : "review"}>
          {context.contribution_mode === "immediate"
            ? "Publishes immediately"
            : context.contribution_mode === "approval"
              ? "Reviewed before publishing"
              : "Contributions closed"}
        </Badge>{" "}
        <span data-testid="branch-slots">
          {context.branch_limit.remaining} of {context.branch_limit.limit} branch slots free
        </span>
      </p>

      {closed && (
        <div data-testid="branch-closed"><Alert tone="warning">
          This adventure is not accepting new branches.
        </Alert></div>
      )}
      {!closed && needsSignIn && (
        <div data-testid="branch-signin"><Alert tone="warning">
          This adventure accepts contributions from signed-in accounts only.{" "}
          <Link to={`/login?redirect=/adventure/${slug}/branch`}>Sign in</Link> to continue.
        </Alert></div>
      )}
      {!closed && blocked && (
        <div data-testid="branch-blocked"><Alert tone="danger">
          You cannot contribute to this adventure.
        </Alert></div>
      )}
      {!closed && !blocked && full && (
        <div data-testid="branch-full"><Alert tone="warning">
          This scene already has all the branches it allows.
        </Alert></div>
      )}
      {!closed && context.scene.locked && (
        <div data-testid="branch-locked"><Alert tone="warning">
          This scene has been locked, so it accepts no further branches.
        </Alert></div>
      )}
      {!closed && context.rate_limited && (
        <div data-testid="branch-rate-limited"><Alert tone="warning">
          You have submitted several branches recently. Try again in an hour.
        </Alert></div>
      )}
      {outcome && (
        <div data-testid="branch-outcome"><Alert tone="danger">
          {OUTCOME_MESSAGES[outcome] ?? "Your branch could not be submitted."}
        </Alert></div>
      )}

      {context.adventure.writing_guidelines.trim() !== "" && (
        <WarningPanel title="Writing guidelines">
          <p>{context.adventure.writing_guidelines}</p>
        </WarningPanel>
      )}

      {canWrite && (
        <form onSubmit={handleSubmit} noValidate data-testid="branch-form">
          <FormSection title="The choice" description="The line readers click to reach your scene.">
            <label htmlFor="choice-text">Choice text</label>
            <input
              id="choice-text"
              name="choice_text"
              type="text"
              maxLength={CHOICE_MAX}
              value={draft.choiceText}
              onChange={(e) => set("choiceText", e.target.value)}
              aria-invalid={errors["choice_text"] ? true : undefined}
              aria-describedby={errors["choice_text"] ? "choice-text-error" : undefined}
            />
            {errors["choice_text"] && (
              <ValidationMessage id="choice-text-error" tone="error">
                {errors["choice_text"]}
              </ValidationMessage>
            )}
          </FormSection>

          <FormSection title="The next scene" description="What happens after that choice.">
            <label htmlFor="scene-title">Scene title</label>
            <input
              id="scene-title"
              name="scene_title"
              type="text"
              maxLength={TITLE_MAX}
              value={draft.sceneTitle}
              onChange={(e) => set("sceneTitle", e.target.value)}
              aria-invalid={errors["scene_title"] ? true : undefined}
              aria-describedby={errors["scene_title"] ? "scene-title-error" : undefined}
            />
            {errors["scene_title"] && (
              <ValidationMessage id="scene-title-error" tone="error">
                {errors["scene_title"]}
              </ValidationMessage>
            )}

            <p className="bp-label" id="scene-body-label">Scene text</p>
            <RichTextEditor
              value={draft.sceneBody}
              onChange={(html) => set("sceneBody", html)}
              ariaLabelledBy="scene-body-label"
              maxPlainTextLength={BODY_MAX}
              data-testid="branch-body-editor"
            />
            {errors["scene_body"] && (
              <ValidationMessage id="scene-body-error" tone="error">
                {errors["scene_body"]}
              </ValidationMessage>
            )}

            <RadioGroup
              legend="Does this scene continue the story or end it?"
              name="scene_type"
              value={draft.sceneType}
              onChange={(value) => set("sceneType", value as BranchSceneType)}
              options={[
                { value: "story", label: "Continue the story", description: "Others can branch from it later." },
                { value: "ending", label: "An ending", description: "The path stops here." },
              ]}
            />
            {errors["scene_type"] && (
              <ValidationMessage tone="error">{errors["scene_type"]}</ValidationMessage>
            )}
          </FormSection>

          <FormSection
            title="Attribution"
            description="How readers see your contribution credited."
          >
            <RadioGroup
              legend="Public attribution"
              name="attribution"
              value={draft.attribution}
              onChange={(value) => set("attribution", value as BranchAttribution)}
              options={attributionOptions}
            />
            <InlineHelp>
              Choosing “Anonymous” hides your name from readers. The adventure's moderators
              still see who submitted the branch.
            </InlineHelp>
            {errors["attribution"] && (
              <ValidationMessage tone="error">{errors["attribution"]}</ValidationMessage>
            )}

            <TextArea
              id="private-note"
              label="Private note to the team (optional)"
              value={draft.privateNote}
              maxLength={NOTE_MAX}
              onChange={(e) => set("privateNote", e.target.value)}
              help="Only the adventure's team reads this. It is never published."
              error={errors["private_note"]}
            />
          </FormSection>

          {context.requires_passcode && (
            <FormSection
              title="Contribution passcode"
              description="This adventure asks contributors for a passcode."
            >
              <PasswordField
                id="branch-passcode"
                label="Contribution passcode"
                value={passcode}
                onChange={(e) => setPasscode(e.target.value)}
                error={errors["passcode"]}
              />
            </FormSection>
          )}

          {/* Honeypot — hidden from people, tempting to bots. */}
          <div aria-hidden="true" style={{ position: "absolute", left: "-9999px" }}>
            <label htmlFor="branch-website">Leave this field empty</label>
            <input
              id="branch-website"
              name="website"
              type="text"
              tabIndex={-1}
              autoComplete="off"
              value={honeypot}
              onChange={(e) => setHoneypot(e.target.value)}
            />
          </div>

          <p aria-live="polite" data-testid="branch-autosave">
            {saved ? "Draft saved in this browser." : "Draft not saved yet."}
          </p>

          <Button type="submit" variant="primary" disabled={busy}>
            {busy
              ? "Submitting…"
              : context.contribution_mode === "immediate"
                ? "Publish this branch"
                : "Submit for review"}
          </Button>{" "}
          <Button type="button" variant="ghost" onClick={() => navigate(`/adventure/${slug}`)}>
            Cancel
          </Button>
        </form>
      )}
    </section>
  );
}

/** Server field codes → sentences shown beside the field. */
function mapServerFields(fields: Record<string, string>): Record<string, string> {
  const out: Record<string, string> = {};
  for (const [field, code] of Object.entries(fields)) {
    if (field === "choice_text" && code === "duplicate") {
      out[field] = "A branch with that same choice already leaves this scene.";
    } else if (code === "too_long") {
      out[field] = "That is longer than this adventure allows.";
    } else if (field === "passcode") {
      out[field] = code === "required" ? "A contribution passcode is required." : "That passcode does not match.";
    } else {
      out[field] = "Please check this field.";
    }
  }
  return out;
}

export default SubmitBranch;
