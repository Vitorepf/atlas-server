<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Cognition\AcosProgram\AcosMaxLote2MeasureService;
use Illuminate\Console\Command;

final class AtlasAiLessonHalfLifeCommand extends Command
{
    protected $signature = 'atlas:ai:lesson-half-life {--json : Emit JSON}';

    protected $description = 'MULTJ-01 lesson half-life read-only series.';

    public function handle(AcosMaxLote2MeasureService $service): int
    {
        $this->line((string) json_encode($service->multj01LessonHalfLife(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
