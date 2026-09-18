<?php
/**
 * StoryMapService — nested story outline, map, search, and integrity
 * validation (v0.23.0).
 *
 * Two audiences share one builder:
 *
 *  - `publicMap()` sees published scenes inside a publicly visible
 *    adventure and nothing else. A choice that points at a draft or
 *    hidden scene is simply absent from the public tree.
 *  - `manageMap()` is for the adventure team. It shows draft and hidden
 *    scenes too, each carrying an explicit label, and it is the only
 *    scope that reports validation findings.
 *
 * The map is a TREE, not a graph. The MVP content rules are:
 *   one parent per non-root scene, no loops, no cross-links, and no
 *   shared destinations. The builder therefore claims each scene for the
 *   first edge that reaches it and records every later edge as a
 *   validation finding instead of following it — which also makes the
 *   traversal terminate on malformed data.
 *
 * Large stories are paged rather than serialised whole: a request asks
 * for a sub-tree (`root`) and a number of levels (`depth`), and any node
 * whose children were not included is flagged `has_more` so the client
 * can fetch that branch on demand.
 */

declare(strict_types=1);

namespace App;

use PDO;

final class StoryMapService
{
    public const OK        = 'ok';
    public const NOT_FOUND = 'not_found';
    public const FORBIDDEN = 'forbidden';

    /** Adventure states that are readable by anyone. */
    private const PUBLIC_STATES = ['published', 'on-hold', 'complete'];

    /** Levels returned when the caller does not ask for a depth. */
    public const DEFAULT_DEPTH = 3;
    /** Hard ceiling on levels per response, so one call cannot be huge. */
    public const MAX_DEPTH = 8;
    /** Hard ceiling on nodes per response. */
    public const MAX_NODES = 250;
    /** A story deeper than this is reported as excessively deep. */
    public const DEPTH_LIMIT = 40;
    /** Search results returned per query. */
    public const MAX_MATCHES = 50;

    private const LABELS = [
        'published' => 'Published',
        'draft'     => 'Draft',
        'hidden'    => 'Hidden',
    ];

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    // ------------------------------------------------------------------
    // Entry points
    // ------------------------------------------------------------------

    /**
     * Published-only outline for readers.
     *
     * @param array<string,mixed> $opts root|depth|q
     * @return array{0:string,1:?array<string,mixed>}
     */
    public function publicMap(string $slug, array $opts = []): array
    {
        $adv = $this->adventureRow($slug);
        if ($adv === null || !in_array((string) $adv['state'], self::PUBLIC_STATES, true)) {
            return [self::NOT_FOUND, null];
        }
        return [self::OK, $this->build($adv, false, $opts, null)];
    }

    /**
     * Full map for the adventure team, drafts and hidden scenes included.
     *
     * @param array<string,mixed> $opts root|depth|q
     * @return array{0:string,1:?array<string,mixed>}
     */
    public function manageMap(string $slug, ?int $userId, bool $isAdmin, array $opts = []): array
    {
        $adv = $this->adventureRow($slug);
        if ($adv === null) return [self::NOT_FOUND, null];

        $mod  = new ModerationService($this->pdo);
        $role = $mod->roleFor((int) $adv['id'], $userId, $isAdmin);
        if (!$mod->canView($role)) return [self::FORBIDDEN, null];

        return [self::OK, $this->build($adv, true, $opts, $role)];
    }

    /**
     * Validation findings only — the same checks the manage map carries,
     * without the tree payload.
     *
     * @return array{0:string,1:?array<string,mixed>}
     */
    public function validation(string $slug, ?int $userId, bool $isAdmin): array
    {
        [$outcome, $payload] = $this->manageMap($slug, $userId, $isAdmin, ['depth' => 0]);
        if ($outcome !== self::OK || $payload === null) return [$outcome, null];
        return [self::OK, [
            'adventure' => $payload['adventure'],
            'issues'    => $payload['issues'],
            'summary'   => $payload['issue_summary'],
        ]];
    }

    // ------------------------------------------------------------------
    // Builder
    // ------------------------------------------------------------------

