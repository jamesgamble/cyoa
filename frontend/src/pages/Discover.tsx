import { useEffect, useMemo, useState, useCallback } from "react";
import { Link, useSearchParams } from "react-router-dom";
import {
  AdventureCard,
  Select,
  SearchField,
  EmptyState,
  Button,
  Dialog,
  GENRE_OPTIONS,
  RATING_OPTIONS,
} from "../components";
import type {
  AdventureSummary,
  AdventureStatus,
  ContentRating,
  Genre,
} from "../components/AdventureCard";
import { DISCOVER_ADVENTURES } from "../data/discover";
import { fetchDiscover } from "../lib/apiClient";
import { useHelpContext } from "../components/GlobalHelp";

/* ---------------- filter state ---------------- */

export const SORT_OPTIONS = [
  { value: "recent", label: "Recently updated" },
  { value: "newest", label: "Newest" },
  { value: "title", label: "Title (A–Z)" },
  { value: "scenes", label: "Scene count (high to low)" },
  { value: "endings", label: "Ending count (high to low)" },
] as const;
export type SortKey = (typeof SORT_OPTIONS)[number]["value"];

const ADVENTURE_STATUSES: ReadonlyArray<AdventureStatus> = [
  "draft",
  "review",
  "published",
  "archived",
];

const CONTRIBUTION_STATUSES = ["open", "closed"] as const;
type ContributionStatus = (typeof CONTRIBUTION_STATUSES)[number];

export interface DiscoverFilters {
  q: string;
  genre: Genre | "";
  rating: ContentRating | "";
  status: AdventureStatus | "";
  contributions: ContributionStatus | "";
  sort: SortKey;
}

export const DEFAULT_FILTERS: DiscoverFilters = {
  q: "",
  genre: "",
  rating: "",
  status: "",
  contributions: "",
  sort: "recent",
};

const isSortKey = (v: string): v is SortKey =>
  SORT_OPTIONS.some((o) => o.value === v);
const isGenre = (v: string): v is Genre =>
  GENRE_OPTIONS.some((o) => o.value === v);
const isRating = (v: string): v is ContentRating =>
  RATING_OPTIONS.some((o) => o.value === v);
const isStatus = (v: string): v is AdventureStatus =>
  (ADVENTURE_STATUSES as ReadonlyArray<string>).includes(v);
const isContribution = (v: string): v is ContributionStatus =>
  (CONTRIBUTION_STATUSES as ReadonlyArray<string>).includes(v);

export function parseFilters(params: URLSearchParams): DiscoverFilters {
  const q = params.get("q") ?? "";
  const genreRaw = params.get("genre") ?? "";
  const ratingRaw = params.get("rating") ?? "";
  const statusRaw = params.get("status") ?? "";
  const contribRaw = params.get("contributions") ?? "";
  const sortRaw = params.get("sort") ?? "";
  return {
    q,
    genre: isGenre(genreRaw) ? genreRaw : "",
    rating: isRating(ratingRaw) ? ratingRaw : "",
    status: isStatus(statusRaw) ? statusRaw : "",
    contributions: isContribution(contribRaw) ? contribRaw : "",
    sort: isSortKey(sortRaw) ? sortRaw : "recent",
  };
}

export function serialiseFilters(f: DiscoverFilters): URLSearchParams {
  const p = new URLSearchParams();
  if (f.q.trim()) p.set("q", f.q.trim());
  if (f.genre) p.set("genre", f.genre);
  if (f.rating) p.set("rating", f.rating);
  if (f.status) p.set("status", f.status);
  if (f.contributions) p.set("contributions", f.contributions);
  if (f.sort && f.sort !== "recent") p.set("sort", f.sort);
  return p;
}

export function filtersAreDefault(f: DiscoverFilters): boolean {
  return (
    !f.q.trim() &&
    !f.genre &&
    !f.rating &&
    !f.status &&
    !f.contributions &&
    f.sort === "recent"
  );
}

/* ---------------- filtering / sorting ---------------- */

