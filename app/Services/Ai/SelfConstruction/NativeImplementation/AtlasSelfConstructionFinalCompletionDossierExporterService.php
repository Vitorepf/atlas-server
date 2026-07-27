<?php

namespace App\Services\Ai\SelfConstruction\NativeImplementation;

use App\Services\Ai\SelfConstruction\Concerns\RecursivelyKsortsArrays;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
/**
 * Read-only exporter that produces the final completion dossier (machine
 * JSON + human Markdown + checklist + evidence map) for Atlas
 * Self-Construction OS.
 *
 * Default behaviour persists nothing. With option `persist_export=true`
 * the exporter MAY persist the generated dossier (not evidence, not
 * receipts) to a local storage path for operator audit. Persistence of
 * evidence or receipts remains forbidden under any flag.
 *
 * The exporter:
 *   - NEVER signs receipts;
 *   - NEVER persists receipts or evidence;
 *   - NEVER promotes completion;
 *   - NEVER calls providers, dispatches work or spends tokens.
 */
use App\Services\Ai\SelfConstruction\Readiness\AtlasSelfConstructionReadinessService;

final class AtlasSelfConstructionFinalCompletionDossierExporterService
{
    use RecursivelyKsortsArrays { recursivelyKsort as ksortRecursive; }

    public const SCHEMA_VERSION = 'atlas.self_construction.final_completion_dossier_exporter.v1';

    public const MODE = 'read_only_final_completion_dossier_exporter';

    public const STORAGE_DISK = 'local';

    public const STORAGE_PREFIX = 'atlas/self-construction/os-completion/final-completion-dossier-exports';

    public function __construct(
        private readonly AtlasSelfConstructionReadinessService $readiness,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function build(array $options = []): array
    {
        $persistExport = (bool) ($options['persist_export'] ?? false);

        $humanGateOptions = [];
        foreach (['completion_audit', 'completion_evidence', 'completion_receipt', 'runtime_promotion_receipt', 'real_provider_smoke'] as $key) {
            if (isset($options[$key]) && is_array($options[$key]) && $options[$key] !== []) {
                $humanGateOptions[$key] = $options[$key];
            }
        }

        $humanGate = (array) ($options['final_completion_human_gate']
            ?? (new AtlasSelfConstructionFinalCompletionHumanGateService($this->readiness))->build($humanGateOptions));

        $completionAudit = (array) data_get($humanGate, 'completion_audit', []);
        $prereqMatrix = (array) data_get($humanGate, 'prerequisite_matrix', []);
        $orderedSteps = (array) data_get($humanGate, 'ordered_operator_steps', []);
        $exactCommands = (array) data_get($humanGate, 'exact_commands', []);
        $failedBlockers = (array) data_get($completionAudit, 'failed_criteria', []);

        $checklist = $this->checklist($prereqMatrix, $failedBlockers, $humanGate);
        $evidenceMap = $this->evidenceMap($humanGate);
        $nextCommands = $this->nextCommands($humanGate, $failedBlockers, $exactCommands);

        $machineJson = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'final_audit_status' => (string) data_get($completionAudit, 'status', 'unknown'),
            'completion_audit_hash' => (string) data_get($completionAudit, 'completion_audit_hash', ''),
            'final_completion_human_gate_status' => (string) data_get($humanGate, 'status', ''),
            'final_completion_human_gate_hash' => (string) data_get($humanGate, 'human_gate_hash', ''),
            'prerequisite_matrix' => $prereqMatrix,
            'failed_blockers' => $failedBlockers,
            'checklist' => $checklist,
            'evidence_map' => $evidenceMap,
            'next_commands' => $nextCommands,
            'ordered_operator_steps' => $orderedSteps,
            'safety_invariants' => (array) data_get($humanGate, 'safety_invariants', []),
            'non_execution_guarantees' => [
                'final_completion_dossier_exporter_does_not_sign_for_operator',
                'final_completion_dossier_exporter_does_not_persist_receipts',
                'final_completion_dossier_exporter_does_not_persist_evidence',
                'final_completion_dossier_exporter_does_not_promote_completion',
                'final_completion_dossier_exporter_does_not_call_provider',
                'final_completion_dossier_exporter_does_not_spend_tokens',
                'final_completion_dossier_exporter_does_not_dispatch_work',
                'final_completion_dossier_exporter_does_not_enable_runtime',
                'final_completion_dossier_exporter_does_not_enable_self_programming',
            ],
            'completion_claim_allowed' => false,
        ];
        $machineJson['final_audit_complete'] = (string) data_get($completionAudit, 'status') === 'complete'
            && (int) data_get($completionAudit, 'failed_count', 1) === 0;

        $markdown = $this->renderMarkdown($machineJson, $humanGate, $checklist, $evidenceMap, $nextCommands);

        $persisted = false;
        $exportPath = '';
        $persistenceBlocker = '';
        if ($persistExport) {
            try {
                $exportPath = $this->persistDossier($machineJson, $markdown);
                $persisted = true;
            } catch (\Throwable $e) {
                $persistenceBlocker = 'final_completion_dossier_export_persist_failed';
            }
        }

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => $persisted
                ? 'export_persisted'
                : ($persistExport ? 'export_persistence_blocked' : 'export_ready'),
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'final_audit_status' => $machineJson['final_audit_status'],
            'final_audit_complete' => $machineJson['final_audit_complete'],
            'final_completion_human_gate_status' => $machineJson['final_completion_human_gate_status'],
            'machine_json' => $machineJson,
            'markdown' => $markdown,
            'markdown_byte_size' => strlen($markdown),
            'checklist' => $checklist,
            'evidence_map' => $evidenceMap,
            'next_commands' => $nextCommands,
            'failed_blockers' => $failedBlockers,
            'persist_export_requested' => $persistExport,
            'export_persisted' => $persisted,
            'export_path' => $exportPath,
            'export_persistence_blocker' => $persistenceBlocker,
            'storage_disk' => self::STORAGE_DISK,
            'storage_prefix' => self::STORAGE_PREFIX,
            'non_execution_guarantees' => $machineJson['non_execution_guarantees'],
            'completion_claim_allowed' => false,
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'adapter_execution_allowed' => false,
            'self_programming_allowed' => false,
        ];
        $payload['exporter_hash'] = $this->stableHash($payload);

