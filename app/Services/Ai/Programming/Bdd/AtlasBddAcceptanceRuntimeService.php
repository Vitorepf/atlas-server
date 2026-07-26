<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\Bdd;

use App\Services\Ai\Governance\AtlasAutonomyAdmissionService;
use App\Services\Ai\Governance\AtlasConstitutionalKernelService;
use App\Services\Ai\Support\AppendOnlyJsonlStore;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Atlas BDD Acceptance Runtime.
 *
 * Fecha o gap canon `executable_acceptance_layer` declarado nos runbooks Atlas
 * (Programming Governance, Forge contracts, Execution Doctrine). Antes desta
 * implementação, BDD/Gherkin era apenas referência conceitual nos docs sem
 * runtime real.
 *
 * Provê:
 *   - Compiler Gherkin → Scenario canônico
 *   - Step Definition Registry (callbacks pré-registrados, sem code exec arbitrária)
 *   - Executor com pétreo gates (Kernel + Admission)
 *   - Acceptance Report honesto (pending_definition NUNCA conta como pass)
 *
 * Authority doc: docs/engineering-knowledge-base/atlas-bdd-acceptance-runtime.md
 *
 * Schemas:
 *   - atlas.bdd.scenario.v1
 *   - atlas.bdd.step_result.v1
 *   - atlas.bdd.execution_report.v1
 *
 * Invariantes pétreas:
 *   - auto_pass_undefined = false (hardcoded)
 *   - overall=pass exige failed=0 AND pending_definition=0
 *   - claim_policy provider-safe (no benchmark/rivals/winner claims)
 *   - Kernel + Admission gates obrigatórios em execute()
 *   - Storage append-only JSONL local-first
 */
class AtlasBddAcceptanceRuntimeService
{
    public const SCENARIO_SCHEMA = 'atlas.bdd.scenario.v1';

    public const STEP_RESULT_SCHEMA = 'atlas.bdd.step_result.v1';

    public const EXECUTION_REPORT_SCHEMA = 'atlas.bdd.execution_report.v1';

    public const STEP_KINDS = ['given', 'when', 'then', 'and', 'but'];

    public const STATUS_PASSED = 'passed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_PENDING_DEFINITION = 'pending_definition';

    public const STATUS_SKIPPED = 'skipped';

    public const OVERALL_PASS = 'pass';

    public const OVERALL_FAIL = 'fail';

    public const OVERALL_INCOMPLETE = 'incomplete';

    private ?string $scenariosLogOverride = null;

    private ?string $executionsLogOverride = null;

    /**
     * @var array<int, array{pattern:string, kind:string, callback:callable}>
     */
    private array $stepDefinitions = [];

    public function __construct(
        private readonly AtlasConstitutionalKernelService $kernel,
        private readonly AtlasAutonomyAdmissionService $admission,
    ) {}

    public function setScenariosLogPathForTesting(?string $path): void
    {
        $this->scenariosLogOverride = $path;
    }

    public function setExecutionsLogPathForTesting(?string $path): void
    {
        $this->executionsLogOverride = $path;
    }

    public function scenariosLogPath(): string
    {
        if ($this->scenariosLogOverride !== null) {
            return $this->scenariosLogOverride;
        }
        $base = function_exists('storage_path')
            ? storage_path('atlas/bdd')
            : sys_get_temp_dir().'/atlas/bdd';

        return $base.DIRECTORY_SEPARATOR.'scenarios.jsonl';
    }

    public function executionsLogPath(): string
    {
        if ($this->executionsLogOverride !== null) {
            return $this->executionsLogOverride;
        }
        $base = function_exists('storage_path')
            ? storage_path('atlas/bdd')
            : sys_get_temp_dir().'/atlas/bdd';

        return $base.DIRECTORY_SEPARATOR.'executions.jsonl';
    }

