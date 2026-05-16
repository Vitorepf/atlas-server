<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Schemas;

use App\Services\Ai\Programming\AtlasDev\Schemas\Components\CompletionSummary;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\CostSummary;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\EscalationSummary;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\EvidenceRef;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\GateOutcome;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\RepairSummary;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\TestRun;
use App\Services\Ai\Programming\AtlasDev\Schemas\Contracts\AtlasDevSchemaContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\AtlasDevSchemaArray;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalHasher;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalJson;
use InvalidArgumentException;

final class VerificationReceipt implements AtlasDevSchemaContract
{
    public const SCHEMA_VERSION = 'atlas.dev.verification_receipt.v1';

    public const ALLOWED_TASK_KINDS = ['question', 'patch', 'repair', 'review', 'frontend', 'risky'];
    public const ALLOWED_RISK_LEVELS = ['R0', 'R1', 'R2', 'R3', 'R4', 'R5'];

    private const HASH_FIELD = 'receipt_hash';

    /**
     * @param  list<string>  $changedFiles
     * @param  array<string, string>  $fileHashes
     * @param  list<EvidenceRef>  $evidenceRefs
     * @param  list<GateOutcome>  $gates
     * @param  list<TestRun>  $tests
     */
    public function __construct(
        public readonly string $runId,
        public readonly string $taskContractHash,
        public readonly string $workspaceHash,
        public readonly string $taskKind,
        public readonly string $riskLevel,
        public readonly string $provider,
        public readonly string $model,
        public readonly string $contextPackHash,
        public readonly string $promptProjectionHash,
        public readonly ?string $scopeGuardReceiptHash,
        public readonly ?string $diffHash,
        public readonly array $changedFiles,
        public readonly array $fileHashes,
        public readonly array $evidenceRefs,
        public readonly array $gates,
        public readonly array $tests,
        public readonly RepairSummary $repair,
        public readonly CostSummary $cost,
        public readonly CompletionSummary $completion,
        public readonly EscalationSummary $escalation,
        public readonly string $receiptHash,
    ) {
        if (! in_array($this->taskKind, self::ALLOWED_TASK_KINDS, true)) {
            throw new InvalidArgumentException(
                "VerificationReceipt.task_kind must be one of [".implode(',', self::ALLOWED_TASK_KINDS)."], got '{$this->taskKind}'."
            );
        }
        if (! in_array($this->riskLevel, self::ALLOWED_RISK_LEVELS, true)) {
            throw new InvalidArgumentException(
                "VerificationReceipt.risk_level must be one of [".implode(',', self::ALLOWED_RISK_LEVELS)."], got '{$this->riskLevel}'."
            );
        }
        foreach ($this->changedFiles as $i => $f) {
            if (! is_string($f)) {
                throw new InvalidArgumentException("changed_files[{$i}] must be a string.");
            }
        }
        foreach ($this->fileHashes as $path => $hash) {
            if (! is_string($path) || ! is_string($hash)) {
                throw new InvalidArgumentException('file_hashes must be a map of string=>string.');
            }
        }
        foreach ($this->evidenceRefs as $i => $r) {
            if (! $r instanceof EvidenceRef) {
                throw new InvalidArgumentException("evidence_refs[{$i}] must be EvidenceRef.");
            }
        }
        foreach ($this->gates as $i => $g) {
            if (! $g instanceof GateOutcome) {
                throw new InvalidArgumentException("gates[{$i}] must be GateOutcome.");
            }
        }
        foreach ($this->tests as $i => $t) {
            if (! $t instanceof TestRun) {
                throw new InvalidArgumentException("tests[{$i}] must be TestRun.");
            }
        }

        $this->assertCompletionInvariants();
    }

    public function isPassed(): bool
    {
        return $this->completion->status === CompletionSummary::STATUS_PASSED;
    }

