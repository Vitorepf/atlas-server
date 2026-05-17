<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\PromptProjection;

use App\Services\Ai\Programming\AtlasDev\Schemas\CodeDiscoveryManifest;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ContextRef;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\PromptSections;
use App\Services\Ai\Programming\AtlasDev\Schemas\LightTaskContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\MiniProgrammingSpec;
use App\Services\Ai\Programming\AtlasDev\Schemas\OpenBrainProgrammingProjection;
use App\Services\Ai\Programming\AtlasDev\Schemas\OperationEnvelope;

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
    ): PromptSections {
        $miniSpecHash = $miniSpec->miniSpecHash !== '' ? $miniSpec->miniSpecHash : $miniSpec->hash();
        $taskContractHash = $taskContract->taskContractHash !== '' ? $taskContract->taskContractHash : $taskContract->hash();
        $discoveryHash = $discovery->manifestHash !== '' ? $discovery->manifestHash : $discovery->hash();
        $projectionHash = $projection->projectionHash !== '' ? $projection->projectionHash : $projection->hash();

        return new PromptSections(
            objective: $this->buildObjective($envelope, $miniSpec),
            operatingRules: self::ATLAS_DEV_OPERATING_RULES,
            miniSpecRef: $this->buildRef('mini-spec', $miniSpecHash),
            taskContractRef: $this->buildRef('task-contract', $taskContractHash),
            contextRefs: $this->buildContextRefs($projection, $discoveryHash, $projectionHash),
            codeDiscoveryRef: $this->buildRef('code-discovery', $discoveryHash),
            allowedFiles: $this->normaliseList($taskContract->allowedFiles),
            forbiddenFiles: $this->normaliseList($taskContract->forbiddenFiles),
            expectedTests: $this->buildExpectedTests($miniSpec),
            acceptanceCriteria: $this->buildAcceptanceCriteria($miniSpec),
            stopConditions: $this->buildStopConditions($miniSpec),
            escalationConditions: $this->normaliseList($taskContract->escalationOn),
            outputContract: self::OUTPUT_CONTRACT_CLAUSES,
            providerSafe: true,
            nonGoals: $this->normaliseList($miniSpec->nonGoals),
        );
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
    private function buildContextRefs(
        OpenBrainProgrammingProjection $projection,
        string $discoveryHash,
        string $projectionHash,
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

        return $this->dedupeList($refs);
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

        return $this->dedupeList($tests);
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

        return $this->dedupeList($conditions);
    }

    /**
     * @param  list<mixed>  $items
     * @return list<string>
     */
    private function normaliseList(array $items): array
    {
        $clean = [];
        foreach ($items as $item) {
            if (! is_string($item)) {
                continue;
            }
            $trimmed = trim($item);
            if ($trimmed === '') {
                continue;
            }
            $clean[] = $trimmed;
        }

        return $this->dedupeList($clean);
    }

    /**
     * @param  list<string>  $items
     * @return list<string>
     */
    private function dedupeList(array $items): array
    {
        $seen = [];
        $result = [];
        foreach ($items as $item) {
            if (isset($seen[$item])) {
                continue;
            }
            $seen[$item] = true;
            $result[] = $item;
        }

        return $result;
    }
}
