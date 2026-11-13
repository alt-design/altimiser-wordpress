<?php

namespace AltDesign\Altimiser;

use Throwable;

class ChangeDispatcher
{
    /**
     * @param  array<int, Applier>  $appliers
     */
    public function __construct(private array $appliers, private PageCache $pageCache) {}

    /**
     * Does a bounded amount of work and hands the rest back as deferred, because no
     * web server holds a request open for hundreds of changes. Altimiser sends the
     * rest again, and a change already in place comes back as already applied.
     *
     * The purge happens here and not at the end of the request because W3 Total Cache
     * carries a flush out on the shutdown action, so one asked for later is not acted on.
     * A change already in place purges its page too: the request that wrote it may have
     * died before getting this far, and being sent the change again is the only chance
     * left to do it.
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

        $landed = [];

        foreach ($changes as $change) {
            if ($results !== [] && $this->outOfRoom(count($results), $batchSize, $startedAt, $budget)) {
                $results[] = ChangeResult::deferred($change->id);

                continue;
            }

            $result = $this->applyOne($change, $dryRun);
            $results[] = $result;

            if (in_array($result['status'], ['applied', 'already_applied'], true)) {
                $landed[] = [$change->url, $result['location'] ?? []];
            }
        }

        if (! $dryRun) {
            $this->purge($landed);
        }

        return $results;
    }

    /**
     * A change stored as a default shows on every page of its kind, and alt text in the
     * media library on every page that prints the image, so either clears the whole
     * cache. Anything else clears the page it was made for.
     *
     * @param  array<int, array{0: string, 1: array<string, mixed>}>  $landed
     */
    private function purge(array $landed): void
    {
        if ($landed === []) {
            return;
        }

        foreach ($landed as [, $location]) {
            if (($location['default'] ?? false) === true || ($location['type'] ?? null) === 'asset') {
                $this->pageCache->purgeEverything();

                return;
            }
        }

        $this->pageCache->purge(array_column($landed, 0));
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
     * @return array<string, mixed>
     */
    private function applyOne(ChangeRequest $change, bool $dryRun): array
    {
        foreach ($this->appliers as $applier) {
            if (! $applier->supports($change->check)) {
                continue;
            }

            try {
                $result = $applier->apply($change, $dryRun);
            } catch (Throwable $exception) {
                $result = ChangeResult::failed($change->id, 'write_failed', $exception->getMessage());
            }

            if (($result['reason'] ?? null) === 'write_failed') {
                error_log("Altimiser could not apply {$change->check} to {$change->url}: {$result['message']}");
            }

            return $result;
        }

        return ChangeResult::skipped(
            $change->id,
            'unsupported_check',
            "This receiver has no transform for {$change->check}.",
        );
    }
}