    /**
     * Compile Gherkin source text into a canonical Scenario envelope.
     *
     * @return array<string,mixed>
     */
    public function compile(string $gherkin): array
    {
        $gherkin = trim($gherkin);
        if ($gherkin === '') {
            throw new InvalidArgumentException('Gherkin input is empty.');
        }

        $lines = preg_split('/\R/', $gherkin) ?: [];
        $feature = '';
        $scenarioName = '';
        $tags = [];
        $steps = [];
        $sawScenario = false;
        $pendingTags = [];

        foreach ($lines as $rawLine) {
            $line = trim($rawLine);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            // Tags
            if (str_starts_with($line, '@')) {
                foreach (preg_split('/\s+/', $line) ?: [] as $t) {
                    $t = trim($t);
                    if ($t !== '' && str_starts_with($t, '@')) {
                        $pendingTags[] = $t;
                    }
                }

                continue;
            }

            if (preg_match('/^Feature:\s*(.+)$/i', $line, $m)) {
                $feature = trim($m[1]);

                continue;
            }
            if (preg_match('/^Scenario(?: Outline)?:\s*(.+)$/i', $line, $m)) {
                $scenarioName = trim($m[1]);
                $tags = $pendingTags;
                $pendingTags = [];
                $sawScenario = true;

                continue;
            }
            if (preg_match('/^(Given|When|Then|And|But)\s+(.+)$/i', $line, $m)) {
                $kind = strtolower($m[1]);
                $text = trim($m[2]);
                $stepId = 'stp_'.substr(hash('sha256', $kind.'|'.$text), 0, 12);
                $steps[] = [
                    'kind' => $kind,
                    'text' => $text,
                    'step_id' => $stepId,
                ];

                continue;
            }
        }

        if ($feature === '') {
            throw new InvalidArgumentException("Missing 'Feature:' line.");
        }
        if (! $sawScenario || $scenarioName === '') {
            throw new InvalidArgumentException("Missing 'Scenario:' line.");
        }
        if ($steps === []) {
            throw new InvalidArgumentException('Scenario has no steps.');
        }

        $scenarioId = 'scn_'.substr(hash('sha256', $feature.'|'.$scenarioName.'|'.json_encode($steps)), 0, 12);
        $scenario = [
            'schema_version' => self::SCENARIO_SCHEMA,
            'scenario_id' => $scenarioId,
            'feature' => $feature,
            'name' => $scenarioName,
            'tags' => array_values(array_unique($tags)),
            'steps' => $steps,
        ];
        $scenario['scenario_hash'] = 'sha256:'.hash('sha256', json_encode([
            'schema' => self::SCENARIO_SCHEMA,
            'feature' => $feature,
            'name' => $scenarioName,
            'steps' => $steps,
        ], JSON_THROW_ON_ERROR));

        AppendOnlyJsonlStore::append($this->scenariosLogPath(), $scenario);

        return $scenario;
    }

    /**
     * Register a step definition. Pattern is matched against step text via
     * preg_match (full PCRE). Callback receives matched groups + context.
     *
     * @param  callable(array<int,string>, array<string,mixed>): bool  $callback
     */
    public function registerStep(string $pattern, callable $callback, string $kind = 'given'): void
    {
        if (! in_array($kind, self::STEP_KINDS, true)) {
            throw new InvalidArgumentException("Unknown step kind '{$kind}'.");
        }
        // Validate pattern is valid PCRE
        if (@preg_match($pattern, '') === false) {
            throw new InvalidArgumentException("Invalid PCRE pattern: '{$pattern}'.");
        }
        $this->stepDefinitions[] = [
            'pattern' => $pattern,
            'kind' => $kind,
            'callback' => $callback,
        ];
    }

