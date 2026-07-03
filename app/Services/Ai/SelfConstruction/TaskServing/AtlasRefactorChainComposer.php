<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskServing;

/**
 * REFACTOR CHAIN COMPOSER — a heavy refactor is never ONE packet. Given the
 * brain's seam decision (refactor_design_spec) and the file groups it mapped,
 * composes the staged chain the version-ladder already knows how to serve:
 *
 *   s1 extração        (the new seam + its first home)
 *   s2 migração        (real callers move onto the seam)      depends_on s1
 *   s3 remoção legado  (the duplicated/legacy paths die)      depends_on s2
 *   s4 prova final     (equivalence tests over the whole seam) depends_on s3
 *
 * Pure: returns enqueue-batch specs (the exact shape atlas:task:enqueue
 * consumes — depends_on + wave gate the ordering, refactor_design_spec rides
 * on every stage so each worker sees the SAME seam decision). Stages whose
 * file group is empty are skipped and the chain re-links (e.g. no legacy
 * removal when the refactor is extract+migrate only).
 */
final class AtlasRefactorChainComposer
{
    public const SCHEMA = 'atlas.task_serving.refactor_chain.v1';

    /**
     * @param array{
     *   objective:string,
     *   refactor_design_spec:array<string,mixed>,
     *   seam_files:list<string>,
     *   caller_files?:list<string>,
     *   legacy_files?:list<string>,
     *   proof_files?:list<string>,
     *   id_prefix?:string,
     * } $design
     * @return list<array<string,mixed>> enqueue-batch specs, chained
     */
    public function compose(array $design): array
    {
        $objective = trim((string) ($design['objective'] ?? ''));
        $spec = (array) ($design['refactor_design_spec'] ?? []);
        $prefix = trim((string) ($design['id_prefix'] ?? ''));
        if ($prefix === '') {
            $prefix = 'refchain-'.substr(hash('xxh3', $objective.json_encode($spec)), 0, 10);
        }

        $stages = [
            ['key' => 's1-extract', 'files' => $this->files($design, 'seam_files'),
                'objective' => 'Extraia a costura: '.$objective.' — implemente a abstração proposta no design spec nos arquivos desta etapa, sem tocar callers ainda.',
                'accept' => 'a nova costura existe, compila e tem teste unitário verde próprio'],
            ['key' => 's2-migrate', 'files' => $this->files($design, 'caller_files'),
                'objective' => 'Migre os callers reais para a costura extraída: '.$objective.' — cada caller listado passa a consumir a abstração; comportamento preservado.',
                'accept' => 'todos os callers desta etapa consomem a costura e os testes existentes seguem verdes'],
            ['key' => 's3-remove', 'files' => $this->files($design, 'legacy_files'),
                'objective' => 'Remova o legado duplicado agora órfão: '.$objective.' — delete os caminhos antigos que a migração tornou mortos.',
                'accept' => 'o código legado listado foi removido e nenhuma referência restante quebra (rg sem callers)'],
            ['key' => 's4-prove', 'files' => $this->files($design, 'proof_files'),
                'objective' => 'Prova final de equivalência: '.$objective.' — cubra a costura com os testes de equivalência desta etapa e rode a suíte da área.',
                'accept' => 'suíte da área verde e o delta da cadeia é mensurável (menos duplicação/linhas)'],
        ];

        $out = [];
        $previousId = null;
        $wave = 0;
        foreach ($stages as $stage) {
            if ($stage['files'] === []) {
                continue; // stage skipped — the chain re-links across the gap
            }
            $id = $prefix.'-'.$stage['key'];
            $stageSpec = [
                'task_packet_id' => $id,
                'objective' => $stage['objective'],
                'allowed_files' => $stage['files'],
                'scope_in' => array_values(array_unique(array_merge(
                    $stage['files'],
                    $this->files($design, 'seam_files'),
                ))),
                'acceptance_criteria' => [$stage['accept']],
                'required_evidence' => ['tests_or_gates_result'],
                'depends_on' => $previousId !== null ? [$previousId] : [],
                'wave' => $wave,
                'refactor_design_spec' => $spec,
            ];
            // The proof stage is test-only by design; it satisfies the fabric's
            // anti-microtask doctrine with a REAL behavior contract derived from
            // the same seam decision (never fabricated: target = the proposed
            // abstraction, risk = the spec's own risk, one equivalence case per
            // real caller plus the two parity dimensions any equivalence proof owes).
            if ($stage['key'] === 's4-prove') {
                $callers = array_values(array_filter(array_map('strval', (array) ($spec['callers'] ?? []))));
                $stageSpec['continuation_context'] = ['brain_seed_credit' => ['test_only_contract' => [
                    'target_behavior' => (string) ($spec['proposed_abstraction'] ?? ''),
                    'risk_if_missing' => (string) ($spec['risk'] ?? ''),
                    'cases' => array_merge(
                        array_map(static fn (string $c): string => 'equivalence preserved for caller '.$c, $callers),
                        ['happy-path output parity between legacy and seam', 'error-path parity between legacy and seam'],
                    ),
                ]]];
            }
            $out[] = $stageSpec;
            $previousId = $id;
            $wave++;
        }

        return $out;
    }

    /** @return list<string> */
    private function files(array $design, string $key): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn ($f): string => trim((string) $f),
            (array) ($design[$key] ?? []),
        ), static fn (string $f): bool => $f !== '')));
    }
}
