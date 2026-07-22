<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Cognition\AcosProgram\AcosMaxLote2MeasureService;
use Illuminate\Console\Command;

final class AtlasAiLessonDedupCalibrationCommand extends Command
{
    protected $signature = 'atlas:ai:lesson-dedup-calibration {--json : Emit JSON}';

    protected $description = 'MULTJ-02 semantic lesson dedup calibration freeze.';

    public function handle(AcosMaxLote2MeasureService $service): int
    {
        $this->line((string) json_encode($service->multj02DedupCalibration(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
