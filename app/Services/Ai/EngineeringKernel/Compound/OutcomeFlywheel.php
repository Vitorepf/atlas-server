<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel\Compound;

use App\Services\Ai\AtlasDecide\AtlasConductorRoutingMemory;

/**
 * Engineering Kernel mechanism (OBRA #4 S4): a roda — liga o OutcomeDigestReader aos consumidores
 * reais. "Cada ciclo bom aumenta a capacidade do próximo ciclo" deixa de ser tese e vira wiring:
 *   b) routing — outcomes resolvidos alimentam AtlasConductorRoutingMemory::record() (o recommend()
 *      do ciclo seguinte passa a preferir quem ENTREGOU);
 *   a) exemplares — as sementes de runs verdes ficam expostas para o indexador do Dev;
 *   c) repair priors — o corpus do S3 é consumido pelo RepairDiagnosisStage (assinatura já
 *      reparada => estratégia comprovada reusada).
 * Idempotência barata: o feed marca a última linha consumida num cursor; re-rodar não re-alimenta.
 */
final class OutcomeFlywheel
{
    public function __construct(
        private readonly OutcomeDigestReader $reader = new OutcomeDigestReader,
        private readonly ?string $cursorPath = null,
    ) {}

    /**
     * @return array{fed:int, skipped_already_fed:int, digest:array<string,mixed>}
     */
    public function feedRoutingMemory(AtlasConductorRoutingMemory $memory): array
    {
        $samples = $this->reader->routingSamples();
        $cursor = $this->readCursor();
        $fed = 0;
        foreach ($samples as $i => $sample) {
            if ($i < $cursor) {
                continue;
            }
            $memory->record($sample);
            $fed++;
        }
        $this->writeCursor(count($samples));

        return [
            'fed' => $fed,
            'skipped_already_fed' => min($cursor, count($samples)),
            'digest' => $this->reader->digest(),
        ];
    }

    private function readCursor(): int
    {
        $file = $this->cursor();

        return is_file($file) ? max(0, (int) trim((string) file_get_contents($file))) : 0;
    }

    private function writeCursor(int $position): void
    {
        $file = $this->cursor();
        $dir = dirname($file);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        file_put_contents($file, (string) $position);
    }

    private function cursor(): string
    {
        if ($this->cursorPath !== null && $this->cursorPath !== '') {
            return $this->cursorPath;
        }
        try {
            return storage_path('atlas/engineering_kernel/outcome_flywheel.cursor');
        } catch (\Throwable) {
            return sys_get_temp_dir().'/atlas-outcome-flywheel.cursor';
        }
    }
}
