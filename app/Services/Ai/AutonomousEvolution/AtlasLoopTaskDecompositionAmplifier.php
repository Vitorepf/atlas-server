<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopDecompositionShapeFingerprinter;
use Throwable;

/**
 * P5 mitigation: deterministic alternative decompositions for the same task packet.
 *
 * OFF returns the single baseline shape and does not consult history. ON widens the
 * attempt surface with structural variants ranked by the outcome corpus for the
 * same task family. The packet itself is treated as immutable input.
 */
final class AtlasLoopTaskDecompositionAmplifier
{
    public const SCHEMA_VERSION = 'atlas.loop.task_decomposition_amplifier.v1';

    public function __construct(
        private readonly ?AtlasLoopDecompositionShapePrior $shapePrior = null,
        private readonly ?AtlasLoopDecompositionOutcomeRecorder $outcomeRecorder = null,
        private readonly ?AtlasLoopDecompositionShapeFingerprinter $fingerprinter = null,
    ) {}

    /**
     * @param  array<string,mixed>|object  $taskPacket
     * @return list<array<string,mixed>>
     */
    public function amplify(array|object $taskPacket): array
    {
        if (! $this->enabled()) {
            return [$this->decorate($this->baselineShape($taskPacket), $taskPacket, false)];
        }

        $variants = [];
        $seen = [];
        foreach ($this->candidateShapes($taskPacket) as $shape) {
            $decorated = $this->decorate($shape, $taskPacket, true);
            $fingerprint = (string) $decorated['fingerprint'];
            if ($fingerprint === '' || isset($seen[$fingerprint])) {
                continue;
            }
            $seen[$fingerprint] = true;
            $variants[] = $decorated;
        }

        usort($variants, static function (array $a, array $b): int {
            $lbCmp = ((float) ($b['prior']['lower_bound'] ?? 0.0)) <=> ((float) ($a['prior']['lower_bound'] ?? 0.0));
            if ($lbCmp !== 0) {
                return $lbCmp;
            }

            return strcmp((string) $a['fingerprint'], (string) $b['fingerprint']);
        });

        foreach ($variants as $i => $variant) {
            $variants[$i]['rank'] = $i + 1;
        }

        return $variants;
    }

