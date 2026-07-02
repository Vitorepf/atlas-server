<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\PromptProjection;

use App\Services\Ai\Programming\AtlasDev\Schemas\AtlasDevOperationEnvelope as OperationEnvelope;
use App\Services\Ai\Programming\AtlasDev\Schemas\CodeDiscoveryManifest;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ContextRef;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\PromptSections;
use App\Services\Ai\Programming\AtlasDev\Schemas\LightTaskContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\MiniProgrammingSpec;
use App\Services\Ai\Programming\AtlasDev\Schemas\OpenBrainProgrammingProjection;
use App\Services\Ai\Programming\AtlasDev\Support\AtlasDevStringListNormalizer;
use App\Services\Ai\Programming\AtlasDev\Support\Elevations\ElevationConfig;

/**
 * Maps the validated upstream artifacts into the 13 prompt sections required by
 * contracts doc section 5.4 (ProviderPromptProjection).
 *
 * Pure function: same inputs always produce the same PromptSections. No I/O,
 * no clock, no random. Operating rules are a frozen list, never user-derived.
 */
final class PromptSectionsMapper
{
    public const ATLAS_DEV_OPERATING_RULES = [
        'Esta corrida tem exatamente uma chamada principal ao provider.',
        'So edite arquivos listados em allowed_files; qualquer outro caminho bloqueia o completion.',
        'Caminhos em forbidden_files nunca podem ser tocados, nem para leitura sensivel.',
        'Se os acceptance criteria nao forem verificaveis no estado atual, responda no_patch_needed e explique o motivo.',
        'Se houver ambiguidade que impeca o avanco, responda blocked com a pergunta exata necessaria para destravar.',
        'Nao expanda o escopo: nada de refator oportunista, dependencia nova ou flag de configuracao.',
        'Patches pequenos sao preferidos a refactors amplos; quebre em diff minimo.',
        'Preserve as mudancas preexistentes do usuario no worktree; nao reverta arquivos fora do diff.',
        'Use context_refs como leitura primaria; nao invente paths nem cite arquivos fora da lista.',
        'Nao use ferramentas de escrita, edicao, shell ou teste; Atlas Dev aplica o diff e roda verificacao fora do provider.',
        'Se uma ferramenta pedir permissao de escrita, nao espere aprovacao: responda somente com unified diff.',
        'A resposta deve caber exatamente nas secoes do output_contract; sem narrativa solta.',
    ];

    public const OUTPUT_CONTRACT_CLAUSES = [
        'diff em formato unified (sem prefixo a/ b/ relativo a outro repo)',
        'somente texto de diff; nunca aplique patch diretamente, nunca aguarde permissao de escrita',
        'lista de changed_files (paths relativos ao workspace)',
        'no_patch_needed=true + razao curta quando os acceptance criteria nao forem verificaveis',
        'acceptance_criteria com status (pass|fail|untested) e evidence_path (log/teste) quando aplicavel',
        'blocked=true + pergunta unica quando uma ambiguidade impedir progresso',
    ];

