<?php

use AltDesign\Altimiser\MarkdownBlocks;

class MarkdownBlocksTest extends WP_UnitTestCase
{
    private function convert(string $markdown): string
    {
        return (new MarkdownBlocks())->convert($markdown);
    }

    private function paragraph(string $html): string
    {
        return "<!-- wp:paragraph -->\n<p>{$html}</p>\n<!-- /wp:paragraph -->";
    }

    public function test_takes_out_the_characters_it_uses_to_hold_markup_in_place(): void
    {
        $this->assertSame($this->paragraph('Costs 5 and <a href="http://x">link</a> more.'), $this->convert("Costs \u{E000}5\u{E001} and [link](x) more."));
    }

    public function test_wraps_paragraphs_in_blocks(): void
    {
        $this->assertSame(
            $this->paragraph('First.')."\n\n".$this->paragraph("Second\nline."),
            $this->convert("First.\n\nSecond\nline."),
        );
    }

    public function test_writes_headings_with_their_level(): void
    {
        $this->assertSame(
            "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">How long</h2>\n<!-- /wp:heading -->",
            $this->convert('## How long'),
        );
        $this->assertStringContainsString(
            "<!-- wp:heading {\"level\":3} -->\n<h3 class=\"wp-block-heading\">Costs</h3>",
            $this->convert('### Costs'),
        );
    }

    public function test_writes_bulleted_and_numbered_lists(): void
    {
        $bulleted = $this->convert("- one\n- two");
        $numbered = $this->convert("1. one\n2. two");

        $this->assertStringContainsString('<!-- wp:list -->', $bulleted);
        $this->assertStringContainsString("<ul class=\"wp-block-list\"><!-- wp:list-item -->\n<li>one</li>", $bulleted);
        $this->assertStringContainsString('<li>two</li>', $bulleted);
        $this->assertStringContainsString('<!-- wp:list {"ordered":true} -->', $numbered);
        $this->assertStringContainsString('<ol class="wp-block-list">', $numbered);
    }

    public function test_writes_quotes_around_paragraph_blocks(): void
    {
        $this->assertSame(
            "<!-- wp:quote -->\n<blockquote class=\"wp-block-quote\">".$this->paragraph('Said once.')."</blockquote>\n<!-- /wp:quote -->",
            $this->convert('> Said once.'),
        );
    }

    public function test_writes_links_bold_italic_and_code(): void
    {
        $this->assertSame(
            $this->paragraph('<a href="https://example.com/a_b_c">a <strong>link</strong></a>, <em>italic</em>, <em>also</em>, <code>code</code>.'),
            $this->convert('[a **link**](https://example.com/a_b_c), *italic*, _also_, `code`.'),
        );
    }

    public function test_keeps_every_gap_marker_as_written(): void
    {
        $markdown = "Most cost between [GAP: typical range].\n\n## How long\n\n[GAP: duration in_weeks_*]";

        $converted = $this->convert($markdown);

        $this->assertStringContainsString('[GAP: typical range]', $converted);
        $this->assertStringContainsString('[GAP: duration in_weeks_*]', $converted);
        $this->assertSame(2, substr_count($converted, '[GAP:'));
    }

    public function test_escapes_markup_in_the_text(): void
    {
        $converted = $this->convert('<script>alert(1)</script> & [x](javascript:alert(1))');

        $this->assertStringNotContainsString('<script>', $converted);
        $this->assertStringNotContainsString('href', $converted);
        $this->assertStringContainsString('&lt;script&gt;', $converted);
        $this->assertStringContainsString('&amp;', $converted);
    }

    public function test_leaves_backslashes_alone(): void
    {
        $this->assertStringContainsString('C:\temp', $this->convert('Saved in C:\temp today.'));
    }

    public function test_starts_a_new_list_where_the_kind_changes(): void
    {
        $converted = $this->convert("- one\n1. two");

        $this->assertSame(2, preg_match_all('/<!-- wp:list(?: |-->)/', $converted));
        $this->assertSame(2, substr_count($converted, '<!-- /wp:list -->'));
    }

    public function test_writes_a_rule(): void
    {
        $this->assertStringContainsString('<!-- wp:separator -->', $this->convert("Above\n\n---\n\nBelow"));
    }

    public function test_produces_markup_that_parses_into_the_expected_blocks(): void
    {
        $blocks = parse_blocks($this->convert("## Title\n\nText with [GAP: x].\n\n- a\n- b\n\n> quote"));

        $this->assertSame(
            ['core/heading', 'core/paragraph', 'core/list', 'core/quote'],
            array_values(array_filter(array_column($blocks, 'blockName'))),
        );
    }
}
