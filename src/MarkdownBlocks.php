<?php

namespace AltDesign\Altimiser;

/**
 * Markdown as block editor markup: paragraphs, headings, lists, quotes, rules,
 * links, bold, italic and inline code.
 *
 * Every character of text is escaped before any markup is added, so nothing in a
 * draft can become a tag. A [GAP: ...] marker is set aside before the inline
 * rules run and put back as it was written, so an underscore or an asterisk
 * inside one is not taken for emphasis.
 */
class MarkdownBlocks
{
    public function convert(string $markdown): string
    {
        $lines = preg_split('/\R/', trim($markdown)) ?: [];

        return implode("\n\n", $this->blocks($lines));
    }

    /**
     * @param  array<int, string>  $lines
     * @return array<int, string>
     */
    private function blocks(array $lines): array
    {
        $blocks = [];
        $paragraph = [];
        $position = 0;

        while ($position < count($lines)) {
            $line = $lines[$position];

            if (trim($line) === '') {
                $this->flush($paragraph, $blocks);
                $position++;

                continue;
            }

            $block = $this->structural($lines, $position);

            if ($block === null) {
                $paragraph[] = trim($line);
                $position++;

                continue;
            }

            $this->flush($paragraph, $blocks);
            $blocks[] = $block;
        }

        $this->flush($paragraph, $blocks);

        return $blocks;
    }

    /**
     * Moves the position past what it consumed.
     *
     * @param  array<int, string>  $lines
     */
    private function structural(array $lines, int &$position): ?string
    {
        $line = $lines[$position];

        if (preg_match('/^\s{0,3}(#{1,6})\s+(.*?)(?:\s+#+)?\s*$/', $line, $heading) === 1) {
            $position++;

            return $this->heading(strlen($heading[1]), $heading[2]);
        }

        if (preg_match('/^\s{0,3}([-*_])(\s*\1){2,}\s*$/', $line) === 1) {
            $position++;

            return "<!-- wp:separator -->\n<hr class=\"wp-block-separator has-alpha-channel-opacity\"/>\n<!-- /wp:separator -->";
        }

        if (preg_match('/^\s*>/', $line) === 1) {
            return $this->quote($lines, $position);
        }

        if (preg_match('/^\s*(?:[-*+]|\d+[.)])\s+/', $line) === 1) {
            return $this->list($lines, $position);
        }

        return null;
    }

    private function heading(int $level, string $text): string
    {
        $attributes = $level === 2 ? '' : " {\"level\":{$level}}";

        return "<!-- wp:heading{$attributes} -->\n<h{$level} class=\"wp-block-heading\">{$this->inline($text)}</h{$level}>\n<!-- /wp:heading -->";
    }

    /**
     * @param  array<int, string>  $lines
     */
    private function quote(array $lines, int &$position): string
    {
        $inner = [];

        while ($position < count($lines) && preg_match('/^\s*>\s?(.*)$/', $lines[$position], $quoted) === 1) {
            $inner[] = $quoted[1];
            $position++;
        }

        $body = implode("\n\n", $this->blocks($inner));

        return "<!-- wp:quote -->\n<blockquote class=\"wp-block-quote\">{$body}</blockquote>\n<!-- /wp:quote -->";
    }

    /**
     * @param  array<int, string>  $lines
     */
    private function list(array $lines, int &$position): string
    {
        $ordered = preg_match('/^\s*\d+[.)]\s+/', $lines[$position]) === 1;
        $marker = $ordered ? '\d+[.)]' : '[-*+]';
        $items = '';

        while ($position < count($lines) && preg_match("/^\s*{$marker}\s+(.*)$/", $lines[$position], $item) === 1) {
            $items .= "<!-- wp:list-item -->\n<li>{$this->inline($item[1])}</li>\n<!-- /wp:list-item -->";
            $position++;
        }

        $tag = $ordered ? 'ol' : 'ul';
        $attributes = $ordered ? ' {"ordered":true}' : '';

        return "<!-- wp:list{$attributes} -->\n<{$tag} class=\"wp-block-list\">{$items}</{$tag}>\n<!-- /wp:list -->";
    }

    /**
     * @param  array<int, string>  $paragraph
     * @param  array<int, string>  $blocks
     */
    private function flush(array &$paragraph, array &$blocks): void
    {
        if ($paragraph === []) {
            return;
        }

        $blocks[] = "<!-- wp:paragraph -->\n<p>{$this->inline(implode("\n", $paragraph))}</p>\n<!-- /wp:paragraph -->";
        $paragraph = [];
    }

    private function inline(string $text): string
    {
        $stash = [];

        $withoutMarkers = (string) preg_replace_callback(
            '/\[GAP:[^\]]*\]/',
            function (array $marker) use (&$stash): string {
                return $this->stash($this->escape($marker[0]), $stash);
            },
            $text,
        );

        $converted = $this->emphasis($this->links($this->escape($withoutMarkers), $stash));

        return (string) preg_replace_callback(
            "/\u{E000}(\d+)\u{E001}/u",
            fn (array $placeholder): string => $stash[(int) $placeholder[1]],
            $converted,
        );
    }

    /**
     * @param  array<int, string>  $stash
     */
    private function stash(string $html, array &$stash): string
    {
        $stash[] = $html;

        return "\u{E000}".(count($stash) - 1)."\u{E001}";
    }

    /**
     * The tags are set aside with the markers, so an underscore in an address is
     * not taken for emphasis.
     *
     * @param  array<int, string>  $stash
     */
    private function links(string $escaped, array &$stash): string
    {
        return (string) preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', function (array $link) use (&$stash): string {
            $address = esc_url(html_entity_decode($link[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            if ($address === '') {
                return $link[1];
            }

            return $this->stash("<a href=\"{$address}\">", $stash).$link[1].$this->stash('</a>', $stash);
        }, $escaped);
    }

    private function emphasis(string $html): string
    {
        $rules = [
            '/`([^`]+)`/' => '<code>$1</code>',
            '/(\*\*|__)(?=\S)(.+?)(?<=\S)\1/s' => '<strong>$2</strong>',
            '/(?<![\w*])\*(?=\S)(.+?)(?<=\S)\*(?![\w*])/s' => '<em>$1</em>',
            '/(?<![\w_])_(?=\S)(.+?)(?<=\S)_(?![\w_])/s' => '<em>$1</em>',
        ];

        return (string) preg_replace(array_keys($rules), array_values($rules), $html);
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
