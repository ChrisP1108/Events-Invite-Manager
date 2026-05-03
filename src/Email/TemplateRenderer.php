<?php

declare(strict_types=1);

namespace EventsInviteManager\Email;

if (!defined('ABSPATH')) exit;

/**
 * Renders HTML email templates by replacing {{ variable }} tags with dynamic values.
 *
 * Tags are case-sensitive and must match a key in the provided variables array.
 * Whitespace around the tag name is ignored, so both {{ name }} and {{name}} work.
 * Unrecognised tags are left as-is in the output.
 */
final class TemplateRenderer
{
    /**
     * Replaces all {{ variable }} placeholders in the template string with their values.
     *
     * @param string                $template  HTML template containing {{ variable }} tags.
     * @param array<string, string> $variables Map of tag names to replacement values.
     * @return string Rendered output with all known tags substituted.
     */
    public function render(string $template, array $variables): string
    {
        return preg_replace_callback(
            '/\{\{\s*(\w+)\s*\}\}/',
            static function (array $matches) use ($variables): string {
                return $variables[$matches[1]] ?? $matches[0];
            },
            $template
        ) ?? $template;
    }
}