    public function schemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    public function toCanonicalArray(): array
    {
        $fileHashes = $this->fileHashes;
        ksort($fileHashes, SORT_STRING);

        return CanonicalJson::canonicalize([
            'changed_files' => array_values($this->changedFiles),
            'completion' => $this->completion->toCanonicalArray(),
            'context_pack_hash' => $this->contextPackHash,
            'cost' => $this->cost->toCanonicalArray(),
            'diff_hash' => $this->diffHash,
            'escalation' => $this->escalation->toCanonicalArray(),
            'evidence_refs' => array_map(
                static fn (EvidenceRef $r): array => $r->toCanonicalArray(),
                array_values($this->evidenceRefs),
            ),
            'file_hashes' => $fileHashes,
            'gates' => array_map(
                static fn (GateOutcome $g): array => $g->toCanonicalArray(),
                array_values($this->gates),
            ),
            'model' => $this->model,
            'prompt_projection_hash' => $this->promptProjectionHash,
            'provider' => $this->provider,
            'provider_safe' => true,
            'receipt_hash' => $this->receiptHash,
            'repair' => $this->repair->toCanonicalArray(),
            'risk_level' => $this->riskLevel,
            'run_id' => $this->runId,
            'schema_version' => self::SCHEMA_VERSION,
            'scope_guard_receipt_hash' => $this->scopeGuardReceiptHash,
            'task_contract_hash' => $this->taskContractHash,
            'task_kind' => $this->taskKind,
            'tests' => array_map(
                static fn (TestRun $t): array => $t->toCanonicalArray(),
                array_values($this->tests),
            ),
            'workspace_hash' => $this->workspaceHash,
        ]);
    }

    public function toProviderSafeArray(): array
    {
        return $this->toCanonicalArray();
    }

    public function toJson(): string
    {
        return CanonicalJson::encode($this->toCanonicalArray());
    }

    public function hash(): string
    {
        return CanonicalHasher::hashWithout($this->toCanonicalArray(), self::HASH_FIELD);
    }

    public function isProviderSafe(): bool
    {
        return true;
    }

    /**
     * Convenience constructor that seals the receipt_hash deterministically.
     *
     * @param  list<string>  $changedFiles
     * @param  array<string, string>  $fileHashes
     * @param  list<EvidenceRef>  $evidenceRefs
     * @param  list<GateOutcome>  $gates
     * @param  list<TestRun>  $tests
     */
    public static function issue(
        string $runId,
        string $taskContractHash,
        string $workspaceHash,
        string $taskKind,
        string $riskLevel,
        string $provider,
        string $model,
        string $contextPackHash,
        string $promptProjectionHash,
        ?string $scopeGuardReceiptHash,
        ?string $diffHash,
        array $changedFiles,
        array $fileHashes,
        array $evidenceRefs,
        array $gates,
        array $tests,
        RepairSummary $repair,
        CostSummary $cost,
        CompletionSummary $completion,
        EscalationSummary $escalation,
    ): self {
        $skeleton = new self(
            $runId,
            $taskContractHash,
            $workspaceHash,
            $taskKind,
            $riskLevel,
            $provider,
            $model,
            $contextPackHash,
            $promptProjectionHash,
            $scopeGuardReceiptHash,
            $diffHash,
            $changedFiles,
            $fileHashes,
            $evidenceRefs,
            $gates,
            $tests,
            $repair,
            $cost,
            $completion,
            $escalation,
            'pending',
        );

        $hash = $skeleton->hash();

        return new self(
            $runId,
            $taskContractHash,
            $workspaceHash,
            $taskKind,
            $riskLevel,
            $provider,
            $model,
            $contextPackHash,
            $promptProjectionHash,
            $scopeGuardReceiptHash,
            $diffHash,
            $changedFiles,
            $fileHashes,
            $evidenceRefs,
            $gates,
            $tests,
            $repair,
            $cost,
            $completion,
            $escalation,
            $hash,
        );
    }