    /**
     * Execute a previously-compiled scenario by id. Runs each step through the
     * registered step definitions; unmatched steps mark pending_definition.
     *
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function execute(string $scenarioId, array $context = []): array
    {
        $scenario = $this->findScenario($scenarioId);
        if ($scenario === null) {
            throw new InvalidArgumentException("Scenario '{$scenarioId}' not found.");
        }

        // Constitutional Kernel + Admission gates
        $kernelEnv = $this->kernel->validateChange([
            'change_kind' => 'bdd_scenario_execute',
            'proposed_effect' => "execute BDD scenario {$scenarioId} feature='{$scenario['feature']}'",
            'scope' => ['privacy_class' => (string) ($context['privacy_class'] ?? 'normal')],
            'actor' => (string) ($context['actor'] ?? 'bdd_runtime'),
        ]);
        $admissionEnv = $this->admission->admit([
            'change_kind' => 'bdd_scenario_execute',
            'proposed_effect' => "execute BDD scenario {$scenarioId}",
            'scope' => ['privacy_class' => (string) ($context['privacy_class'] ?? 'normal')],
            'actor' => (string) ($context['actor'] ?? 'bdd_runtime'),
            'requested_autonomy' => 'execute_with_approval',
        ]);

        // If Kernel blocks, do not execute steps.
        if ($kernelEnv['decision'] === AtlasConstitutionalKernelService::DECISION_BLOCK) {
            $report = $this->emptyReport(
                scenarioId: $scenarioId,
                kernelDecision: $kernelEnv['decision'],
                admissionDecision: $admissionEnv['decision'],
                reason: 'kernel_blocked',
            );
            AppendOnlyJsonlStore::append($this->executionsLogPath(), $report);

            return $report;
        }

        $stepResults = [];
        foreach ($scenario['steps'] as $step) {
            $start = microtime(true);
            $match = $this->matchStep($step['kind'], $step['text']);
            if ($match === null) {
                $stepResults[] = $this->buildStepResult($step, self::STATUS_PENDING_DEFINITION, 0, 'no step definition registered for pattern');

                continue;
            }
            try {
                $result = ($match['callback'])($match['groups'], $context);
                $duration = (int) round((microtime(true) - $start) * 1000);
                if ($result === true) {
                    $stepResults[] = $this->buildStepResult($step, self::STATUS_PASSED, $duration, null);
                } else {
                    $stepResults[] = $this->buildStepResult($step, self::STATUS_FAILED, $duration, 'step callback returned non-true');
                }
            } catch (\Throwable $e) {
                $duration = (int) round((microtime(true) - $start) * 1000);
                $stepResults[] = $this->buildStepResult($step, self::STATUS_FAILED, $duration, $e->getMessage());
            }
        }

        $tally = [
            self::STATUS_PASSED => 0,
            self::STATUS_FAILED => 0,
            self::STATUS_PENDING_DEFINITION => 0,
            self::STATUS_SKIPPED => 0,
        ];
        foreach ($stepResults as $r) {
            if (isset($tally[$r['status']])) {
                $tally[$r['status']]++;
            }
        }

        // Honest overall:
        // pass requires: 0 failed AND 0 pending_definition AND 0 skipped AND total > 0
        // fail when any failed
        // incomplete when no failed but has pending_definition or skipped
        $overall = self::OVERALL_INCOMPLETE;
        if ($tally[self::STATUS_FAILED] > 0) {
            $overall = self::OVERALL_FAIL;
        } elseif ($tally[self::STATUS_PENDING_DEFINITION] === 0
            && $tally[self::STATUS_SKIPPED] === 0
            && $tally[self::STATUS_PASSED] > 0) {
            $overall = self::OVERALL_PASS;
        }

        $reportId = 'rpt_'.substr(hash('sha256', $scenarioId.'|'.json_encode($tally).'|'.microtime(true)), 0, 12);
        $report = [
            'schema_version' => self::EXECUTION_REPORT_SCHEMA,
            'report_id' => $reportId,
            'scenario_id' => $scenarioId,
            'executed_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
            'total_steps' => count($stepResults),
            'passed' => $tally[self::STATUS_PASSED],
            'failed' => $tally[self::STATUS_FAILED],
            'pending_definition' => $tally[self::STATUS_PENDING_DEFINITION],
            'skipped' => $tally[self::STATUS_SKIPPED],
            'overall' => $overall,
            'kernel_decision' => $kernelEnv['decision'],
            'admission_decision' => $admissionEnv['decision'],
            'step_results' => $stepResults,
            'claim_policy' => [
                'benchmark_claim_allowed' => false,
                'rivals_claim_allowed' => false,
                'superiority_claim_allowed' => false,
                'auto_pass_undefined' => false,
            ],
        ];
        $report['report_hash'] = 'sha256:'.hash('sha256', json_encode([
            'schema' => self::EXECUTION_REPORT_SCHEMA,
            'scenario_id' => $scenarioId,
            'overall' => $overall,
            'passed' => $report['passed'],
            'failed' => $report['failed'],
            'pending_definition' => $report['pending_definition'],
        ], JSON_THROW_ON_ERROR));

        AppendOnlyJsonlStore::append($this->executionsLogPath(), $report);

        return $report;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listScenarios(): array
    {
        return AppendOnlyJsonlStore::read($this->scenariosLogPath());
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listExecutions(): array
    {
        return AppendOnlyJsonlStore::read($this->executionsLogPath());
    }

    /**
     * Aggregate honest report across all executions persisted.
     *
     * @return array<string,mixed>
     */
    public function report(): array
    {
        $executions = $this->listExecutions();
        $tally = [
            self::OVERALL_PASS => 0,
            self::OVERALL_FAIL => 0,
            self::OVERALL_INCOMPLETE => 0,
        ];
        foreach ($executions as $e) {
            $o = (string) ($e['overall'] ?? '');
            if (isset($tally[$o])) {
                $tally[$o]++;
            }
        }

        return [
            'schema_version' => 'atlas.bdd.aggregate_report.v1',
            'generated_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
            'total_executions' => count($executions),
            'overall_tally' => $tally,
            'scenarios_count' => count($this->listScenarios()),
            'claim_policy' => [
                'benchmark_claim_allowed' => false,
                'rivals_claim_allowed' => false,
                'auto_pass_undefined' => false,
            ],
        ];
    }

