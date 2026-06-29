<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Trinity\ThreeWay\AtlasLoopTrinityFeedbackAuditor;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopTrinityFeedbackAuditor::audit()} at the operator surface: for a cycle id it
 * audits whether all three Trinity sources (loop / cortex / maestro) emitted NEW facts that cycle and emits the
 * result (is_static + per-source new fact ids + static-cycle violations) as deterministic facts. Read-only.
 *
 * The TrinityFacts stream comes from an injectable seam (empty by default until a live merger is wired).
 */
final class AtlasLoopTrinityFeedbackAuditCommand extends Command
{
    /** Container key for an injected TrinityFacts stream (test/integration seam): list<array>|callable():list<array>. */
    private const STREAM_BINDING = 'atlas.loop.trinity.stream';

    protected $signature = 'atlas:loop:trinity-feedback-audit {--cycle=} {--json}';

    protected $description = 'Read-only Trinity feedback audit for a cycle (did loop/cortex/maestro each move?).';

    public function handle(): int
    {
        $cycle = trim((string) $this->option('cycle'));
        if ($cycle === '') {
            $this->line((string) json_encode([
                'outcome' => 'refused',
                'reason' => 'usage_error',
                'message' => 'trinity-feedback-audit requires --cycle=<id>',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $result = (new AtlasLoopTrinityFeedbackAuditor($this->stream()))->audit($cycle);

        $this->line((string) json_encode(array_merge(
            ['schema_version' => 'atlas.loop.trinity_feedback_audit.v1'],
            $result->toArray(),
        ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function stream(): array
    {
        $app = $this->getLaravel();
        if ($app->bound(self::STREAM_BINDING)) {
            $bound = $app->make(self::STREAM_BINDING);
            if (is_callable($bound)) {
                $bound = $bound();
            }
            if (is_array($bound)) {
                return array_values(array_filter($bound, 'is_array'));
            }
        }

        return [];
    }
}
