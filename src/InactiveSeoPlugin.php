<?php

namespace AltDesign\Altimiser;

/**
 * Answers for Yoast's checks while Yoast is not active. Failed rather than skipped,
 * because Altimiser withdraws the approval on a skip, and these come out differently
 * once Yoast SEO is back.
 */
class InactiveSeoPlugin implements Applier
{
    public function checks(): array
    {
        return [];
    }

    public function supports(string $check): bool
    {
        return in_array(strstr($check, '.', true), ['title', 'meta_description', 'open_graph'], true);
    }

    public function apply(ChangeRequest $change, bool $dryRun): array
    {
        return ChangeResult::failed(
            $change->id,
            'seo_plugin_inactive',
            'Yoast SEO is not active on this site, so nothing was attempted. The change stays approved for the next apply.',
        );
    }
}
