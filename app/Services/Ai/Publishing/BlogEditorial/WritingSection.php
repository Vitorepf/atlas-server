<?php

declare(strict_types=1);

namespace App\Services\Ai\Publishing\BlogEditorial;

use App\Services\Ai\AtlasOpenBrainService;

final class WritingSection
{
    public function __construct(
        private readonly ?AtlasOpenBrainService $openBrain = null,
        private readonly EditorialSupport $support = new EditorialSupport(),
    ) {}








































}
