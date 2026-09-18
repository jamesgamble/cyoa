/**
 * Story validation report (v0.23.0).
 *
 * Lists the integrity problems the server found in a story: choices
 * that lead nowhere, published choices into hidden scenes, empty
 * scenes, sibling choices that read the same, published scenes that
 * stop without an ending, scenes nothing leads to, broken parent
 * relationships, and stories that run too deep.
 *
 * The report is advisory. Nothing here removes or rewrites content.
 */
import { useEffect, useState } from "react";
import { Alert } from "./Alert";
import { Panel } from "./Panel";
import { fetchStoryValidation, type StoryValidationPayload } from "../lib/apiClient";

const ISSUE_TITLES: Record<string, string> = {
  missing_destination: "Choice leads nowhere",
  hidden_destination: "Published choice into an unpublished scene",
  empty_scene: "Empty scene",
  duplicate_sibling_choice: "Two choices read the same",
  dead_end: "Published scene with no way onward",
  unreachable_scene: "Scene nothing leads to",
  invalid_parent: "Broken branch shape",
  excessive_depth: "The story runs very deep",
  no_opening_scene: "No opening scene",
};

export function StoryValidationPanel({ slug }: { slug: string }) {
  const [report, setReport] = useState<StoryValidationPayload | null>(null);
  const [failed, setFailed] = useState(false);

  useEffect(() => {
    const ctrl = new AbortController();
    void fetchStoryValidation(slug, ctrl.signal).then((data) => {
      if (ctrl.signal.aborted) return;
      if (!data) { setFailed(true); return; }
      setReport(data);
    });
    return () => ctrl.abort();
  }, [slug]);

  if (failed) {
    return (
      <Panel title="Story check">
        <p className="bp-meta">The story check could not be run just now.</p>
      </Panel>
    );
  }
  if (!report) {
    return (
      <Panel title="Story check">
        <p className="bp-meta">Checking the story…</p>
      </Panel>
    );
  }

  const { summary, issues } = report;

  return (
    <Panel title="Story check">
      <p data-testid="validation-summary">
        {summary.total === 0
          ? "No problems found. Every branch leads somewhere and every published scene has a way onward."
          : `${summary.errors} to fix, ${summary.warnings} to look at.`}
      </p>
      {summary.errors > 0 && (
        <Alert tone="warning" title="Some branches need attention before publishing" />
      )}
      {issues.length > 0 && (
        <ul className="bp-validation-list" data-testid="validation-issues">
          {issues.map((i, n) => (
            <li
              key={`${i.code}-${i.scene}-${n}`}
              data-testid="validation-issue"
              data-code={i.code}
              data-severity={i.severity}
            >
              <strong>{ISSUE_TITLES[i.code] ?? i.code}</strong>
              <span className="bp-tag bp-tag--muted">
                {i.severity === "error" ? "Fix" : "Check"}
              </span>
              <span className="bp-meta"> — {i.scene_title}</span>
              <div>{i.message}</div>
            </li>
          ))}
        </ul>
      )}
    </Panel>
  );
}
