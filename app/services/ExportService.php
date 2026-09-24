<?php
/**
 * ExportService (v0.26.0) — owner/editor exports of an adventure.
 *
 * Formats: json, text, print (printable HTML), play (standalone
 * playable HTML). Every format is built from one allowlisted snapshot:
 * published scenes, choices between published scenes, endings,
 * metadata, content warnings, writing guidelines, and safe attribution
 * (display names only, anonymous contributions uncredited). Nothing
 * else — no emails, hashes, sessions, tokens, SMTP data, IPs, rate
 * limits, reports, private notes, activity, or filesystem paths — is
 * ever read into the snapshot, so it cannot leak into an export.
 */

declare(strict_types=1);

namespace App;

use PDO;

final class ExportService
{
    public const OK = 'ok';
    public const NOT_FOUND = 'not_found';
    public const FORBIDDEN = 'forbidden';
    public const BAD_FORMAT = 'bad_format';

    public const SCHEMA_VERSION = 1;
    public const FORMATS = ['json', 'text', 'print', 'play'];

    public function __construct(private PDO $pdo) {}

    /**
     * @return array{0:string,1:array{filename:string,mime:string,body:string}|null}
     */
    public function export(string $slug, string $format, ?int $userId, bool $isAdmin): array
    {
        if (!in_array($format, self::FORMATS, true)) return [self::BAD_FORMAT, null];
        $mod = new ModerationService($this->pdo);
        $adv = $mod->adventureBySlug($slug);
        if ($adv === null) return [self::NOT_FOUND, null];
        if (!$mod->canDecide($mod->roleFor((int) $adv['id'], $userId, $isAdmin))) {
            return [self::FORBIDDEN, null];
        }
        $snap = $this->snapshot((int) $adv['id']);
        $base = preg_replace('/[^a-z0-9-]+/', '-', strtolower((string) $snap['metadata']['slug'])) ?: 'adventure';
        return [self::OK, match ($format) {
            'json'  => ['filename' => "$base.json", 'mime' => 'application/json; charset=utf-8', 'body' => $this->toJson($snap)],
            'text'  => ['filename' => "$base.txt", 'mime' => 'text/plain; charset=utf-8', 'body' => $this->toText($snap)],
            'print' => ['filename' => "$base-print.html", 'mime' => 'text/html; charset=utf-8', 'body' => $this->toPrintHtml($snap)],
            'play'  => ['filename' => "$base-play.html", 'mime' => 'text/html; charset=utf-8', 'body' => $this->toPlayHtml($snap)],
        }];
    }

