<?php

namespace AltDesign\Altimiser;

use WP_Post;

/**
 * Renders the structured data blocks Altimiser writes: a JSON template holding
 * {{ variable }} values, {{ contact:telephone }} for an options page field, and
 * one loop over a post type, {{ collection:handle }} to {{ /collection:handle }}
 * with {{ count }} and {{ unless last }} inside it.
 *
 * Every value is JSON escaped as it goes in, and the result is decoded and
 * encoded again, so a block that does not come out as JSON prints nothing and
 * nothing in it can close the script tag around it. A tag it does not know is a
 * failure and not an empty value, because the block would otherwise print a
 * description or a date as blank.
 */
class StructuredDataRenderer
{
    public function __construct(
        private PostVariables $variables,
        private Globals $globals,
        private Collections $collections,
    ) {}

    /**
     * Null when the block does not render to a JSON object or array.
     */
    public function render(string $template, WP_Post $post): ?string
    {
        $failed = false;
        $rendered = $this->expand($template, $this->variables->for($post), null, $failed);

        if ($failed) {
            return null;
        }

        $decoded = json_decode($rendered);

        if (json_last_error() !== JSON_ERROR_NONE || (! is_object($decoded) && ! is_array($decoded))) {
            return null;
        }

        $json = wp_json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);

        return is_string($json) ? $json : null;
    }

    /**
     * A single pass, so a value that itself holds {{ title }} is printed as text
     * and not run again.
     *
     * @param  array<string, string>  $values
     * @param  ?array{count: int, last: bool}  $position
     */
    private function expand(string $template, array $values, ?array $position, bool &$failed): string
    {
        $pattern = '/\{\{\s*collection:([\w-]+)[^}]*\}\}(.*?)\{\{\s*\/collection(?::\1)?\s*\}\}'
            .'|\{\{\s*unless\s+last\s*\}\}(.*?)\{\{\s*\/unless\s*\}\}'
            .'|\{\{\s*([A-Za-z_][\w-]*)(?::([\w-]+))?\s*\}\}'
            .'|\{\{.*?\}\}/s';

        return (string) preg_replace_callback($pattern, function (array $tag) use ($values, $position, &$failed): string {
            if ($tag[1] !== null && $position === null) {
                return $this->loop($tag[1], $tag[2], $failed);
            }

            if ($tag[3] !== null && $position !== null) {
                return $position['last'] ? '' : $this->expand($tag[3], $values, $position, $failed);
            }

            if ($tag[4] !== null) {
                return $this->value($tag[4], $tag[5] ?? '', $values, $position, $failed);
            }

            $failed = true;

            return '';
        }, $template, -1, $count, PREG_UNMATCHED_AS_NULL);
    }

    /**
     * The block is rendered on every uncached view of the page, so a loop takes the
     * first 50 posts rather than every one a large post type holds.
     */
    private function loop(string $handle, string $body, bool &$failed): string
    {
        if (! $this->collections->loopable($handle)) {
            return '';
        }

        $posts = get_posts([
            'post_type' => $handle,
            'post_status' => 'publish',
            'numberposts' => 50,
            'orderby' => ['menu_order' => 'ASC', 'date' => 'DESC'],
            'no_found_rows' => true,
            'update_post_term_cache' => false,
        ]);

        $rendered = '';

        foreach (array_values($posts) as $index => $post) {
            $position = ['count' => $index + 1, 'last' => $index === count($posts) - 1];
            $rendered .= $this->expand($body, $this->variables->for($post), $position, $failed);
        }

        return $rendered;
    }

    /**
     * @param  array<string, string>  $values
     * @param  ?array{count: int, last: bool}  $position
     */
    private function value(string $name, string $field, array $values, ?array $position, bool &$failed): string
    {
        if ($field !== '') {
            $global = $this->globals->value($name, $field);
            $failed = $failed || $global === null;

            return $this->escape($global ?? '');
        }

        if ($name === 'count' && $position !== null) {
            return (string) $position['count'];
        }

        if (! array_key_exists($name, $values)) {
            $failed = true;

            return '';
        }

        return $this->escape($values[$name]);
    }

    private function escape(string $value): string
    {
        $encoded = (string) wp_json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return substr($encoded, 1, -1);
    }
}
