<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Cognition\AcosProgram\AcosMaxLote2MeasureService;
use Illuminate\Console\Command;

final class AtlasFlywheelLearningLatencyCommand extends Command
{
    protected $signature = 'atlas:flywheel:learning-latency {--json : Emit JSON}';

    protected $description = 'MULTX-06 learning latency read-only series.';

    public function handle(AcosMaxLote2MeasureService $service): int
    {
        $this->line((string) json_encode($service->multx06LearningLatency(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
