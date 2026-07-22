<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Cognition\AcosProgram\AcosMaxObraRetroService;
use Illuminate\Console\Command;

final class AtlasAcosObraRetroCommand extends Command
{
    protected $signature = 'atlas:acos:obra-retro
        {--lote= : ACOS Max lote number to close}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Emit ACOS Max Obra-Retro lote-close outcomes and normal learning candidates.';

    public function handle(AcosMaxObraRetroService $retro): int
    {
        $lote = (int) ($this->option('lote') ?? 0);
        $payload = $retro->closeLote($lote);
        $code = ($payload['status'] ?? null) === 'recorded' ? self::SUCCESS : self::FAILURE;

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $code;
        }

        $this->info(sprintf(
            'ACOS Max lote %d retro: %s outcomes, %s lesson candidates',
            $lote,
            (string) data_get($payload, 'outcomes.recorded', 0),
            (string) data_get($payload, 'lesson_candidates.queued', 0),
        ));

        return $code;
    }
}
