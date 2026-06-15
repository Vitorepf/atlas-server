<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Verify\AtlasEngineeringHonestyGate;
use App\Services\Ai\AutonomousEvolution\Verify\AtlasLoopSignalAnalyzer;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AdversarialProofPanelService;
use App\Services\Ai\Support\AiStringListNormalizer;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Final certificate for small semantic implementation proposals.
 *
 * It composes the existing P4 implementation gate with an independent
 * adversarial proof panel and optional external refuter commands. The result is
 * still propose-only: the certificate proves reviewability, never mergeability.
 */
final class AtlasLoopSemanticImplementationCertifier
{
    public const SCHEMA = 'atlas.loop.semantic_implementation_certification.v1';

    public function __construct(
        private readonly AtlasEngineeringHonestyGate $honestyGate,
        private readonly AdversarialProofPanelService $adversarialPanel,
        private readonly AtlasLoopMutationAdequacyGateService $mutationAdequacyGate,
        private readonly AtlasLoopCrossFileConsumerGateService $crossFileConsumerGate,
        private readonly ?AtlasLoopSignalAnalyzer $signalAnalyzer = null,
    ) {}

    /**
     * @param  array<string,mixed>  $targetAcceptance
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function certify(string $workspace, array $targetAcceptance, array $options = []): array
    {
        $sealedHoldouts = AiStringListNormalizer::trimmedStrings($options['sealed_holdout_commands'] ?? []);
        $allowedFiles = AiStringListNormalizer::trimmedStrings($options['allowed_files'] ?? []);
        $objective = trim((string) ($options['objective'] ?? ''));
        $refuterCommands = $this->refuterCommands($options);
        $requiredRefuters = $this->requiredRefuters($options, count($refuterCommands));

        $deterministicGate = $this->honestyGate->evaluateImplementation($workspace, $targetAcceptance, $sealedHoldouts);
        $changedFiles = $this->changedFiles($workspace);
        $changedFileContents = $this->changedFileContents($workspace, $changedFiles);
        // AUTÓPSIA 12/06 (causa-raiz #2 do "0 propostas"): o painel checava markers de
        // incompletude no ARQUIVO INTEIRO — alvos reais do Atlas carregam TODOs legítimos
        // pré-existentes, então toda proposta de discovery era refutada para sempre.
        // Extraímos as linhas ADICIONADAS pelo diff (a única coisa que a proposta pode
        // sujar) para o painel escanear o delta, não o passivo histórico do arquivo.
        $changedAddedLines = $this->addedLines($workspace, $changedFiles);
        $panelVerdict = $this->adversarialPanel->refute($this->panelCycle(
            $workspace,
            $objective,
            $allowedFiles,
            $targetAcceptance,
            $deterministicGate,
            $changedFiles,
            $changedFileContents,
            $changedAddedLines,
        ));
        $mutationAdequacy = $this->mutationAdequacyGate->evaluate($workspace, $targetAcceptance, $changedFiles, [
            'enabled' => (bool) config('atlas.loop.mutation_adequacy_gate.enabled', false),
            'timeout_seconds' => (int) config('atlas.loop.mutation_adequacy_gate.timeout_seconds', 120),
            'max_mutants' => (int) config('atlas.loop.mutation_adequacy_gate.max_mutants', 1),
            'property_commands' => AiStringListNormalizer::uniqueMergedStrings(
                AiStringListNormalizer::trimmedStrings($options['mutation_property_commands'] ?? []),
                AiStringListNormalizer::trimmedStrings($targetAcceptance['property_commands'] ?? []),
            ),
        ]);
        // L6-6: local GREEN is not enough for unreviewed trust. If Code Intelligence
        // says a changed symbol has consumer contracts, those contracts must also stay
        // GREEN in the candidate workspace. No graph/consumer signal skips safely; a
        // discovered failing consumer refutes the proposal.
        $crossFileConsumers = $this->crossFileConsumerGate->evaluate($workspace, $targetAcceptance, $changedFiles, [
            'enabled' => (bool) config('atlas.loop.cross_file_consumer_gate.enabled', false),
            'timeout_seconds' => (int) config('atlas.loop.cross_file_consumer_gate.timeout_seconds', 120),
            'code_graph_workspace' => $options['code_graph_workspace'] ?? null,
            'consumer_commands' => AiStringListNormalizer::uniqueMergedStrings(
                AiStringListNormalizer::trimmedStrings($options['cross_file_consumer_commands'] ?? []),
                AiStringListNormalizer::trimmedStrings($targetAcceptance['cross_file_consumer_commands'] ?? []),
                AiStringListNormalizer::trimmedStrings($options['consumer_commands'] ?? []),
                AiStringListNormalizer::trimmedStrings($targetAcceptance['consumer_commands'] ?? []),
            ),
            'consumer_contracts' => is_array($options['consumer_contracts'] ?? null) ? $options['consumer_contracts'] : [],
            'code_graph_consumer_contracts' => is_array($options['code_graph_consumer_contracts'] ?? null) ? $options['code_graph_consumer_contracts'] : [],
        ]);
        $providerRefuters = $this->runRefuters($workspace, [
            'schema_version' => self::SCHEMA.'.refuter_packet',
            'objective' => $objective,
            'workspace' => $workspace,
            'changed_files' => $changedFiles,
            'allowed_files' => $allowedFiles,
            'target_acceptance' => $this->redactedAcceptance($targetAcceptance),
            'deterministic_gate' => $deterministicGate,
            'adversarial_panel' => $panelVerdict,
            'mutation_adequacy_gate' => $mutationAdequacy,
            'cross_file_consumer_gate' => $crossFileConsumers,
            'proposal_only' => true,
            'merged_to_main' => false,
        ], $refuterCommands, [
            'required' => $requiredRefuters,
            'provider' => isset($options['refuter_provider']) ? trim((string) $options['refuter_provider']) : null,
            'timeout_seconds' => max(1, (int) ($options['refuter_timeout_seconds'] ?? 120)),
        ]);

        // COMPLEXITY-DROP GATE (framework refactor certification). When the FROZEN acceptance is a
        // refactor contract (complexity_proof=true AND metric_kind=minimize), certification is a
        // CONJUNCTION: behavior MUST be preserved (the deterministic gate above re-ran the REAL
        // frozen tests and they stayed GREEN) AND a REAL AST max-per-method cyclomatic measure MUST
        // drop (file total not increasing), measured by the judge's OWN analyzer in this gate
        // workspace — never a provider-claimed number. Ungameable: a behavior change turns the real
        // test RED (deterministic gate), a no-op leaves candidate>=baseline => rejected here. Gated
        // on the ACCEPTANCE flag (set by the synthesizer), NOT a config flag — a vanilla
        // implementation contract has complexity_proof absent and never enters this branch
        // (byte-identical to today). Fail-CLOSED: an unmeasurable/no-op diff returns null => refuted.
        $complexityProof = null;
        $scopeViolation = [];
        if ($this->complexityProofRequired($targetAcceptance)) {
            // SCOPE GATE (anti-gaming): the aggregate-drop must be measured over the files the obra
            // was ALLOWED to touch — NOT the raw git diff. Without this, a multi-file obra could drop
            // the headline complexity in an allowed file while editing an UNRELATED file outside the
            // cluster (which the diff-scoped measurement would silently include). The shared
            // {@see scopedChangedFiles} intersects the changed .php files against allowed_files.
            [$scoped, $scopeViolation] = $this->scopedChangedFiles($changedFiles, $allowedFiles);
            $complexityProof = $this->measureComplexityReduction($workspace, $scoped);
        }

        $reasons = $this->reasons($deterministicGate, $panelVerdict, $mutationAdequacy, $crossFileConsumers, $providerRefuters);
        if ($this->complexityProofRequired($targetAcceptance)) {
            if ($scopeViolation !== []) {
                $reasons[] = 'complexity_gate:changed_files_outside_allowed:'.implode(',', array_slice($scopeViolation, 0, 5));
            }
            if (($complexityProof['reduced'] ?? null) !== true) {
                // fail-closed: not reduced, OR null/error measuring (could not verify the drop).
                $reasons[] = is_array($complexityProof)
                    ? 'complexity_gate:complexity_not_reduced'
                    : 'complexity_gate:measurement_failed';
            }
        }
        $reasons = AiStringListNormalizer::uniqueStrings($reasons);
        $certified = $reasons === [];
        $receipt = [
            'schema_version' => self::SCHEMA,
            'status' => $certified ? 'certified' : 'refuted',
            'certified' => $certified,
            'level' => $this->level($providerRefuters),
            'objective' => $objective,
            'workspace' => $workspace,
            'changed_files' => $changedFiles,
            'allowed_files' => $allowedFiles,
            'reasons' => $certified ? ['certified'] : $reasons,
            'deterministic_gate' => $deterministicGate,
            'adversarial_panel' => $panelVerdict,
            'mutation_adequacy_gate' => $mutationAdequacy,
            'cross_file_consumer_gate' => $crossFileConsumers,
            'provider_refuters' => $providerRefuters,
            'complexity_proof' => $complexityProof,
            'evidence' => [
                'target_acceptance_passed' => (bool) data_get($deterministicGate, 'report.holdouts.target_frozen_passed', false),
                'complexity_proof_required' => $this->complexityProofRequired($targetAcceptance),
                'complexity_reduced' => is_array($complexityProof) ? (bool) ($complexityProof['reduced'] ?? false) : false,
                'complexity_baseline_max' => is_array($complexityProof) ? (int) ($complexityProof['baseline_max'] ?? 0) : null,
                'complexity_candidate_max' => is_array($complexityProof) ? (int) ($complexityProof['candidate_max'] ?? 0) : null,
                'complexity_baseline_total' => is_array($complexityProof) ? (int) ($complexityProof['baseline_total'] ?? 0) : null,
                'complexity_candidate_total' => is_array($complexityProof) ? (int) ($complexityProof['candidate_total'] ?? 0) : null,
                'diff_earned' => data_get($deterministicGate, 'report.holdouts.diff_earned') === true,
                'sealed_holdout_passed' => data_get($deterministicGate, 'report.holdouts.sealed_holdout_passed') === true,
                'adversarial_refuted_count' => (int) ($panelVerdict['refuted_count'] ?? 0),
                'mutation_gate_enabled' => (bool) config('atlas.loop.mutation_adequacy_gate.enabled', false),
                'mutation_mutants_sampled' => (int) ($mutationAdequacy['mutants_sampled'] ?? 0),
                'mutation_mutants_killed' => (int) ($mutationAdequacy['mutants_killed'] ?? 0),
                'mutation_mutants_survived' => (int) ($mutationAdequacy['mutants_survived'] ?? 0),
                'cross_file_gate_enabled' => (bool) config('atlas.loop.cross_file_consumer_gate.enabled', false),
                'cross_file_changed_symbols' => count((array) data_get($crossFileConsumers, 'code_graph.changed_symbols', [])),
                'cross_file_consumer_contracts' => (int) ($crossFileConsumers['consumer_contract_count'] ?? 0),
                'cross_file_consumer_failures' => (int) ($crossFileConsumers['consumer_contracts_failed'] ?? 0),
                'provider_refuters_required' => (int) ($providerRefuters['required'] ?? 0),
                'provider_refuters_executed' => (int) ($providerRefuters['executed'] ?? 0),
                'provider_refuters_refuted' => (int) ($providerRefuters['refuted'] ?? 0),
            ],
            'invariants' => [
                'proposal_only' => true,
                'merged_to_main' => false,
                'source_checkout_mutated' => false,
            ],
            'generated_at' => time(),
        ];

        $receiptPath = trim((string) ($options['receipt_path'] ?? ''));
        if ($receiptPath !== '') {
            $this->writeJson($receiptPath, $receipt);
            $receipt['receipt_path'] = $receiptPath;
        }

        return $receipt;
    }

    /**
     * @param  array<string,mixed>  $options
     * @return list<string>
     */
    private function refuterCommands(array $options): array
    {
        $commands = [];
        foreach (['semantic_refuter_commands', 'provider_refuter_commands', 'refuter_commands'] as $key) {
            foreach (AiStringListNormalizer::trimmedStrings($options[$key] ?? []) as $command) {
                $commands[] = $command;
            }
        }

        return AiStringListNormalizer::uniqueStrings($commands);
    }

