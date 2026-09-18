/**
 * Public story map (v0.23.0).
 *
 * The reader-facing outline of an adventure. Only published scenes
 * appear: a choice leading to a draft or hidden scene is absent, with
 * nothing to suggest it is there. Large stories load a branch at a
 * time.
 */
import { Link, useParams } from "react-router-dom";
import { StoryOutline } from "../components";
import { useHelpContext } from "../components/GlobalHelp";

export function StoryMap() {
  const { slug = "" } = useParams();
  useHelpContext({ section: "story-map" });

  return (
    <section aria-labelledby="map-h" data-testid="story-map-page">
      <h1 id="map-h">Story outline</h1>
      <p>
        Every published scene in this adventure, nested under the choice that
        leads to it. Unfinished branches are not listed.
      </p>
      <p>
        <Link to={`/adventure/${slug}`}>Back to the adventure</Link>
      </p>
      <StoryOutline slug={slug} scope="public" heading="Published scenes" />
    </section>
  );
}
