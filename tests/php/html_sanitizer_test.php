<?php
/**
 * BPHtmlSanitizerTest — allow-list, XSS, and plain-text tests for
 * App\HtmlSanitizer. The sanitizer is the sole authoritative filter
 * for user-authored HTML; if any of these assertions fails, the
 * front-end is no longer safely bounded.
 */

declare(strict_types=1);

use App\HtmlSanitizer;

final class BPHtmlSanitizerTest
{
    // ── Allow-list ─────────────────────────────────────────────

    public function testAllowsWhitelistedTags(): void
    {
        $in = '<p>Hello <strong>world</strong> and <em>emphasis</em> and <u>under</u>.</p>';
        $out = HtmlSanitizer::sanitize($in);
        assert_true(str_contains($out, '<strong>world</strong>'), 'strong preserved');
        assert_true(str_contains($out, '<em>emphasis</em>'), 'em preserved');
        assert_true(str_contains($out, '<u>under</u>'), 'u preserved');
    }

    public function testRenamesLegacyBoldItalic(): void
    {
        $out = HtmlSanitizer::sanitize('<p><b>bold</b> and <i>ital</i></p>');
        assert_true(str_contains($out, '<strong>bold</strong>'), 'b -> strong');
        assert_true(str_contains($out, '<em>ital</em>'), 'i -> em');
    }

    public function testAllowsHeadingsAndBlockquote(): void
    {
        $out = HtmlSanitizer::sanitize('<h2>A</h2><h3>B</h3><blockquote>quoted</blockquote>');
        assert_true(str_contains($out, '<h2>A</h2>'));
        assert_true(str_contains($out, '<h3>B</h3>'));
        assert_true(str_contains($out, '<blockquote>quoted</blockquote>'));
    }

    public function testAllowsListsAndHrAndBr(): void
    {
        $out = HtmlSanitizer::sanitize('<ul><li>one</li><li>two</li></ul><hr><ol><li>alpha</li></ol><p>a<br>b</p>');
        assert_true(str_contains($out, '<ul><li>one</li><li>two</li></ul>'));
        assert_true(str_contains($out, '<hr>'));
        assert_true(str_contains($out, '<ol><li>alpha</li></ol>'));
        assert_true(str_contains($out, '<br>'));
    }

    public function testDisallowsHeadingLevelsOutsideTwoAndThree(): void
    {
        $out = HtmlSanitizer::sanitize('<h1>Big</h1><h2>OK</h2><h4>small</h4>');
        assert_true(!str_contains($out, '<h1'), 'h1 removed');
        assert_true(!str_contains($out, '<h4'), 'h4 removed');
        assert_true(str_contains($out, '<h2>OK</h2>'));
        // Their text survives as paragraphs.
        assert_true(str_contains($out, 'Big'));
        assert_true(str_contains($out, 'small'));
    }

    // ── Attribute stripping ─────────────────────────────────────

    public function testStripsAllAttributes(): void
    {
        $in = '<p class="danger" style="color:red" id="x" data-x="1" onclick="alert(1)">hi</p>';
        $out = HtmlSanitizer::sanitize($in);
        assert_same('<p>hi</p>', trim($out));
    }

    public function testStripsLinksButKeepsText(): void
    {
        $out = HtmlSanitizer::sanitize('<p>see <a href="https://evil.example" target="_blank">this</a> now</p>');
        assert_true(!str_contains($out, '<a'), 'no anchor tag');
        assert_true(!str_contains($out, 'href'), 'no href attribute');
        assert_true(str_contains($out, 'see this now'), 'text preserved');
    }

    // ── XSS / dangerous content ─────────────────────────────────

    public function testDropsScriptElement(): void
    {
        $out = HtmlSanitizer::sanitize('<p>hi</p><script>alert(1)</script><p>bye</p>');
        assert_true(!str_contains(strtolower($out), 'script'), 'no script tag or text');
        assert_true(!str_contains($out, 'alert(1)'), 'script body dropped');
        assert_true(str_contains($out, 'hi') && str_contains($out, 'bye'));
    }

