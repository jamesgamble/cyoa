<?php
/**
 * scripts/seed-dev-data.php
 *
 * Idempotent development seed data for the public adventure model
 * (v0.10.0). Populates:
 *
 *   - Twelve adventures that mirror the frontend Discover fixtures
 *     so the API and the fallback fixtures show the same library.
 *   - Two adventures with additional visibility characteristics —
 *     one suspended, one unlisted — to exercise the read-side rules.
 *   - Full scene / choice content for "The Lantern Road" and
 *     "The Inn at the Crossing" mirroring frontend/src/data/scenes.ts.
 *   - A hidden scene inside "The Lantern Road" plus a choice pointing
 *     to it, so the "cannot expose unpublished destinations" rule can
 *     be verified end-to-end.
 *
 * The script is safe to run more than once: it clears the public
 * content tables inside a transaction before inserting, but never
 * touches `schema_migrations`.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Database;
use App\Migrator;

$pdo = Database::open();

// Make sure the schema is present before we seed.
$migrator = new Migrator($pdo);
$migrator->migrate();

$pdo->beginTransaction();
try {
    // Wipe in FK-safe order.
    $pdo->exec('DELETE FROM choices');
    $pdo->exec('DELETE FROM content_warnings');
    $pdo->exec('DELETE FROM scenes');
    $pdo->exec('DELETE FROM adventures');
    $pdo->exec('DELETE FROM users');

    // ── Authors ────────────────────────────────────────────────
    $authors = [
        'marisol'  => 'Marisol Vega',
        'rowan'    => 'Rowan Ash',
        'fen'      => 'Fen Okafor',
        'ines'     => 'Ines Marchetti',
        'jonas'    => 'Jonas Bell',
        'ada'      => 'Ada Meunier',
        'priya'    => 'Priya Ndlovu',
        'otto'     => 'Otto Lindqvist',
        'camille'  => 'Camille Doré',
        'bo'       => 'Bo Nakamura',
        'hana'     => 'Hana Petrescu',
        'sasha'    => 'Sasha Belov',
        'quinn'    => 'Quinn Alder',
        'noor'     => 'Noor Rahim',
    ];
    $authorId = [];
    $insUser = $pdo->prepare(
        'INSERT INTO users (username, display_name) VALUES (:u, :d)'
    );
    foreach ($authors as $u => $d) {
        $insUser->execute([':u' => $u, ':d' => $d]);
        $authorId[$u] = (int) $pdo->lastInsertId();
    }

    // ── Adventures ─────────────────────────────────────────────
    $insAdv = $pdo->prepare(
        'INSERT INTO adventures (
            slug, title, author_id, synopsis, description, genre,
            content_rating, state, visibility, contribution_state,
            writing_guidelines, created_at, updated_at
         ) VALUES (
            :slug, :title, :author_id, :synopsis, :description, :genre,
            :content_rating, :state, :visibility, :contribution_state,
            :writing_guidelines, :ts, :ts
         )'
    );

    $adventures = [
        ['the-lantern-road', 'The Lantern Road', 'marisol', 'fantasy', 'everyone',
         'published', 'public', 'approval',
         "A cartographer's apprentice inherits a lantern that only lights paths that have not yet been walked.",
         "Elin has walked the same trade road for six years. When her mentor dies, she inherits a brass lantern that will only light a path no living cartographer has drawn.",
         'Keep the geography internally consistent. New paths should lead somewhere; dead ends are for endings, not for scenes. No graphic violence.',
         ['Grief', 'Non-graphic peril'],
         '2026-07-16T09:00:00Z'],
        ['the-clockmakers-daughter', "The Clockmaker's Daughter", 'rowan', 'mystery', 'everyone',
         'published', 'public', 'immediate',
         'A village keeps time by a single tower. When its hands stop, only one apprentice has a key to the workings.',
         'The village of Hesse has kept its hours by the tower clock for four hundred years. When the hands stop, only Ivo can climb the shaft.',
         'Every scene should turn on a small mechanical detail. Prefer craft to spectacle.',
         ['Mild claustrophobia'],
         '2026-07-18T14:00:00Z'],
        ['salt-and-signal', 'Salt and Signal', 'fen', 'mystery', 'teen',
         'complete', 'public', 'closed',
         'A lighthouse keeper receives a letter that could not have been posted. Every reply changes the shore.',
         'A lighthouse keeper on the south coast receives a letter dated four years before her posting.',
         null,
         ['Grief', 'Implied bereavement'],
         '2026-07-17T11:00:00Z'],
        ['the-orchard-below', 'The Orchard Below', 'ines', 'folklore', 'teen',
         'published', 'public', 'approval',
         "The town's founders planted an orchard the wrong way up. Its fruit has begun to ripen.",
         "Beneath the founders' hill, the orchard grows the wrong way up.",
         'Contributions should preserve the folkloric register — plain, unhurried sentences.',
         ['Body horror (mild)', 'Ritual imagery'],
         '2026-07-15T08:00:00Z'],
        ['a-quiet-cartography', 'A Quiet Cartography', 'jonas', 'fantasy', 'everyone',
         'on-hold', 'public', 'closed',
         'A retired surveyor is asked to map a house whose rooms do not stay in place.',
         'Sten has retired to a shore town to forget his last commission.',
         null,
         ['Memory loss'],
         '2026-07-12T10:00:00Z'],
        ['the-second-shore', 'The Second Shore', 'ada', 'science-fiction', 'teen',
         'published', 'public', 'immediate',
         'A generation ship approaches a planet already claimed by another version of its own crew.',
         'The Meridian has been in transit for two hundred and forty years.',
         'New branches may add crew members and rooms aboard either ship.',
         ['Existential themes'],
         '2026-07-14T12:00:00Z'],
        ['the-hollow-suite', 'The Hollow Suite', 'priya', 'horror', 'mature',
         'draft', 'public', 'closed',
         'The concierge of an old hotel holds the room key for a suite that no floor plan admits.',
         'The Grand Meridien has forty-two rooms across four floors, and one suite that no floor plan admits.',
         null,
         ['Psychological horror', 'Isolation', 'Non-graphic violence'],
         '2026-07-13T18:00:00Z'],
        ['letters-from-the-canal', 'Letters from the Canal', 'otto', 'historical', 'everyone',
         'published', 'public', 'approval',
         'A canal boat carries a correspondence between two houses that never write directly.',
         'The Ulla and the Vederstad have never written directly.',
         'Contributions should read as period letters.',
         [],
         '2026-07-09T09:00:00Z'],
        ['a-fair-hand', 'A Fair Hand', 'camille', 'romance', 'teen',
         'complete', 'public', 'immediate',
         'Two apprentices share a single letter of introduction and only one interview.',
         "Iren and Célie share a letter of introduction, a set of practice pieces, and a single interview.",
         "Branches should keep both apprentices' voices distinct. No explicit content.",
         [],
         '2026-07-05T09:00:00Z'],
        ['the-market-of-quiet-things', 'The Market of Quiet Things', 'bo', 'contemporary', 'everyone',
         'complete', 'public', 'closed',
         'A weekly market sells objects that once belonged to lives never lived.',
         'Every seventh evening the Quiet Market opens under the river bridge.',
         null,
         ['Melancholy themes'],
         '2026-07-08T09:00:00Z'],
        ['beneath-the-observatory', 'Beneath the Observatory', 'hana', 'science-fiction', 'everyone',
         'draft', 'public', 'approval',
         'Under an old observatory sits a second telescope pointed at nothing anyone can name.',
         'The Krajen observatory has one telescope on its roof and another, older, sealed in its cellar.',
         'Draft adventure — expect editorial changes. Contribute at your own risk.',
         [],
         '2026-07-18T20:00:00Z'],
        ['the-inn-at-the-crossing', 'The Inn at the Crossing', 'sasha', 'fantasy', 'everyone',
         'archived', 'public', 'closed',
         'Three roads meet at an inn whose ledger records travellers before they arrive.',
         'The Three Kings stands where the old northern roads meet.',
         null,
         [],
         '2026-06-18T09:00:00Z'],
        // Additional coverage rows:
        ['the-suspended-rehearsal', 'The Suspended Rehearsal', 'quinn', 'contemporary', 'teen',
         'suspended', 'public', 'closed',
         'A theatre rehearsal was suspended for review; the ledger is on hold.',
         'Under moderation review.',
         null,
         [],
         '2026-07-01T12:00:00Z'],
        ['unlisted-notebook', 'Unlisted Notebook', 'noor', 'contemporary', 'everyone',
         'published', 'unlisted', 'closed',
         'A private notebook, published unlisted for people who already have the link.',
         'Readable by direct link, but not surfaced on Discover.',
         null,
         [],
         '2026-07-10T09:00:00Z'],
    ];

    $advId = [];
    $insWarn = $pdo->prepare(
        'INSERT INTO content_warnings (adventure_id, label, position) VALUES (:aid, :label, :pos)'
    );
    foreach ($adventures as $a) {
        [$slug, $title, $author, $genre, $rating, $state, $visibility, $contrib,
         $syn, $desc, $guidelines, $warnings, $ts] = $a;

        $insAdv->execute([
            ':slug'               => $slug,
            ':title'              => $title,
            ':author_id'          => $authorId[$author],
            ':synopsis'           => $syn,
            ':description'        => $desc,
            ':genre'              => $genre,
            ':content_rating'     => $rating,
            ':state'              => $state,
            ':visibility'         => $visibility,
            ':contribution_state' => $contrib,
            ':writing_guidelines' => $guidelines,
            ':ts'                 => $ts,
        ]);
        $advId[$slug] = (int) $pdo->lastInsertId();

        foreach ($warnings as $i => $w) {
            $insWarn->execute([
                ':aid' => $advId[$slug], ':label' => $w, ':pos' => $i,
            ]);
        }
    }

    // ── Scenes: The Lantern Road ────────────────────────────────
    seed_lantern_road($pdo, $advId['the-lantern-road']);

    // ── Scenes: The Inn at the Crossing ─────────────────────────
    seed_inn($pdo, $advId['the-inn-at-the-crossing']);

    $pdo->commit();
} catch (\Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'seed failed: ' . $e->getMessage() . "\n");
    exit(1);
}

echo "seeded public adventure data\n";

// ---------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------

function insert_scene(PDO $pdo, array $r): int
{
    $stmt = $pdo->prepare(
        'INSERT INTO scenes (
            adventure_id, slug, scene_number, chapter, title, body,
            scene_type, state, ending_title, ending_kind, ending_body, is_start
         ) VALUES (
            :aid, :slug, :num, :chapter, :title, :body,
            :type, :state, :et, :ek, :eb, :start
         )'
    );
    $stmt->execute([
        ':aid'     => $r['adventure_id'],
        ':slug'    => $r['slug'],
        ':num'     => $r['scene_number'],
        ':chapter' => $r['chapter'] ?? null,
        ':title'   => $r['title'],
        ':body'    => $r['body'],
        ':type'    => $r['scene_type'] ?? 'story',
        ':state'   => $r['state'] ?? 'published',
        ':et'      => $r['ending_title'] ?? null,
        ':ek'      => $r['ending_kind']  ?? null,
        ':eb'      => $r['ending_body']  ?? null,
        ':start'   => !empty($r['is_start']) ? 1 : 0,
    ]);
    return (int) $pdo->lastInsertId();
}

function insert_choice(PDO $pdo, int $sceneId, int $targetId, string $label, int $pos): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO choices (scene_id, target_scene_id, label, position) ' .
        'VALUES (:s, :t, :l, :p)'
    );
    $stmt->execute([':s' => $sceneId, ':t' => $targetId, ':l' => $label, ':p' => $pos]);
}

function seed_lantern_road(PDO $pdo, int $aid): void
{
    $ids = [];
    $ids['start'] = insert_scene($pdo, [
        'adventure_id' => $aid, 'slug' => 'start', 'scene_number' => 1,
        'chapter' => 'Chapter I — Setting Out',
        'title' => 'The tin lantern on the sill',
        'body' => "You wake before the harbour bells. A tin lantern sits on the kitchen sill, its flame low and steady, exactly where you did not leave it.\n\nOutside, the lantern-road runs east into fog and west toward the canal bridge. Someone has been walking it in the night.",
        'is_start' => true,
    ]);
    $ids['tin-lantern'] = insert_scene($pdo, [
        'adventure_id' => $aid, 'slug' => 'tin-lantern', 'scene_number' => 2,
        'chapter' => 'Chapter I — Setting Out',
        'title' => 'East, where the fog thins',
        'body' => "The lantern throws a small circle of warm light across the wet stones. Half a mile in, the road forks: a narrow track drops toward the shore, and a broader lane climbs toward the market square, already awake.",
    ]);
    $ids['canal-bridge'] = insert_scene($pdo, [
        'adventure_id' => $aid, 'slug' => 'canal-bridge', 'scene_number' => 3,
        'chapter' => 'Chapter I — Setting Out',
        'title' => 'The canal bridge before dawn',
        'body' => "The bridge is older than the town's own charter. Water moves under it in slow black lines. Someone has left a paper boat on the parapet, ink still wet.\n\nA second lane cuts up from the towpath toward the market square. You could also lean, just a little, over the parapet.",
    ]);
    $ids['market'] = insert_scene($pdo, [
        'adventure_id' => $aid, 'slug' => 'market', 'scene_number' => 4,
        'chapter' => 'Chapter II — What the Town Kept',
        'title' => 'The market square, half awake',
        'body' => "Stallholders are stacking crates. A woman is selling tea from a copper urn. She looks at your lantern for a long moment before she pours a cup and refuses your coin.\n\n\"Take the road back the way you did not come,\" she says. \"Or drink, and go home.\"",
    ]);
    $ids['quiet-shore'] = insert_scene($pdo, [
        'adventure_id' => $aid, 'slug' => 'quiet-shore', 'scene_number' => 5,
        'chapter' => 'Chapter II — What the Town Kept',
        'title' => 'The lighthouse, unlit',
        'body' => "The shore path ends at a lighthouse that has not been lit in a generation. Its door stands open.",
        'scene_type' => 'ending',
        'ending_title' => 'The lighthouse, relit',
        'ending_kind'  => 'A quiet ending',
        'ending_body'  => "You place the tin lantern in the great lamp and step back. Its small flame climbs the mirrors and, impossibly, is enough.",
    ]);
    $ids['cold-water-ending'] = insert_scene($pdo, [
        'adventure_id' => $aid, 'slug' => 'cold-water-ending', 'scene_number' => 6,
        'chapter' => 'Chapter II — What the Town Kept',
        'title' => 'The paper boat',
        'body' => "You lean over the parapet. The ink on the paper boat runs, and rearranges, and spells your own name.",
        'scene_type' => 'ending',
        'ending_title' => 'Cold water, small flame',
        'ending_kind'  => 'A cold ending',
        'ending_body'  => "Some doors close because you leaned too far to read them.",
    ]);
    $ids['quiet-return-ending'] = insert_scene($pdo, [
        'adventure_id' => $aid, 'slug' => 'quiet-return-ending', 'scene_number' => 7,
        'chapter' => 'Chapter II — What the Town Kept',
        'title' => 'The tea and the road home',
        'body' => "The tea tastes of nothing you can name and of every kitchen you have ever known.",
        'scene_type' => 'ending',
        'ending_title' => 'The quiet return',
        'ending_kind'  => 'A gentle ending',
        'ending_body'  => "Not every walk has to end at the lighthouse.",
    ]);
    // Hidden scene — must not appear in outline or scene endpoints,
    // and the choice pointing to it must be filtered out.
    $ids['hidden-cellar'] = insert_scene($pdo, [
        'adventure_id' => $aid, 'slug' => 'hidden-cellar', 'scene_number' => 8,
        'title' => 'A cellar not yet published',
        'body'  => 'This scene is not published and must never be exposed.',
        'state' => 'hidden',
    ]);

    insert_choice($pdo, $ids['start'], $ids['tin-lantern'], 'Take the lantern and follow the east road', 0);
    insert_choice($pdo, $ids['start'], $ids['canal-bridge'], 'Walk west to the canal bridge instead', 1);
    insert_choice($pdo, $ids['tin-lantern'], $ids['quiet-shore'], 'Follow the narrow track down to the quiet shore', 0);
    insert_choice($pdo, $ids['tin-lantern'], $ids['market'], 'Climb the lane to the market square', 1);
    insert_choice($pdo, $ids['canal-bridge'], $ids['market'], 'Climb up to the market square', 0);
    insert_choice($pdo, $ids['canal-bridge'], $ids['cold-water-ending'], 'Lean over the parapet and read the paper boat', 1);
    insert_choice($pdo, $ids['market'], $ids['canal-bridge'], 'Walk back down toward the canal bridge', 0);
    insert_choice($pdo, $ids['market'], $ids['quiet-return-ending'], 'Drink the tea and turn for home', 1);
    // Choice pointing at the hidden scene — must be filtered on read.
    insert_choice($pdo, $ids['tin-lantern'], $ids['hidden-cellar'], 'Descend into a cellar (hidden)', 2);
}

function seed_inn(PDO $pdo, int $aid): void
{
    $startId = insert_scene($pdo, [
        'adventure_id' => $aid, 'slug' => 'start', 'scene_number' => 1,
        'title' => 'The signboard, freshly painted',
        'body'  => "You arrive at the crossing after dark. The inn's signboard has been repainted since your last visit, though nobody in the taproom will admit to painting it.",
        'is_start' => true,
    ]);
    $endId = insert_scene($pdo, [
        'adventure_id' => $aid, 'slug' => 'innkeeper-ending', 'scene_number' => 2,
        'title' => "The innkeeper's answer",
        'body'  => "The innkeeper wipes her hands on her apron and does not meet your eye. \"Some signs paint themselves,\" she says.",
        'scene_type' => 'ending',
        'ending_title' => 'Signs that paint themselves',
        'ending_kind'  => 'A short ending',
        'ending_body'  => "You take a room for the night. In the morning the signboard reads a different name.",
    ]);
    insert_choice($pdo, $startId, $endId, 'Ask the innkeeper about the sign', 0);
}