    public static function fromArray(array $payload): self
    {
        $evidenceRefs = [];
        foreach (array_values((array) ($payload['evidence_refs'] ?? [])) as $i => $raw) {
            if (! is_array($raw)) {
                throw new InvalidArgumentException("evidence_refs[{$i}] must be an array.");
            }
            $evidenceRefs[] = EvidenceRef::fromArray($raw);
        }

        $gates = [];
        foreach (array_values((array) ($payload['gates'] ?? [])) as $i => $raw) {
            if (! is_array($raw)) {
                throw new InvalidArgumentException("gates[{$i}] must be an array.");
            }
            $gates[] = GateOutcome::fromArray($raw);
        }

        $tests = [];
        foreach (array_values((array) ($payload['tests'] ?? [])) as $i => $raw) {
            if (! is_array($raw)) {
                throw new InvalidArgumentException("tests[{$i}] must be an array.");
            }
            $tests[] = TestRun::fromArray($raw);
        }

        $fileHashes = [];
        foreach ((array) ($payload['file_hashes'] ?? []) as $path => $hash) {
            $fileHashes[(string) $path] = (string) $hash;
        }

        return new self(
            runId: AtlasDevSchemaArray::string($payload, 'run_id'),
            taskContractHash: AtlasDevSchemaArray::string($payload, 'task_contract_hash'),
            workspaceHash: AtlasDevSchemaArray::string($payload, 'workspace_hash'),
            taskKind: AtlasDevSchemaArray::string($payload, 'task_kind'),
            riskLevel: AtlasDevSchemaArray::string($payload, 'risk_level'),
            provider: AtlasDevSchemaArray::string($payload, 'provider'),
            model: AtlasDevSchemaArray::string($payload, 'model'),
            contextPackHash: AtlasDevSchemaArray::string($payload, 'context_pack_hash'),
            promptProjectionHash: AtlasDevSchemaArray::string($payload, 'prompt_projection_hash'),
            scopeGuardReceiptHash: AtlasDevSchemaArray::nullableString($payload, 'scope_guard_receipt_hash'),
            diffHash: AtlasDevSchemaArray::nullableString($payload, 'diff_hash'),
            changedFiles: AtlasDevSchemaArray::stringList($payload, 'changed_files'),
            fileHashes: $fileHashes,
            evidenceRefs: $evidenceRefs,
            gates: $gates,
            tests: $tests,
            repair: RepairSummary::fromArray((array) $payload['repair']),
            cost: CostSummary::fromArray((array) $payload['cost']),
            completion: CompletionSummary::fromArray((array) $payload['completion']),
            escalation: EscalationSummary::fromArray((array) $payload['escalation']),
            receiptHash: AtlasDevSchemaArray::string($payload, 'receipt_hash'),
        );
    }

    private function assertCompletionInvariants(): void
    {
        $status = $this->completion->status;

        // Invariant 4: escalate_forge forces escalation.recommended=true and target!=null
        if ($status === CompletionSummary::STATUS_ESCALATE_FORGE) {
            if (! $this->escalation->recommended || $this->escalation->target === null) {
                throw new InvalidArgumentException(
                    'VerificationReceipt invariant: completion.status=escalate_forge requires escalation.recommended=true and escalation.target!=null.'
                );
            }
        }

        // Invariant 1: completion.passed requires all required gates passed + at least one passing test or no_patch_reason
        if ($status === CompletionSummary::STATUS_PASSED) {
            foreach ($this->gates as $gate) {
                if ($gate->required && $gate->status !== GateOutcome::STATUS_PASSED) {
                    throw new InvalidArgumentException(
                        "VerificationReceipt invariant: completion.passed requires all required gates passed; gate '{$gate->name}' is '{$gate->status}'."
                    );
                }
            }
            if ($this->tests !== []) {
                $anyOk = false;
                foreach ($this->tests as $t) {
                    if ($t->ok) {
                        $anyOk = true;
                        break;
                    }
                }
                if (! $anyOk) {
                    $hasNoPatchReason = false;
                    foreach ($this->evidenceRefs as $r) {
                        if ($r->kind === 'no_patch_reason') {
                            $hasNoPatchReason = true;
                            break;
                        }
                    }
                    if (! $hasNoPatchReason) {
                        throw new InvalidArgumentException(
                            'VerificationReceipt invariant: completion.passed with tests requires at least one ok=true or an evidence_refs[kind=no_patch_reason].'
                        );
                    }
                }
            }
        }

        // Invariant 7: required gates must be fresh
        foreach ($this->gates as $gate) {
            if ($gate->required && ! $gate->fresh && $gate->status !== GateOutcome::STATUS_WAIVED) {
                throw new InvalidArgumentException(
                    "VerificationReceipt invariant: required gate '{$gate->name}' must be fresh=true (or status=waived)."
                );
            }
        }
    }
}