    /**
     * @param array<string,mixed> $adv
     * @param array<string,mixed> $opts
     * @return array<string,mixed>
     */
    private function build(array $adv, bool $manage, array $opts, ?string $role): array
    {
        $adventureId = (int) $adv['id'];
        $scenes      = $this->scenes($adventureId, $manage);
        $edges       = $this->edges($adventureId);

        // Index scenes by id and slug.
        $byId = [];
        foreach ($scenes as $s) $byId[(int) $s['id']] = $s;

        $issues = $manage
            ? $this->collectIssues($adventureId, $byId, $edges)
            : [];

        // ---- tree assembly -------------------------------------------
        $visibleEdges = [];
        foreach ($edges as $e) {
            $from = (int) $e['scene_id'];
            $to   = (int) $e['target_scene_id'];
            // A destination the audience may not see is not an edge for
            // that audience; public readers never learn it exists.
            if (!isset($byId[$from]) || !isset($byId[$to])) continue;
            $visibleEdges[$from][] = $e;
        }

        $rootId = $this->rootId($scenes);
        $parentOf = [];   // child id => parent id (first claim wins)
        $childrenOf = []; // parent id => list of [edge, childId]
        if ($rootId !== null) {
            $queue   = [$rootId];
            $claimed = [$rootId => true];
            while ($queue !== []) {
                $current = array_shift($queue);
                foreach ($visibleEdges[$current] ?? [] as $e) {
                    $target = (int) $e['target_scene_id'];
                    if (isset($claimed[$target])) {
                        // Loop, cross-link, or shared destination: recorded
                        // by the validator, never followed here.
                        continue;
                    }
                    $claimed[$target]   = true;
                    $parentOf[$target]  = $current;
                    $childrenOf[$current][] = ['edge' => $e, 'child' => $target];
                    $queue[] = $target;
                }
            }
        }

        $reachable = $rootId === null ? [] : $this->reachableIds($rootId, $childrenOf);

        // ---- requested slice -----------------------------------------
        $rootSlug = isset($opts['root']) && is_string($opts['root']) && $opts['root'] !== ''
            ? $opts['root'] : null;
        $sliceRoot = $rootId;
        if ($rootSlug !== null) {
            $sliceRoot = null;
            foreach ($scenes as $s) {
                if ((string) $s['slug'] === $rootSlug) { $sliceRoot = (int) $s['id']; break; }
            }
        }

        $depth = isset($opts['depth']) && is_numeric($opts['depth'])
            ? (int) $opts['depth'] : self::DEFAULT_DEPTH;
        $depth = max(0, min(self::MAX_DEPTH, $depth));

        $counter = ['nodes' => 0, 'truncated' => false];
        $tree = [];
        if ($sliceRoot !== null && $depth > 0) {
            $tree = [$this->node(
                $sliceRoot, $byId, $childrenOf, $depth, 0, null, $manage, $counter,
            )];
        }

        $payload = [
            'adventure' => [
                'slug'  => (string) $adv['slug'],
                'title' => (string) $adv['title'],
                'state' => (string) $adv['state'],
            ],
            'scope'         => $manage ? 'manage' : 'public',
            'role'          => $role,
            'root'          => $sliceRoot !== null ? (string) $byId[$sliceRoot]['slug'] : null,
            'depth'         => $depth,
            'max_depth'     => self::MAX_DEPTH,
            'total_scenes'  => count($scenes),
            'loaded_scenes' => $counter['nodes'],
            'truncated'     => $counter['truncated'],
            'tree'          => $tree,
        ];

        $q = isset($opts['q']) && is_string($opts['q']) ? trim($opts['q']) : '';
        if ($q !== '') {
            $payload['query']   = $q;
            $payload['matches'] = $this->search($q, $scenes, $byId, $parentOf, $rootId, $manage);
        }

        if ($manage) {
            $payload['issues']        = $issues;
            $payload['issue_summary'] = $this->summarise($issues);
            // Scenes the traversal never claimed — orphans the team
            // should either link up or remove.
            $unreachable = [];
            foreach ($scenes as $s) {
                $id = (int) $s['id'];
                if ($rootId !== null && !isset($reachable[$id])) {
                    $unreachable[] = (string) $s['slug'];
                }
            }
            $payload['unreachable'] = $unreachable;
        }

        return $payload;
    }