export function applyFilters(
  items: ReadonlyArray<AdventureSummary>,
  f: DiscoverFilters,
): AdventureSummary[] {
  const needle = f.q.trim().toLowerCase();
  const filtered = items.filter((a) => {
    if (f.genre && a.genre !== f.genre) return false;
    if (f.rating && a.contentRating !== f.rating) return false;
    if (f.status && a.status !== f.status) return false;
    if (f.contributions === "open" && a.contributionsOpen !== true) return false;
    if (f.contributions === "closed" && a.contributionsOpen !== false)
      return false;
    if (needle) {
      const hay = `${a.title} ${a.author} ${a.synopsis ?? ""}`.toLowerCase();
      if (!hay.includes(needle)) return false;
    }
    return true;
  });

  const sorted = [...filtered];
  switch (f.sort) {
    case "title":
      sorted.sort((a, b) => a.title.localeCompare(b.title));
      break;
    case "scenes":
      sorted.sort((a, b) => (b.sceneCount ?? 0) - (a.sceneCount ?? 0));
      break;
    case "endings":
      sorted.sort((a, b) => (b.endingCount ?? 0) - (a.endingCount ?? 0));
      break;
    case "newest":
      // Newest by ISO timestamp; the fixtures use updatedAtIso as a proxy
      // for publication order until a dedicated createdAt field exists.
      sorted.sort((a, b) =>
        (b.updatedAtIso ?? "").localeCompare(a.updatedAtIso ?? ""),
      );
      break;
    case "recent":
    default:
      sorted.sort((a, b) =>
        (b.updatedAtIso ?? "").localeCompare(a.updatedAtIso ?? ""),
      );
      break;
  }
  return sorted;
}

/* ---------------- component ---------------- */

interface FilterControlsProps {
  filters: DiscoverFilters;
  update: (partial: Partial<DiscoverFilters>) => void;
  idPrefix: string;
}

function FilterControls({ filters, update, idPrefix }: FilterControlsProps) {
  return (
    <>
      <Select
        id={`${idPrefix}-genre`}
        label="Genre"
        value={filters.genre}
        onChange={(e) => update({ genre: e.target.value as Genre | "" })}
        data-testid={`${idPrefix}-genre`}
      >
        <option value="">All genres</option>
        {GENRE_OPTIONS.map((o) => (
          <option key={o.value} value={o.value}>
            {o.label}
          </option>
        ))}
      </Select>
      <Select
        id={`${idPrefix}-rating`}
        label="Content rating"
        value={filters.rating}
        onChange={(e) => update({ rating: e.target.value as ContentRating | "" })}
        data-testid={`${idPrefix}-rating`}
      >
        <option value="">All ratings</option>
        {RATING_OPTIONS.map((o) => (
          <option key={o.value} value={o.value}>
            {o.label}
          </option>
        ))}
      </Select>
      <Select
        id={`${idPrefix}-status`}
        label="Adventure status"
        value={filters.status}
        onChange={(e) => update({ status: e.target.value as AdventureStatus | "" })}
        data-testid={`${idPrefix}-status`}
      >
        <option value="">Any status</option>
        {ADVENTURE_STATUSES.map((s) => (
          <option key={s} value={s}>
            {s.charAt(0).toUpperCase() + s.slice(1)}
          </option>
        ))}
      </Select>
      <Select
        id={`${idPrefix}-contributions`}
        label="Contribution status"
        value={filters.contributions}
        onChange={(e) =>
          update({ contributions: e.target.value as ContributionStatus | "" })
        }
        data-testid={`${idPrefix}-contributions`}
      >
        <option value="">Any contribution status</option>
        <option value="open">Contributions open</option>
        <option value="closed">Contributions closed</option>
      </Select>
      <Select
        id={`${idPrefix}-sort`}
        label="Sort by"
        value={filters.sort}
        onChange={(e) =>
          update({ sort: (e.target.value as SortKey) })
        }
        data-testid={`${idPrefix}-sort`}
      >
        {SORT_OPTIONS.map((o) => (
          <option key={o.value} value={o.value}>
            {o.label}
          </option>
        ))}
      </Select>
    </>
  );
}