    public function map(
        OperationEnvelope $envelope,
        MiniProgrammingSpec $miniSpec,
        LightTaskContract $taskContract,
        CodeDiscoveryManifest $discovery,
        OpenBrainProgrammingProjection $projection,
        array $knownFailureModes = [],
        ?ElevationConfig $e2Config = null,
        array $provenExemplars = [],
    ): PromptSections {
        $miniSpecHash = $miniSpec->miniSpecHash !== '' ? $miniSpec->miniSpecHash : $miniSpec->hash();
        $taskContractHash = $taskContract->taskContractHash !== '' ? $taskContract->taskContractHash : $taskContract->hash();
        $discoveryHash = $discovery->manifestHash !== '' ? $discovery->manifestHash : $discovery->hash();
        $projectionHash = $projection->projectionHash !== '' ? $projection->projectionHash : $projection->hash();

        $e2 = $e2Config ?? $this->resolveE2Config();

        return new PromptSections(
            objective: $this->buildObjective($envelope, $miniSpec),
            operatingRules: self::ATLAS_DEV_OPERATING_RULES,
            miniSpecRef: $this->buildRef('mini-spec', $miniSpecHash),
            taskContractRef: $this->buildRef('task-contract', $taskContractHash),
            contextRefs: $this->buildContextRefs($projection, $discovery, $discoveryHash, $projectionHash, $provenExemplars),
            codeDiscoveryRef: $this->buildRef('code-discovery', $discoveryHash),
            allowedFiles: AtlasDevStringListNormalizer::uniqueTrimmedStrings($taskContract->allowedFiles),
            forbiddenFiles: AtlasDevStringListNormalizer::uniqueTrimmedStrings($taskContract->forbiddenFiles),
            expectedTests: $this->buildExpectedTests($miniSpec),
            acceptanceCriteria: $this->buildAcceptanceCriteria($miniSpec),
            stopConditions: $this->buildStopConditions($miniSpec),
            escalationConditions: AtlasDevStringListNormalizer::uniqueTrimmedStrings($taskContract->escalationOn),
            outputContract: self::OUTPUT_CONTRACT_CLAUSES,
            providerSafe: true,
            nonGoals: AtlasDevStringListNormalizer::uniqueTrimmedStrings($miniSpec->nonGoals),
            knownFailureModes: AtlasDevStringListNormalizer::uniqueStrings(array_merge(
                $knownFailureModes,
                $this->buildContextDegradationSignals($projection),
            )),
            definitionOfDone: $e2->isOff() ? [] : $this->buildDefinitionOfDone($miniSpec),
        );
    }

    /**
     * Resolve the E2 elevation config. When the Laravel kernel is available
     * (feature tests / production) the live config block is read; otherwise
     * the safe default (advisory) is used so plain-PHPUnit unit tests do not
     * require a bootstrapped app and never crash.
     */
    private function resolveE2Config(): ElevationConfig
    {
        if (function_exists('config')) {
            try {
                return ElevationConfig::fromConfig('e2');
            } catch (\Throwable) {
                return ElevationConfig::for('e2', null);
            }
        }

        return ElevationConfig::for('e2', null);
    }

    /**
     * Context-degradation review signal (canonical retrieval policy is
     * degrade_with_review_signal, never block). When the open-brain retrieval
     * came back incomplete — required sources missing or the ref list
     * truncated — the model must be TOLD it is operating with degraded
     * context, so it compensates by reading workspace files directly instead
     * of silently assuming absent memory/decisions exist. A complete
     * projection returns [] and the prompt stays byte-identical.
     *
     * Lines are provider-safe by construction: missing sources are the fixed
     * doc://... identifiers from DocContextTierSelector and truncation
     * reasons are fixed adapter constants — never user or file content.
     *
     * @return list<string>
     */
    private function buildContextDegradationSignals(OpenBrainProgrammingProjection $projection): array
    {
        $signals = [];

        $missing = AtlasDevStringListNormalizer::uniqueTrimmedStrings($projection->missingSources);
        if ($missing !== []) {
            $signals[] = 'contexto_degradado: fontes requeridas indisponiveis nesta corrida ('
                .implode(', ', $missing)
                .') — nao assuma o conteudo delas; confirme por leitura direta dos arquivos do workspace antes de editar.';
        }

        if ($projection->isTruncated()) {
            $reasons = AtlasDevStringListNormalizer::uniqueTrimmedStrings(
                array_values(array_filter((array) ($projection->truncation['reasons'] ?? []), 'is_string')),
            );
            $signals[] = 'contexto_truncado'
                .($reasons !== [] ? ' ('.implode(', ', $reasons).')' : '')
                .': a lista de context_refs esta incompleta; trate contexto ausente como desconhecido e verifique no codigo antes de depender dele.';
        }

        return $signals;
    }

    private function buildObjective(OperationEnvelope $envelope, MiniProgrammingSpec $miniSpec): string
    {
        $goal = trim($miniSpec->goal);
        if ($goal !== '') {
            return $goal;
        }

        $normalized = trim($envelope->normalizedIntent);
        if ($normalized !== '') {
            return $normalized;
        }

        return trim($envelope->rawIntent);
    }