    /**
     * @param array<int,array<string,mixed>> $byId
     * @param array<int,list<array{edge:array<string,mixed>,child:int}>> $childrenOf
     * @param array<string,int|bool> $counter
     * @return array<string,mixed>
     */
    private function node(
        int $id,
        array $byId,
        array $childrenOf,
        int $depthLeft,
        int $level,
        ?array $viaEdge,
        bool $manage,
        array &$counter,
    ): array {
        $s = $byId[$id];
        $counter['nodes'] = (int) $counter['nodes'] + 1;

        $kids      = $childrenOf[$id] ?? [];
        $children  = [];
        $hasMore   = false;

        if ($depthLeft <= 1) {
            $hasMore = $kids !== [];
        } else {
            foreach ($kids as $k) {
                if ((int) $counter['nodes'] >= self::MAX_NODES) {
                    $hasMore = true;
                    $counter['truncated'] = true;
                    break;
                }
                $children[] = $this->node(
                    $k['child'], $byId, $childrenOf, $depthLeft - 1, $level + 1,
                    $k['edge'], $manage, $counter,
                );
            }
        }

        $state = (string) $s['state'];
        $node = [
            'id'          => (string) $s['slug'],
            'scene_id'    => $id,
            'number'      => (int) $s['scene_number'],
            'title'       => (string) $s['title'],
            'type'        => (string) $s['scene_type'] === 'ending' ? 'ending' : 'story',
            'is_start'    => (int) $s['is_start'] === 1,
            'depth'       => $level,
            'choice_label' => $viaEdge !== null ? (string) $viaEdge['label'] : null,
            'child_count' => count($kids),
            'has_more'    => $hasMore,
            'children'    => $children,
        ];
        if ($manage) {
            $node['state']  = $state;
            $node['label']  = self::LABELS[$state] ?? ucfirst($state);
            $node['locked'] = (int) ($s['is_locked'] ?? 0) === 1;
        }
        return $node;
    }

    // ------------------------------------------------------------------
    // Search
    // ------------------------------------------------------------------

