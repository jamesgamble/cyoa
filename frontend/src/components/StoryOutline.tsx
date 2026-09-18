/**
 * Story outline (v0.23.0).
 *
 * An accessible nested outline of an adventure's scenes. Branches
 * collapse and expand, deep branches load on demand, and a search box
 * finds a scene by title and shows the path back to the opening scene.
 *
 * Two scopes share one component:
 *
 *   public  — published scenes only. A choice leading anywhere else is
 *             simply absent; nothing hints that it exists.
 *   manage  — the team also sees drafts and hidden scenes, each with a
 *             plain label saying so.
 *
 * The markup is a real tree: `role="tree"` with nested `role="group"`,
 * `aria-expanded` on every branch, and roving-tabindex keyboard
 * navigation (arrows, Home, End) as screen-reader users expect.
 */
import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { Link } from "react-router-dom";
import { SearchField } from "./SearchField";
import {
  fetchManageStoryMap,
  fetchStoryMap,
  type StoryMapNode,
  type StoryMapPayload,
} from "../lib/apiClient";

export interface StoryOutlineProps {
  slug: string;
  scope?: "public" | "manage";
  /** Levels fetched per request. Lower values load lazily in smaller steps. */
  depth?: number;
  /** Link each scene to the reader. Off for the owner map. */
  linkToReader?: boolean;
  heading?: string;
}

interface FlatRow {
  node: StoryMapNode;
  level: number;
  parentId: string | null;
}

/** Depth-first walk of the visible rows, honouring collapsed branches. */
function flatten(
  nodes: StoryMapNode[],
  expanded: Record<string, boolean>,
  extra: Record<string, StoryMapNode[]>,
  level = 0,
  parentId: string | null = null,
  out: FlatRow[] = [],
): FlatRow[] {
  for (const node of nodes) {
    out.push({ node, level, parentId });
    const children = node.children.length > 0 ? node.children : (extra[node.id] ?? []);
    if (expanded[node.id] && children.length > 0) {
      flatten(children, expanded, extra, level + 1, node.id, out);
    }
  }
  return out;
}

