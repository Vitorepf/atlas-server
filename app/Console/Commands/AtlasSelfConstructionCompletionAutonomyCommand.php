<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\Completion\AtlasSelfConstructionAutonomyDependencyAudit;
use App\Services\Ai\SelfConstruction\Completion\AtlasSelfConstructionAutonomyTransitionMap;
use App\Services\Ai\SelfConstruction\Completion\AtlasSelfConstructionCodeIndexReadinessBridge;
use App\Services\Ai\SelfConstruction\Completion\AtlasSelfConstructionFinalAutonomyVerdict;
use Illuminate\Console\Command;
use Throwable;

/**
 * Read-only operator surface for the final autonomy completion brain.
 *
 *   audit             AtlasSelfConstructionAutonomyDependencyAudit::audit(facts.evidence)
 *   transition-map    AtlasSelfConstructionAutonomyTransitionMap::transition(audit_verdict)
 *   policy            echoes the readiness policy facts (no business decision)
 *   verdict           composes audit + transition-map + readiness into AtlasSelfConstructionFinalAutonomyVerdict
 *   code-index-readiness  AtlasSelfConstructionCodeIndexReadinessBridge::verify(facts.code_index ?? facts) —
 *                         the OS must NOT claim ready while code indexing is missing, stale, or schema-drifted
 *
 * Every action is read-only. Payload always carries:
 *   final_runtime_owner          = "atlas_native"
 *   steady_state_runtime_owner   = "atlas_server"
 *
 * Fail-closed on missing/invalid facts. NEVER mutates runtime.
 */
final class AtlasSelfConstructionCompletionAutonomyCommand extends Command
{
    public const EXIT_OK = 0;

    public const EXIT_USAGE = 2;

    public const FINAL_RUNTIME_OWNER = 'atlas_native';

    public const STEADY_STATE_RUNTIME_OWNER = 'atlas_server';

    protected $signature = 'atlas:self-construction:completion-autonomy {action : audit|transition-map|policy|verdict|code-index-readiness|final-brain-score} {--facts=} {--json}';

    protected $description = 'Read-only final-autonomy completion CLI: audit | transition-map | policy | verdict | code-index-readiness.';

    public function handle(
        AtlasSelfConstructionAutonomyDependencyAudit $auditSvc,
        AtlasSelfConstructionAutonomyTransitionMap $transitionSvc,
        AtlasSelfConstructionFinalAutonomyVerdict $verdictSvc,
        AtlasSelfConstructionCodeIndexReadinessBridge $codeIndexBridge,
    ): int {
        $action = (string) $this->argument('action');
        $facts = $this->loadFacts();
        if ($facts === null) {
            return self::EXIT_USAGE;
        }

        $payload = match ($action) {
            'audit' => $auditSvc->audit((array) ($facts['evidence'] ?? [])),
            'transition-map' => $transitionSvc->transition($auditSvc->audit((array) ($facts['evidence'] ?? []))),
            'policy' => ['policy_echo' => (array) ($facts['readiness'] ?? [])],
            'verdict' => $verdictSvc->compose(
                $auditSvc->audit((array) ($facts['evidence'] ?? [])),
                $transitionSvc->transition($auditSvc->audit((array) ($facts['evidence'] ?? []))),
                (array) ($facts['readiness'] ?? []),
            ),
            'code-index-readiness' => $codeIndexBridge->verify((array) ($facts['code_index'] ?? $facts)),
            'final-brain-score' => $this->finalBrainScore($facts, $auditSvc, $transitionSvc, $verdictSvc),
            default => null,
        };
        if ($payload === null) {
            $this->refuseUsage('unknown action: '.$action);

            return self::EXIT_USAGE;
        }

        $payload['final_runtime_owner'] = self::FINAL_RUNTIME_OWNER;
        $payload['steady_state_runtime_owner'] = self::STEADY_STATE_RUNTIME_OWNER;

        $this->emit($payload);

        return self::EXIT_OK;
    }

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    private function finalBrainScore(
        array $facts,
        AtlasSelfConstructionAutonomyDependencyAudit $auditSvc,
        AtlasSelfConstructionAutonomyTransitionMap $transitionSvc,
        AtlasSelfConstructionFinalAutonomyVerdict $verdictSvc,
    ): array {
        $auditResult = $auditSvc->audit((array) ($facts['evidence'] ?? []));
        $transitionResult = $transitionSvc->transition($auditResult);
        $capabilityFacts = (array) ($facts['capability_facts'] ?? []);

        $verdict = $verdictSvc->compose(
            $auditResult,
            $transitionResult,
            (array) ($facts['readiness'] ?? []),
            $capabilityFacts,
        );

        $missingTasks = array_values(array_map(
            static fn (string $b): string => substr($b, strlen('missing_capability_lane:')),
            array_filter($verdict['blockers'], static fn (string $b): bool => str_starts_with($b, 'missing_capability_lane:')),
        ));

        $score = (int) ($verdict['score'] ?? 0);

        return [
            'final_brain_score' => $score,
            'verdict' => $verdict['verdict'],
            'ready' => $score >= 90 && $verdict['verdict'] === AtlasSelfConstructionFinalAutonomyVerdict::VERDICT_COMPLETE,
            'criteria' => AtlasSelfConstructionFinalAutonomyVerdict::REQUIRED_CAPABILITY_LANES,
            'missing_tasks' => $missingTasks,
            'proof_commands' => [
                'php artisan atlas:self-construction:completion-autonomy final-brain-score --facts=<path> --json',
                'php artisan atlas:self-construction:completion-autonomy verdict --facts=<path> --json',
            ],
            'blockers' => $verdict['blockers'],
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function loadFacts(): ?array
    {
        $path = (string) $this->option('facts');
        if ($path === '' || ! is_file($path)) {
            $this->refuseUsage('--facts=<path> is required and must point to an existing JSON file');

            return null;
        }
        try {
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            $this->refuseUsage('facts payload not valid JSON: '.mb_substr($e->getMessage(), 0, 200));

            return null;
        }
        if (! is_array($decoded)) {
            $this->refuseUsage('facts payload root must be a JSON object');

            return null;
        }

        return $decoded;
    }

    private function refuseUsage(string $reason): void
    {
        if ($this->option('json')) {
            $this->line((string) json_encode(['status' => 'usage_error', 'reason' => $reason], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return;
        }
        $this->error($reason);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload): void
    {
        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return;
        }
        foreach ($payload as $k => $v) {
            $this->line($k.': '.(is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_SLASHES)));
        }
    }
}