    /**
     * Title search across the whole story, with the path back to the
     * opening scene so a hit can be located in a collapsed outline.
     *
     * @param list<array<string,mixed>> $scenes
     * @param array<int,array<string,mixed>> $byId
     * @param array<int,int> $parentOf
     * @return list<array<string,mixed>>
     */
    private function search(
        string $q,
        array $scenes,
        array $byId,
        array $parentOf,
        ?int $rootId,
        bool $manage,
    ): array {
        $needle = mb_strtolower($q);
        $out = [];
        foreach ($scenes as $s) {
            $haystack = mb_strtolower((string) $s['title']);
            if (!str_contains($haystack, $needle)) continue;

            $id   = (int) $s['id'];
            $path = [];
            $walk = $id;
            $guard = 0;
            while (isset($parentOf[$walk]) && $guard++ < 200) {
                $walk = $parentOf[$walk];
                array_unshift($path, (string) $byId[$walk]['title']);
            }
            $entry = [
                'id'      => (string) $s['slug'],
                'title'   => (string) $s['title'],
                'number'  => (int) $s['scene_number'],
                'type'    => (string) $s['scene_type'] === 'ending' ? 'ending' : 'story',
                'path'    => $path,
                'reachable' => $rootId === null ? false : ($id === $rootId || isset($parentOf[$id])),
            ];
            if ($manage) {
                $entry['state'] = (string) $s['state'];
                $entry['label'] = self::LABELS[(string) $s['state']] ?? (string) $s['state'];
            }
            $out[] = $entry;
            if (count($out) >= self::MAX_MATCHES) break;
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Validation
    // ------------------------------------------------------------------

    /**
     * @param array<int,array<string,mixed>> $visible scenes the manager sees
     * @param list<array<string,mixed>> $edges
     * @return list<array<string,mixed>>
     */
    private function collectIssues(int $adventureId, array $visible, array $edges): array
    {
        $issues = [];

        // Every scene, regardless of state — validation is never filtered.
        $all = $this->scenes($adventureId, true);
        $byId = [];
        foreach ($all as $s) $byId[(int) $s['id']] = $s;

        $childrenOf = [];
        $parents    = [];
        foreach ($edges as $e) {
            $from = (int) $e['scene_id'];
            $to   = (int) $e['target_scene_id'];
            if (!isset($byId[$from])) continue;

            if (!isset($byId[$to])) {
                $issues[] = $this->issue(
                    'missing_destination', 'error', $byId[$from],
                    'The choice “' . $e['label'] . '” points at a scene that no longer exists.',
                    (string) $e['label'],
                );
                continue;
            }
            $childrenOf[$from][] = $e;
            $parents[$to][] = $from;

            if ((string) $byId[$from]['state'] === 'published'
                && (string) $byId[$to]['state'] !== 'published') {
                $issues[] = $this->issue(
                    'hidden_destination', 'error', $byId[$from],
                    'The published choice “' . $e['label'] . '” leads to “'
                        . $byId[$to]['title'] . '”, which is ' . strtolower(
                            self::LABELS[(string) $byId[$to]['state']] ?? 'not published',
                        ) . '. Readers reach a dead end here.',
                    (string) $e['label'],
                );
            }
        }

        foreach ($all as $s) {
            $id   = (int) $s['id'];
            $kids = $childrenOf[$id] ?? [];

            // Empty scene.
            $text = trim((string) ($s['body_plain'] ?? ''));
            if ($text === '') $text = trim(strip_tags((string) $s['body']));
            if ($text === '') {
                $issues[] = $this->issue('empty_scene', 'error', $s, 'This scene has no text yet.');
            }

            // Duplicate sibling choices.
            $seen = [];
            foreach ($kids as $e) {
                $key = preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower((string) $e['label'])) ?? '';
                $key = trim((string) $key);
                if ($key === '') continue;
                if (isset($seen[$key])) {
                    $issues[] = $this->issue(
                        'duplicate_sibling_choice', 'warning', $s,
                        'Two choices on this scene read the same: “' . $e['label'] . '”.',
                        (string) $e['label'],
                    );
                    continue;
                }
                $seen[$key] = true;
            }

            // Published, not an ending, and nowhere to go.
            if ((string) $s['state'] === 'published'
                && (string) $s['scene_type'] !== 'ending'
                && $kids === []) {
                $issues[] = $this->issue(
                    'dead_end', 'error', $s,
                    'This published scene is not an ending but offers no choices.',
                );
            }

            // Tree rule: one parent per non-root scene.
            $count = count($parents[$id] ?? []);
            if ((int) $s['is_start'] === 1 && $count > 0) {
                $issues[] = $this->issue(
                    'invalid_parent', 'error', $s,
                    'The opening scene is the destination of a choice. The opening scene must have no parent.',
                );
            }
            if ((int) $s['is_start'] !== 1 && $count > 1) {
                $issues[] = $this->issue(
                    'invalid_parent', 'error', $s,
                    'This scene is the destination of ' . $count
                        . ' choices. Every scene except the opening one must have exactly one parent.',
                );
            }
        }

        // Reachability and depth, from the opening scene down.
        $rootId = $this->rootId($all);
        if ($rootId === null) {
            $issues[] = [
                'code' => 'invalid_parent', 'severity' => 'error',
                'scene' => null, 'scene_title' => null, 'choice' => null,
                'message' => 'This adventure has no opening scene.',
            ];
        } else {
            $depthOf  = [$rootId => 0];
            $queue    = [$rootId];
            $seen     = [$rootId => true];
            $deepest  = 0;
            while ($queue !== []) {
                $cur = array_shift($queue);
                foreach ($childrenOf[$cur] ?? [] as $e) {
                    $to = (int) $e['target_scene_id'];
                    if (isset($seen[$to])) continue; // loop / cross-link, reported above
                    $seen[$to]   = true;
                    $depthOf[$to] = $depthOf[$cur] + 1;
                    $deepest = max($deepest, $depthOf[$to]);
                    $queue[] = $to;
                }
            }
            foreach ($all as $s) {
                if (!isset($seen[(int) $s['id']])) {
                    $issues[] = $this->issue(
                        'unreachable_scene', 'warning', $s,
                        'No path from the opening scene reaches this scene.',
                    );
                }
            }
            if ($deepest > self::DEPTH_LIMIT) {
                $issues[] = [
                    'code' => 'excessive_depth', 'severity' => 'warning',
                    'scene' => null, 'scene_title' => null, 'choice' => null,
                    'message' => 'The longest path is ' . $deepest . ' scenes deep, past the '
                        . self::DEPTH_LIMIT . '-scene guideline. Very deep branches are hard to read and to review.',
                ];
            }
        }

        unset($visible);
        return $issues;
    }

    /**
     * @param array<string,mixed> $scene
     * @return array<string,mixed>
     */
    private function issue(
        string $code,
        string $severity,
        array $scene,
        string $message,
        ?string $choice = null,
    ): array {
        return [
            'code'        => $code,
            'severity'    => $severity,
            'scene'       => (string) $scene['slug'],
            'scene_title' => (string) $scene['title'],
            'choice'      => $choice,
            'message'     => $message,
        ];
    }

    /**
     * @param list<array<string,mixed>> $issues
     * @return array<string,int>
     */
    private function summarise(array $issues): array
    {
        $out = ['errors' => 0, 'warnings' => 0, 'total' => count($issues)];
        foreach ($issues as $i) {
            if (($i['severity'] ?? '') === 'error') $out['errors']++;
            else $out['warnings']++;
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Data access
    // ------------------------------------------------------------------

    /** @return array<string,mixed>|null */
    private function adventureRow(string $slug): ?array
    {
        $s = $this->pdo->prepare('SELECT * FROM adventures WHERE slug = :s LIMIT 1');
        $s->execute([':s' => $slug]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        return $r === false ? null : $r;
    }

    /** @return list<array<string,mixed>> */
    private function scenes(int $adventureId, bool $includeUnpublished): array
    {
        $sql = 'SELECT id, slug, scene_number, chapter, title, body, body_plain, scene_type,
                       state, is_start, is_locked
                  FROM scenes WHERE adventure_id = :a';
        if (!$includeUnpublished) $sql .= " AND state = 'published'";
        $sql .= ' ORDER BY scene_number, id';
        $s = $this->pdo->prepare($sql);
        $s->execute([':a' => $adventureId]);
        return $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return list<array<string,mixed>> */
    private function edges(int $adventureId): array
    {
        $s = $this->pdo->prepare(
            'SELECT c.id, c.scene_id, c.target_scene_id, c.label, c.position
               FROM choices c
               JOIN scenes s ON s.id = c.scene_id
              WHERE s.adventure_id = :a
              ORDER BY c.scene_id, c.position, c.id'
        );
        $s->execute([':a' => $adventureId]);
        return $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @param list<array<string,mixed>> $scenes */
    private function rootId(array $scenes): ?int
    {
        foreach ($scenes as $s) {
            if ((int) $s['is_start'] === 1) return (int) $s['id'];
        }
        return $scenes === [] ? null : (int) $scenes[0]['id'];
    }

    /**
     * @param array<int,list<array{edge:array<string,mixed>,child:int}>> $childrenOf
     * @return array<int,bool>
     */
    private function reachableIds(int $rootId, array $childrenOf): array
    {
        $seen  = [$rootId => true];
        $queue = [$rootId];
        while ($queue !== []) {
            $cur = array_shift($queue);
            foreach ($childrenOf[$cur] ?? [] as $k) {
                if (isset($seen[$k['child']])) continue;
                $seen[$k['child']] = true;
                $queue[] = $k['child'];
            }
        }
        return $seen;
    }
}
