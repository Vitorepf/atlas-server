<?php

declare(strict_types=1);

namespace App\Services\Ai\Learning;

use App\Services\Ai\Compounding\AtlasLearningSignalScanner;

/**
 * @deprecated Compatibility alias for the M1b extraction. Remove after one
 *             release cycle once serialized legacy references have drained.
 */
class_alias(AtlasLearningSignalScanner::class, AtlasAiLearningLoopService::class);