    /**
     * @param  array<string,mixed>  $options
     */
    private function requiredRefuters(array $options, int $configured): int
    {
        foreach (['provider_refuters_required', 'refuters_required', 'refuters'] as $key) {
            if (isset($options[$key]) && is_numeric($options[$key])) {
                return max(0, (int) $options[$key]);
            }
        }

        return $configured;
    }

    /**
     * @param  array<string,mixed>  $targetAcceptance
     * @param  array<string,mixed>  $deterministicGate
     * @param  list<string>  $changedFiles
     * @param  array<string,string>  $changedFileContents
     * @return array<string,mixed>
     */
    private function panelCycle(
        string $workspace,
        string $objective,
        array $allowedFiles,
        array $targetAcceptance,
        array $deterministicGate,
        array $changedFiles,
        array $changedFileContents,
        array $changedAddedLines = [],
    ): array {
        $commands = AiStringListNormalizer::uniqueTrimmedStrings(array_merge(
            AiStringListNormalizer::trimmedStrings($targetAcceptance['commands'] ?? []),
            array_map(
                static fn (array $r): string => (string) ($r['command'] ?? ''),
                is_array(data_get($deterministicGate, 'report.sealed_holdout_results')) ? data_get($deterministicGate, 'report.sealed_holdout_results') : [],
            ),
        ));

        return [
            'cycle_id' => hash('sha256', $workspace.'|'.$objective.'|'.implode('|', $changedFiles)),
            'objective' => $objective,
            'changed_files' => $changedFiles,
            'allowed_files' => $allowedFiles,
            'selected_finding' => ['affected_files' => $allowedFiles],
            'validation' => [
                'ran' => true,
                'passed' => (bool) ($deterministicGate['certified'] ?? false),
                'commands' => $commands,
            ],
            'changed_file_contents' => $changedFileContents,
            // Diff-scoped: as linhas que o diff ADICIONOU por arquivo — o painel checa
            // markers de incompletude no delta, não nos TODOs pré-existentes do alvo.
            'changed_added_lines' => $changedAddedLines,
            'outcome_measured' => true,
            'outcome_metric' => [
                'outcome_met' => (bool) ($deterministicGate['certified'] ?? false),
                'target_acceptance_passed' => (bool) data_get($deterministicGate, 'report.holdouts.target_frozen_passed', false),
                'diff_earned' => data_get($deterministicGate, 'report.holdouts.diff_earned') === true,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $packet
     * @param  list<string>  $commands
     * @param  array{required:int,provider:?string,timeout_seconds:int}  $config
     * @return array<string,mixed>
     */
    private function runRefuters(string $workspace, array $packet, array $commands, array $config): array
    {
        $required = max(0, (int) $config['required']);
        $timeout = max(1, (int) $config['timeout_seconds']);
        $packetPath = tempnam(sys_get_temp_dir(), 'atlas-semantic-refuter-');
        if ($packetPath === false) {
            throw new RuntimeException('semantic certification: cannot create refuter packet');
        }
        file_put_contents($packetPath, json_encode($packet, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $verdicts = [];
        try {
            foreach ($commands as $index => $command) {
                $process = Process::fromShellCommandline($command, $workspace, [
                    'ATLAS_SEMANTIC_REFUTER_PACKET' => $packetPath,
                    'ATLAS_SEMANTIC_REFUTER_INDEX' => (string) ($index + 1),
                    'ATLAS_SEMANTIC_REFUTER_PROVIDER' => (string) ($config['provider'] ?? ''),
                ], null, (float) $timeout);
                $process->run();
                $verdicts[] = $this->refuterVerdict($index + 1, $command, $process);
            }
        } finally {
            @unlink($packetPath);
        }

        $refuted = count(array_filter($verdicts, static fn (array $v): bool => (bool) ($v['refuted'] ?? false)));
        $executed = count($verdicts);
        $metRequired = $executed >= $required;

        return [
            'schema_version' => self::SCHEMA.'.provider_refuters.v1',
            'provider' => $config['provider'],
            'required' => $required,
            'configured' => count($commands),
            'executed' => $executed,
            'met_required' => $metRequired,
            'refuted' => $refuted,
            'verdicts' => $verdicts,
            'fail_closed' => true,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function refuterVerdict(int $index, string $command, Process $process): array
    {
        $stdout = trim((string) $process->getOutput());
        $stderr = trim((string) $process->getErrorOutput());
        $json = $stdout !== '' ? json_decode($stdout, true) : null;
        $parsed = is_array($json);
        $exit = $process->getExitCode() ?? 1;
        $refuted = $exit !== 0;
        $reason = $refuted ? 'command_exit_'.$exit : 'no_refutation';

        if ($parsed) {
            $refuted = (bool) ($json['refuted'] ?? $refuted);
            $reason = trim((string) ($json['reason'] ?? $json['detail'] ?? $reason)) ?: $reason;
        }

        return [
            'index' => $index,
            'command' => $command,
            'exit_code' => $exit,
            'refuted' => $refuted,
            'reason' => $reason,
            'stdout' => $this->excerpt($stdout),
            'stderr' => $this->excerpt($stderr),
            'parsed_json' => $parsed,
        ];
    }

    /**
     * @param  array<string,mixed>  $deterministicGate
     * @param  array<string,mixed>  $panelVerdict
     * @param  array<string,mixed>  $providerRefuters
     * @return list<string>
     */
    private function reasons(array $deterministicGate, array $panelVerdict, array $mutationAdequacy, array $crossFileConsumers, array $providerRefuters): array
    {
        $reasons = [];
        if (! (bool) ($deterministicGate['certified'] ?? false)) {
            foreach ((array) ($deterministicGate['reasons'] ?? ['deterministic_gate_failed']) as $reason) {
                $reasons[] = 'deterministic_gate:'.(string) $reason;
            }
        }
        if (! (bool) ($panelVerdict['merge_allowed'] ?? false)) {
            $reasons[] = 'adversarial_panel:'.(string) ($panelVerdict['reason'] ?? 'refuted');
        }
        if (! (bool) ($mutationAdequacy['certified'] ?? false)) {
            $reasons[] = 'mutation_adequacy_gate:'.(string) ($mutationAdequacy['status'] ?? 'blocked');
            foreach ((array) ($mutationAdequacy['blockers'] ?? []) as $blocker) {
                $reasons[] = 'mutation_adequacy_gate:'.(string) $blocker;
            }
        }
        if (! (bool) ($crossFileConsumers['certified'] ?? false)) {
            $reasons[] = 'cross_file_consumer_gate:'.(string) ($crossFileConsumers['status'] ?? 'blocked');
            foreach ((array) ($crossFileConsumers['blockers'] ?? []) as $blocker) {
                $reasons[] = 'cross_file_consumer_gate:'.(string) $blocker;
            }
        }
        if (! (bool) ($providerRefuters['met_required'] ?? false)) {
            $reasons[] = 'provider_refuters_missing(required:'.(int) ($providerRefuters['required'] ?? 0).',executed:'.(int) ($providerRefuters['executed'] ?? 0).')';
        }
        foreach ((array) ($providerRefuters['verdicts'] ?? []) as $verdict) {
            if (is_array($verdict) && (bool) ($verdict['refuted'] ?? false)) {
                $reasons[] = 'provider_refuter_'.(int) ($verdict['index'] ?? 0).':'.(string) ($verdict['reason'] ?? 'refuted');
            }
        }

        return AiStringListNormalizer::uniqueStrings($reasons);
    }

    /**
     * Is this a refactor contract that must PROVE a complexity drop? Gated on the ACCEPTANCE
     * flag (set by the framework/within-file refactor synthesizer), never a config flag — a
     * vanilla implementation contract has complexity_proof absent and skips the gate entirely.
     *
     * @param  array<string,mixed>  $acceptance
     */
    private function complexityProofRequired(array $acceptance): bool
    {
        return (bool) ($acceptance['complexity_proof'] ?? false)
            && (string) ($acceptance['metric_kind'] ?? '') === AtlasEvolutionFrozenJudge::METRIC_MINIMIZE;
    }

    /**
     * PUBLIC, reusable aggregate-drop measurement scoped to allowed_files — the ungameable refactor
     * judge ({@see measureComplexityReduction}) plus the {@see scopedChangedFiles} scope intersect.
     * Used by {@see certify()} AND by the obra EXECUTION adapter, which replays the obra net diff
     * into a base_head worktree and re-measures the drop (the executor's integrated check proves
     * behaviour preserved, NOT complexity reduced — this is the separate quality gate).
     *
     * reduced is true ONLY when NO changed .php file lands outside allowed_files AND the scoped
     * aggregate AST max-per-method dropped (total non-increasing). Fail-closed: an out-of-scope edit
     * or an unmeasurable/no-op diff => reduced=false.
     *
     * @param  list<string>  $changedFiles
     * @param  list<string>  $allowedFiles
     * @return array{reduced:bool, scope_violation:list<string>, proof:array<string,mixed>|null}
     */
    public function measureScopedComplexityDrop(string $workspace, array $changedFiles, array $allowedFiles): array
    {
        [$scoped, $violations] = $this->scopedChangedFiles($changedFiles, $allowedFiles);
        $proof = $this->measureComplexityReduction($workspace, $scoped);
        $reduced = $violations === [] && is_array($proof) && ($proof['reduced'] ?? null) === true;

        return ['reduced' => $reduced, 'scope_violation' => $violations, 'proof' => is_array($proof) ? $proof : null];
    }

    /**
     * Intersect the changed .php files against allowed_files: the SCOPED set to measure, plus any
     * out-of-allowed .php files (the scope violation). An empty allowed set measures everything.
     *
     * @param  list<string>  $changedFiles
     * @param  list<string>  $allowedFiles
     * @return array{0:list<string>, 1:list<string>}  [scoped, violations]
     */
    private function scopedChangedFiles(array $changedFiles, array $allowedFiles): array
    {
        if ($allowedFiles === []) {
            return [array_values($changedFiles), []];
        }
        $allowSet = array_flip(array_map(static fn (string $f): string => ltrim($f, '/'), $allowedFiles));
        $scoped = [];
        $violations = [];
        foreach ($changedFiles as $cf) {
            $norm = ltrim((string) $cf, '/');
            if (! str_ends_with($norm, '.php')) {
                continue;
            }
            if (isset($allowSet[$norm])) {
                $scoped[] = $cf;
            } else {
                $violations[] = $norm;
            }
        }

        return [$scoped, $violations];
    }

    /**
     * The ungameable refactor proof for the framework path: did the candidate genuinely REDUCE
     * complexity? Replicates {@see AtlasEvolutionFrozenJudge::complexityEarned} EXACTLY (the same
     * AST analyzer, the same git-stash machinery, the same aggregation rule) so the framework
     * certifier and the within-file judge measure the SAME fact. The diff is LIVE in this gate
     * workspace, so measure the CANDIDATE first, stash to the committed baseline, measure BASELINE,
     * and restore (try/finally guarantees the pop even on error).
     *
     * AGGREGATION: reduced = candidate_max < baseline_max AND candidate_total <= baseline_total.
     * The PRIMARY metric is max-per-method cyclomatic (extracting from the worst method registers
     * a real drop even when the file total stays flat); the file total may not increase, so
     * "split one ugly method into two uglier ones" cannot game the max.
     *
     * @param  list<string>  $changedFiles
     * @return array{baseline_max:int,candidate_max:int,baseline_total:int,candidate_total:int,reduced:bool}|null
     *                                  null = could not verify (no PHP file / no diff to stash /
     *                                  git error / unparseable) => fail closed
     */
    private function measureComplexityReduction(string $workspace, array $changedFiles): ?array
    {
        $phpFiles = array_values(array_filter(
            $changedFiles,
            static fn (string $f): bool => str_ends_with($f, '.php'),
        ));
        if ($phpFiles === []) {
            return null; // nothing measurable -> fail closed
        }
        $absPaths = array_map(static fn (string $f): string => $workspace.'/'.ltrim($f, '/'), $phpFiles);

        $analyzer = $this->signalAnalyzer ?? new AtlasLoopSignalAnalyzer();

        // CANDIDATE first: the diff is live in the working tree right now.
        $candidate = $analyzer->aggregateComplexity($absPaths);
        if (! $candidate['measured']) {
            return null; // candidate unparseable -> fail closed
        }

        $stash = new Process(['git', 'stash', 'push', '--include-untracked', '--quiet'], $workspace, null, null, 60.0);
        $stash->run();
        if (! $stash->isSuccessful() || ! $this->stashCreated($workspace)) {
            return null; // no diff to stash (no-op candidate) -> fail closed
        }

        try {
            $baseline = $analyzer->aggregateComplexity($absPaths);
        } finally {
            (new Process(['git', 'stash', 'pop', '--quiet'], $workspace, null, null, 60.0))->run();
        }

        if (! $baseline['measured']) {
            return null; // baseline unparseable -> fail closed
        }

        // Secondary "no new complexity" guard on DECISION POINTS (total − methods), not raw total:
        // extract-method adds +1 to total per new method (base cyclomatic 1), so a raw-total gate
        // falsely rejects legitimate extraction — the only way to cut max-per-method (proven live: a
        // worst-method 19→4 refactor was rejected purely because 10 new helpers raised total). The
        // max-gate still blocks gaming (no method may exceed baseline max). Flag default ON.
        $decisionsGate = (bool) config('atlas.loop.complexity_decisions_gate', true);
        $perFileGate = (bool) config('atlas.loop.complexity_per_file_max_gate', true);
        $candidateAgg = $decisionsGate ? ($candidate['total'] - ($candidate['methods'] ?? 0)) : $candidate['total'];
        $baselineAgg = $decisionsGate ? ($baseline['total'] - ($baseline['methods'] ?? 0)) : $baseline['total'];
        // PER-FILE reduced verdict (shared single source with the frozen judge so the two cannot
        // drift): a multi-file cluster refactor that simplifies the hub but not the cluster's
        // global-worst method (living in an UNTOUCHED sibling) used to be FALSE-rejected — the
        // big-obra=3/10 keystone. Single-file behaviour is byte-identical (per-file max == global max).
        $reduced = AtlasLoopSignalAnalyzer::complexityReduced($baseline, $candidate, $decisionsGate, $perFileGate);

        return [
            'baseline_max' => $baseline['max_per_method'],
            'candidate_max' => $candidate['max_per_method'],
            'baseline_total' => $baseline['total'],
            'candidate_total' => $candidate['total'],
            'baseline_decisions' => $baseline['total'] - ($baseline['methods'] ?? 0),
            'candidate_decisions' => $candidate['total'] - ($candidate['methods'] ?? 0),
            'reduced' => $reduced,
        ];
    }

    /** True when a stash entry exists (the push actually captured changes). */
    private function stashCreated(string $workspace): bool
    {
        $list = new Process(['git', 'stash', 'list'], $workspace, null, null, 30.0);
        $list->run();

        return trim((string) $list->getOutput()) !== '';
    }

    /**
     * @param  array<string,mixed>  $providerRefuters
     */
    private function level(array $providerRefuters): string
    {
        if ((int) ($providerRefuters['required'] ?? 0) > 0 && (bool) ($providerRefuters['met_required'] ?? false)) {
            return 'semantic_implementation_certified_with_external_refuters';
        }

        return 'semantic_implementation_certified_with_deterministic_panel';
    }

    /**
     * @return list<string>
     */
    /**
     * As linhas que o diff ADICIONOU, por arquivo (rel => texto das linhas '+'). Para
     * arquivos novos/untracked, o conteúdo inteiro é "adicionado". Best-effort: erro de
     * git ⇒ mapa vazio (o painel cai no scan file-scoped, fail-closed preservado).
     *
     * @param  list<string>  $changedFiles
     * @return array<string,string>
     */
    private function addedLines(string $workspace, array $changedFiles): array
    {
        $out = [];
        $p = new Process(['git', 'diff', '--unified=0', '--no-ext-diff'], $workspace, null, null, 30.0);
        $p->run();
        $current = null;
        foreach (preg_split('/\R/', (string) $p->getOutput()) ?: [] as $line) {
            if (str_starts_with($line, '+++ b/')) {
                $current = substr($line, 6);

                continue;
            }
            if ($current !== null && str_starts_with($line, '+') && ! str_starts_with($line, '+++')) {
                $out[$current] = ($out[$current] ?? '')."\n".substr($line, 1);
            }
        }
        // Untracked (arquivo novo): tudo é adição.
        $ls = new Process(['git', 'ls-files', '--others', '--exclude-standard'], $workspace, null, null, 30.0);
        $ls->run();
        foreach (preg_split('/\R/', trim((string) $ls->getOutput())) ?: [] as $rel) {
            $rel = trim($rel);
            if ($rel !== '' && ! isset($out[$rel]) && is_file($workspace.'/'.$rel)) {
                $out[$rel] = (string) @file_get_contents($workspace.'/'.$rel);
            }
        }

        return $out;
    }

    private function changedFiles(string $workspace): array
    {
        $files = [];
        foreach ([
            ['git', 'diff', '--name-only', '--no-ext-diff'],
            ['git', 'ls-files', '--others', '--exclude-standard'],
        ] as $argv) {
            $process = new Process($argv, $workspace, null, null, 30.0);
            $process->run();
            if (! $process->isSuccessful() && $process->getExitCode() !== 1) {
                continue;
            }
            foreach (preg_split('/\R/', trim((string) $process->getOutput())) ?: [] as $line) {
                $line = trim($line);
                if ($line !== '') {
                    $files[$line] = true;
                }
            }
        }

        return array_keys($files);
    }

    /**
     * @param  list<string>  $files
     * @return array<string,string>
     */
    private function changedFileContents(string $workspace, array $files): array
    {
        $contents = [];
        foreach ($files as $file) {
            $path = $workspace.'/'.$file;
            if (is_file($path)) {
                $contents[$file] = (string) file_get_contents($path);
            }
        }

        return $contents;
    }

    /**
     * @param  array<string,mixed>  $acceptance
     * @return array<string,mixed>
     */
    private function redactedAcceptance(array $acceptance): array
    {
        return [
            'commands' => AiStringListNormalizer::trimmedStrings($acceptance['commands'] ?? []),
            'allowed_globs' => AiStringListNormalizer::trimmedStrings($acceptance['allowed_globs'] ?? []),
            'frozen_globs' => AiStringListNormalizer::trimmedStrings($acceptance['frozen_globs'] ?? []),
            'metric_kind' => (string) ($acceptance['metric_kind'] ?? ''),
        ];
    }

    private function writeJson(string $path, array $payload): void
    {
        $dir = dirname($path);
        if (! is_dir($dir) && ! mkdir($dir, 0o755, true) && ! is_dir($dir)) {
            throw new RuntimeException('semantic certification: cannot create receipt dir '.$dir);
        }
        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
    }

    private function excerpt(string $text): string
    {
        $text = trim($text);

        return mb_strlen($text) > 1200 ? mb_substr($text, 0, 1200).'…' : $text;
    }
}
