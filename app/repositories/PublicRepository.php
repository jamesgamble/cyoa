<?php
/**
 * PublicRepository — read-only queries for the public API.
 *
 * Every query in this class enforces the v0.10.0 visibility rules:
 *
 *   - draft, suspended adventures are never returned
 *   - unlisted adventures are returned by slug but are excluded from
 *     the Discover list
 *   - hidden and draft scenes are never returned
 *   - choices whose destination is not a published, readable scene
 *     are filtered out (so callers can never learn that an
 *     unpublished target exists)
 *
 * The class deals exclusively in plain associative arrays shaped for
 * JSON output. Callers are responsible for `json_encode`ing the
 * result and setting HTTP status codes.
 */

declare(strict_types=1);

namespace App;

use PDO;

final class PublicRepository
{
    /** Adventure states considered publicly readable. */
    public const PUBLIC_STATES = ['published', 'on-hold', 'complete', 'archived'];

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    // ------------------------------------------------------------------
    // Discover
    // ------------------------------------------------------------------

    /**
     * Return the Discover list, applying the requested filters and
     * sort. Unknown filter values are treated as "no filter", matching
     * the existing frontend behaviour where malformed URL state is
     * ignored rather than error-ing.
     *
     * @param array{
     *   q?: string,
     *   genre?: string,
     *   rating?: string,
     *   status?: string,
     *   contributions?: string,
     *   sort?: string
     * } $filters
     * @return array<int, array<string, mixed>>
     */
    public function discover(array $filters): array
    {
        $where = [
            "a.visibility = 'public'",
            "a.state IN ('" . implode("','", self::PUBLIC_STATES) . "')",
        ];
        $params = [];

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(LOWER(a.title) LIKE :q OR LOWER(a.synopsis) LIKE :q OR LOWER(a.description) LIKE :q OR LOWER(u.display_name) LIKE :q)';
            $params[':q'] = '%' . strtolower($q) . '%';
        }

        $genre = (string) ($filters['genre'] ?? '');
        if ($genre !== '') {
            $where[] = 'a.genre = :genre';
            $params[':genre'] = $genre;
        }

        $rating = (string) ($filters['rating'] ?? '');
        if ($rating !== '' && in_array($rating, ['everyone','teen','mature'], true)) {
            $where[] = 'a.content_rating = :rating';
            $params[':rating'] = $rating;
        }

        $status = (string) ($filters['status'] ?? '');
        if ($status !== '' && in_array($status, self::PUBLIC_STATES, true)) {
            $where[] = 'a.state = :status';
            $params[':status'] = $status;
        }

        $contrib = (string) ($filters['contributions'] ?? '');
        if ($contrib === 'open') {
            $where[] = "a.contribution_state IN ('immediate','approval')";
        } elseif ($contrib === 'closed') {
            $where[] = "a.contribution_state = 'closed'";
        } elseif (in_array($contrib, ['immediate','approval'], true)) {
            $where[] = 'a.contribution_state = :contrib';
            $params[':contrib'] = $contrib;
        }

        $sort = (string) ($filters['sort'] ?? '');
        switch ($sort) {
            case 'title':
                $orderBy = 'a.title COLLATE NOCASE ASC';
                break;
            case 'oldest':
                $orderBy = 'a.updated_at ASC';
                break;
            case 'updated':
            default:
                $orderBy = 'a.updated_at DESC';
        }

