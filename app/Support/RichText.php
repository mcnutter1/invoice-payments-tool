<?php

namespace App\Support;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/** Agreement/template rich text: a small, server-enforced HTML whitelist (no styles, scripts, images or iframes). */
final class RichText
{
    private static ?HtmlSanitizer $sanitizer = null;

    public static function isHtml(string $s): bool
    {
        return (bool) preg_match('/^\s*<(p|h[1-6]|ul|ol|div|blockquote|table|hr|br|strong|em|b|i|u|s)\b/i', $s);
    }

    public static function sanitize(string $html): string
    {
        self::$sanitizer ??= new HtmlSanitizer(
            (new HtmlSanitizerConfig())
                ->allowElement('p')->allowElement('br')->allowElement('hr')
                ->allowElement('h1')->allowElement('h2')->allowElement('h3')->allowElement('h4')
                ->allowElement('strong')->allowElement('b')->allowElement('em')->allowElement('i')
                ->allowElement('u')->allowElement('s')->allowElement('sub')->allowElement('sup')
                ->allowElement('ul')->allowElement('ol')->allowElement('li')->allowElement('blockquote')
                ->allowElement('table')->allowElement('thead')->allowElement('tbody')->allowElement('tr')
                ->allowElement('th', ['colspan', 'rowspan'])->allowElement('td', ['colspan', 'rowspan'])
                ->allowElement('a', ['href'])
                ->allowLinkSchemes(['https', 'http', 'mailto'])
                ->allowRelativeLinks(false)
                ->forceAttribute('a', 'rel', 'noopener noreferrer')
                ->dropElement('script')->dropElement('style')->dropElement('iframe')->dropElement('object')
                ->withMaxInputLength(200000)
        );

        return trim(self::$sanitizer->sanitize($html));
    }

    /** Legacy plain-text bodies (# headings, **bold**, - lists) → HTML. Escape-first; merge tokens survive. */
    public static function fromText(string $text): string
    {
        $out = [];
        $para = [];
        $list = [];
        $flushP = function () use (&$para, &$out) { if ($para) { $out[] = '<p>'.implode('<br>', $para).'</p>'; $para = []; } };
        $flushL = function () use (&$list, &$out) { if ($list) { $out[] = '<ul>'.implode('', array_map(fn ($i) => "<li>$i</li>", $list)).'</ul>'; $list = []; } };
        foreach (preg_split('/\r?\n/', $text) as $line) {
            $e = e($line);
            $e = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $e);
            if (trim($line) === '') { $flushP(); $flushL(); continue; }
            if (preg_match('/^(#{1,3})\s+(.*)$/', $e, $m)) { $flushP(); $flushL(); $lvl = strlen($m[1]); $out[] = "<h$lvl>{$m[2]}</h$lvl>"; continue; }
            if (preg_match('/^[-*]\s+(.*)$/', $e, $m)) { $flushP(); $list[] = $m[1]; continue; }
            $flushL();
            $para[] = $e;
        }
        $flushP();
        $flushL();

        return implode("\n", $out);
    }

    /** Safe HTML for display, the editor and PDFs, whatever the stored format. */
    public static function toHtml(string $body): string
    {
        return self::isHtml($body) ? self::sanitize($body) : self::fromText($body);
    }

    /** Normalize editor input for storage: always sanitized HTML. */
    public static function normalize(?string $body): string
    {
        $body = (string) $body;
        if (trim(strip_tags($body)) === '' && ! str_contains($body, '<hr')) return '';

        return self::toHtml($body);
    }
}
