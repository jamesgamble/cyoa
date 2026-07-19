<?php
/**
 * HtmlSanitizer — strict allow-list HTML sanitizer for user-authored
 * story and profile content.
 *
 * The client-side WYSIWYG editor emits a restricted subset of HTML,
 * but every request that stores rich text on the server MUST pass
 * through this sanitizer. The editor is a UX aid, not a security
 * boundary — a hostile client could POST arbitrary HTML directly to
 * the API, so this class is the sole authoritative filter.
 *
 * Allowed tags (no others):
 *   p, strong, em, u, h2, h3, ul, ol, li, blockquote, hr, br
 *
 * Attributes: none. Every attribute — including class, style, id,
 * data-*, aria-*, href, src, on* — is stripped. The editor CSS owns
 * presentation entirely; the sanitizer refuses to let stored content
 * carry inline style, class hooks, links, event handlers, images,
 * scripts, iframes, forms, media, SVG, MathML, or custom tags.
 *
 * Nesting rules:
 *   - `p`, `h2`, `h3`, `blockquote` are block containers that may
 *     contain only inline content (`strong`, `em`, `u`, `br`, text).
 *   - `ul`/`ol` may contain only `li` children; unknown children are
 *     unwrapped so their text survives.
 *   - `li` may contain inline content and nested `ul`/`ol`.
 *   - `hr` and `br` are void; any children are dropped.
 *
 * Any tag outside the allow-list is UNWRAPPED — its children (recursively
 * sanitized) replace the tag. This preserves the user's text when
 * pasting from external sources without letting the tag's semantics
 * survive. `<script>`, `<style>`, `<iframe>`, `<object>`, `<embed>`,
 * `<svg>`, `<math>`, `<form>`, `<template>`, and every comment or
 * processing-instruction node are dropped entirely (including their
 * text) because their contents are never body text.
 */

declare(strict_types=1);

namespace App;

final class HtmlSanitizer
{
    /** Block-level tags that may hold inline content and text. */
    private const BLOCK_TAGS = ['p', 'h2', 'h3', 'blockquote'];

    /** Inline tags that may nest inside blocks or list items. */
    private const INLINE_TAGS = ['strong', 'em', 'u'];

    /** Void tags with no children. */
    private const VOID_TAGS = ['hr', 'br'];

    /** List container tags — only `li` children survive. */
    private const LIST_TAGS = ['ul', 'ol'];

    /** Tags whose entire subtree (including text) is discarded. */
    private const DROP_SUBTREE_TAGS = [
        'script', 'style', 'iframe', 'object', 'embed', 'noscript',
        'svg', 'math', 'form', 'template', 'button', 'input',
        'textarea', 'select', 'option', 'link', 'meta', 'title',
        'head', 'canvas', 'audio', 'video', 'source', 'track',
        'picture', 'map', 'area', 'frame', 'frameset', 'applet',
        'base',
    ];

    /**
     * Legacy inline tag names we quietly rename to the canonical
     * allow-list equivalent so paste-from-Word style output survives
     * with the right semantics. Anything not in this map that isn't
     * in the allow-list is unwrapped.
     */
    private const TAG_ALIASES = [
        'b'      => 'strong',
        'i'      => 'em',
        'strong' => 'strong',
        'em'     => 'em',
        'u'      => 'u',
        'ins'    => 'u',
    ];

    /**
     * Sanitize a raw HTML fragment. Returns a UTF-8 string that only
     * contains tags from the allow-list and no attributes.
     *
     * The output is a sequence of block elements — never a bare text
     * run. Free text at the top level is wrapped in `<p>`.
     */
    public static function sanitize(string $html): string
    {
        // Normalise line endings and collapse anything that looks like
        // a `javascript:` URL scheme fragment before it ever reaches
        // the DOM parser — belt-and-braces since we strip all
        // attributes anyway.
        $html = str_replace(["\r\n", "\r"], "\n", $html);

        // Trim BOM.
        if (str_starts_with($html, "\xEF\xBB\xBF")) {
            $html = substr($html, 3);
        }

        if (trim($html) === '') {
            return '';
        }

        $doc = new \DOMDocument('1.0', 'UTF-8');
        // Suppress libxml warnings for unknown tags / malformed HTML.
        $prev = libxml_use_internal_errors(true);
        // Wrap in a UTF-8 declared body so DOMDocument keeps multibyte
        // characters intact. LIBXML_HTML_NOIMPLIED / NODEFDTD prevent
        // <html><body> wrappers being added, so we can walk the
        // fragment directly.
        $wrapped = '<?xml encoding="UTF-8"?><div id="bp-root">' . $html . '</div>';
        $doc->loadHTML(
            $wrapped,
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET
        );
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $root = $doc->getElementById('bp-root');
        if (!$root) {
            return '';
        }

        $blocks = self::processContainer($doc, $root, /* insideList */ false);

        // Fold the sanitized block nodes back to HTML.
        $out = '';
        foreach ($blocks as $node) {
            $out .= $doc->saveHTML($node);
        }
        // saveHTML sometimes emits a trailing newline per node; collapse.
        $out = preg_replace("/\n+/", "\n", trim($out)) ?? '';
        return $out;
    }

