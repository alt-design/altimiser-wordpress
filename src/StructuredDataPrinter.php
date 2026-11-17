<?php

namespace AltDesign\Altimiser;

use WP_Post;

/**
 * Prints a post's structured data block in the head of its page.
 */
class StructuredDataPrinter
{
    public function register(): void
    {
        add_action('wp_head', [$this, 'print'], 20);
    }

    /**
     * Yoast is looked for here and not when the plugin loads, because plugins load
     * in alphabetical order and this one is ahead of it.
     */
    public function print(): void
    {
        $post = is_singular() ? get_queried_object() : null;

        if (! $post instanceof WP_Post) {
            return;
        }

        $store = new StructuredDataStore();
        $block = $store->blockFor($post);

        if ($block === '') {
            return;
        }

        $variables = new PostVariables(defined('WPSEO_VERSION') ? new Yoast() : null);
        $json = (new StructuredDataRenderer($variables, new Globals(), new Collections()))->render($block, $post);

        if ($json !== null) {
            echo "<script type=\"application/ld+json\">{$json}</script>\n";
        }
    }
}