    /** @return array<string,mixed> */
    public function snapshot(int $adventureId): array
    {
        $a = $this->pdo->prepare(
            'SELECT a.slug, a.title, a.synopsis, a.description, a.genre, a.content_rating,
                    a.state, a.writing_guidelines, a.created_at, a.updated_at,
                    u.display_name AS owner_name
               FROM adventures a LEFT JOIN users u ON u.id = a.author_id WHERE a.id = :a'
        );
        $a->execute([':a' => $adventureId]);
        $adv = $a->fetch(PDO::FETCH_ASSOC) ?: [];

        $w = $this->pdo->prepare('SELECT code, label, details FROM content_warnings WHERE adventure_id = :a ORDER BY id');
        $w->execute([':a' => $adventureId]);
        $warnings = array_map(static fn ($r) => [
            'code' => (string) $r['code'], 'label' => (string) $r['label'],
            'details' => $r['details'] !== null && $r['details'] !== '' ? HtmlSanitizer::toPlainText((string) $r['details']) : null,
        ], $w->fetchAll(PDO::FETCH_ASSOC));

        $s = $this->pdo->prepare(
            "SELECT id, scene_number, chapter, title, body, scene_type, ending_title, ending_kind, ending_body, is_start
               FROM scenes WHERE adventure_id = :a AND state = 'published' ORDER BY scene_number, id"
        );
        $s->execute([':a' => $adventureId]);
        $scenes = [];
        $endings = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $id = (int) $r['id'];
            $isEnding = $r['scene_type'] === 'ending';
            $scenes[$id] = [
                'id' => $id, 'number' => (int) $r['scene_number'],
                'chapter' => $r['chapter'] !== null ? (string) $r['chapter'] : null,
                'title' => (string) $r['title'],
                'body_html' => HtmlSanitizer::sanitize((string) $r['body']),
                'is_start' => (int) $r['is_start'] === 1,
                'is_ending' => $isEnding, 'choices' => [],
            ];
            if ($isEnding) {
                $endings[] = [
                    'scene_id' => $id,
                    'title' => (string) ($r['ending_title'] ?? '') ?: (string) $r['title'],
                    'kind' => $r['ending_kind'] !== null ? (string) $r['ending_kind'] : null,
                    'body_html' => $r['ending_body'] ? HtmlSanitizer::sanitize((string) $r['ending_body']) : '',
                ];
            }
        }
        $c = $this->pdo->prepare(
            "SELECT c.id, c.scene_id, c.target_scene_id, c.label, c.position
               FROM choices c JOIN scenes s ON s.id = c.scene_id JOIN scenes t ON t.id = c.target_scene_id
              WHERE s.adventure_id = :a AND s.state = 'published' AND t.state = 'published'
              ORDER BY c.scene_id, c.position, c.id"
        );
        $c->execute([':a' => $adventureId]);
        $choices = [];
        foreach ($c->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $ch = ['id' => (int) $r['id'], 'from' => (int) $r['scene_id'], 'to' => (int) $r['target_scene_id'],
                   'text' => HtmlSanitizer::toPlainText((string) $r['label'])];
            $choices[] = $ch;
            $scenes[$ch['from']]['choices'][] = ['text' => $ch['text'], 'to' => $ch['to']];
        }

