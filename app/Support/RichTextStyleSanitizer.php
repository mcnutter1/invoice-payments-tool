<?php

namespace App\Support;

use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;

/** Keeps only text color, font size, weight and style from inline styles, with strictly validated values. */
final class RichTextStyleSanitizer implements AttributeSanitizerInterface
{
    public const SIZES = ['x-small', 'small', 'medium', 'large', 'x-large', 'xx-large'];

    public function getSupportedElements(): ?array
    {
        return ['span'];
    }

    public function getSupportedAttributes(): ?array
    {
        return ['style'];
    }

    public function sanitizeAttribute(string $element, string $attribute, string $value, HtmlSanitizerConfig $config): ?string
    {
        return self::filter($value);
    }

    public static function filter(string $style): ?string
    {
        $out = [];
        foreach (explode(';', strtolower($style)) as $decl) {
            [$prop, $val] = array_map('trim', explode(':', $decl, 2) + [1 => '']);
            if ($prop === 'color' && preg_match('/^(#[0-9a-f]{3}|#[0-9a-f]{6}|rgb\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*\))$/', $val)) {
                $out['color'] = $val;
            } elseif ($prop === 'font-weight' && in_array($val, ['normal', 'bold', '400', '700'], true)) {
                // Browsers unbold text inside <b>/<strong> with a font-weight override.
                $out['font-weight'] = $val;
            } elseif ($prop === 'font-style' && in_array($val, ['normal', 'italic'], true)) {
                $out['font-style'] = $val;
            } elseif ($prop === 'font-size' && (in_array($val, self::SIZES, true)
                || preg_match('/^(\d{1,2}(\.\d)?)(px|pt)$/', $val, $m) && (float) $m[1] >= 6 && (float) $m[1] <= 48
                || preg_match('/^(\d(\.\d{1,3})?)em$/', $val, $m) && (float) $m[1] >= 0.5 && (float) $m[1] <= 3)) {
                $out['font-size'] = $val;
            }
        }
        if (! $out) return null;

        return implode('; ', array_map(fn ($k, $v) => $k.': '.$v, array_keys($out), $out));
    }
}
