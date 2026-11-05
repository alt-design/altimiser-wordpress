<?php

namespace AltDesign\Altimiser;

use Throwable;

class ChangeDispatcher
{
    /**
     * @param  array<int, YoastApplier>  $appliers
     */
    public function __construct(private array $appliers, private PageCache $pageCache) {}

    /**
     * Does a bounded amount of work and hands the rest back as deferred, because no
     * web server holds a request open for hundreds of changes. Altimiser sends the
     * rest again, and a change already in place comes back as already applied.
     *
     * The purge happens here and not at the end of the request because W3 Total Cache
     * carries a flush out on the shutdown action, so one asked for later is not acted on.
     * A change already in place purges too: the request that wrote it may have died before
     * getting this far, and being sent the change again is the only chance left to do it.
     *
     * @param  array<int, ChangeRequest>  $changes
     * @return array<int, array<string, mixed>>
     */
    public function dispatch(array $changes, bool $dryRun): array
    {
        $startedAt = microtime(true);
        $batchSize = (int) apply_filters('altimiser_batch_size', 10);
        $budget = $this->timeBudget();
        $results = [];

        foreach ($changes as $change) {
            if ($results !== [] && $this->outOfRoom(count($results), $batchSize, $startedAt, $budget)) {
                $results[] = ChangeResult::deferred($change->id);

                continue;
            }

            $results[] = $this->applyOne($change, $dryRun);
        }

        if (! $dryRun && array_intersect(['applied', 'already_applied'], array_column($results, 'status')) !== []) {
            $this->pageCache->purge();
        }

        return $results;
    }

    /**
     * Half the limit, because a request killed mid-change leaves it unrecorded, and
     * one change can take a while once it has been started.
     */
    public function timeBudget(): float
    {
        $budget = (float) apply_filters('altimiser_time_budget', 45);
        $limit = (int) ini_get('max_execution_time');

        return $limit > 0 ? min($budget, $limit / 2) : $budget;
    }

    private function outOfRoom(int $attempted, int $batchSize, float $startedAt, float $budget): bool
    {
        return $attempted >= $batchSize || microtime(true) - $startedAt > $budget;
    }

    /**
     * Failed rather than skipped with no applier, because Altimiser withdraws the
     * approval on a skip, and this one comes out differently once Yoast SEO is back.
     *
     * @return array<string, mixed>
     */
    private function applyOne(ChangeRequest $change, bool $dryRun): array
    {
        if ($this->appliers === []) {
            return ChangeResult::failed(
                $change->id,
                'seo_plugin_inactive',
                'Yoast SEO is not active on this site, so nothing was attempted. The change stays approved for the next apply.',
            );
        }

        foreach ($this->appliers as $applier) {
            if (! $applier->supports($change->check)) {
                continue;
            }

            try {
                return $applier->apply($change, $dryRun);
            } catch (Throwable $exception) {
                return ChangeResult::failed($change->id, 'write_failed', $exception->getMessage());
            }
        }

        return ChangeResult::skipped(
            $change->id,
            'unsupported_check',
            "This receiver has no transform for {$change->check}.",
        );
    }
}