        return $payload;
    }

    /**
     * @param  array<string, array<string, mixed>>  $prereqMatrix
     * @param  list<string>  $failedBlockers
     * @param  array<string, mixed>  $humanGate
     * @return array<int, array<string, mixed>>
     */
    private function checklist(array $prereqMatrix, array $failedBlockers, array $humanGate): array
    {
        $rows = [];
        foreach ($prereqMatrix as $name => $row) {
            $rows[] = [
                'id' => (string) $name,
                'status' => (string) ($row['status'] ?? 'unknown'),
                'green' => (bool) ($row['green'] ?? false),
                'evidence_source' => (string) ($row['evidence_source'] ?? ''),
                'kind' => 'prerequisite',
            ];
        }
        foreach ($failedBlockers as $blocker) {
            $rows[] = [
                'id' => (string) $blocker,
                'status' => 'blocked',
                'green' => false,
                'evidence_source' => 'completion_audit.failed_criteria',
                'kind' => 'completion_audit_blocker',
            ];
        }
        $rows[] = [
            'id' => 'final_completion_human_gate',
            'status' => (string) data_get($humanGate, 'status', ''),
            'green' => (string) data_get($humanGate, 'status', '') === 'verifier_passed_ready_for_explicit_persistence'
                || (string) data_get($humanGate, 'status', '') === 'complete_candidate_after_audit_rerun',
            'evidence_source' => 'atlas.self_construction.final_completion_human_gate.v1',
            'kind' => 'gate',
        ];

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $humanGate
     * @return array<string, mixed>
     */
    private function evidenceMap(array $humanGate): array
    {
        return [
            'completion_audit_hash' => (string) data_get($humanGate, 'completion_audit.completion_audit_hash', ''),
            'final_completion_human_gate_hash' => (string) data_get($humanGate, 'human_gate_hash', ''),
            'submission_preflight_hash' => (string) data_get($humanGate, 'submission_preflight.submission_preflight_hash', ''),
            'human_completion_receipt_dossier_hash' => (string) data_get($humanGate, 'human_completion_receipt_dossier.dossier_hash', ''),
            'human_completion_receipt_runbook_hash' => (string) data_get($humanGate, 'human_completion_receipt_runbook.runbook_hash', ''),
            'human_completion_receipt_draft_hash' => (string) data_get($humanGate, 'human_completion_receipt_draft.draft_hash', ''),
            'final_evidence_bundle_hash' => (string) data_get($humanGate, 'final_evidence_bundle.final_evidence_bundle_hash', ''),
            'human_completion_receipt_endgame_verifier_hash' => (string) data_get($humanGate, 'human_receipt_verification.endgame_verification_hash', ''),
            'release_dossier_hash' => (string) data_get($humanGate, 'human_completion_receipt_dossier.release_dossier_snapshot.release_dossier_hash', ''),
            'certification_status_batch_hash' => (string) data_get($humanGate, 'human_completion_receipt_dossier.certification_status_batch.batch_hash', ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $humanGate
     * @param  list<string>  $failedBlockers
     * @param  array<string, string>  $exactCommands
     * @return list<string>
     */
    private function nextCommands(array $humanGate, array $failedBlockers, array $exactCommands): array
    {
        $next = [];
        $status = (string) data_get($humanGate, 'status', '');
        $next[] = (string) ($exactCommands['refresh_completion_audit'] ?? '');
        $next[] = (string) ($exactCommands['refresh_terminal_loop_operational_proof'] ?? '');
        $next[] = (string) ($exactCommands['refresh_completion_audit_with_terminal_loop_operational_proof'] ?? '');
        $next[] = (string) ($exactCommands['check_completion_evidence'] ?? '');

        if ($status === 'blocked_runtime_promotion_required') {
            $next[] = (string) ($exactCommands['draft_runtime_promotion_receipt'] ?? '');
            $next[] = (string) ($exactCommands['persist_runtime_promotion_receipt'] ?? '');
        } elseif ($status === 'blocked_real_provider_smoke_required') {
            $next[] = (string) ($exactCommands['draft_real_provider_smoke'] ?? '');
            $next[] = (string) ($exactCommands['persist_real_provider_smoke'] ?? '');
        } elseif ($status === 'blocked_human_signature_required' || $status === 'ready_to_verify_human_receipt') {
            $next[] = (string) ($exactCommands['draft_human_completion_receipt'] ?? '');
            $next[] = (string) ($exactCommands['run_endgame_verifier'] ?? '');
        } elseif ($status === 'verifier_passed_ready_for_explicit_persistence') {
            $next[] = (string) ($exactCommands['persist_human_completion_receipt'] ?? '');
            $next[] = (string) ($exactCommands['refresh_terminal_loop_operational_proof'] ?? '');
            $next[] = (string) ($exactCommands['refresh_completion_audit_with_terminal_loop_operational_proof'] ?? '');
            $next[] = (string) ($exactCommands['final_completion_readiness_gate_status'] ?? '');
        } elseif ($status === 'complete_candidate_after_audit_rerun') {
            $next[] = (string) ($exactCommands['final_completion_readiness_gate_status'] ?? '');
        }

        if ($failedBlockers !== []) {
            $next[] = (string) ($exactCommands['final_completion_human_gate_status'] ?? '');
        }

        return array_values(array_filter(array_unique($next), static fn (string $cmd): bool => $cmd !== ''));
    }

    /**
     * @param  array<string, mixed>  $machineJson
     * @param  array<string, mixed>  $humanGate
     * @param  array<int, array<string, mixed>>  $checklist
     * @param  array<string, mixed>  $evidenceMap
     * @param  list<string>  $nextCommands
     */
    private function renderMarkdown(array $machineJson, array $humanGate, array $checklist, array $evidenceMap, array $nextCommands): string
    {
        $lines = [];
        $lines[] = '# Atlas Self-Construction · Final Completion Dossier';
        $lines[] = '';
        $lines[] = '> **Read-only dossier.** This document persists nothing on its own. It';
        $lines[] = '> documents exactly what blocks Atlas Self-Construction OS completion';
        $lines[] = '> right now and which commands the operator must run next. It never';
        $lines[] = '> signs receipts, never promotes completion, never calls a provider,';
        $lines[] = '> never spends tokens, never dispatches work, never enables runtime.';
        $lines[] = '';
        $lines[] = '## Final audit status';
        $lines[] = '';
        $lines[] = '- **Audit status:** `'.$machineJson['final_audit_status'].'`';
        $lines[] = '- **Audit complete:** '.($machineJson['final_audit_complete'] ? 'yes' : '**no**');
        $lines[] = '- **Final human gate status:** `'.$machineJson['final_completion_human_gate_status'].'`';
        $lines[] = '- **Completion audit hash:** `'.$machineJson['completion_audit_hash'].'`';
        $lines[] = '- **Completion claim allowed:** `false`';
        $lines[] = '';
        $lines[] = '## Prerequisite matrix';
        $lines[] = '';
        $lines[] = '| Prerequisite | Status |';
        $lines[] = '|---|---|';
        foreach ((array) data_get($machineJson, 'prerequisite_matrix', []) as $name => $row) {
            $status = (string) data_get($row, 'status', 'unknown');
            $lines[] = '| `'.$name.'` | `'.$status.'` |';
        }
        $lines[] = '';
        $lines[] = '## Failed completion audit criteria';
        $lines[] = '';
        if ((array) data_get($machineJson, 'failed_blockers', []) === []) {
            $lines[] = '_None — all completion audit criteria are green._';
        } else {
            foreach ((array) data_get($machineJson, 'failed_blockers', []) as $blocker) {
                $lines[] = '- `'.$blocker.'`';
            }
        }
        $lines[] = '';
        $lines[] = '## Checklist';
        $lines[] = '';
        foreach ($checklist as $row) {
            $box = ($row['green'] ?? false) ? '[x]' : '[ ]';
            $lines[] = '- '.$box.' `'.$row['id'].'` — '.$row['status'].' ('.($row['kind'] ?? '').')';
        }
        $lines[] = '';
        $lines[] = '## Evidence map';
        $lines[] = '';
        foreach ($evidenceMap as $name => $value) {
            $lines[] = '- **'.$name.'**: `'.(string) $value.'`';
        }
        $lines[] = '';
        $lines[] = '## Next commands';
        $lines[] = '';
        foreach ($nextCommands as $command) {
            $lines[] = '```bash';
            $lines[] = $command;
            $lines[] = '```';
        }
        $lines[] = '';
        $lines[] = '## Non-execution guarantees';
        $lines[] = '';
        foreach ((array) data_get($machineJson, 'non_execution_guarantees', []) as $guarantee) {
            $lines[] = '- '.$guarantee;
        }
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $machineJson
     */
    private function persistDossier(array $machineJson, string $markdown): string
    {
        $hash = hash('sha256', (string) json_encode($this->ksortRecursive($machineJson), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $path = self::STORAGE_PREFIX.'/'.$hash;
        Storage::disk(self::STORAGE_DISK)->put($path.'.json', (string) json_encode($machineJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        Storage::disk(self::STORAGE_DISK)->put($path.'.md', $markdown);
        $registryPath = self::STORAGE_PREFIX.'/registry.json';
        $registry = [];
        if (Storage::disk(self::STORAGE_DISK)->exists($registryPath)) {
            $decoded = json_decode((string) Storage::disk(self::STORAGE_DISK)->get($registryPath), true);
            if (is_array($decoded)) {
                $registry = array_values(array_filter($decoded, 'is_array'));
            }
        }
        $registry[] = [
            'exporter_hash' => $hash,
            'json_path' => $path.'.json',
            'markdown_path' => $path.'.md',
            'persisted_at' => CarbonImmutable::now()->toIso8601String(),
        ];
        Storage::disk(self::STORAGE_DISK)->put($registryPath, (string) json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $path;
    }

    /** @param array<string, mixed> $payload */
    private function stableHash(array $payload): string
    {
        $clean = $this->stripVolatileKeys($payload);
        unset($clean['generated_at'], $clean['exporter_hash']);

        return hash('sha256', (string) json_encode($this->ksortRecursive($clean), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function stripVolatileKeys(array $payload): array
    {
        $volatile = [
            'generated_at',
            'verified_at',
            'audited_at',
            'persisted_at',
            'certified_at',
            'export_path',
            'receipt_id',
            'receipt_hash',
            'draft_hash',
            'dossier_hash',
            'runbook_hash',
            'preflight_hash',
            'verification_hash',
            'pre_submission_verification_hash',
            'endgame_verification_hash',
            'human_receipt_verification',
        ];
        foreach ($volatile as $key) {
            unset($payload[$key]);
        }
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->stripVolatileKeys($value);
            }
        }

        return $payload;
    }

    /** @param array<string, mixed> $value */
}