        $b = $this->pdo->prepare(
            "SELECT DISTINCT u.display_name, u.username, b.attribution
               FROM branch_submissions b JOIN users u ON u.id = b.user_id
               JOIN scenes s ON s.id = b.created_scene_id
              WHERE b.adventure_id = :a AND b.state = 'approved' AND s.state = 'published'
                AND b.attribution <> 'anonymous'"
        );
        $b->execute([':a' => $adventureId]);
        $contributors = [];
        foreach ($b->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $name = $r['attribution'] === 'username' ? (string) $r['username'] : (string) $r['display_name'];
            if ($name !== '' && !in_array($name, $contributors, true)) $contributors[] = $name;
        }
        sort($contributors);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'exported_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'metadata' => [
                'slug' => (string) ($adv['slug'] ?? ''), 'title' => (string) ($adv['title'] ?? ''),
                'synopsis' => (string) ($adv['synopsis'] ?? ''),
                'description_html' => HtmlSanitizer::sanitize((string) ($adv['description'] ?? '')),
                'genre' => $adv['genre'] ?? null, 'content_rating' => (string) ($adv['content_rating'] ?? ''),
                'status' => (string) ($adv['state'] ?? ''),
                'created_at' => $adv['created_at'] ?? null, 'updated_at' => $adv['updated_at'] ?? null,
            ],
            'content_warnings' => $warnings,
            'writing_guidelines_html' => HtmlSanitizer::sanitize((string) ($adv['writing_guidelines'] ?? '')),
            'scenes' => array_values(array_map(static function ($sc) { unset($sc['choices']); return $sc; }, $scenes)),
            'choices' => $choices,
            'endings' => $endings,
            'attribution' => ['owner' => (string) ($adv['owner_name'] ?? ''), 'contributors' => $contributors],
            '_graph' => $scenes,
        ];
    }

    public function toJson(array $snap): string
    {
        unset($snap['_graph']);
        return json_encode($snap, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    }

    public function toText(array $snap): string
    {
        $p = static fn (string $html): string => trim(HtmlSanitizer::toPlainText($html));
        $m = $snap['metadata'];
        $out = [strtoupper($m['title']), 'By ' . $snap['attribution']['owner']];
        if ($snap['attribution']['contributors']) $out[] = 'With contributions from ' . implode(', ', $snap['attribution']['contributors']);
        $out[] = 'Exported ' . $snap['exported_at'];
        $out[] = '';
        if ($m['description_html'] !== '') { $out[] = $p($m['description_html']); $out[] = ''; }
        if ($snap['content_warnings']) {
            $out[] = 'CONTENT WARNINGS';
            foreach ($snap['content_warnings'] as $w) $out[] = '- ' . $w['label'] . ($w['details'] ? ': ' . $w['details'] : '');
            $out[] = '';
        }
        if ($snap['writing_guidelines_html'] !== '') { $out[] = 'WRITING GUIDELINES'; $out[] = $p($snap['writing_guidelines_html']); $out[] = ''; }
        $num = [];
        foreach ($snap['_graph'] as $sc) $num[$sc['id']] = $sc['number'];
        foreach ($snap['_graph'] as $sc) {
            $out[] = str_repeat('=', 40);
            $out[] = $sc['number'] . '. ' . $sc['title'];
            $out[] = '';
            $out[] = $p($sc['body_html']);
            $out[] = '';
            if ($sc['is_ending']) {
                foreach ($snap['endings'] as $e) if ($e['scene_id'] === $sc['id']) {
                    $out[] = 'THE END — ' . $e['title'];
                    if ($e['body_html'] !== '') $out[] = $p($e['body_html']);
                }
            }
            foreach ($sc['choices'] as $ch) $out[] = '  > ' . $ch['text'] . ' — turn to ' . $num[$ch['to']];
            $out[] = '';
        }
        $text = implode("\n", $out) . "\n";
        return html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private static function e(?string $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private const CSP = "default-src 'none'; style-src 'unsafe-inline'; img-src 'none'; connect-src 'none'; form-action 'none'; base-uri 'none'";

    private function styles(): string
    {
        return 'body{background:#f4ecd8;color:#1d1a16;font-family:Georgia,"Times New Roman",serif;line-height:1.6;max-width:40rem;margin:2rem auto;padding:0 1rem}'
            . 'h1,h2,h3{color:#7a1712}section{border-top:1px solid #c9b995;padding-top:1rem;margin-top:1.5rem}'
            . '.warn{border:1px solid #7a1712;padding:.75rem}.end{font-variant:small-caps;color:#7a1712}'
            . 'button{font:inherit;background:none;border:1px solid #7a1712;color:#7a1712;padding:.4rem .8rem;margin:.25rem 0;cursor:pointer;display:block;text-align:left}'
            . '@media print{body{background:#fff;margin:0}section{break-inside:avoid-page}}';
    }

    private function head(array $snap, string $suffix, string $csp): string
    {
        return "<!doctype html>\n<html lang=\"en\"><head><meta charset=\"utf-8\">"
            . '<meta http-equiv="Content-Security-Policy" content="' . $csp . '">'
            . '<meta name="robots" content="noindex, nofollow"><meta name="referrer" content="no-referrer">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . self::e($snap['metadata']['title'] . $suffix) . '</title><style>' . $this->styles() . '</style></head>';
    }

    private function frontMatter(array $snap): string
    {
        $h = '<h1>' . self::e($snap['metadata']['title']) . '</h1><p>By ' . self::e($snap['attribution']['owner']) . '</p>';
        if ($snap['attribution']['contributors']) {
            $h .= '<p>With contributions from ' . self::e(implode(', ', $snap['attribution']['contributors'])) . '</p>';
        }
        $h .= $snap['metadata']['description_html'];
        if ($snap['content_warnings']) {
            $h .= '<div class="warn"><h2>Content warnings</h2><ul>';
            foreach ($snap['content_warnings'] as $w) $h .= '<li>' . self::e($w['label'] . ($w['details'] ? ': ' . $w['details'] : '')) . '</li>';
            $h .= '</ul></div>';
        }
        return $h;
    }

    public function toPrintHtml(array $snap): string
    {
        $num = [];
        foreach ($snap['_graph'] as $sc) $num[$sc['id']] = $sc['number'];
        $h = $this->head($snap, ' — printable', self::CSP) . '<body>' . $this->frontMatter($snap);
        if ($snap['writing_guidelines_html'] !== '') $h .= '<section><h2>Writing guidelines</h2>' . $snap['writing_guidelines_html'] . '</section>';
        foreach ($snap['_graph'] as $sc) {
            $h .= '<section><h2>' . $sc['number'] . '. ' . self::e($sc['title']) . '</h2>' . $sc['body_html'];
            foreach ($snap['endings'] as $e) if ($e['scene_id'] === $sc['id']) {
                $h .= '<p class="end">The end — ' . self::e($e['title']) . '</p>' . $e['body_html'];
            }
            if ($sc['choices']) {
                $h .= '<ul>';
                foreach ($sc['choices'] as $ch) $h .= '<li>' . self::e($ch['text']) . ' — turn to ' . $num[$ch['to']] . '</li>';
                $h .= '</ul>';
            }
            $h .= '</section>';
        }
        return $h . '<p><small>Exported ' . self::e($snap['exported_at']) . '</small></p></body></html>' . "\n";
    }

    public function toPlayHtml(array $snap): string
    {
        $scenes = [];
        $start = null;
        foreach ($snap['_graph'] as $sc) {
            $ending = null;
            foreach ($snap['endings'] as $e) if ($e['scene_id'] === $sc['id']) $ending = ['t' => $e['title'], 'b' => $e['body_html']];
            $scenes[(string) $sc['id']] = ['t' => $sc['title'], 'b' => $sc['body_html'], 'c' => $sc['choices'], 'e' => $ending];
            if ($sc['is_start'] && $start === null) $start = $sc['id'];
        }
        if ($start === null && $scenes) $start = (int) array_key_first($scenes);
        $data = json_encode(['start' => $start, 'scenes' => $scenes],
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
        $csp = "default-src 'none'; style-src 'unsafe-inline'; script-src 'unsafe-inline'; img-src 'none'; connect-src 'none'; form-action 'none'; base-uri 'none'";
        $js = <<<'JS'
(function(){var d=JSON.parse(document.getElementById('bp-data').textContent);var root=document.getElementById('bp-story');
function el(t,x){var n=document.createElement(t);if(x)n.textContent=x;return n;}
function show(id){var s=d.scenes[String(id)];root.innerHTML='';if(!s){root.appendChild(el('p','This path has not been written yet.'));return;}
root.appendChild(el('h2',s.t));var b=document.createElement('div');b.innerHTML=s.b;root.appendChild(b);
if(s.e){root.appendChild(el('p','The end — '+s.e.t)).className='end';var eb=document.createElement('div');eb.innerHTML=s.e.b;root.appendChild(eb);}
s.c.forEach(function(c){var btn=el('button',c.text);btn.type='button';btn.onclick=function(){show(c.to);};root.appendChild(btn);});
if(!s.c.length){var r=el('button','Start again');r.type='button';r.onclick=function(){show(d.start);};root.appendChild(r);}
if(root.focus){root.focus();}}
show(d.start);})();
JS;
        return $this->head($snap, '', $csp) . '<body>' . $this->frontMatter($snap)
            . '<main id="bp-story" tabindex="-1" aria-live="polite"><noscript>Turn on JavaScript to play this adventure.</noscript></main>'
            . '<script type="application/json" id="bp-data">' . $data . '</script>'
            . '<script>' . $js . '</script></body></html>' . "\n";
    }
}
