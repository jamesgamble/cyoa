import { useCallback, useEffect, useMemo, useState } from "react";
import { ReaderPreferencesPanel } from "../components/ReaderPreferences";
import { Link, useNavigate, useParams } from "react-router-dom";
import {
  Alert,
  Button,
  LinkButton,
  ChoiceList,
  EndingPanel,
  Panel,
  SceneTitle,
  StoryBody,
  StoryPage,
} from "../components";
import { findAdventureBySlug } from "../data/discover";
import {
  findScene,
  scenesFor,
  startScene,
  type Scene,
} from "../data/scenes";
import { fetchAdventure, fetchScene } from "../lib/apiClient";
import type { AdventureSummary } from "../components/AdventureCard";
import { useAdventureProgress } from "../hooks/useLocalProgress";
import { useHelpContext } from "../components/GlobalHelp";

/** Build the reader URL for a specific scene. */
function readerHref(slug: string, sceneId: string): string {
  return `/adventure/${slug}/read/${sceneId}`;
}

export function Reader() {
  const { slug = "", sceneId } = useParams();
  const navigate = useNavigate();
  useHelpContext({ section: "reader" });

  const fixtureAdventure = findAdventureBySlug(slug);
  const fixtureScenes = useMemo(() => scenesFor(slug), [slug]);
  const opener = useMemo(() => startScene(slug), [slug]);

  // Progressive enhancement: adventure metadata + current scene body
  // come from the API when available, otherwise from fixtures.
  const [adventure, setAdventure] = useState<AdventureSummary | undefined>(
    fixtureAdventure,
  );
  useEffect(() => {
    if (!slug) return;
    const ctrl = new AbortController();
    fetchAdventure(slug, ctrl.signal).then((r) => { if (r) setAdventure(r); });
    return () => ctrl.abort();
  }, [slug]);

  const fixtureScene: Scene | undefined = sceneId
    ? findScene(slug, sceneId)
    : opener;
  const [remoteScene, setRemoteScene] = useState<Scene | null>(null);
  useEffect(() => {
    if (!slug || !sceneId) { setRemoteScene(null); return; }
    const ctrl = new AbortController();
    fetchScene(slug, sceneId, ctrl.signal).then((s) => setRemoteScene(s));
    return () => ctrl.abort();
  }, [slug, sceneId]);

  const currentScene: Scene | undefined = remoteScene ?? fixtureScene;
  const scenes = fixtureScenes;

  const progress = useAdventureProgress(slug);

  // Redirect `/adventure/:slug/read` → `/adventure/:slug/read/:startId`.
  useEffect(() => {
    if (!adventure || scenes.length === 0) return;
    if (!sceneId && opener) {
      navigate(readerHref(slug, opener.id), { replace: true });
    }
  }, [adventure, scenes.length, sceneId, opener, slug, navigate]);

  // Record a visit whenever we land on a valid scene. `progress.visit` is
  // stable per adventure slug, so this runs once per scene id — depending
  // on the full `progress` object would re-run every render.
  const visit = progress.visit;
  useEffect(() => {
    if (!currentScene) return;
    visit(currentScene.id, { isEnding: !!currentScene.ending });
  }, [currentScene, visit]);

  if (!adventure) {
    return (
      <section aria-labelledby="reader-missing-adv">
        <h1 id="reader-missing-adv">Adventure not found</h1>
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

  if (scenes.length === 0) {
    return (
      <section
        aria-labelledby="reader-no-scenes"
        data-testid="reader-empty"
      >
        <h1 id="reader-no-scenes">{adventure.title}</h1>
        <Alert tone="warning" title="This adventure has no readable scenes yet">
          The creator has not published any scenes for this adventure.
          Check back after the next update.
        </Alert>
        <p>
          <Link to={`/adventure/${slug}`}>Back to the adventure page</Link>
        </p>
      </section>
    );
  }

  if (!currentScene) {
    // sceneId provided but does not exist in the adventure.
    return (
      <section
        aria-labelledby="reader-missing-scene"
        data-testid="reader-invalid-scene"
      >
        <h1 id="reader-missing-scene">Scene not found</h1>
        <Alert tone="warning" title="That scene is not part of this adventure">
          The link may be out of date, or the scene may have been renamed.
        </Alert>
        <p>
          <Link
            to={readerHref(slug, opener!.id)}
            data-testid="invalid-scene-restart"
          >
            Start from the beginning
          </Link>
        </p>
        <p>
          <Link to={`/adventure/${slug}`}>Back to the adventure page</Link>
        </p>
      </section>
    );
  }

  return (
    <ReaderView
      adventure={adventure}
      scene={currentScene}
      opener={opener!}
      progress={progress}
    />
  );
}

interface ViewProps {
  adventure: NonNullable<ReturnType<typeof findAdventureBySlug>>;
  scene: Scene;
  opener: Scene;
  progress: ReturnType<typeof useAdventureProgress>;
}

function ReaderView({ adventure, scene, opener, progress }: ViewProps) {
  const navigate = useNavigate();
  const { slug } = adventure;

  const contributionsAllowed =
    adventure.contributionState === "immediate" ||
    adventure.contributionState === "approval" ||
    (adventure.contributionState === undefined &&
      adventure.contributionsOpen === true);

  const historyLength = progress.progress?.history.length ?? 0;
  const canGoBack = historyLength >= 2;
  const isBookmarked = progress.isBookmarked(scene.id);
  const isEnding = !!scene.ending;

  const goToScene = useCallback(
    (id: string) => navigate(readerHref(slug, id)),
    [navigate, slug],
  );

  const handleBack = useCallback(() => {
    const prev = progress.goBack();
    if (prev) navigate(readerHref(slug, prev));
  }, [progress, navigate, slug]);

  const handleRestart = useCallback(() => {
    progress.restart(opener.id);
    navigate(readerHref(slug, opener.id));
  }, [progress, navigate, opener.id, slug]);

  const handleClear = useCallback(() => {
    progress.clear();
    navigate(readerHref(slug, opener.id));
  }, [progress, navigate, opener.id, slug]);

  // Choices — each maps to a client-side navigation so the reader can
  // use browser Back as well as the in-page Back button.
  const choices = (scene.choices ?? []).map((c) => ({
    label: c.label,
    onClick: () => goToScene(c.target),
  }));

  // "Explore another path" at an ending: link back to the last scene
  // that had a choice, or to the start when there is none.
  const branchPoint = useMemo(() => {
    if (!isEnding || !progress.progress) return opener;
    const trail = progress.progress.history;
    for (let i = trail.length - 2; i >= 0; i--) {
      const s = findScene(slug, trail[i]);
      if (s && s.choices && s.choices.length > 1) return s;
    }
    return opener;
  }, [isEnding, progress.progress, opener, slug]);

  return (
    <div className="bp-reader" data-testid="reader" data-scene-id={scene.id}>
      <header className="bp-reader__masthead">
        <p className="bp-reader__adventure" data-testid="reader-adventure-title">
          <Link to={`/adventure/${slug}`}>{adventure.title}</Link>
        </p>
        <p className="bp-reader__scene-meta" data-testid="reader-scene-meta">
          {scene.chapter && (
            <span data-testid="reader-chapter">{scene.chapter}</span>
          )}
          {scene.chapter && <span aria-hidden="true"> · </span>}
          <span data-testid="reader-scene-number">
            Scene {scene.sceneNumber}
          </span>
        </p>
      </header>

      <StoryPage>
        <SceneTitle>{scene.title}</SceneTitle>
        <StoryBody text={scene.body} />

        {!isEnding && choices.length > 0 && (
          <ChoiceList choices={choices} ariaLabel="Choose your path" />
        )}

        {isEnding && scene.ending && (
          <EndingPanel
            title={scene.ending.title}
            kind={scene.ending.kind}
            body={scene.ending.body}
          />
        )}
      </StoryPage>

      {isEnding && (
        <Panel title="After the ending">
          <p>
            You have discovered {progress.progress?.discoveredEndings.length ?? 1}{" "}
            of {adventure.endingCount ?? "many"} endings for this adventure.
          </p>
          <div className="bp-reader__ending-actions">
            <LinkButton
              href={readerHref(slug, branchPoint.id)}
              variant="primary"
              data-testid="action-explore-another"
            >
              Explore another path
            </LinkButton>
            <Button
              type="button"
              variant="secondary"
              onClick={handleRestart}
              data-testid="action-restart-ending"
            >
              Restart from the beginning
            </Button>
          </div>
        </Panel>
      )}

      <nav
        className="bp-reader__actions"
        aria-label="Reader actions"
        data-testid="reader-actions"
      >
        <Button
          type="button"
          variant="ghost"
          onClick={handleBack}
          disabled={!canGoBack}
          data-testid="action-back"
        >
          Back one scene
        </Button>
        <Button
          type="button"
          variant="ghost"
          onClick={handleRestart}
          data-testid="action-restart"
        >
          Restart
        </Button>
        <LinkButton
          href={`/adventure/${slug}/map`}
          variant="ghost"
          data-testid="action-story-map"
        >
          Story map
        </LinkButton>
        <Button
          type="button"
          variant="ghost"
          aria-pressed={isBookmarked}
          onClick={() => progress.toggleBookmark(scene.id)}
          data-testid="action-bookmark"
        >
          {isBookmarked ? "Bookmarked" : "Bookmark"}
        </Button>
        {contributionsAllowed && (
          <LinkButton
            href={`/adventure/${slug}/branch?from=${scene.id}`}
            variant="ghost"
            data-testid="action-add-branch"
          >
            Add a branch
          </LinkButton>
        )}
        <LinkButton
          href="/help/reading"
          variant="ghost"
          data-testid="action-help"
        >
          Help
        </LinkButton>
      </nav>

      <ReaderPreferencesPanel />

      <details className="bp-reader__local" data-testid="reader-local">
        <summary>Your local reading data</summary>
        <p className="bp-inline-help">
          Reading progress, bookmarks, and discovered endings are stored
          only in your browser. Clearing them cannot be undone.
        </p>
        {progress.progress && (
          <ul className="bp-reader__local-summary">
            <li data-testid="local-history-count">
              History: {progress.progress.history.length} scene
              {progress.progress.history.length === 1 ? "" : "s"}
            </li>
            <li data-testid="local-bookmark-count">
              Bookmarks: {progress.progress.bookmarks.length}
            </li>
            <li data-testid="local-ending-count">
              Endings discovered: {progress.progress.discoveredEndings.length}
            </li>
          </ul>
        )}
        <Button
          type="button"
          variant="ghost"
          onClick={handleClear}
          data-testid="action-clear-local"
        >
          Clear local progress
        </Button>
      </details>
    </div>
  );
}
