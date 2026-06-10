<?php

declare(strict_types=1);

namespace App\Services\Ai\Context;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Programming\ProgrammingPythonRuntimeContract;
use App\Services\Ai\Programming\ProgrammingPythonRuntimeExecutor;
use App\Services\Ai\Programming\ProgrammingPythonRuntimeGraphProjector;
use App\Services\Ai\Programming\ProgrammingPythonRuntimePolicy;
use Carbon\CarbonImmutable;

/**
 * AUCRI block 9 (APDR) — the GOVERNED wrapper over the *programming* Python runtime,
 * NOT a semantic/vector RAG retrieval engine.
 *
 * HONEST ROLE (R6): despite the legacy "…Retrieval…" in the canonical name
 * (`atlas-python-data-retrieval-runtime.md`, where "retrieval" means *retrieval
 * analytics* — clustering/eval/graph-fragment support, not prompt recall), this
 * service is wired by construction to the programming/code-intelligence runtime:
 * it injects {@see ProgrammingPythonRuntimeContract} (capability
 * `programming_ast_embeddings`), {@see ProgrammingPythonRuntimeExecutor} (which
 * spawns `runtimes/python/programming_intelligence/main.py`) and
 * {@see ProgrammingPythonRuntimeGraphProjector} (which emits a file→symbol→import
 * CODE graph). Its only production caller passes CODE FILES
 * ({@see \App\Services\Ai\Context\AtlasAucriRuntimeEnforcementService} → `['files' => ['composer.json']]`),
 * never a query + documents. The canonical doc states the runtime is "wrapper AUCRI
 * sobre o Programming Python Runtime"; the deeper analytics it advertises are still
 * gated behind a dedicated runtime contract.
 *
 * It deliberately does NOT invoke the real embeddings/semantic-RAG engine. That path
 * is {@see \App\Services\Ai\RuntimeBoundary\SemanticRagRuntimeClient} (spawns
 * `runtimes/python/semantic_rag/.venv/bin/python`, enforces the `real_embeddings`
 * boundary receipt) and is consumed by the live prompt-retrieval path
 * ({@see \App\Services\Ai\AtlasHybridMemoryRetrievalService}). Wiring THIS class to
 * semantic_rag would invent a RAG role the architecture assigns elsewhere; following
 * the {@see \App\Services\Ai\Context\AtlasAucriRuntimeEnforcementService} precedent,
 * the over-claim is corrected here in the contract, not by renaming the load-bearing
 * class / `APDR` schemas (DI bindings + persisted/hashed audit schemas + the canonical
 * doc's `technical_name`).
 */
final class AtlasPythonDataRetrievalRuntimeService
{
    public const REQUEST_SCHEMA = 'atlas.aucri.python_data_request.v1';

    public const EXECUTION_RECEIPT_SCHEMA = 'atlas.aucri.python_data_execution_receipt.v1';

    public const GRAPH_FRAGMENT_SCHEMA = 'atlas.aucri.python_graph_fragment.v1';

