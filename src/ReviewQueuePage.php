<?php

namespace AltDesign\Altimiser;

class ReviewQueuePage
{
    private string $capability = 'edit_others_posts';

    public function __construct(private ControlPanel $panel) {}

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenuItem']);
        add_action('wp_dashboard_setup', [$this, 'addDashboardWidget']);
        add_action('admin_enqueue_scripts', [$this, 'addStyles']);
    }

    public function addMenuItem(): void
    {
        if (! current_user_can($this->capability)) {
            return;
        }

        add_menu_page(
            __('Altimiser', 'altimiser'),
            __('Altimiser', 'altimiser').$this->badge(),
            $this->capability,
            'altimiser',
            [$this, 'render'],
            $this->icon(),
        );
    }

    /**
     * The Statamic addon's rocket. It is a line drawing and WordPress only recolours fills
     * to match the admin colour scheme, so the lines mask a filled square.
     */
    private function icon(): string
    {
        $rocket = 'M11.4 4.9c2.6-2.6 5.4-2.8 6.6-2.7.1 1.2-.1 4-2.7 6.6l-5 5-3.9-3.9 5-5Z M6.6 12.2 3.1 9.9l2.8-1.7 2.3.6m3.1 6.6 2.3 3.5 1.7-2.8-.6-2.3 M7.9 12.1 4.6 15.4 M13.8 6.2a1 1 0 1 1-1.4 1.4 1 1 0 0 1 1.4-1.4Z';

        return 'data:image/svg+xml;base64,'.base64_encode(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">'
            ."<mask id=\"rocket\"><path stroke=\"#fff\" stroke-linecap=\"round\" stroke-linejoin=\"round\" stroke-width=\"1.5\" d=\"{$rocket}\"/></mask>"
            .'<rect width="20" height="20" fill="#a7aaad" mask="url(#rocket)"/></svg>'
        );
    }

    public function addDashboardWidget(): void
    {
        if (! current_user_can($this->capability)) {
            return;
        }

        wp_add_dashboard_widget('altimiser', __('Altimiser', 'altimiser'), [$this, 'renderWidget']);
    }

    /**
     * The queue fills the screen below the admin bar, with WordPress's own padding and
     * footer taken away around it.
     */
    public function addStyles(string $hook): void
    {
        if ($hook !== 'toplevel_page_altimiser') {
            return;
        }

        wp_register_style('altimiser-review-queue', false, [], null);
        wp_enqueue_style('altimiser-review-queue');
        wp_add_inline_style(
            'altimiser-review-queue',
            '.wp-admin #wpcontent{padding:0}#wpbody-content{padding-bottom:0}#wpfooter{display:none}'
            .'.altimiser-review-queue{display:block;width:100%;height:calc(100vh - var(--wp-admin--admin-bar--height,32px));border:0}',
        );
    }

    public function render(): void
    {
        printf(
            '<iframe class="altimiser-review-queue" src="%s" title="%s" '
            .'sandbox="allow-scripts allow-same-origin allow-forms allow-popups allow-popups-to-escape-sandbox allow-modals"></iframe>',
            esc_url($this->panel->reviewUrl(wp_get_current_user()->user_email, $this->panel->linkExpiry())),
            esc_attr__('Altimiser review queue', 'altimiser'),
        );
    }

    public function renderWidget(): void
    {
        $summary = $this->panel->summary();

        printf('<p>%s</p>', esc_html($this->describe($summary['awaiting_review'] ?? null)));

        $details = array_filter([$this->pending($summary), $this->handled($summary)]);

        if ($details !== []) {
            printf('<ul>%s</ul>', implode('', array_map(
                static fn (string $detail): string => '<li>'.esc_html($detail).'</li>',
                $details,
            )));
        }

        printf(
            '<p><a class="button button-primary" href="%s">%s</a></p>',
            esc_url(admin_url('admin.php?page=altimiser')),
            esc_html__('Open the review queue', 'altimiser'),
        );
    }

    private function badge(): string
    {
        $count = $this->panel->awaitingReview();

        if (! $count) {
            return '';
        }

        return sprintf(
            ' <span class="awaiting-mod"><span aria-hidden="true">%1$d</span><span class="screen-reader-text">%2$s</span></span>',
            $count,
            esc_html(sprintf(
                /* translators: %d: how many changes are waiting for review */
                _n('%d waiting for review', '%d waiting for review', $count, 'altimiser'),
                $count,
            )),
        );
    }

    private function describe(?int $count): string
    {
        if ($count === null) {
            return __('The count is not available right now, but the review queue should still open.', 'altimiser');
        }

        if ($count === 0) {
            return __('Nothing is waiting for review.', 'altimiser');
        }

        return sprintf(
            /* translators: %d: how many changes are waiting for review */
            _n('%d change is waiting for review.', '%d changes are waiting for review.', $count, 'altimiser'),
            $count,
        );
    }

    /**
     * @param  ?array<string, int>  $summary
     */
    private function pending(?array $summary): ?string
    {
        $pending = $summary['pending'] ?? 0;

        if ($pending === 0) {
            return null;
        }

        return sprintf(
            /* translators: %d: how many approved changes have not been applied yet */
            _n('%d approved change is waiting to be applied.', '%d approved changes are waiting to be applied.', $pending, 'altimiser'),
            $pending,
        );
    }

    /**
     * @param  ?array<string, int>  $summary
     */
    private function handled(?array $summary): ?string
    {
        $outstanding = $summary['outstanding'] ?? 0;

        if ($outstanding === 0) {
            return null;
        }

        return sprintf(
            /* translators: 1: findings this plugin can fix, 2: all open findings */
            _n('Altimiser can apply fixes for %1$d of the %2$d open finding on this site.', 'Altimiser can apply fixes for %1$d of the %2$d open findings on this site.', $outstanding, 'altimiser'),
            $summary['handled'] ?? 0,
            $outstanding,
        );
    }
}