        $sql =
            'SELECT a.*, u.display_name AS author_name ' .
            'FROM adventures a JOIN users u ON u.id = a.author_id ' .
            'WHERE ' . implode(' AND ', $where) . ' ' .
            'ORDER BY ' . $orderBy;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        return array_map(fn ($r) => $this->summarizeAdventure($r), $rows);
    }

    // ------------------------------------------------------------------
    // Adventure by slug
    // ------------------------------------------------------------------

    /**
     * Return the full adventure record for a slug, or null if the
     * adventure is not publicly readable (draft, suspended, or
     * unknown slug).
     *
     * Unlisted adventures ARE returned here — the "unlisted" rule
     * only affects the Discover list.
     */
    public function adventureBySlug(string $slug): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.*, u.display_name AS author_name ' .
            'FROM adventures a JOIN users u ON u.id = a.author_id ' .
            'WHERE a.slug = :slug'
        );
        $stmt->execute([':slug' => $slug]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        if (!in_array($row['state'], self::PUBLIC_STATES, true)) {
            return null;
        }
        $summary = $this->summarizeAdventure($row);
        $summary['contentWarnings'] = $this->warningsFor((int) $row['id']);
        $summary['writingGuidelines'] = $row['writing_guidelines'] !== null
            ? (string) $row['writing_guidelines'] : null;
        $summary['description'] = $row['description'] !== null
            ? (string) $row['description'] : null;
        // Scene / ending counts derived from published scenes so the
        // public view matches what a reader can actually reach.
        $counts = $this->publishedSceneCounts((int) $row['id']);
        $summary['sceneCount']  = $counts['scenes'];
        $summary['endingCount'] = $counts['endings'];
        return $summary;
    }

    // ------------------------------------------------------------------
    // Scene
    // ------------------------------------------------------------------

    /**
     * Return a published scene inside a public adventure. Returns null
     * if the adventure is not public, the scene does not exist, or
     * the scene is not published.
     *
     * Choices are filtered: any choice whose destination scene is
     * missing or not published is dropped from the response.
     */
    public function scene(string $adventureSlug, string $sceneSlug): ?array
    {
        $adv = $this->publicAdventureRow($adventureSlug);
        if (!$adv) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT * FROM scenes ' .
            "WHERE adventure_id = :aid AND slug = :slug AND state = 'published'"
        );
        $stmt->execute([':aid' => $adv['id'], ':slug' => $sceneSlug]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        return $this->hydrateScene($adventureSlug, (int) $adv['id'], $row);
    }

    // ------------------------------------------------------------------
    // Public outline
    // ------------------------------------------------------------------

    /**
     * Return a summary of the published scene graph for an adventure:
     * scene ids, titles, chapter labels, whether they are endings,
     * and their choice edges (only edges pointing to other published
     * scenes are included).
     */
    public function outline(string $adventureSlug): ?array
    {
        $adv = $this->publicAdventureRow($adventureSlug);
        if (!$adv) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT id, slug, scene_number, chapter, title, scene_type, is_start ' .
            'FROM scenes ' .
            "WHERE adventure_id = :aid AND state = 'published' " .
            'ORDER BY scene_number ASC'
        );
        $stmt->execute([':aid' => $adv['id']]);
        $scenes = $stmt->fetchAll();

        $publishedIds = [];
        foreach ($scenes as $s) {
            $publishedIds[(int) $s['id']] = (string) $s['slug'];
        }

        $result = [];
        foreach ($scenes as $s) {
            $edgeStmt = $this->pdo->prepare(
                'SELECT c.target_scene_id, c.label ' .
                'FROM choices c ' .
                'WHERE c.scene_id = :sid ORDER BY c.position ASC, c.id ASC'
            );
            $edgeStmt->execute([':sid' => $s['id']]);
            $edges = [];
            foreach ($edgeStmt->fetchAll() as $e) {
                $tid = (int) $e['target_scene_id'];
                if (!isset($publishedIds[$tid])) {
                    // Never expose an unpublished destination.
                    continue;
                }
                $edges[] = [
                    'label'  => (string) $e['label'],
                    'target' => $publishedIds[$tid],
                ];
            }
            $result[] = [
                'id'          => (string) $s['slug'],
                'sceneNumber' => (int) $s['scene_number'],
                'chapter'     => $s['chapter'] !== null ? (string) $s['chapter'] : null,
                'title'       => (string) $s['title'],
                'isEnding'    => $s['scene_type'] === 'ending',
                'isStart'     => ((int) $s['is_start']) === 1,
                'choices'     => $edges,
            ];
        }

        return [
            'adventureSlug' => $adventureSlug,
            'scenes'        => $result,
        ];
    }

    // ------------------------------------------------------------------
    // Internal helpers
    // ------------------------------------------------------------------

    private function publicAdventureRow(string $slug): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM adventures WHERE slug = :slug'
        );
        $stmt->execute([':slug' => $slug]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        if (!in_array($row['state'], self::PUBLIC_STATES, true)) {
            return null;
        }
        return $row;
    }

    private function warningsFor(int $adventureId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT label FROM content_warnings ' .
            'WHERE adventure_id = :aid ORDER BY position ASC, id ASC'
        );
        $stmt->execute([':aid' => $adventureId]);
        return array_map(static fn ($r) => (string) $r['label'], $stmt->fetchAll());
    }

    private function publishedSceneCounts(int $adventureId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' .
            "SUM(CASE WHEN state='published' THEN 1 ELSE 0 END) AS scenes, " .
            "SUM(CASE WHEN state='published' AND scene_type='ending' THEN 1 ELSE 0 END) AS endings " .
            'FROM scenes WHERE adventure_id = :aid'
        );
        $stmt->execute([':aid' => $adventureId]);
        $row = $stmt->fetch();
        return [
            'scenes'  => (int) ($row['scenes']  ?? 0),
            'endings' => (int) ($row['endings'] ?? 0),
        ];
    }

    private function hydrateScene(string $adventureSlug, int $adventureId, array $row): array
    {
        // Fetch published sibling slugs for choice filtering.
        $siblings = $this->pdo->prepare(
            'SELECT id, slug FROM scenes ' .
            "WHERE adventure_id = :aid AND state = 'published'"
        );
        $siblings->execute([':aid' => $adventureId]);
        $publishedById = [];
        foreach ($siblings->fetchAll() as $s) {
            $publishedById[(int) $s['id']] = (string) $s['slug'];
        }

        $choicesStmt = $this->pdo->prepare(
            'SELECT target_scene_id, label FROM choices ' .
            'WHERE scene_id = :sid ORDER BY position ASC, id ASC'
        );
        $choicesStmt->execute([':sid' => $row['id']]);
        $choices = [];
        foreach ($choicesStmt->fetchAll() as $c) {
            $tid = (int) $c['target_scene_id'];
            if (!isset($publishedById[$tid])) continue;
            $choices[] = [
                'label'  => (string) $c['label'],
                'target' => $publishedById[$tid],
            ];
        }

        $scene = [
            'id'            => (string) $row['slug'],
            'adventureSlug' => $adventureSlug,
            'sceneNumber'   => (int) $row['scene_number'],
            'chapter'       => $row['chapter'] !== null ? (string) $row['chapter'] : null,
            'title'         => (string) $row['title'],
            'body'          => (string) $row['body'],
            'choices'       => $choices,
            'isStart'       => ((int) $row['is_start']) === 1,
        ];

        if ($row['scene_type'] === 'ending') {
            $scene['ending'] = [
                'title' => (string) ($row['ending_title'] ?? $row['title']),
                'kind'  => $row['ending_kind'] !== null ? (string) $row['ending_kind'] : null,
                'body'  => (string) ($row['ending_body'] ?? $row['body']),
            ];
            // An ending scene has no choices by definition.
            $scene['choices'] = [];
        }

        return $scene;
    }

    private function summarizeAdventure(array $row): array
    {
        return [
            'slug'              => (string) $row['slug'],
            'title'             => (string) $row['title'],
            'author'            => (string) $row['author_name'],
            'synopsis'          => $row['synopsis'] !== null ? (string) $row['synopsis'] : null,
            'description'       => $row['description'] !== null ? (string) $row['description'] : null,
            'genre'             => $row['genre'] !== null ? (string) $row['genre'] : null,
            'contentRating'     => (string) $row['content_rating'],
            'status'            => (string) $row['state'],
            'storyStatus'       => $this->storyStatusFromState((string) $row['state']),
            'contributionState' => (string) $row['contribution_state'],
            'contributionsOpen' => in_array($row['contribution_state'], ['immediate','approval'], true),
            'updatedAtIso'      => (string) $row['updated_at'],
        ];
    }

    private function storyStatusFromState(string $state): string
    {
        return match ($state) {
            'complete' => 'complete',
            'on-hold'  => 'on-hold',
            'archived' => 'archived',
            default    => 'in-progress',
        };
    }
}
