<?php
/**
 * EmailTemplateRepository — read the seeded email_templates rows and
 * render one against a data array.
 *
 * Placeholders use `{name}` syntax; unknown placeholders are left
 * untouched so a template author can see the gap in the rendered
 * output rather than silently losing content. Values are HTML-escaped
 * only when rendered into html_body; the text_body is left untouched
 * so line-oriented content stays readable.
 */

declare(strict_types=1);

namespace App;

use PDO;

final class EmailTemplateRepository
{
    private PDO $pdo;
    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    /** @return array<string,mixed>|null */
    public function find(string $key): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT template_key, subject, text_body, html_body
             FROM email_templates WHERE template_key = :k'
        );
        $stmt->execute([':k' => $key]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Render one template for send. Returns [subject, text, html].
     *
     * @param array<string,scalar> $data
     * @return array{0:string,1:string,2:string}
     */
    public function render(string $key, array $data): array
    {
        $tpl = $this->find($key);
        if ($tpl === null) {
            // Fallback so a missing template never crashes the worker.
            return ['Branching Paths notification',
                    'A template ("' . $key . '") is not available.',
                    ''];
        }
        return [
            self::substitute((string) $tpl['subject'],   $data, false),
            self::substitute((string) $tpl['text_body'], $data, false),
            self::substitute((string) $tpl['html_body'], $data, true),
        ];
    }

    /**
     * Substitute {key} placeholders. If `$escapeHtml` is true, values
     * are HTML-encoded before insertion.
     *
     * @param array<string,scalar> $data
     */
    private static function substitute(string $body, array $data, bool $escapeHtml): string
    {
        return preg_replace_callback('/\{([a-z0-9_]+)\}/i', static function ($m) use ($data, $escapeHtml) {
            $key = $m[1];
            if (!array_key_exists($key, $data)) {
                return $m[0];
            }
            $v = (string) $data[$key];
            return $escapeHtml ? htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : $v;
        }, $body) ?? $body;
    }
}