export function StoryOutline({
  slug,
  scope = "public",
  depth = 3,
  linkToReader = true,
  heading = "Story outline",
}: StoryOutlineProps) {
  const [map, setMap] = useState<StoryMapPayload | null>(null);
  const [failed, setFailed] = useState(false);
  const [expanded, setExpanded] = useState<Record<string, boolean>>({});
  const [extra, setExtra] = useState<Record<string, StoryMapNode[]>>({});
  const [loading, setLoading] = useState<string | null>(null);
  const [query, setQuery] = useState("");
  const [active, setActive] = useState<string | null>(null);
  const rowRefs = useRef<Record<string, HTMLDivElement | null>>({});

  const load = useCallback(
    (opts: { q?: string }, signal?: AbortSignal) => {
      const fetcher = scope === "manage" ? fetchManageStoryMap : fetchStoryMap;
      return fetcher(slug, { depth, ...opts }, signal).then((data) => {
        if (signal?.aborted) return;
        if (!data) { setFailed(true); return; }
        setFailed(false);
        setMap(data);
        // Everything that arrived in the first response starts open.
        const open: Record<string, boolean> = {};
        const mark = (ns: StoryMapNode[]) => {
          for (const n of ns) {
            if (n.children.length > 0) open[n.id] = true;
            mark(n.children);
          }
        };
        mark(data.tree);
        setExpanded(open);
        setExtra({});
      });
    },
    [slug, scope, depth],
  );

  useEffect(() => {
    const ctrl = new AbortController();
    void load({}, ctrl.signal);
    return () => ctrl.abort();
  }, [load]);

  const rows = useMemo(
    () => (map ? flatten(map.tree, expanded, extra) : []),
    [map, expanded, extra],
  );

  useEffect(() => {
    if (active === null && rows.length > 0) setActive(rows[0].node.id);
  }, [rows, active]);

  /** Expanding an unloaded branch fetches it — this is the lazy load. */
  async function expand(node: StoryMapNode) {
    setExpanded((e) => ({ ...e, [node.id]: true }));
    if (node.children.length > 0 || extra[node.id]) return;
    if (!node.has_more) return;
    setLoading(node.id);
    const fetcher = scope === "manage" ? fetchManageStoryMap : fetchStoryMap;
    const slice = await fetcher(slug, { root: node.id, depth: depth + 1 });
    setLoading(null);
    if (slice && slice.tree.length > 0) {
      setExtra((x) => ({ ...x, [node.id]: slice.tree[0].children }));
    }
  }

  function toggle(node: StoryMapNode) {
    if (expanded[node.id]) {
      setExpanded((e) => ({ ...e, [node.id]: false }));
      return;
    }
    void expand(node);
  }

  function focusRow(id: string) {
    setActive(id);
    rowRefs.current[id]?.focus();
  }

  function onKeyDown(e: React.KeyboardEvent, row: FlatRow) {
    const i = rows.findIndex((r) => r.node.id === row.node.id);
    const hasChildren =
      row.node.child_count > 0 || row.node.children.length > 0 || row.node.has_more;
    const open = Boolean(expanded[row.node.id]);
    switch (e.key) {
      case "ArrowDown":
        e.preventDefault();
        if (i < rows.length - 1) focusRow(rows[i + 1].node.id);
        break;
      case "ArrowUp":
        e.preventDefault();
        if (i > 0) focusRow(rows[i - 1].node.id);
        break;
      case "ArrowRight":
        e.preventDefault();
        if (hasChildren && !open) void expand(row.node);
        else if (open && i < rows.length - 1) focusRow(rows[i + 1].node.id);
        break;
      case "ArrowLeft":
        e.preventDefault();
        if (hasChildren && open) setExpanded((x) => ({ ...x, [row.node.id]: false }));
        else if (row.parentId) focusRow(row.parentId);
        break;
      case "Home":
        e.preventDefault();
        if (rows.length > 0) focusRow(rows[0].node.id);
        break;
      case "End":
        e.preventDefault();
        if (rows.length > 0) focusRow(rows[rows.length - 1].node.id);
        break;
      case " ":
      case "Enter":
        if (hasChildren) { e.preventDefault(); toggle(row.node); }
        break;
      default:
        break;
    }
  }

  function onSearch(e: React.FormEvent) {
    e.preventDefault();
    void load({ q: query.trim() });
  }

  if (failed && !map) {
    return (
      <section className="bp-outline" data-testid="story-outline">
        <h2>{heading}</h2>
        <p className="bp-meta">The outline could not be loaded just now.</p>
      </section>
    );
  }
  if (!map) {
    return (
      <section className="bp-outline" data-testid="story-outline">
        <h2>{heading}</h2>
        <p className="bp-meta">Loading the outline…</p>
      </section>
    );
  }

  const matches = map.matches;

  return (
    <section
      className="bp-outline"
      aria-labelledby="outline-h"
      data-testid="story-outline"
      data-scope={map.scope}
    >
      <h2 id="outline-h">{heading}</h2>
      <p className="bp-meta" data-testid="outline-count">
        {map.total_scenes} {map.total_scenes === 1 ? "scene" : "scenes"} in this story;{" "}
        {map.loaded_scenes} shown. Branches open as you need them.
      </p>

      <form onSubmit={onSearch} className="bp-outline__search" role="search">
        <SearchField
          label="Search scenes by title"
          value={query}
          data-testid="outline-search-input"
          onChange={(e) => setQuery(e.currentTarget.value)}
        />
        <button type="submit" className="bp-button bp-button--quiet" data-testid="outline-search">
          Search
        </button>
      </form>

      {matches && (
        <div className="bp-outline__matches" data-testid="outline-matches" aria-live="polite">
          {matches.length === 0 ? (
            <p>No scene title matches “{map.query}”.</p>
          ) : (
            <>
              <p>
                {matches.length} {matches.length === 1 ? "scene matches" : "scenes match"} “
                {map.query}”.
              </p>
              <ul className="bp-outline__match-list">
                {matches.map((m) => (
                  <li key={m.id} data-testid="outline-match">
                    <strong>{m.title}</strong>
                    {m.label && (
                      <span className="bp-tag bp-tag--muted" data-testid="outline-match-label">
                        {m.label}
                      </span>
                    )}
                    {m.path.length > 0 && (
                      <span className="bp-meta"> — {m.path.join(" → ")}</span>
                    )}
                    {!m.reachable && (
                      <span className="bp-meta"> — not reachable from the opening scene</span>
                    )}
                  </li>
                ))}
              </ul>
            </>
          )}
        </div>
      )}

      {rows.length === 0 ? (
        <p data-testid="outline-empty">There is nothing to outline yet.</p>
      ) : (
        <div role="tree" aria-label={`${map.adventure.title} outline`} className="bp-outline__tree">
          {rows.map((row) => {
            const { node, level } = row;
            const hasChildren =
              node.child_count > 0 || node.children.length > 0 || node.has_more;
            const open = Boolean(expanded[node.id]);
            return (
              <div
                key={node.id}
                ref={(el) => { rowRefs.current[node.id] = el; }}
                role="treeitem"
                aria-level={level + 1}
                aria-expanded={hasChildren ? open : undefined}
                tabIndex={active === node.id ? 0 : -1}
                onFocus={() => setActive(node.id)}
                onKeyDown={(e) => onKeyDown(e, row)}
                className="bp-outline__row"
                style={{ paddingInlineStart: `${level * 1.25}rem` }}
                data-testid="outline-node"
                data-scene={node.id}
                data-level={level}
              >
                {hasChildren ? (
                  <button
                    type="button"
                    className="bp-outline__twisty"
                    tabIndex={-1}
                    aria-label={`${open ? "Collapse" : "Expand"} ${node.title}`}
                    onClick={() => toggle(node)}
                    data-testid="outline-toggle"
                  >
                    {open ? "▾" : "▸"}
                  </button>
                ) : (
                  <span className="bp-outline__twisty" aria-hidden="true">
                    •
                  </span>
                )}
                {node.choice_label && (
                  <span className="bp-outline__choice" data-testid="outline-choice">
                    {node.choice_label}:
                  </span>
                )}{" "}
                {linkToReader ? (
                  <Link to={`/adventure/${map.adventure.slug}/read/${node.id}`}>{node.title}</Link>
                ) : (
                  <span className="bp-outline__title">{node.title}</span>
                )}
                {node.is_start && (
                  <span className="bp-tag bp-tag--muted">Opening scene</span>
                )}
                {node.type === "ending" && <span className="bp-tag">Ending</span>}
                {node.label && node.label !== "Published" && (
                  <span className="bp-tag bp-tag--accent" data-testid="outline-state-label">
                    {node.label}
                  </span>
                )}
                {loading === node.id && (
                  <span className="bp-meta" data-testid="outline-loading">
                    {" "}
                    loading…
                  </span>
                )}
                {!open && hasChildren && (
                  <span className="bp-meta">
                    {" "}
                    ({node.child_count} {node.child_count === 1 ? "branch" : "branches"})
                  </span>
                )}
              </div>
            );
          })}
        </div>
      )}

      {map.truncated && (
        <p className="bp-meta" data-testid="outline-truncated">
          This story is large, so only part of it is shown. Open a branch to
          load the rest of it.
        </p>
      )}
    </section>
  );
}
