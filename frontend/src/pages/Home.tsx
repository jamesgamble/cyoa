import { Link } from "react-router-dom";
import {
  AdventureCard,
  FeaturedAdventureCard,
  Panel,
} from "../components";
import { FEATURED_ADVENTURE, RECENTLY_UPDATED } from "../data/adventures";

/**
 * Public homepage.
 *
 * Composes the primary reader actions, featured and recently-updated
 * adventures, a three-step explanation, an account-optional notice, a
 * registration call to action, a summary of the community guidelines,
 * and a directory of the main public destinations.
 *
 * The homepage intentionally omits activity feeds, rankings, likes,
 * comments, and social profiles.
 */
export function Home() {
  return (
    <div className="bp-home" data-testid="home">
      <section
        className="bp-home__hero"
        aria-labelledby="home-title"
        data-testid="home-hero"
      >
        <p className="bp-home__eyebrow">A library of branching stories</p>
        <h1 id="home-title" className="bp-home__title">
          Branching Paths
        </h1>
        <p className="bp-home__lede">
          A quiet, collaborative library of choose-your-own-adventure stories.
          Read one at your own pace, begin your own, or take up a path another
          author has left open.
        </p>
        <ul
          className="bp-home__primary-actions"
          aria-label="Primary actions"
          data-testid="home-primary-actions"
        >
          <li>
            <Link
              to="/discover"
              className="bp-btn bp-btn--primary"
              data-testid="cta-read"
            >
              Read an adventure
            </Link>
          </li>
          <li>
            <Link
              to="/start"
              className="bp-btn bp-btn--secondary"
              data-testid="cta-create"
            >
              Create an adventure
            </Link>
          </li>
          <li>
            <Link
              to="/discover?open=true"
              className="bp-btn bp-btn--ghost"
              data-testid="cta-continue"
            >
              Continue someone else's story
            </Link>
          </li>
        </ul>
      </section>

      <section
        className="bp-home__featured"
        aria-labelledby="home-featured-heading"
        data-testid="home-featured"
      >
        <h2 id="home-featured-heading" className="bp-home__section-title">
          Featured adventure
        </h2>
        <FeaturedAdventureCard adventure={FEATURED_ADVENTURE} />
      </section>

      <section
        className="bp-home__recent"
        aria-labelledby="home-recent-heading"
        data-testid="home-recent"
      >
        <div className="bp-home__section-head">
          <h2 id="home-recent-heading" className="bp-home__section-title">
            Recently updated
          </h2>
          <Link to="/discover" className="bp-home__section-more">
            All adventures →
          </Link>
        </div>
        <ul className="bp-home__grid" aria-label="Recently updated adventures">
          {RECENTLY_UPDATED.map((adventure) => (
            <li key={adventure.slug}>
              <AdventureCard adventure={adventure} />
            </li>
          ))}
        </ul>
      </section>

      <section
        className="bp-home__how"
        aria-labelledby="home-how-heading"
        data-testid="home-how"
      >
        <h2 id="home-how-heading" className="bp-home__section-title">
          How it works
        </h2>
        <ol className="bp-home__steps" aria-label="Three steps to begin">
          <li>
            <span className="bp-home__step-number" aria-hidden="true">
              1
            </span>
            <h3 className="bp-home__step-title">Open a path</h3>
            <p>
              Browse Discover and start reading any published adventure. No
              account is needed to read.
            </p>
          </li>
          <li>
            <span className="bp-home__step-number" aria-hidden="true">
              2
            </span>
            <h3 className="bp-home__step-title">Choose a direction</h3>
            <p>
              At each scene, pick a numbered choice. Your path is your own —
              nothing is shared unless you decide to contribute.
            </p>
          </li>
          <li>
            <span className="bp-home__step-number" aria-hidden="true">
              3
            </span>
            <h3 className="bp-home__step-title">Write the next scene</h3>
            <p>
              Sign up to author your own adventure or add a branch where an
              existing story pauses at a fork.
            </p>
          </li>
        </ol>
      </section>

      <section
        className="bp-home__account"
        aria-labelledby="home-account-heading"
        data-testid="home-account"
      >
        <Panel>
          <h2 id="home-account-heading" className="bp-home__section-title">
            Reading does not require an account
          </h2>
          <p>
            Every published adventure on Branching Paths is free to read
            without signing in. Accounts are only needed to author
            adventures, submit branches, or save your place across devices.
          </p>
          <p className="bp-home__account-cta">
            <Link
              to="/register"
              className="bp-btn bp-btn--primary"
              data-testid="cta-register"
            >
              Create an account to write
            </Link>
            <Link
              to="/login"
              className="bp-btn bp-btn--ghost"
              data-testid="cta-signin"
            >
              I already have one
            </Link>
          </p>
        </Panel>
      </section>

      <section
        className="bp-home__guidelines"
        aria-labelledby="home-guidelines-heading"
        data-testid="home-guidelines"
      >
        <h2 id="home-guidelines-heading" className="bp-home__section-title">
          Community guidelines, in short
        </h2>
        <ul className="bp-home__guidelines-list">
          <li>
            <strong>Write in good faith.</strong> Contribute scenes and endings
            you would be glad to read yourself.
          </li>
          <li>
            <strong>Respect other authors.</strong> Branches extend a story;
            they do not overwrite its established scenes.
          </li>
          <li>
            <strong>Keep it readable.</strong> No harassment, no illegal
            content, no impersonation, no spam.
          </li>
          <li>
            <strong>Attribute clearly.</strong> Only submit writing that is
            your own or that you have permission to share.
          </li>
        </ul>
        <p className="bp-home__guidelines-more">
          <Link to="/help/community-guidelines">
            Read the full community guidelines →
          </Link>
        </p>
      </section>

      <nav
        className="bp-home__directory"
        aria-label="Explore Branching Paths"
        data-testid="home-directory"
      >
        <h2 className="bp-home__section-title">Explore</h2>
        <ul>
          <li>
            <Link to="/discover">
              <span className="bp-home__dir-title">Discover</span>
              <span className="bp-home__dir-desc">
                Browse every published adventure.
              </span>
            </Link>
          </li>
          <li>
            <Link to="/start">
              <span className="bp-home__dir-title">Create</span>
              <span className="bp-home__dir-desc">
                Begin a new adventure of your own.
              </span>
            </Link>
          </li>
          <li>
            <Link to="/help">
              <span className="bp-home__dir-title">Help</span>
              <span className="bp-home__dir-desc">
                Guidance for readers, authors, and moderators.
              </span>
            </Link>
          </li>
          <li>
            <Link to="/changelog">
              <span className="bp-home__dir-title">Changelog</span>
              <span className="bp-home__dir-desc">
                What changed in each release.
              </span>
            </Link>
          </li>
        </ul>
      </nav>
    </div>
  );
}