    private function buildRef(string $kind, string $hash): string
    {
        return $hash === '' ? '' : 'atlas-dev://'.$kind.'/'.$hash;
    }

    /**
     * @return list<string>
     */
    /**
     * How many discovery callers/tests ride into the prompt. Bounded so the
     * highest-signal local evidence never floods the ref list (mirrors the
     * DevContextBudgetDistiller constitution: callers > tests > the rest).
     */
    private const MAX_DISCOVERY_REFS_PER_GROUP = 8;

    /** @param  list<array<string,mixed>>  $provenExemplars  DevGreenRunExemplarRetriever::retrieve() output */
    private function buildContextRefs(
        OpenBrainProgrammingProjection $projection,
        CodeDiscoveryManifest $discovery,
        string $discoveryHash,
        string $projectionHash,
        array $provenExemplars = [],
    ): array {
        $refs = [];

        if ($projectionHash !== '') {
            $refs[] = 'atlas-dev://open-brain/'.$projectionHash;
        }

        if ($discoveryHash !== '') {
            $refs[] = 'atlas-dev://code-discovery/'.$discoveryHash;
        }

        foreach ($projection->knowledgeRefs as $ref) {
            $refs[] = $this->contextRefToString($ref);
        }

        foreach ($projection->memoryRefs as $ref) {
            $refs[] = $this->contextRefToString($ref);
        }

        foreach ($projection->codeRefs as $ref) {
            $refs[] = $this->contextRefToString($ref);
        }

        // Discovery evidence: the likely CALLERS of the symbols this run will
        // touch and the tests that pin them. This is the evidence the
        // verification gate acts on later (E5 caller-test selection), so the
        // model must see it BEFORE editing — previously it only rode as an
        // unreadable atlas-dev://code-discovery/<hash> pointer while the
        // full refs lived in receipts/workcell instructions. The reason is
        // included ('path :: why it matters') because for callers the why IS
        // the signal. Empty discovery lists keep the prompt byte-identical.
        foreach (array_slice($discovery->likelyCallers, 0, self::MAX_DISCOVERY_REFS_PER_GROUP) as $ref) {
            $refs[] = $this->contextRefToString($ref).' :: '.$ref->reason;
        }

        foreach (array_slice($discovery->relatedTests, 0, self::MAX_DISCOVERY_REFS_PER_GROUP) as $ref) {
            $refs[] = $this->contextRefToString($ref).' :: '.$ref->reason;
        }

        // Proven green-run exemplars: real runs of the same task kind /
        // design path / files that already PASSED verification here. These
        // previously lived only in the workcell_instructions.json receipt,
        // which nothing consumed — the live prompt never saw them. Only
        // exemplars with a readable objective ride (an opaque run id teaches
        // nothing); the retriever already caps the list.
        foreach ($provenExemplars as $exemplar) {
            if (! is_array($exemplar)) {
                continue;
            }
            $runId = trim((string) ($exemplar['run_id'] ?? ''));
            $objective = trim((string) ($exemplar['objective_excerpt'] ?? ''));
            if ($runId === '' || $objective === '') {
                continue;
            }
            $parts = ['exemplar://'.$runId.' :: did "'.$objective.'"'];
            $command = trim((string) ($exemplar['verification_command'] ?? ''));
            if ($command !== '') {
                $parts[] = 'verified via '.$command;
            }
            $line = implode(' — ', $parts);
            // Sendability poisoning guard: the quality checker fail-closes
            // the WHOLE prompt when any provider-unsafe token appears in the
            // rendered text. A single persisted green run whose goal mentions
            // e.g. '.env' would otherwise make every future prompt of this
            // workspace non-sendable until its receipt dir is deleted. A
            // toxic exemplar is dropped, never allowed to DoS the run.
            $lineLower = strtolower($line);
            foreach (PromptQualityChecker::PROVIDER_UNSAFE_TOKENS as $token) {
                if (str_contains($lineLower, strtolower($token))) {
                    continue 2;
                }
            }
            $refs[] = $line;
        }

        return AtlasDevStringListNormalizer::uniqueStrings($refs);
    }

    private function contextRefToString(ContextRef $ref): string
    {
        return $ref->kind.'://'.$ref->ref;
    }

