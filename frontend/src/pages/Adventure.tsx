import { Link, useParams } from "react-router-dom";
import { useState } from "react";
import {
  Badge,
  Panel,
  LinkButton,
  Button,
  genreLabel,
  ratingLabel,
  storyStatusLabel,
  contributionStateLabel,
} from "../components";
import type {
  AdventureSummary,
  ContributionState,
  StoryStatus,
} from "../components/AdventureCard";
import { findAdventureBySlug } from "../data/discover";
import { useLocalProgress } from "../hooks/useLocalProgress";
import { useSignedIn } from "../hooks/useSignedIn";

/**
 * Derive contribution state from the older `contributionsOpen` boolean
 * when the fixture has not been enriched with an explicit state.
 */
function resolveContributionState(a: AdventureSummary): ContributionState {
  if (a.contributionState) return a.contributionState;
  return a.contributionsOpen ? "immediate" : "closed";
}

/** Story completion status, defaulting to "in progress" for backwards compat. */
function resolveStoryStatus(a: AdventureSummary): StoryStatus {
  if (a.storyStatus) return a.storyStatus;
  if (a.status === "archived") return "archived";
  return "in-progress";
}

export function Adventure() {
  const { slug = "" } = useParams();
  const adventure = findAdventureBySlug(slug);

  if (!adventure) {
    return (
      <section aria-labelledby="adv-missing">
        <h1 id="adv-missing">Adventure not found</h1>
        <p>
          No adventure matches this address. It may have been renamed or
          removed.
        </p>
        <p>
          <Link to="/discover">Return to Discover</Link>
        </p>
      </section>
    );
  }

  return <AdventureView adventure={adventure} />;
}

interface ViewProps {
  adventure: AdventureSummary;
}

