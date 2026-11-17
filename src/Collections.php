<?php

namespace AltDesign\Altimiser;

use WP_Post_Type;

/**
 * The post types whose posts have pages of their own, and whether structured data
 * can loop over them.
 *
 * Altimiser only ever sees URLs, so the handles a loop needs have to come from here.
 * A type with public single pages renders a loop in this plugin, so it is loopable.
 */
class Collections
{
    /**
     * @return array<int, array{handle: string, title: string, route: string, loopable: bool}>
     */
    public function all(): array
    {
        $collections = [];

        foreach (get_post_types(['public' => true], 'objects') as $type) {
            $route = $this->route($type);

            if ($type->name === 'attachment' || $route === '') {
                continue;
            }

            $collections[] = [
                'handle' => $type->name,
                'title' => (string) ($type->labels->name ?? $type->label),
                'route' => $route,
                'loopable' => is_post_type_viewable($type),
            ];
        }

        return $collections;
    }

    public function exists(string $handle): bool
    {
        return in_array($handle, array_column($this->all(), 'handle'), true);
    }

    public function loopable(string $handle): bool
    {
        foreach ($this->all() as $collection) {
            if ($collection['handle'] === $handle) {
                return $collection['loopable'];
            }
        }

        return false;
    }

    /**
     * Empty where the type has no address of its own, which includes every type
     * while the site uses plain permalinks.
     */
    private function route(WP_Post_Type $type): string
    {
        global $wp_rewrite;

        if (! is_post_type_viewable($type)) {
            return '';
        }

        $structure = match ($type->name) {
            'post' => (string) $wp_rewrite->permalink_structure,
            'page' => (string) $wp_rewrite->get_page_permastruct(),
            default => (string) $wp_rewrite->get_extra_permastruct($type->name),
        };

        if ($structure === '') {
            return '';
        }

        $named = str_replace(["%{$type->name}%", '%postname%', '%pagename%'], '%slug%', $structure);
        $variables = (string) preg_replace('/%([a-z_]+)%/', '{ $1 }', $named);

        return '/'.trim($variables, '/');
    }
}