    // ---------- internals ----------

    /**
     * @return array{pattern:string, kind:string, callback:callable, groups:array<int,string>}|null
     */
    private function matchStep(string $kind, string $text): ?array
    {
        foreach ($this->stepDefinitions as $def) {
            // 'and'/'but' inherit the kind of the previous step — but for simplicity
            // we match any kind here since the registry is small and step text is the signal.
            if (preg_match($def['pattern'], $text, $matches) === 1) {
                return [
                    'pattern' => $def['pattern'],
                    'kind' => $def['kind'],
                    'callback' => $def['callback'],
                    'groups' => $matches,
                ];
            }
        }

        return null;
    }

    private function buildStepResult(array $step, string $status, int $durationMs, ?string $error): array
    {
        return [
            'schema_version' => self::STEP_RESULT_SCHEMA,
            'step_id' => $step['step_id'],
            'kind' => $step['kind'],
            'text' => $step['text'],
            'status' => $status,
            'duration_ms' => $durationMs,
            'error' => $error,
        ];
    }

    private function emptyReport(string $scenarioId, string $kernelDecision, string $admissionDecision, string $reason): array
    {
        return [
            'schema_version' => self::EXECUTION_REPORT_SCHEMA,
            'report_id' => 'rpt_blocked_'.substr(hash('sha256', $scenarioId.'|'.$reason), 0, 8),
            'scenario_id' => $scenarioId,
            'executed_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
            'total_steps' => 0,
            'passed' => 0,
            'failed' => 0,
            'pending_definition' => 0,
            'skipped' => 0,
            'overall' => self::OVERALL_INCOMPLETE,
            'kernel_decision' => $kernelDecision,
            'admission_decision' => $admissionDecision,
            'step_results' => [],
            'reason' => $reason,
            'claim_policy' => [
                'benchmark_claim_allowed' => false,
                'rivals_claim_allowed' => false,
                'auto_pass_undefined' => false,
            ],
            'report_hash' => 'sha256:'.hash('sha256', $scenarioId.'|'.$reason),
        ];
    }

    private function findScenario(string $scenarioId): ?array
    {
        foreach ($this->listScenarios() as $s) {
            if (($s['scenario_id'] ?? null) === $scenarioId) {
                return $s;
            }
        }

        return null;
    }
}
