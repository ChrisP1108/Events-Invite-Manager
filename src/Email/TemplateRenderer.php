<?php

declare(strict_types=1);

namespace EventsInviteManager\Email;

if (!defined('ABSPATH')) exit;

/**
 * Renders HTML email templates by replacing {{ variable }} tags with dynamic values.
 *
 * Tags are case-insensitive — {{ First_Name }}, {{ first_name }}, and {{ FIRST_NAME }}
 * all resolve to the same value. Whitespace around the tag name is also ignored, so
 * both {{ name }} and {{name}} work. Unrecognised tags are left as-is in the output.
 *
 * Tags may also carry quoted attributes, e.g. {{ qr_code width="200" height="200" }}.
 * Attributes are only meaningful for tags whose value is a Closure — see render().
 */
final class TemplateRenderer
{
    /**
     * Replaces all {{ variable }} placeholders in the template string with their values.
     *
     * Variable lookup is case-insensitive: tag names are lowercased before matching,
     * and all keys in $variables are normalised to lowercase at render time.
     *
     * A variable's value may be a plain string, or a `Closure(array<string,string> $attributes): string`
     * for tags that need to react to attributes written on the tag itself (e.g. resizing
     * the {{ qr_code }} image). Any attributes present on the tag are parsed and passed
     * to the closure; plain string values ignore attributes entirely.
     *
     * @param string                          $template  HTML template containing {{ variable }} tags.
     * @param array<string, string|\Closure>  $variables Map of tag names to replacement values.
     * @return string Rendered output with all known tags substituted.
     */
    public function render(string $template, array $variables): string
    {
        $normalized = array_combine(
            array_map('strtolower', array_keys($variables)),
            array_values($variables)
        );

        return preg_replace_callback(
            '/\{\{\s*(\w+)((?:\s+[a-zA-Z_][\w-]*\s*=\s*"[^"]*")*)\s*\}\}/',
            static function (array $matches) use ($normalized): string {
                $value = $normalized[strtolower($matches[1])] ?? null;

                if ($value === null) {
                    return $matches[0];
                }

                if ($value instanceof \Closure) {
                    return $value(self::parseAttributes($matches[2]));
                }

                return $value;
            },
            $template
        ) ?? $template;
    }

    /**
     * Parses a tag's raw attribute string (e.g. ` width="200" height="200"`) into an
     * associative array with lowercased attribute names.
     *
     * @param string $attributeString Raw attribute text captured after the tag name.
     * @return array<string, string>
     */
    private static function parseAttributes(string $attributeString): array
    {
        $attributes = [];

        preg_match_all('/([a-zA-Z_][\w-]*)\s*=\s*"([^"]*)"/', $attributeString, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $attributes[strtolower($match[1])] = $match[2];
        }

        return $attributes;
    }
}