    public function __construct(
        private readonly ProgrammingPythonRuntimeContract $contract,
        private readonly ProgrammingPythonRuntimeExecutor $executor,
        private readonly ProgrammingPythonRuntimeGraphProjector $projector,
        private readonly ProgrammingPythonRuntimePolicy $policy,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function run(array $input): array
    {
        $workspace = $this->workspace($input['workspace'] ?? base_path());
        $files = $this->files($input['files'] ?? []);
        $decisionReceiptHash = $this->string($input['decision_receipt_hash'] ?? '') ?? '';
        $approved = (bool) ($input['approved'] ?? false);
        $runtimeBoundaryGreen = (bool) ($input['runtime_boundary_green'] ?? true);
        $execute = (bool) ($input['execute'] ?? false);

        $programmingContract = $this->contract->manifest($workspace, $files, (int) ($input['max_files'] ?? 40), (int) ($input['max_bytes_per_file'] ?? 250000));
        $request = $this->request($programmingContract, $decisionReceiptHash, $execute, $approved, $runtimeBoundaryGreen);
        $gate = $this->gate($request);
        $executionReceipt = null;
        $graphFragment = null;

        if ($execute && ($gate['status'] ?? null) === 'passed') {
            $rawReceipt = $this->executor->execute($programmingContract, $decisionReceiptHash, $runtimeBoundaryGreen, $approved);
            $executionReceipt = $this->executionReceipt($rawReceipt, $programmingContract);
            $graphFragment = $this->graphFragment($rawReceipt);
        } elseif ($execute) {
            $executionReceipt = [
                'schema_version' => self::EXECUTION_RECEIPT_SCHEMA,
                'status' => 'blocked',
                'gate' => $gate,
                'stdout_hash' => null,
                'stderr_hash' => null,
                'receipt_hash' => MissionCanonicalHash::sha256([
                    'request_hash' => $request['request_hash'],
                    'gate' => $gate,
                    'status' => 'blocked',
                ]),
            ];
        }

        $payload = [
            'schema_version' => 'atlas.aucri.python_data_runtime.v1',
            'status' => $this->status($programmingContract, $gate, $executionReceipt, $execute),
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'request' => $request,
            'execution_receipt' => $executionReceipt,
            'graph_fragment' => $graphFragment,
            'claim_policy' => [
                'kernel_decides_runtime_executes' => true,
                'providers_invoked' => false,
                'network_invoked' => false,
                'shell_freeform_allowed' => false,
                'memory_writes' => false,
                'writes' => $execute,
                'benchmark_run' => false,
            ],
        ];

        $hashPayload = $payload;
        unset($hashPayload['generated_at']);
        $payload['runtime_hash'] = MissionCanonicalHash::sha256($hashPayload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $programmingContract
     * @return array<string,mixed>
     */
    private function request(array $programmingContract, string $decisionReceiptHash, bool $execute, bool $approved, bool $runtimeBoundaryGreen): array
    {
        $manifest = (array) data_get($programmingContract, 'manifest', []);
        $request = [
            'schema_version' => self::REQUEST_SCHEMA,
            'status' => ($programmingContract['status'] ?? null) === 'ready' ? 'ready' : 'empty',
            'runtime_invocation_contract' => [
                'schema_version' => 'atlas.runtime_invocation_contract.v1',
                'kernel_first' => true,
                'selected_runtime_family' => 'python_ai_data',
                'runtime_id' => 'aucri_python_data_retrieval_runtime',
                'decision_receipt_hash' => $decisionReceiptHash,
                'required_fields' => ['decision_receipt_hash', 'runtime_family', 'capability', 'limits', 'privacy_class', 'evidence_sink'],
                'forbidden_runtime_authority' => ['choose_provider_or_model', 'choose_domain_or_flow', 'mutate_policy', 'write_memory_directly', 'bypass_evidence_ledger'],
            ],
            'runtime_root' => (string) ($programmingContract['runtime_root'] ?? ''),
            'entrypoint' => (string) ($programmingContract['entrypoint'] ?? ''),
            'manifest_hash' => (string) ($programmingContract['manifest_hash'] ?? ''),
            'workspace_hash' => MissionCanonicalHash::sha256((string) ($manifest['workspace'] ?? '')),
            'files' => array_values((array) ($manifest['files'] ?? [])),
            'limits' => (array) ($manifest['limits'] ?? []),
            'runtime_policy' => (array) data_get($manifest, 'runtime_policy', []),
            'execution_gate_inputs' => [
                'execute_requested' => $execute,
                'approved' => $approved,
                'runtime_boundary_green' => $runtimeBoundaryGreen,
                'decision_receipt_hash_present' => preg_match('/^[a-f0-9]{64}$/', $decisionReceiptHash) === 1,
            ],
        ];
        $request['request_hash'] = MissionCanonicalHash::sha256($request);

        return $request;
    }

    /**
     * @param  array<string,mixed>  $request
     * @return array<string,mixed>
     */
    private function gate(array $request): array
    {
        $reasons = [];
        if (($request['status'] ?? null) !== 'ready') {
            $reasons[] = 'empty_manifest';
        }
        if (! $this->policy->isSafe((array) data_get($request, 'runtime_policy', []))) {
            $reasons[] = 'unsafe_runtime_policy';
        }
        if (data_get($request, 'execution_gate_inputs.execute_requested') === true) {
            if (data_get($request, 'execution_gate_inputs.approved') !== true) {
                $reasons[] = 'approval_required';
            }
            if (data_get($request, 'execution_gate_inputs.runtime_boundary_green') !== true) {
                $reasons[] = 'runtime_boundary_not_green';
            }
            if (data_get($request, 'execution_gate_inputs.decision_receipt_hash_present') !== true) {
                $reasons[] = 'decision_receipt_hash_required';
            }
        }

        return [
            'schema_version' => 'atlas.aucri.python_data_execution_gate.v1',
            'status' => $reasons === [] ? 'passed' : 'blocked',
            'reasons' => $reasons,
        ];
    }

    /**
     * @param  array<string,mixed>  $rawReceipt
     * @param  array<string,mixed>  $programmingContract
     * @return array<string,mixed>
     */
    private function executionReceipt(array $rawReceipt, array $programmingContract): array
    {
        return [
            'schema_version' => self::EXECUTION_RECEIPT_SCHEMA,
            'status' => (string) ($rawReceipt['status'] ?? 'failed'),
            'source_schema_version' => (string) ($rawReceipt['schema_version'] ?? ''),
            'manifest_hash' => (string) ($programmingContract['manifest_hash'] ?? ''),
            'exit_code' => $rawReceipt['exit_code'] ?? null,
            'stdout_hash' => $rawReceipt['stdout_hash'] ?? null,
            'stderr_hash' => $rawReceipt['stderr_hash'] ?? null,
            'file_count' => (int) data_get($rawReceipt, 'result.file_count', 0),
            'skipped_count' => count((array) data_get($rawReceipt, 'result.skipped', [])),
            'receipt_hash' => (string) ($rawReceipt['receipt_hash'] ?? MissionCanonicalHash::sha256($rawReceipt)),
        ];
    }

    /**
     * @param  array<string,mixed>  $rawReceipt
     * @return array<string,mixed>
     */
    private function graphFragment(array $rawReceipt): array
    {
        $fragment = $this->projector->project($rawReceipt);
        $fragment['schema_version'] = self::GRAPH_FRAGMENT_SCHEMA;
        $fragment['fragment_hash'] = MissionCanonicalHash::sha256($fragment);

        return $fragment;
    }

    /**
     * @param  array<string,mixed>  $programmingContract
     * @param  array<string,mixed>|null  $executionReceipt
     */
    private function status(array $programmingContract, array $gate, ?array $executionReceipt, bool $execute): string
    {
        if (($programmingContract['status'] ?? null) !== 'ready') {
            return 'empty';
        }
        if ($execute && ($gate['status'] ?? null) !== 'passed') {
            return 'blocked';
        }
        if ($execute) {
            return ($executionReceipt['status'] ?? null) === 'passed' ? 'ready' : 'failed';
        }

        return 'ready';
    }

    private function workspace(mixed $workspace): string
    {
        $workspace = is_scalar($workspace) ? trim((string) $workspace) : '';

        return $workspace === '' ? base_path() : $workspace;
    }

    /**
     * @return array<int,string>
     */
    private function files(mixed $files): array
    {
        if (is_string($files)) {
            $files = [$files];
        }
        if (! is_array($files)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $file): string => is_scalar($file) ? trim((string) $file) : '',
            $files,
        )));
    }

    private function string(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
