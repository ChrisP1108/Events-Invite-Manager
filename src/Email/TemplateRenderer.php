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
 */
final class TemplateRenderer
{
    /**
     * Replaces all {{ variable }} placeholders in the template string with their values.
     *
     * Variable lookup is case-insensitive: tag names are lowercased before matching,
     * and all keys in $variables are normalised to lowercase at render time.
     *
     * @param string                $template  HTML template containing {{ variable }} tags.
     * @param array<string, string> $variables Map of tag names to replacement values.
     * @return string Rendered output with all known tags substituted.
     */
    public function render(string $template, array $variables): string
    {
        $normalized = array_combine(
            array_map('strtolower', array_keys($variables)),
            array_values($variables)
        );

        return preg_replace_callback(
            '/\{\{\s*(\w+)\s*\}\}/',
            static function (array $matches) use ($normalized): string {
                return $normalized[strtolower($matches[1])] ?? $matches[0];
            },
            $template
        ) ?? $template;
    }
}