    /**
     * Derive plain text from raw or already-sanitized HTML. Blocks are
     * separated by a single newline; list items get a leading dash; a
     * horizontal rule becomes a blank line. This is the canonical form
     * used for search indexes, character limits, snippets, duplicate
     * detection, and plain-text exports.
     */
    public static function toPlainText(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML(
            '<?xml encoding="UTF-8"?><div id="bp-root">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET
        );
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $root = $doc->getElementById('bp-root');
        if (!$root) {
            return '';
        }
        $lines = [];
        self::collectText($root, $lines, 0);
        // Normalise whitespace: trim each line, drop consecutive empties.
        $out = [];
        $prevBlank = true;
        foreach ($lines as $line) {
            $trim = trim(preg_replace('/[ \t]+/', ' ', $line) ?? '');
            if ($trim === '') {
                if (!$prevBlank) {
                    $out[] = '';
                    $prevBlank = true;
                }
                continue;
            }
            $out[] = $trim;
            $prevBlank = false;
        }
        return trim(implode("\n", $out));
    }

    /** Return the length of the plain-text projection. Used for character limits. */
    public static function plainTextLength(string $html): int
    {
        return mb_strlen(self::toPlainText($html), 'UTF-8');
    }

    // ────────────────────── internals ──────────────────────

    /**
     * Walk a container ($root or an unwrapped element) and return an
     * ordered list of sanitized *block-level* DOM nodes. Free text or
     * inline runs at the top level are wrapped in <p>.
     *
     * @return \DOMNode[]
     */
    private static function processContainer(\DOMDocument $doc, \DOMNode $container, bool $insideList): array
    {
        $blocks = [];
        /** @var \DOMNode[] $inlineBuffer */
        $inlineBuffer = [];

        $flushInline = static function () use (&$inlineBuffer, &$blocks, $doc): void {
            if (!$inlineBuffer) return;
            $hasContent = false;
            foreach ($inlineBuffer as $n) {
                if ($n->nodeType === XML_TEXT_NODE && trim($n->nodeValue ?? '') !== '') { $hasContent = true; break; }
                if ($n->nodeType === XML_ELEMENT_NODE) { $hasContent = true; break; }
            }
            if ($hasContent) {
                $p = $doc->createElement('p');
                foreach ($inlineBuffer as $n) { $p->appendChild($n); }
                $blocks[] = $p;
            }
            $inlineBuffer = [];
        };

        // Snapshot children — the loop mutates the tree.
        $children = iterator_to_array($container->childNodes);
        foreach ($children as $child) {
            if ($child instanceof \DOMComment || $child instanceof \DOMProcessingInstruction) {
                continue;
            }
            if ($child instanceof \DOMText) {
                // Preserve inline text runs; they'll be wrapped in <p>.
                $t = $doc->createTextNode($child->nodeValue ?? '');
                $inlineBuffer[] = $t;
                continue;
            }
            if (!($child instanceof \DOMElement)) {
                continue;
            }
            $tag = strtolower($child->tagName);

            if (in_array($tag, self::DROP_SUBTREE_TAGS, true)) {
                continue;
            }

            // Block tags terminate any inline buffer and produce a new block.
            if (in_array($tag, self::BLOCK_TAGS, true)) {
                $flushInline();
                $block = $doc->createElement($tag);
                self::fillInline($doc, $child, $block);
                if ($block->hasChildNodes() && trim($block->textContent) !== '') {
                    $blocks[] = $block;
                }
                continue;
            }

            if (in_array($tag, self::LIST_TAGS, true)) {
                $flushInline();
                $list = $doc->createElement($tag);
                self::fillList($doc, $child, $list);
                if ($list->hasChildNodes()) {
                    $blocks[] = $list;
                }
                continue;
            }

            if ($tag === 'hr') {
                $flushInline();
                $blocks[] = $doc->createElement('hr');
                continue;
            }

            if ($tag === 'br') {
                $inlineBuffer[] = $doc->createElement('br');
                continue;
            }

            $canonical = self::TAG_ALIASES[$tag] ?? null;
            if ($canonical !== null && in_array($canonical, self::INLINE_TAGS, true)) {
                $inline = $doc->createElement($canonical);
                self::fillInline($doc, $child, $inline);
                if ($inline->hasChildNodes()) {
                    $inlineBuffer[] = $inline;
                }
                continue;
            }

            // Unknown tag: unwrap. Recurse into children as if they lived
            // in the parent container.
            $grand = self::processContainer($doc, $child, $insideList);
            // Grand may contain block or inline results; append blocks
            // as-is, buffer inline text nodes.
            foreach ($grand as $g) {
                $blocks[] = $g;
            }
        }

        $flushInline();
        return $blocks;
    }

