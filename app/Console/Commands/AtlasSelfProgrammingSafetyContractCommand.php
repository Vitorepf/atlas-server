<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasSelfProgrammingSafetyContractService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Self-Programming Safety Contract decider CLI.
 *
 *   php artisan atlas:aaeos:self-programming-safety-contract
 *     [--mutation-target=auth_security_boundary]
 *     [--docs-stale] [--context-missing] [--high-risk]
 *     [--no-tests] [--no-rollback] [--forbidden-files] [--validation-failed]
 *     [--precondition=docs_current,context_fresh,...]   // met preconditions
 *     [--json]
 *
 * Read-only, deterministic. Emits the bounded self-programming decision
 * (autonomy ceiling + whether auto-apply / human gate is required) for one
 * proposed change to Atlas itself.
 *
 * @see docs/engineering-knowledge-base/self-construction/self-programming-safety-contract.md
 */
class AtlasSelfProgrammingSafetyContractCommand extends Command
{
    protected $signature = 'atlas:aaeos:self-programming-safety-contract
        {--mutation-target= : proposed mutation target (enum or free-form path/topic)}
        {--precondition= : comma-separated preconditions that ARE met (default: all met)}
        {--docs-stale : risk condition docs_stale}
        {--context-missing : risk condition context_missing}
        {--high-risk : risk condition high_risk}
        {--no-tests : risk condition no_tests}
        {--no-rollback : risk condition no_rollback}
        {--forbidden-files : risk condition forbidden_files_involved}
        {--validation-failed : risk condition validation_failed}
        {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas self-construction · self-programming safety contract decider (autonomy ceiling for one proposed change).';

    /** Closed set of precondition keys the contract requires. */
    private const PRECONDITION_KEYS = [
        'docs_current',
        'context_fresh',
        'spec_present',
        'files_declared',
        'gates_runnable',
        'rollback_present',
        'evidence_known',
        'drift_reviewable',
    ];

    public function handle(AtlasSelfProgrammingSafetyContractService $service): int
    {
        try {
            $preconditionOpt = $this->option('precondition');
            if (is_string($preconditionOpt) && trim($preconditionOpt) !== '') {
                $metKeys = array_values(array_filter(
                    array_map('trim', explode(',', $preconditionOpt)),
                    static fn ($v) => $v !== '',
                ));
            } else {
                // Safe default: all preconditions met, so only the risk flags drive the ceiling.
                $metKeys = self::PRECONDITION_KEYS;
            }
            $preconditions = [];
            foreach (self::PRECONDITION_KEYS as $key) {
                $preconditions[$key] = in_array($key, $metKeys, true);
            }

            $decision = $service->decide([
                'preconditions' => $preconditions,
                'mutation_target' => $this->option('mutation-target') ?? '',
                'conditions' => [
                    'docs_stale' => (bool) $this->option('docs-stale'),
                    'context_missing' => (bool) $this->option('context-missing'),
                    'high_risk' => (bool) $this->option('high-risk'),
                    'no_tests' => (bool) $this->option('no-tests'),
                    'no_rollback' => (bool) $this->option('no-rollback'),
                    'forbidden_files_involved' => (bool) $this->option('forbidden-files'),
                    'validation_failed' => (bool) $this->option('validation-failed'),
                ],
            ]);

            $this->line((string) json_encode(
                ['ok' => true, 'decision' => $decision],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'self_programming_safety_contract_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
