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
    }

    public function addMenuItem(): void
    {
        if (! current_user_can($this->capability)) {
            return;
        }

        add_menu_page('Altimiser', 'Altimiser'.$this->badge(), $this->capability, 'altimiser', [$this, 'render'], $this->icon());
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

        wp_add_dashboard_widget('altimiser', 'Altimiser', [$this, 'renderWidget']);
    }

    public function render(): void
    {
        printf(
            '<style>.wp-admin #wpcontent{padding:0}#wpbody-content{padding-bottom:0}#wpfooter{display:none}</style>'
            .'<iframe src="%s" title="Altimiser review queue" style="display:block;width:100%%;height:calc(100vh - var(--wp-admin--admin-bar--height,32px));border:0" '
            .'sandbox="allow-scripts allow-same-origin allow-forms allow-popups allow-popups-to-escape-sandbox allow-modals"></iframe>',
            esc_url($this->panel->reviewUrl(wp_get_current_user()->user_email, $this->panel->linkExpiry())),
        );
    }

    public function renderWidget(): void
    {
        printf(
            '<p>%s</p><p><a class="button button-primary" href="%s">Open the review queue</a></p>',
            esc_html($this->describe($this->panel->awaitingReview())),
            esc_url(admin_url('admin.php?page=altimiser')),
        );
    }

    private function badge(): string
    {
        $count = $this->panel->awaitingReview();

        if (! $count) {
            return '';
        }

        return sprintf(
            ' <span class="awaiting-mod"><span aria-hidden="true">%1$d</span><span class="screen-reader-text">%1$d waiting for review</span></span>',
            $count,
        );
    }

    private function describe(?int $count): string
    {
        return match ($count) {
            null => 'The count is not available right now, but the review queue should still open.',
            0 => 'Nothing is waiting for review.',
            1 => '1 change is waiting for review.',
            default => "{$count} changes are waiting for review.",
        };
    }
}