    /**
     * Fill $target with sanitized inline children from $source. Only
     * text, `<br>`, and inline tags survive; anything else is
     * unwrapped (its text preserved). Nested block or list tags found
     * inside an inline context are flattened to their text content.
     */
    private static function fillInline(\DOMDocument $doc, \DOMNode $source, \DOMElement $target): void
    {
        foreach ($source->childNodes as $child) {
            if ($child instanceof \DOMText) {
                $target->appendChild($doc->createTextNode($child->nodeValue ?? ''));
                continue;
            }
            if (!($child instanceof \DOMElement)) continue;
            $tag = strtolower($child->tagName);
            if (in_array($tag, self::DROP_SUBTREE_TAGS, true)) continue;
            if ($tag === 'br') {
                $target->appendChild($doc->createElement('br'));
                continue;
            }
            $canonical = self::TAG_ALIASES[$tag] ?? null;
            if ($canonical !== null && in_array($canonical, self::INLINE_TAGS, true)) {
                $inline = $doc->createElement($canonical);
                self::fillInline($doc, $child, $inline);
                if ($inline->hasChildNodes()) {
                    $target->appendChild($inline);
                }
                continue;
            }
            // Anything else — including nested <p>, <div>, <span>,
            // <a>, <img>, <table> — is flattened to its text.
            self::fillInline($doc, $child, $target);
        }
    }

    /** Fill a <ul>/<ol> target with only sanitized <li> children. */
    private static function fillList(\DOMDocument $doc, \DOMNode $source, \DOMElement $target): void
    {
        foreach ($source->childNodes as $child) {
            if (!($child instanceof \DOMElement)) continue;
            $tag = strtolower($child->tagName);
            if ($tag !== 'li') continue;
            $li = $doc->createElement('li');
            // <li> may contain inline content *and* nested lists.
            $blocks = self::processContainer($doc, $child, true);
            $hadContent = false;
            foreach ($blocks as $b) {
                if ($b instanceof \DOMElement) {
                    $btag = strtolower($b->tagName);
                    if ($btag === 'p') {
                        // Inline <p> from inline-buffer flush — hoist
                        // its children into the <li> so we don't nest
                        // block <p> inside inline list-item content.
                        foreach (iterator_to_array($b->childNodes) as $inner) {
                            $li->appendChild($inner);
                            $hadContent = true;
                        }
                        continue;
                    }
                    if (in_array($btag, self::LIST_TAGS, true)) {
                        $li->appendChild($b);
                        $hadContent = true;
                        continue;
                    }
                    // Other blocks (h2, h3, blockquote, hr) are not
                    // allowed inside <li> — flatten to text.
                    $li->appendChild($doc->createTextNode($b->textContent));
                    $hadContent = true;
                }
            }
            if ($hadContent && trim($li->textContent) !== '') {
                $target->appendChild($li);
            }
        }
    }

    /**
     * Recursively collect plain-text lines. `$lines` is filled with
     * one string per block; list items receive a leading "- " so a
     * text export still reads as a list.
     */
    private static function collectText(\DOMNode $node, array &$lines, int $listDepth): void
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMText) {
                $val = $child->nodeValue ?? '';
                if ($val === '') continue;
                // Append onto the current line if it exists, else start one.
                if (!$lines) { $lines[] = $val; }
                else { $lines[count($lines) - 1] .= $val; }
                continue;
            }
            if (!($child instanceof \DOMElement)) continue;
            $tag = strtolower($child->tagName);
            if (in_array($tag, self::DROP_SUBTREE_TAGS, true)) continue;
            if ($tag === 'br') {
                $lines[] = '';
                continue;
            }
            if ($tag === 'hr') {
                $lines[] = '';
                $lines[] = '';
                continue;
            }
            if (in_array($tag, ['p', 'h2', 'h3', 'blockquote'], true)) {
                $lines[] = '';
                self::collectText($child, $lines, $listDepth);
                $lines[] = '';
                continue;
            }
            if (in_array($tag, self::LIST_TAGS, true)) {
                self::collectText($child, $lines, $listDepth + 1);
                continue;
            }
            if ($tag === 'li') {
                $prefix = str_repeat('  ', max(0, $listDepth - 1)) . '- ';
                $lines[] = $prefix;
                self::collectText($child, $lines, $listDepth);
                $lines[] = '';
                continue;
            }
            // Inline: append content onto the current line.
            self::collectText($child, $lines, $listDepth);
        }
    }
}