    public function testDropsStyleAndIframe(): void
    {
        $out = HtmlSanitizer::sanitize('<style>body{}</style><iframe src="x"></iframe><p>ok</p>');
        assert_true(!str_contains(strtolower($out), '<style'), 'no style');
        assert_true(!str_contains(strtolower($out), 'iframe'), 'no iframe');
        assert_true(str_contains($out, '<p>ok</p>'));
    }

    public function testDropsImagesEmbedsMediaSvgMath(): void
    {
        $in = '<p>x</p><img src="a"><svg><g/></svg><math><mi>x</mi></math>'
            . '<video src="v"></video><audio src="a"></audio><object data="o"></object>'
            . '<embed src="e"><form><input></form>';
        $out = HtmlSanitizer::sanitize($in);
        foreach (['img', 'svg', 'math', 'video', 'audio', 'object', 'embed', 'form', 'input'] as $tag) {
            assert_true(!str_contains(strtolower($out), '<' . $tag), "no <$tag>");
        }
        assert_true(str_contains($out, '<p>x</p>'));
    }

    public function testDropsCustomElementsButKeepsText(): void
    {
        $out = HtmlSanitizer::sanitize('<my-widget>keep this</my-widget>');
        assert_true(!str_contains($out, 'my-widget'), 'custom element removed');
        assert_true(str_contains($out, 'keep this'), 'text preserved');
    }

    public function testDropsInlineEventHandlerAttributes(): void
    {
        $out = HtmlSanitizer::sanitize('<p onmouseover="alert(1)" onclick="alert(2)">hi</p>');
        assert_true(!str_contains(strtolower($out), 'onmouseover'), 'no onmouseover');
        assert_true(!str_contains(strtolower($out), 'onclick'), 'no onclick');
        assert_true(!str_contains($out, 'alert('), 'no js payload leaks');
    }

    public function testDropsJavascriptUrlsWithLinks(): void
    {
        $out = HtmlSanitizer::sanitize('<p><a href="javascript:alert(1)">x</a></p>');
        assert_true(!str_contains(strtolower($out), 'javascript'), 'no javascript: url');
        assert_true(!str_contains($out, 'href'), 'no href');
    }

    public function testWrapsFreeTextInParagraph(): void
    {
        $out = HtmlSanitizer::sanitize('hello world');
        assert_same('<p>hello world</p>', trim($out));
    }

    public function testEmptyInputReturnsEmpty(): void
    {
        assert_same('', HtmlSanitizer::sanitize(''));
        assert_same('', HtmlSanitizer::sanitize('   '));
    }

    public function testUnicodePreserved(): void
    {
        $out = HtmlSanitizer::sanitize('<p>café — 日本語</p>');
        assert_true(str_contains($out, 'café'));
        assert_true(str_contains($out, '日本語'));
    }

    // ── Plain text ──────────────────────────────────────────────

    public function testPlainTextStripsTagsAndNormalisesSpacing(): void
    {
        $html = '<h2>Title</h2><p>Hello <strong>bold</strong>   world.</p>';
        $txt = HtmlSanitizer::toPlainText($html);
        assert_true(str_contains($txt, 'Title'));
        assert_true(str_contains($txt, 'Hello bold world.'));
    }

    public function testPlainTextListsGetDashPrefix(): void
    {
        $txt = HtmlSanitizer::toPlainText('<ul><li>one</li><li>two</li></ul>');
        assert_true(str_contains($txt, '- one'));
        assert_true(str_contains($txt, '- two'));
    }

    public function testPlainTextLengthIsMultibyteSafe(): void
    {
        $len = HtmlSanitizer::plainTextLength('<p>café</p>');
        assert_same(4, $len);
    }
}