    public function enabled(): bool
    {
        try {
            return function_exists('config')
                && (bool) config('atlas.loop.decomposition_amplifier_enabled', false);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  array<string,mixed>|object  $taskPacket
     * @return array<string,mixed>
     */
    private function baselineShape(array|object $taskPacket): array
    {
        $allowed = $this->pathList($this->packetValue($taskPacket, 'allowed_files'));
        $primary = $allowed[0] ?? '__task__';

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'enabled' => false,
            'shape_key' => 'baseline_single_node',
            'strategy_hint' => 'Use the task packet exactly as authored.',
            'plan' => [
                'nodes' => [
                    [
                        'id' => 'baseline',
                        'seq' => 0,
                        'target_area' => $primary,
                        'allowed_files' => $allowed,
                        'depends_on' => [],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array<string,mixed>|object  $taskPacket
     * @return list<array<string,mixed>>
     */
    private function candidateShapes(array|object $taskPacket): array
    {
        $allowed = $this->pathList($this->packetValue($taskPacket, 'allowed_files'));
        $production = array_values(array_filter(
            $allowed,
            static fn (string $path): bool => ! str_starts_with($path, 'tests/'),
        ));
        $tests = array_values(array_filter(
            $allowed,
            static fn (string $path): bool => str_starts_with($path, 'tests/'),
        ));
        $primary = $production[0] ?? ($allowed[0] ?? '__task__');
        $proof = $tests[0] ?? ($allowed[count($allowed) - 1] ?? $primary);

        $baseline = $this->baselineShape($taskPacket);

        return [
            $baseline,
            array_replace($baseline, [
                'enabled' => true,
                'shape_key' => 'proof_first_chain',
                'strategy_hint' => 'First pin the proof surface, then implement the minimum production change that satisfies it.',
                'plan' => [
                    'nodes' => [
                        ['id' => 'proof', 'seq' => 0, 'target_area' => $proof, 'allowed_files' => $tests ?: [$proof], 'depends_on' => []],
                        ['id' => 'implementation', 'seq' => 1, 'target_area' => $primary, 'allowed_files' => $production ?: [$primary], 'depends_on' => ['proof']],
                    ],
                ],
            ]),
            array_replace($baseline, [
                'enabled' => true,
                'shape_key' => 'interface_then_impl_then_proof',
                'strategy_hint' => 'Name the seam first, implement behind it, then validate through the task proof.',
                'plan' => [
                    'nodes' => [
                        ['id' => 'seam', 'seq' => 0, 'target_area' => $primary, 'allowed_files' => [$primary], 'depends_on' => []],
                        ['id' => 'implementation', 'seq' => 1, 'target_area' => $primary, 'allowed_files' => $production ?: [$primary], 'depends_on' => ['seam']],
                        ['id' => 'proof', 'seq' => 2, 'target_area' => $proof, 'allowed_files' => $tests ?: [$proof], 'depends_on' => ['implementation']],
                    ],
                ],
            ]),
            array_replace($baseline, [
                'enabled' => true,
                'shape_key' => 'parallel_impl_and_proof_join',
                'strategy_hint' => 'Split implementation and proof as independent nodes, then reconcile with a final invariant pass.',
                'plan' => [
                    'nodes' => [
                        ['id' => 'implementation', 'seq' => 0, 'target_area' => $primary, 'allowed_files' => $production ?: [$primary], 'depends_on' => []],
                        ['id' => 'proof', 'seq' => 1, 'target_area' => $proof, 'allowed_files' => $tests ?: [$proof], 'depends_on' => []],
                        ['id' => 'invariant', 'seq' => 2, 'target_area' => $primary, 'allowed_files' => $allowed, 'depends_on' => ['implementation', 'proof']],
                    ],
                ],
            ]),
        ];
    }

    /**
     * @param  array<string,mixed>  $shape
     * @param  array<string,mixed>|object  $taskPacket
     * @return array<string,mixed>
     */
    private function decorate(array $shape, array|object $taskPacket, bool $consultHistory): array
    {
        $fingerprint = $this->fingerprint((array) ($shape['plan'] ?? []));
        $history = $consultHistory ? $this->history($fingerprint, $this->taskFamily($taskPacket)) : ['certified' => 0, 'total' => 0];
        $total = max(0, (int) ($history['total'] ?? 0));
        $certified = max(0, min((int) ($history['certified'] ?? 0), $total));
        $winRate = $total > 0 ? round($certified / $total, 4) : 0.0;

        return $shape + [
            'fingerprint' => $fingerprint,
            'history' => [
                'certified' => $certified,
                'total' => $total,
                'win_rate' => $winRate,
            ],
            'prior' => ($this->shapePrior ?? new AtlasLoopDecompositionShapePrior)->assess($certified, $total),
            'rank' => 1,
            'graph_ladder' => $this->graphLadder($shape),
        ];
    }

    /**
     * @param  array<string,mixed>  $plan
     */
    private function fingerprint(array $plan): string
    {
        try {
            return (string) (($this->fingerprinter ?? new AtlasLoopDecompositionShapeFingerprinter)
                ->fingerprint($plan)['hash'] ?? '');
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * @return array{certified:int,total:int}
     */
    private function history(string $fingerprint, ?string $taskFamily): array
    {
        try {
            $recorder = $this->outcomeRecorder ?? new AtlasLoopDecompositionOutcomeRecorder;
            if (method_exists($recorder, 'historyForObjectiveKind')) {
                return $recorder->historyForObjectiveKind($fingerprint, $taskFamily);
            }

            return $recorder->history($fingerprint);
        } catch (Throwable) {
            return ['certified' => 0, 'total' => 0];
        }
    }

    /**
     * @param  array<string,mixed>|object  $taskPacket
     */
    private function taskFamily(array|object $taskPacket): ?string
    {
        foreach (['objective_kind', 'task_family', 'work_class', 'kind'] as $key) {
            $value = $this->packetValue($taskPacket, $key);
            if (is_string($value) && trim($value) !== '') {
                return mb_substr(trim($value), 0, 120);
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>|object  $taskPacket
     */
    private function packetValue(array|object $taskPacket, string $key): mixed
    {
        if (is_array($taskPacket)) {
            return $taskPacket[$key] ?? null;
        }

        if (isset($taskPacket->{$key}) || property_exists($taskPacket, $key)) {
            return $taskPacket->{$key};
        }

        if (method_exists($taskPacket, 'toArray')) {
            $array = $taskPacket->toArray();

            return is_array($array) ? ($array[$key] ?? null) : null;
        }

        return null;
    }

    /**
     * Derive a task graph ladder from the shape's plan nodes — purely structural, no external calls.
     *
     * @param  array<string,mixed>  $shape
     * @return array{unlock_edges:list<array{from:string,to:string}>,proof_node_ids:list<string>,risk_notes:string,recommended_for:string}
     */
    private function graphLadder(array $shape): array
    {
        $nodes = is_array($shape['plan']['nodes'] ?? null) ? $shape['plan']['nodes'] : [];

        // unlock_edges: one edge per (dep → node) pair from depends_on.
        $unlockEdges = [];
        foreach ($nodes as $node) {
            $nodeId = (string) ($node['id'] ?? '');
            foreach ((array) ($node['depends_on'] ?? []) as $dep) {
                $unlockEdges[] = ['from' => (string) $dep, 'to' => $nodeId];
            }
        }

        // proof_node_ids: nodes whose id contains 'proof'/'invariant' or whose target_area is under tests/.
        $proofNodeIds = [];
        foreach ($nodes as $node) {
            $id = (string) ($node['id'] ?? '');
            $area = (string) ($node['target_area'] ?? '');
            if (str_contains($id, 'proof') || str_contains($id, 'invariant') || str_starts_with($area, 'tests/')) {
                $proofNodeIds[] = $id;
            }
        }
        $proofNodeIds = array_values(array_unique($proofNodeIds));

        [$riskNotes, $recommendedFor] = match ((string) ($shape['shape_key'] ?? '')) {
            'proof_first_chain' => [
                'proof node depends on no prior implementation — risk if the test cannot be authored before production code exists',
                'macro-tasks with a distinct test file and a clear, authorable acceptance surface',
            ],
            'interface_then_impl_then_proof' => [
                'seam node touches the production file before any test exists — risk of early interface drift if the seam contract is not frozen first',
                'tasks that benefit from an explicit contract seam before implementation begins',
            ],
            'parallel_impl_and_proof_join' => [
                'implementation and proof nodes run independently — risk of interface mismatch at the final join invariant node',
                'tasks where implementation and test can be authored in parallel with a well-known join contract',
            ],
            default => [
                'single-node shape — all changes land in one step with no inter-node unlock dependencies',
                'single-file tasks or tasks where additional decomposition adds no structural value',
            ],
        };

        return [
            'unlock_edges'    => $unlockEdges,
            'proof_node_ids'  => $proofNodeIds,
            'risk_notes'      => $riskNotes,
            'recommended_for' => $recommendedFor,
        ];
    }

    /**
     * @return list<string>
     */
    private function pathList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $entry) {
            $path = is_string($entry) ? trim($entry) : '';
            if ($path !== '') {
                $out[] = ltrim($path, '/');
            }
        }

        return array_values(array_unique($out));
    }
}