function AdventureView({ adventure }: ViewProps) {
  const progress = useLocalProgress(adventure.slug);
  const signedIn = useSignedIn();
  const [following, setFollowing] = useState(false);

  const storyStatus = resolveStoryStatus(adventure);
  const contributionState = resolveContributionState(adventure);
  const contributionsAllowed =
    contributionState === "immediate" || contributionState === "approval";

  const {
    slug,
    title,
    author,
    description,
    synopsis,
    genre,
    contentRating,
    contentWarnings,
    writingGuidelines,
    sceneCount,
    endingCount,
    updatedAt,
    status,
  } = adventure;

  return (
    <article
      className="bp-adv-landing"
      aria-labelledby="adv-title"
      data-slug={slug}
      data-testid="adventure-landing"
    >
      <header className="bp-adv-landing__masthead">
        <p className="bp-adv-landing__byline" data-testid="adv-byline">
          by <span data-testid="adv-author">{author}</span>
        </p>
        <h1 id="adv-title" className="bp-adv-landing__title" data-testid="adv-title">
          {title}
        </h1>
        <p className="bp-adv-landing__tags" data-testid="adv-tags">
          {genre && (
            <span className="bp-tag" data-testid="adv-genre">
              {genreLabel(genre)}
            </span>
          )}
          {contentRating && (
            <span className="bp-tag" data-testid="adv-rating">
              {ratingLabel(contentRating)}
            </span>
          )}
          <span
            className="bp-tag"
            data-testid="adv-story-status"
            data-value={storyStatus}
          >
            {storyStatusLabel(storyStatus)}
          </span>
          <span
            className={
              "bp-tag" +
              (contributionsAllowed ? " bp-tag--accent" : " bp-tag--muted")
            }
            data-testid="adv-contribution-state"
            data-value={contributionState}
          >
            {contributionStateLabel(contributionState)}
          </span>
          {status && (
            <Badge tone={status}>
              <span data-testid="adv-publication-status">{status}</span>
            </Badge>
          )}
        </p>
      </header>

      {(description || synopsis) && (
        <section
          className="bp-adv-landing__description bp-story"
          aria-labelledby="adv-desc-heading"
        >
          <h2 id="adv-desc-heading" className="bp-visually-hidden">
            About this adventure
          </h2>
          <p className="bp-story__body" data-testid="adv-description">
            {description ?? synopsis}
          </p>
        </section>
      )}

      <section
        className="bp-adv-landing__actions"
        aria-labelledby="adv-actions-heading"
      >
        <h2 id="adv-actions-heading" className="bp-visually-hidden">
          Actions
        </h2>
        <div className="bp-adv-landing__action-row">
          <LinkButton
            to={`/read/${slug}`}
            variant="primary"
            data-testid="action-read"
          >
            Read from beginning
          </LinkButton>
          {progress && (
            <LinkButton
              to={`/read/${slug}/scene/${progress.sceneSlug}`}
              variant="secondary"
              data-testid="action-resume"
            >
              Resume reading
            </LinkButton>
          )}
          <LinkButton
            to={`/adventure/${slug}/map`}
            variant="secondary"
            data-testid="action-map"
          >
            View story map
          </LinkButton>
          {contributionsAllowed && (
            <LinkButton
              to={`/adventure/${slug}/branch`}
              variant="secondary"
              data-testid="action-add-branch"
            >
              Add a branch
            </LinkButton>
          )}
        </div>
        {signedIn && (
          <div className="bp-adv-landing__follow">
            <Button
              type="button"
              variant="ghost"
              aria-pressed={following}
              onClick={() => setFollowing((f) => !f)}
              data-testid="action-follow"
            >
              {following ? "Following (placeholder)" : "Follow (placeholder)"}
            </Button>
            <p className="bp-inline-help" data-testid="follow-help">
              Following notifications arrive with the account system.
            </p>
          </div>
        )}
        {!contributionsAllowed && (
          <p
            className="bp-inline-help"
            data-testid="add-branch-unavailable"
          >
            This adventure is closed to new contributions.
          </p>
        )}
      </section>

      <Panel title="At a glance">
        <dl className="bp-adv-landing__facts" data-testid="adv-facts">
          <div>
            <dt>Creator</dt>
            <dd data-testid="fact-author">{author}</dd>
          </div>
          {genre && (
            <div>
              <dt>Genre</dt>
              <dd data-testid="fact-genre">{genreLabel(genre)}</dd>
            </div>
          )}
          {contentRating && (
            <div>
              <dt>Content rating</dt>
              <dd data-testid="fact-rating">{ratingLabel(contentRating)}</dd>
            </div>
          )}
          <div>
            <dt>Adventure status</dt>
            <dd data-testid="fact-story-status">
              {storyStatusLabel(storyStatus)}
            </dd>
          </div>
          <div>
            <dt>Contribution status</dt>
            <dd data-testid="fact-contribution-state">
              {contributionStateLabel(contributionState)}
            </dd>
          </div>
          {sceneCount !== undefined && (
            <div>
              <dt>Scene count</dt>
              <dd data-testid="fact-scene-count">{sceneCount}</dd>
            </div>
          )}
          {endingCount !== undefined && (
            <div>
              <dt>Ending count</dt>
              <dd data-testid="fact-ending-count">{endingCount}</dd>
            </div>
          )}
          {updatedAt && (
            <div>
              <dt>Last updated</dt>
              <dd data-testid="fact-updated">{updatedAt}</dd>
            </div>
          )}
        </dl>
      </Panel>

      {contentWarnings && contentWarnings.length > 0 && (
        <Panel title="Content warnings">
          <ul
            className="bp-adv-landing__warnings"
            data-testid="adv-content-warnings"
          >
            {contentWarnings.map((w) => (
              <li key={w}>{w}</li>
            ))}
          </ul>
        </Panel>
      )}

      {writingGuidelines && (
        <Panel title="Writing guidelines">
          <p
            className="bp-story__body"
            data-testid="adv-writing-guidelines"
          >
            {writingGuidelines}
          </p>
        </Panel>
      )}

      <p className="bp-adv-landing__back">
        <Link to="/discover">Back to Discover</Link>
      </p>
    </article>
  );
}