export function Discover() {
  useHelpContext({ section: "discover" });
  const [searchParams, setSearchParams] = useSearchParams();
  const filters = useMemo(() => parseFilters(searchParams), [searchParams]);
  const [mobileOpen, setMobileOpen] = useState(false);

  const update = useCallback(
    (partial: Partial<DiscoverFilters>) => {
      const next = { ...filters, ...partial };
      setSearchParams(serialiseFilters(next), { replace: true });
    },
    [filters, setSearchParams],
  );

  const clear = useCallback(() => {
    setSearchParams(new URLSearchParams(), { replace: true });
  }, [setSearchParams]);

  // Progressive enhancement: start from fixtures so tests and offline
  // reloads render immediately, then swap to the API response when it
  // arrives. `fetchDiscover` returns null on any error, in which case
  // we simply keep the fixtures.
  const [source, setSource] = useState<ReadonlyArray<AdventureSummary>>(
    DISCOVER_ADVENTURES,
  );
  useEffect(() => {
    const ctrl = new AbortController();
    fetchDiscover(ctrl.signal).then((remote) => {
      if (remote && remote.length > 0) setSource(remote);
    });
    return () => ctrl.abort();
  }, []);

  const results = useMemo(
    () => applyFilters(source, filters),
    [source, filters],
  );

  const active = !filtersAreDefault(filters);

  return (
    <section className="bp-discover" data-testid="discover">
      <header className="bp-discover__header">
        <p className="bp-eyebrow">The library</p>
        <h1>Discover adventures</h1>
        <p className="bp-discover__lede">
          Browse the current library. Every adventure below is free to read.
          Filter by genre, rating, status, or open contributions.
        </p>
      </header>

      {/* Desktop / tablet filter strip */}
      <form
        className="bp-discover__filters"
        role="search"
        aria-label="Filter adventures"
        data-testid="discover-filters"
        onSubmit={(e) => e.preventDefault()}
      >
        <SearchField
          label="Search adventures"
          hideLabel
          placeholder="Search by title, author, or synopsis…"
          value={filters.q}
          onChange={(e) => update({ q: e.target.value })}
          data-testid="discover-search"
        />
        <div className="bp-discover__filter-row" data-testid="discover-filter-row">
          <FilterControls filters={filters} update={update} idPrefix="filter" />
        </div>
        <div className="bp-discover__actions">
          <Button
            type="button"
            variant="ghost"
            onClick={() => setMobileOpen(true)}
            data-testid="discover-open-mobile"
            className="bp-discover__mobile-toggle"
            aria-haspopup="dialog"
          >
            Filter &amp; sort
          </Button>
          <Button
            type="button"
            variant="ghost"
            onClick={clear}
            disabled={!active}
            data-testid="discover-clear"
          >
            Clear filters
          </Button>
        </div>
      </form>

      <p className="bp-discover__count" data-testid="discover-count" aria-live="polite">
        {results.length === 0
          ? "No adventures match those filters."
          : `${results.length} ${results.length === 1 ? "adventure" : "adventures"} shown.`}
      </p>

      {results.length === 0 ? (
        <EmptyState
          title="No adventures match those filters"
          message="Try clearing a filter or searching for a different keyword."
          action={
            active ? (
              <Button variant="primary" onClick={clear}>
                Clear filters
              </Button>
            ) : (
              <Link to="/help" className="bp-btn bp-btn--ghost">
                Read the help
              </Link>
            )
          }
        />
      ) : (
        <ul
          className="bp-discover__grid"
          data-testid="discover-results"
          aria-label="Adventures"
        >
          {results.map((a) => (
            <li key={a.slug}>
              <AdventureCard adventure={a} />
            </li>
          ))}
        </ul>
      )}

      <Dialog
        open={mobileOpen}
        onClose={() => setMobileOpen(false)}
        title="Filter & sort"
        labelledById="discover-mobile-title"
        actions={
          <>
            <Button
              variant="ghost"
              onClick={() => {
                clear();
              }}
              data-testid="discover-mobile-clear"
            >
              Clear filters
            </Button>
            <Button
              variant="primary"
              onClick={() => setMobileOpen(false)}
              data-testid="discover-mobile-apply"
            >
              Show {results.length} result{results.length === 1 ? "" : "s"}
            </Button>
          </>
        }
      >
        <div
          className="bp-discover__mobile-panel"
          data-testid="discover-mobile-panel"
        >
          <SearchField
            label="Search adventures"
            placeholder="Search by title, author, or synopsis…"
            value={filters.q}
            onChange={(e) => update({ q: e.target.value })}
            data-testid="discover-mobile-search"
          />
          <FilterControls
            filters={filters}
            update={update}
            idPrefix="mobile-filter"
          />
        </div>
      </Dialog>
    </section>
  );
}
