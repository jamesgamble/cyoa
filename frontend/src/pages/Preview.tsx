/**
 * Adventure preview (v0.17.0).
 *
 * Shows the reader experience for an adventure the visitor is
 * authorised to manage — drafts included. The payload comes from
 * /api/adventures/{slug}/preview, which requires a session and a role
 * (owner, editor, or administrator); unauthorised visitors never
 * receive draft content.
 *
 * The route is deliberately un-indexable: the server sends
 * `X-Robots-Tag: noindex, nofollow` and this page adds a matching
 * `<meta name="robots" content="noindex, nofollow">` while mounted.
 */
import { useEffect, useState } from "react";
import { Link, useParams } from "react-router-dom";
import { Alert, Panel, SceneTitle, StoryBody } from "../components";
import { LoadingState, NotFoundState, UnauthorizedState } from "../states";
import { useHelpContext } from "../components/GlobalHelp";
import { richTextToPlainText } from "../lib/richTextSanitizer";
import {
  fetchAdventurePreview,
  type ManageOutcome,
  type PreviewPayload,
} from "../lib/apiClient";

/** Add (and remove) the robots meta tag for the lifetime of the page. */
function useNoIndex() {
  useEffect(() => {
    const meta = document.createElement("meta");
    meta.setAttribute("name", "robots");
    meta.setAttribute("content", "noindex, nofollow");
    meta.setAttribute("data-testid", "preview-noindex");
    document.head.appendChild(meta);
    return () => { meta.remove(); };
  }, []);
}

export function Preview() {
  const { slug = "" } = useParams();
  useHelpContext({ section: "publishing" });
  useNoIndex();

  const [outcome, setOutcome] = useState<ManageOutcome | null>(null);
  const [data, setData] = useState<PreviewPayload | null>(null);

  useEffect(() => {
    let live = true;
    void fetchAdventurePreview(slug).then((r) => {
      if (!live) return;
      setOutcome(r.outcome);
      setData(r.data);
    });
    return () => { live = false; };
  }, [slug]);

  if (outcome === null) return <LoadingState message="Loading preview…" />;
  if (outcome === "unauthenticated") return <UnauthorizedState />;
  if (outcome === "forbidden") {
    return (
      <section>
        <h1>Preview unavailable</h1>
        <Alert tone="warning" title="You cannot preview this adventure">
          Previews are limited to the adventure’s owner, its editors, and
          site administrators.
        </Alert>
      </section>
    );
  }
  if (outcome === "not_found" || !data) return <NotFoundState />;

  return (
    <section aria-labelledby="preview-h" data-testid="adventure-preview">
      <h1 id="preview-h">Preview: {data.adventure.title}</h1>
      <Alert tone="info" title="This is a private preview">
        You are seeing the adventure exactly as it stands, including
        unpublished scenes. This page is not indexed by search engines and
        cannot be opened by readers. Current status:{" "}
        <strong>{data.adventure.state}</strong>.
      </Alert>
      <p>
        <Link to={`/manage/${data.adventure.slug}`}>Back to manage adventure</Link>
      </p>

      {data.scenes.map((scene) => (
        <Panel key={scene.id} title={`Scene ${scene.sceneNumber}`}>
          <SceneTitle
            title={scene.title}
            chapter={scene.chapter ?? undefined}
            sceneNumber={scene.sceneNumber}
          />
          <p className="bp-meta" data-testid={`preview-scene-state-${scene.slug}`}>
            {scene.state === "published" ? "Published scene" : "Unpublished scene"}
          </p>
          <StoryBody text={richTextToPlainText(scene.body)} />
        </Panel>
      ))}
    </section>
  );
}