    /**
     * @return list<string>
     */
    private function buildExpectedTests(MiniProgrammingSpec $miniSpec): array
    {
        $tests = [];

        foreach ($miniSpec->verificationPlan->commands as $command) {
            $command = trim($command);
            if ($command !== '') {
                $tests[] = $command;
            }
        }

        foreach ($miniSpec->acceptanceCriteria as $criterion) {
            if (! is_array($criterion)) {
                continue;
            }
            $verificationRef = isset($criterion['verification_ref']) && is_string($criterion['verification_ref'])
                ? trim($criterion['verification_ref'])
                : '';
            if ($verificationRef !== '') {
                $tests[] = $verificationRef;
            }
        }

        return AtlasDevStringListNormalizer::uniqueStrings($tests);
    }

    /**
     * @return list<string>
     */
    private function buildAcceptanceCriteria(MiniProgrammingSpec $miniSpec): array
    {
        $criteria = [];

        foreach ($miniSpec->acceptanceCriteria as $criterion) {
            if (! is_array($criterion)) {
                continue;
            }

            $id = isset($criterion['id']) && is_string($criterion['id']) ? trim($criterion['id']) : '';
            $description = isset($criterion['description']) && is_string($criterion['description'])
                ? trim($criterion['description'])
                : '';
            $verification = isset($criterion['verification']) && is_string($criterion['verification'])
                ? trim($criterion['verification'])
                : '';

            if ($description === '') {
                continue;
            }

            $line = $id !== '' ? '['.$id.'] '.$description : $description;
            if ($verification !== '') {
                $line .= ' (verification='.$verification.')';
            }

            $criteria[] = $line;
        }

        return $criteria;
    }

    /**
     * @return list<string>
     */
    private function buildStopConditions(MiniProgrammingSpec $miniSpec): array
    {
        $conditions = ['patch_applied_and_diff_recorded', 'no_patch_needed_with_reason'];

        foreach ($miniSpec->completionCriteria as $criterion) {
            if (! is_string($criterion)) {
                continue;
            }
            $trimmed = trim($criterion);
            if ($trimmed !== '') {
                $conditions[] = $trimmed;
            }
        }

        return AtlasDevStringListNormalizer::uniqueStrings($conditions);
    }

    /**
     * Build the `## Definition of Done` bullet lines sourced from
     * MiniProgrammingSpec.expectedBehavior[] + completionCriteria[]. The list
     * is returned empty when both sources are empty so the renderer (which
     * uses the conditional-empty pattern, mirroring `## Known Failure Modes`)
     * emits byte-identical output to the pre-E2 baseline (VAL-E2-002).
     *
     * Each expected_behavior entry is rendered as "Behavior: <description>
     * (observable by: <observable_by>)" so the model sees both the expected
     * observable signal and the human/evidence channel. Each completion
     * criterion is rendered verbatim. Duplicates (post-trim) are deduped.
     *
     * E2 gating: the caller passes an empty list when atlas_dev.elevations.e2.mode=off
     * so the section is never emitted (VAL-E2-013).
     *
     * @return list<string>
     */
    private function buildDefinitionOfDone(MiniProgrammingSpec $miniSpec): array
    {
        $lines = [];

        foreach ($miniSpec->expectedBehavior as $behavior) {
            if (! is_array($behavior)) {
                continue;
            }
            $description = isset($behavior['description']) && is_string($behavior['description'])
                ? trim($behavior['description'])
                : '';
            if ($description === '') {
                continue;
            }
            $observableBy = isset($behavior['observable_by']) && is_string($behavior['observable_by'])
                ? trim($behavior['observable_by'])
                : '';
            $lines[] = $observableBy !== ''
                ? "Behavior: {$description} (observable_by: {$observableBy})"
                : "Behavior: {$description}";
        }

        foreach ($miniSpec->completionCriteria as $criterion) {
            if (! is_string($criterion)) {
                continue;
            }
            $trimmed = trim($criterion);
            if ($trimmed !== '') {
                $lines[] = $trimmed;
            }
        }

        return AtlasDevStringListNormalizer::uniqueStrings($lines);
    }
}
