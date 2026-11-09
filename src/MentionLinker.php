<?php

namespace AltDesign\Altimiser;

/**
 * Turns the first mention of a phrase in a piece of HTML into a link, or takes that
 * link off again. Only words already there are linked, so nothing needs rewording and
 * taking the link off restores the HTML as it was.
 *
 * A mention inside a tag, a shortcode, a comment (block delimiters included), a heading,
 * code, a form control or a summary, or inside an SVG, is passed over. Tags are read
 * with their quoted attributes, so a ">" inside an attribute does not end one. A
 * mention inside a link's text is the first mention,
 * and is answered for rather than passed over: somebody chose that link, and linking
 * a later mention instead would put two destinations on one name.
 */
class MentionLinker
{
    private static string $attributes = '(?:[^>"\']|"[^"]*"|\'[^\']*\')*';

    /**
     * @return array{outcome: string, html: string} outcome is changed, already, inside_link or not_found
     */
    public function link(string $html, string $anchor, string $href): array
    {
        $mention = $this->expression($anchor);
        $attributes = self::$attributes;
        $markup = $this->spans("/<!--.*?-->|<{$attributes}>|\[\/?[a-z_][^\]]*\]/isu", $html);
        $passedOver = $this->spans("/<(script|style|textarea|select|option|button|label|summary|code|pre|svg|math|title|iframe|noscript|h[1-6])\b{$attributes}>.*?<\/\\1\s*>/isu", $html);
        $links = $this->links($html);

        preg_match_all("/{$mention}/iu", $html, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] as [$text, $offset]) {
            $end = $offset + strlen($text);

            if ($this->within($markup, $offset, $end)) {
                continue;
            }

            foreach ($links as $link) {
                if ($offset >= $link['start'] && $end <= $link['end']) {
                    return ['outcome' => $this->samePlace($link['href'], $href) ? 'already' : 'inside_link', 'html' => $html];
                }
            }

            if ($this->within($passedOver, $offset, $end)) {
                continue;
            }

            $linked = sprintf('<a href="%s">%s</a>', esc_url($href), $text);

            return ['outcome' => 'changed', 'html' => substr_replace($html, $linked, $offset, strlen($text))];
        }

        return ['outcome' => 'not_found', 'html' => $html];
    }

    /**
     * @return array{outcome: string, html: string} outcome is changed or not_found
     */
    public function unlink(string $html, string $anchor, string $href): array
    {
        $wholly = '/^\s*'.$this->expression($anchor).'\s*$/iu';

        foreach ($this->links($html) as $link) {
            if ($this->samePlace($link['href'], $href) && preg_match($wholly, $link['text']) === 1) {
                return [
                    'outcome' => 'changed',
                    'html' => substr_replace($html, $link['text'], $link['start'], $link['end'] - $link['start']),
                ];
            }
        }

        return ['outcome' => 'not_found', 'html' => $html];
    }

    /**
     * Whole words only, ignoring case and how the spaces between them were typed, so
     * "loft conversion" never links half of "loft conversions".
     */
    private function expression(string $anchor): string
    {
        $words = preg_split('/\s+/u', trim($anchor)) ?: [];
        $quoted = implode('(?:[\s\x{00A0}]|&nbsp;)+', array_map(static fn (string $word): string => preg_quote($word, '/'), $words));

        return "(?<![\p{L}\p{N}]){$quoted}(?![\p{L}\p{N}])";
    }

    /**
     * @return array<int, array{start: int, end: int, href: string, text: string}>
     */
    private function links(string $html): array
    {
        $attributes = self::$attributes;

        preg_match_all("/<a\b({$attributes})>(.*?)<\/a\s*>/isu", $html, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        return array_map(static function (array $match): array {
            preg_match('/\bhref\s*=\s*(["\'])(.*?)\1/i', $match[1][0], $href);

            return [
                'start' => $match[0][1],
                'end' => $match[0][1] + strlen($match[0][0]),
                'href' => html_entity_decode($href[2] ?? '', ENT_QUOTES | ENT_HTML5),
                'text' => $match[2][0],
            ];
        }, $matches);
    }

    /**
     * @return array<int, array{0: int, 1: int}>
     */
    private function spans(string $pattern, string $html): array
    {
        preg_match_all($pattern, $html, $matches, PREG_OFFSET_CAPTURE);

        return array_map(static fn (array $match): array => [$match[1], $match[1] + strlen($match[0])], $matches[0]);
    }

    /**
     * @param  array<int, array{0: int, 1: int}>  $spans
     */
    private function within(array $spans, int $start, int $end): bool
    {
        foreach ($spans as [$spanStart, $spanEnd]) {
            if ($start < $spanEnd && $end > $spanStart) {
                return true;
            }
        }

        return false;
    }

    /**
     * Compared as paths, so a full address on this site and a root relative one are the
     * same place. An address on another site never is.
     */
    private function samePlace(string $existing, string $wanted): bool
    {
        return $this->pathOf($existing) === $this->pathOf($wanted);
    }

    private function pathOf(string $href): string
    {
        $host = parse_url($href, PHP_URL_HOST);

        if (is_string($host) && $host !== parse_url(home_url(), PHP_URL_HOST)) {
            return $href;
        }

        return '/'.trim((string) parse_url($href, PHP_URL_PATH), '/');
    }
}
