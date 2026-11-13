<?php

namespace AltDesign\Altimiser;

interface Applier
{
    /**
     * The checks advertised on /health, which is everything Altimiser will send.
     *
     * @return array<int, string>
     */
    public function checks(): array;

    public function supports(string $check): bool;

    /**
     * @return array<string, mixed>
     */
    public function apply(ChangeRequest $change, bool $dryRun): array;
}
