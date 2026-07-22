<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use Closure;
use Throwable;

/**
 * ARQUITETAR (ultra-profissional) — phase 4, the PROJEÇÃO phase that DOMINATES quality (docs/loop-canonical-
 * definition.md). It composes a STRUCTURAL draft from the leverage-decision envelope (phase 3) + the
 * orientation snapshot (phase 1) through a frontier WRITER callable (Hermes-bound, §9-fenced) and a SEPARATE
 * cross-model CRITIC callable — the canonical "quem cria nunca julga".
 *
 * WRITER ≠ JUDGE (same admission contract as AtlasLoopAutoArchitectureProposalService): ANY critic refutation
 * ⇒ a REFUSED envelope, no draft. §9 fence: with no writer it fail-closes (no_writer). It NEVER applies,
 * merges, proposes-to-backlog, or scores — it only DRAFTS a structurally-validated envelope that the
 * AtlasLoopDecompositionService reads. Flag atlas.loop.architecture_draft_enabled default OFF ⇒ null no-op.
 *
 * Writer callable: fn(string $objective, list<string> $citedSymbols, array $orientationSnapshot): array
 *                  ⇒ {proposed_files:list<string>, proposed_seams:list<string>, provider:string}
 * Critic callable: fn(string $objective, array $writerDraft, array $orientationSnapshot): array
 *                  ⇒ {refutations:list<string>, provider:string}
 */
final class AtlasLoopArchitectureDraftService
{
    public const SCHEMA = 'atlas.loop.architecture_draft.v1';

    public function __construct(
        private readonly ?Closure $writer = null,
        private readonly ?Closure $critic = null,
    ) {}

    /**
     * @param  array<string,mixed>  $leverageDecisionEnvelope  the phase-3 decision fact envelope
     * @param  array<string,mixed>  $orientationSnapshot        the phase-1 orientation fact snapshot
     * @return array<string,mixed>|null
     */
    public function draft(array $leverageDecisionEnvelope, array $orientationSnapshot): ?array
    {
        if (! (bool) config('atlas.loop.architecture_draft_enabled', false)) {
            return null; // flag OFF ⇒ byte-identical no-op
        }

        // §9 FENCE: no writer ⇒ fail-closed (the loop never fabricates a draft without the frontier writer).
        if ($this->writer === null) {
            return ['drafted' => false, 'reason' => 'no_writer'];
        }

        $objective = trim((string) ($leverageDecisionEnvelope['selected_objective'] ?? ($leverageDecisionEnvelope['objective'] ?? '')));
        $citedSymbols = array_values(array_filter((array) ($leverageDecisionEnvelope['cited_symbols'] ?? []), 'is_string'));

        try {
            $writerOut = (array) ($this->writer)($objective, $citedSymbols, $orientationSnapshot);
        } catch (Throwable) {
            return ['drafted' => false, 'reason' => 'writer_failed'];
        }

        $proposedFiles = array_values(array_filter((array) ($writerOut['proposed_files'] ?? []), 'is_string'));
        $proposedSeams = array_values(array_filter((array) ($writerOut['proposed_seams'] ?? []), 'is_string'));
        $writerProvider = (string) ($writerOut['provider'] ?? '');

        // CROSS-MODEL CRITIC — a separate model judges the writer's draft. ANY refutation ⇒ refused.
        $refutations = [];
        $criticProvider = '';
        if ($this->critic !== null) {
            try {
                $criticOut = (array) ($this->critic)($objective, $writerOut, $orientationSnapshot);
            } catch (Throwable) {
                return ['drafted' => false, 'reason' => 'critic_failed'];
            }
            $refutations = array_values(array_filter((array) ($criticOut['refutations'] ?? []), 'is_string'));
            $criticProvider = (string) ($criticOut['provider'] ?? '');
        }

        if ($refutations !== []) {
            return ['drafted' => false, 'reason' => 'critic_refuted', 'refutations' => $refutations]; // writes nothing
        }

        sort($proposedFiles, SORT_STRING);
        sort($proposedSeams, SORT_STRING);
        sort($citedSymbols, SORT_STRING);

        $envelope = [
            'schema_version' => self::SCHEMA,
            'drafted' => true,
            'objective' => $objective,
            'cited_symbols' => $citedSymbols,
            'proposed_files' => $proposedFiles,
            'proposed_seams' => $proposedSeams,
            'writer_provider' => $writerProvider,
            'critic_provider' => $criticProvider,
            'critic_refutations' => [],
        ];
        ksort($envelope);

        return $envelope;
    }
}
