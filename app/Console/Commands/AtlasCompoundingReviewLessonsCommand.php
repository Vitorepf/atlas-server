<?php

namespace App\Console\Commands;

use App\Console\Concerns\EmitsCanonicalJson;
use App\Models\AiLearningCandidate;
use App\Services\Ai\Memory\AtlasMemoryRegistryService;
use App\Services\Ai\Compounding\AtlasCompoundingMemoryService;
use App\Services\Ai\Compounding\AtlasObraLessonHarvester;
use App\Services\Ai\Compounding\AtlasRefutationStrengthService;
use App\Services\Ai\Memory\AtlasMemorySemanticIndexer;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Obra #14 H2.3 — fecha o ciclo das lições de obra: os candidates que o
 * harvester deixa em quarentena G0 ('hold'/'held_for_evidence') são listados
 * aqui e a promoção para memória governada no registry Atlas é DECISÃO
 * EXPLÍCITA do operador via --promote. Regra pétrea: G0 NUNCA auto-promove —
 * sem flag este comando é read-only.
 */
class AtlasCompoundingReviewLessonsCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:compounding:review-lessons
        {--promote= : Promove UM candidate (id ou prefixo curto) para memória governada no registry}
        {--reject= : Marca UM candidate (id ou prefixo curto) como rejected}
        {--reason= : Razão da rejeição (obrigatória com --reject)}
        {--json : Print machine-readable JSON}';

    protected $description = 'Revisa a quarentena de lições de obra: lista candidates em hold; --promote/--reject é decisão explícita do operador (nunca auto-promove).';

    public function handle(
        AtlasMemoryRegistryService $memory,
        AtlasCompoundingMemoryService $compounding,
        AtlasRefutationStrengthService $refutationStrength,
    ): int {
        $promote = $this->ref('promote');
        $reject = $this->ref('reject');
        if ($promote !== null && $reject !== null) {
            return $this->failWith('Use --promote OU --reject, não ambos.');
        }

        if ($promote !== null) {
            return $this->decide($promote, fn (AiLearningCandidate $candidate): array => $this->promote($candidate, $memory, $compounding, $refutationStrength));
        }

        if ($reject !== null) {
            $reason = trim((string) $this->option('reason'));
            if ($reason === '') {
                return $this->failWith('--reject exige --reason="...".');
            }

            return $this->decide($reject, fn (AiLearningCandidate $candidate): array => $this->reject($candidate, $reason));
        }

        return $this->digest();
    }

    private function digest(): int
    {
        $rows = $this->quarantine()->orderBy('created_at')->get()
            ->map(fn (AiLearningCandidate $candidate): array => $this->row($candidate));

        $payload = [
            'ok' => true,
            'schema_version' => AtlasObraLessonHarvester::SCHEMA_VERSION,
            'quarantined' => $rows->count(),
            'candidates' => $rows->all(),
            'hint' => 'Promoção é decisão explícita do operador: --promote=<id curto> | --reject=<id curto> --reason="..."',
        ];

        if ((bool) $this->option('json')) {
            $this->jsonLine($payload);

            return self::SUCCESS;
        }

        $this->info(sprintf('%d lições de obra em quarentena G0 (hold).', $rows->count()));
        if ($rows->isNotEmpty()) {
            $this->table(
                ['id curto', 'claim', 'origem', 'status'],
                $rows->map(fn (array $row): array => [
                    $row['short_id'],
                    Str::limit($row['claim'], 90),
                    $row['origin'],
                    $row['status'],
                ])->all(),
            );
            $this->line((string) $payload['hint']);
        }

        return self::SUCCESS;
    }

    private function decide(string $ref, callable $action): int
    {
        // Coluna id é uuid nativo no Postgres: '=' com não-uuid dá 22P02 e
        // LIKE sem cast dá 42883 — daí o branch por Str::isUuid + CAST.
        $matches = $this->quarantine()
            ->when(
                Str::isUuid($ref),
                fn ($query) => $query->whereKey($ref),
                fn ($query) => $query->whereRaw('CAST(id AS TEXT) LIKE ?', [Str::lower($ref).'%']),
            )
            ->limit(2)
            ->get();

        if ($matches->isEmpty()) {
            return $this->failWith("Nenhum candidate em quarentena com id/prefixo '{$ref}' (já decidido ou inexistente).");
        }
        if ($matches->count() > 1) {
            return $this->failWith("Prefixo '{$ref}' é ambíguo — use mais caracteres do id.");
        }

        $payload = $action($matches->first());

        if ((bool) $this->option('json')) {
            $this->jsonLine($payload);

            return self::SUCCESS;
        }

        $this->info((string) $payload['message']);

        return self::SUCCESS;
    }

    /**
     * Promoção explícita: escreve no registry pelo caminho canônico
     * (record() = normalize + privacy + embed-on-write + reality graph).
     * Embedding em sqlite/sem venv degrada honesto para recall lexical.
     *
     * @return array<string,mixed>
     */
    private function promote(
        AiLearningCandidate $candidate,
        AtlasMemoryRegistryService $memory,
        AtlasCompoundingMemoryService $compounding,
        AtlasRefutationStrengthService $refutationStrength,
    ): array {
        $claim = trim((string) $candidate->claim);
        $docPath = trim((string) data_get($candidate->payload, 'doc_path', ''));
        $obraTag = 'obra:'.(Str::slug(pathinfo($docPath, PATHINFO_FILENAME)) ?: 'unknown');

        $candidate->forceFill(['promotion_allowed' => true])->save();

        $compoundingMemory = null;
        $blockedReason = null;

        try {
            $compoundingMemory = $compounding->promote($candidate);
        } catch (InvalidArgumentException $exception) {
            $blockedReason = $exception->getMessage();
        }

        $registryMetadata = [
            'candidate_id' => (string) $candidate->getKey(),
            'candidate_hash' => (string) $candidate->candidate_hash,
            'doc_path' => $docPath,
            'evidence_refs' => array_values((array) ($candidate->evidence_refs ?? [])),
            'promoted_by' => 'operator:review-lessons',
            'refutation_strength' => $refutationStrength->forCandidate($candidate),
        ];
        if ($compoundingMemory !== null) {
            $registryMetadata['promoted_compounding_memory_id'] = (string) $compoundingMemory->getKey();
        }
        if ($blockedReason !== null) {
            $registryMetadata['compounding_blocked_reason'] = $blockedReason;
        }

        $entry = $memory->record([
            'memory_type' => 'refutation_memory',
            'scope_type' => 'global',
            'title' => $claim,
            'body' => $claim,
            'summary' => Str::limit($claim, 200),
            'importance' => 4,
            'confidence' => max(0, min(100, (int) $candidate->confidence)) / 100,
            'source_type' => 'obra_lesson',
            'source_id' => (string) $candidate->getKey(),
            'source_label' => $docPath !== '' ? basename($docPath) : 'obra-lesson',
            'tags' => ['obra-lesson', $obraTag],
            'metadata' => $registryMetadata,
        ]);

        if ($compoundingMemory !== null) {
            $compoundingMemory->forceFill([
                'payload' => array_merge((array) $compoundingMemory->payload, [
                    'promoted_by' => 'operator:review-lessons',
                    'promoted_memory_entry_id' => (string) $entry->getKey(),
                ]),
            ])->save();
        }

        $candidatePayload = array_merge((array) $candidate->payload, [
            'promoted_memory_entry_id' => (string) $entry->getKey(),
        ]);
        if ($compoundingMemory !== null) {
            $candidatePayload['promoted_compounding_memory_id'] = (string) $compoundingMemory->getKey();
        }
        if ($blockedReason !== null) {
            $candidatePayload['compounding_blocked_reason'] = $blockedReason;
        }

        $candidate->forceFill([
            'status' => 'promoted',
            'decision' => 'promote',
            'promotion_allowed' => true,
            'decided_at' => now(),
            'payload' => $candidatePayload,
        ])->save();

        $response = [
            'ok' => true,
            'action' => 'promote',
            'candidate' => $this->row($candidate->refresh()),
            'memory' => [
                'id' => (string) $entry->getKey(),
                'memory_type' => $entry->memory_type,
                'scope_type' => $entry->scope_type,
                'title' => $entry->title,
                'tags' => $entry->tags,
                'status' => $entry->status,
                'source' => $entry->source_type.':'.$entry->source_id,
                'semantic_index' => app(AtlasMemorySemanticIndexer::class)->isEnabled()
                    ? 'embed-on-write'
                    : 'lexical-fallback (embedding indisponível neste driver — degrade honesto)',
            ],
            'recall_hint' => sprintf('php artisan atlas:memory:recall "%s" --type=refutation_memory --json', Str::limit($claim, 60, '')),
            'message' => sprintf('Candidate %s promovido → memória %s (refutation_memory, %s).', Str::substr((string) $candidate->getKey(), 0, 8), $entry->getKey(), $obraTag),
        ];

        if ($blockedReason !== null) {
            $response['blocked_reason'] = $blockedReason;
            $response['compounding'] = ['status' => 'blocked'];
        } else {
            $response['compounding'] = [
                'status' => 'active',
                'id' => (string) $compoundingMemory->getKey(),
                'memory_type' => $compoundingMemory->memory_type,
                'claim' => $compoundingMemory->claim,
                'confidence' => $compoundingMemory->confidence,
                'evidence_refs' => $compoundingMemory->evidence_refs,
            ];
        }

        return $response;
    }

    /**
     * @return array<string,mixed>
     */
    private function reject(AiLearningCandidate $candidate, string $reason): array
    {
        $candidate->forceFill([
            'status' => 'rejected',
            'decision' => 'reject',
            'decided_at' => now(),
            'payload' => array_merge((array) $candidate->payload, ['rejection_reason' => $reason]),
        ])->save();

        return [
            'ok' => true,
            'action' => 'reject',
            'candidate' => $this->row($candidate->refresh()),
            'reason' => $reason,
            'message' => sprintf('Candidate %s rejeitado: %s', Str::substr((string) $candidate->getKey(), 0, 8), $reason),
        ];
    }

    /**
     * Quarentena = candidates de lição de obra ainda em hold.
     *
     * @return Builder<AiLearningCandidate>
     */
    private function quarantine(): Builder
    {
        return AiLearningCandidate::query()
            ->where('schema_version', AtlasObraLessonHarvester::SCHEMA_VERSION)
            ->where('status', 'held_for_evidence')
            ->where('decision', 'hold');
    }

    /**
     * @return array<string,mixed>
     */
    private function row(AiLearningCandidate $candidate): array
    {
        return [
            'id' => (string) $candidate->getKey(),
            'short_id' => Str::substr((string) $candidate->getKey(), 0, 8),
            'claim' => (string) $candidate->claim,
            'origin' => (string) data_get($candidate->payload, 'doc_path', ''),
            'status' => (string) $candidate->status,
            'decision' => (string) $candidate->decision,
            'memory_type' => (string) $candidate->memory_type,
            'confidence' => (int) $candidate->confidence,
            'promoted_memory_entry_id' => data_get($candidate->payload, 'promoted_memory_entry_id'),
        ];
    }

    private function ref(string $option): ?string
    {
        $value = $this->option($option);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function failWith(string $message): int
    {
        if ((bool) $this->option('json')) {
            $this->jsonLine(['ok' => false, 'error' => $message]);
        } else {
            $this->error($message);
        }

        return self::FAILURE;
    }
}
