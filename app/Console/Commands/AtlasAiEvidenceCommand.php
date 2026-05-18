<?php

namespace App\Console\Commands;

use App\Services\Ai\Evidence\ArtifactRegistryService;
use App\Services\Ai\Evidence\BlockerService;
use App\Services\Ai\Evidence\CertificationRuntimeService;
use App\Services\Ai\Evidence\ClaimVerificationService;
use App\Services\Ai\Evidence\EvidenceControlPlaneService;
use App\Services\Ai\Evidence\EvidencePackService;
use App\Services\Ai\Evidence\EvidenceReadinessService;
use App\Services\Ai\Evidence\GateRunService;
use App\Services\Ai\Evidence\ReceiptService;
use App\Services\Ai\Evidence\SourceRefService;
use App\Services\Ai\Evidence\TestResultService;
use Illuminate\Console\Command;
use Throwable;

class AtlasAiEvidenceCommand extends Command
{
    protected $signature = 'atlas:ai:evidence
        {positional? : Optional positional action (alternative to --action)}
        {--action=readiness : readiness, smoke, pack, receipt, certify, control-plane}
        {--target-type= : Target type for pack/certify}
        {--target-id= : Target id for pack/certify}
        {--mission= : Mission uuid/id (optional)}
        {--type= : Receipt type for receipt action}
        {--action-name= : Action description for receipt action}
        {--json : Machine-readable JSON output}';

    protected $description = 'Atlas Evidence/Certification Runtime: readiness, evidence packs, receipts, certification and control-plane.';

    public function handle(
        EvidenceReadinessService $readiness,
        EvidencePackService $packs,
        ReceiptService $receipts,
        CertificationRuntimeService $certifications,
        EvidenceControlPlaneService $controlPlane,
        ArtifactRegistryService $artifacts,
        SourceRefService $sources,
        TestResultService $tests,
        GateRunService $gateRuns,
        BlockerService $blockers,
        ClaimVerificationService $claims,
    ): int {
        $positional = $this->argument('positional');
        $action = is_string($positional) && trim($positional) !== ''
            ? trim($positional)
            : (string) $this->option('action');

        try {
            return match ($action) {
                'readiness' => $this->renderReadiness($readiness),
                'smoke' => $this->renderSmoke(
                    $artifacts,
                    $sources,
                    $tests,
                    $receipts,
                    $packs,
                    $gateRuns,
                    $claims,
                    $certifications,
                ),
                'pack' => $this->renderPack($packs),
                'receipt' => $this->renderReceipt($receipts),
                'certify' => $this->renderCertify($certifications),
                'control-plane' => $this->renderControlPlane($controlPlane),
                default => $this->invalidAction($action),
            };
        } catch (Throwable $e) {
            $payload = [
                'ok' => false,
                'error' => 'exception',
                'message' => $e->getMessage(),
                'type' => $e::class,
            ];
            $this->line($this->encode($payload));

            return self::FAILURE;
        }
    }

    private function renderReadiness(EvidenceReadinessService $readiness): int
    {
        $payload = $readiness->report();
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('ok', $payload['ok'] ? 'true' : 'false');
            $this->components->twoColumnDetail('passed', (string) $payload['summary']['passed']);
            $this->components->twoColumnDetail('failed', (string) $payload['summary']['failed']);
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderPack(EvidencePackService $packs): int
    {
        $targetType = $this->stringOption('target-type');
        $targetId = $this->stringOption('target-id');
        if ($targetType === null || $targetId === null) {
            return $this->failWith('pack requires --target-type and --target-id');
        }

        $pack = $packs->build([
            'target_type' => $targetType,
            'target_id' => $targetId,
            'mission_id' => $this->stringOption('mission'),
            'command_refs' => [['kind' => 'command', 'name' => 'atlas:ai:evidence', 'action' => 'pack']],
        ]);

        $payload = [
            'ok' => true,
            'action' => 'pack',
            'evidence_pack' => [
                'id' => $pack->id,
                'uuid' => $pack->uuid,
                'target_type' => $pack->target_type,
                'target_id' => $pack->target_id,
                'evidence_hash' => $pack->evidence_hash,
            ],
        ];
        $this->emit($payload, function () use ($pack): void {
            $this->components->twoColumnDetail('evidence_pack_id', (string) $pack->id);
            $this->components->twoColumnDetail('evidence_hash', (string) $pack->evidence_hash);
        });

        return self::SUCCESS;
    }

    private function renderReceipt(ReceiptService $receipts): int
    {
        $type = $this->stringOption('type');
        $action = $this->stringOption('action-name');
        if ($type === null || $action === null) {
            return $this->failWith('receipt requires --type and --action-name');
        }
        $receipt = $receipts->emit([
            'receipt_type' => $type,
            'action' => $action,
            'mission_id' => $this->stringOption('mission'),
            'target_type' => $this->stringOption('target-type'),
            'target_id' => $this->stringOption('target-id'),
            'status' => ReceiptService::STATUS_OK,
        ]);
        $payload = [
            'ok' => true,
            'action' => 'receipt',
            'receipt' => [
                'id' => $receipt->id,
                'uuid' => $receipt->uuid,
                'receipt_type' => $receipt->receipt_type,
                'status' => $receipt->status,
                'receipt_hash' => $receipt->receipt_hash,
            ],
        ];
        $this->emit($payload, function () use ($receipt): void {
            $this->components->twoColumnDetail('receipt_type', (string) $receipt->receipt_type);
            $this->components->twoColumnDetail('receipt_hash', (string) $receipt->receipt_hash);
        });

        return self::SUCCESS;
    }

    private function renderCertify(CertificationRuntimeService $certifications): int
    {
        $targetType = $this->stringOption('target-type');
        $targetId = $this->stringOption('target-id');
        if ($targetType === null || $targetId === null) {
            return $this->failWith('certify requires --target-type and --target-id');
        }
        $record = $certifications->certify([
            'target_type' => $targetType,
            'target_id' => $targetId,
            'mission_id' => $this->stringOption('mission'),
        ]);
        $payload = [
            'ok' => $record->status === CertificationRuntimeService::STATUS_PASSED,
            'action' => 'certify',
            'certification' => [
                'id' => $record->id,
                'uuid' => $record->uuid,
                'target_type' => $record->target_type,
                'target_id' => $record->target_id,
                'status' => $record->status,
                'certification_hash' => $record->certification_hash,
                'missing_requirements' => $record->missing_requirements,
            ],
        ];
        $this->emit($payload, function () use ($record): void {
            $this->components->twoColumnDetail('certification_status', (string) $record->status);
            $this->components->twoColumnDetail('certification_hash', (string) $record->certification_hash);
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderControlPlane(EvidenceControlPlaneService $controlPlane): int
    {
        $payload = $controlPlane->snapshot();
        $payload['ok'] = true;
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('open_blockers', (string) $payload['open_blockers_count']);
            $this->components->twoColumnDetail('unverified_claims', (string) $payload['unverified_claims_count']);
        });

        return self::SUCCESS;
    }

    private function renderSmoke(
        ArtifactRegistryService $artifacts,
        SourceRefService $sources,
        TestResultService $tests,
        ReceiptService $receipts,
        EvidencePackService $packs,
        GateRunService $gateRuns,
        ClaimVerificationService $claims,
        CertificationRuntimeService $certifications,
    ): int {
        $targetType = EvidencePackService::TARGET_WORK_ORDER;
        $targetId = 'smoke-'.now()->format('YmdHisv');

        $artifact = $artifacts->register([
            'artifact_type' => ArtifactRegistryService::TYPE_REPORT,
            'name' => 'Evidence Runtime smoke report',
            'path_or_ref' => 'atlas:evidence:smoke:'.$targetId,
            'metadata' => ['suite' => 'EvidenceRuntimeSmokeTest'],
        ]);

        $source = $sources->register([
            'source_type' => SourceRefService::TYPE_DOC,
            'source_ref' => 'docs/engineering-knowledge-base/atlas-evidence-certification-runtime.md',
            'source_quality' => 0.95,
        ]);

        $test = $tests->record([
            'test_scope' => 'evidence:smoke',
            'command' => 'php artisan atlas:ai:evidence --action=smoke',
            'status' => TestResultService::STATUS_PASSED,
            'output_ref' => 'smoke-output:'.$targetId,
        ]);

        $receipt = $receipts->emit([
            'receipt_type' => ReceiptService::TYPE_COMMAND,
            'action' => 'atlas:ai:evidence:smoke',
            'target_type' => $targetType,
            'target_id' => $targetId,
            'status' => ReceiptService::STATUS_OK,
        ]);

        $pack = $packs->build([
            'target_type' => $targetType,
            'target_id' => $targetId,
            'artifact_refs' => [['id' => $artifact->id, 'kind' => 'artifact']],
            'source_refs' => [['id' => $source->id, 'kind' => 'source_ref']],
            'test_refs' => [['id' => $test->id, 'kind' => 'test_result']],
            'receipt_refs' => [['id' => $receipt->id, 'kind' => 'receipt', 'hash' => $receipt->receipt_hash]],
            'command_refs' => [['kind' => 'command', 'action' => 'atlas:ai:evidence:smoke']],
        ]);

        $gateRun = $gateRuns->record([
            'gate_type' => 'evidence:smoke',
            'target_type' => $targetType,
            'target_id' => $targetId,
            'status' => GateRunService::STATUS_PASSED,
            'checked_requirements' => [['requirement' => 'pack_created', 'status' => 'passed']],
            'missing_requirements' => [],
        ]);

        $claim = $claims->register([
            'claim_text' => 'Evidence Runtime smoke completed end-to-end.',
            'claim_type' => ClaimVerificationService::TYPE_SUPPORTED,
            'risk_level' => ClaimVerificationService::RISK_LOW,
            'evidence_refs' => [
                ['kind' => 'test_result', 'id' => $test->id],
                ['kind' => 'gate_run', 'id' => $gateRun->id],
            ],
        ]);

        $certification = $certifications->certify([
            'target_type' => $targetType,
            'target_id' => $targetId,
            'evidence_pack_id' => $pack->id,
        ]);

        $payload = [
            'ok' => $certification->status === CertificationRuntimeService::STATUS_PASSED,
            'action' => 'smoke',
            'target_type' => $targetType,
            'target_id' => $targetId,
            'artifact_id' => $artifact->id,
            'source_ref_id' => $source->id,
            'test_result_id' => $test->id,
            'receipt_id' => $receipt->id,
            'evidence_pack_id' => $pack->id,
            'gate_run_id' => $gateRun->id,
            'claim_id' => $claim->id,
            'certification' => [
                'id' => $certification->id,
                'status' => $certification->status,
                'certification_hash' => $certification->certification_hash,
            ],
        ];
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('target_id', (string) $payload['target_id']);
            $this->components->twoColumnDetail('certification_status', (string) $payload['certification']['status']);
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function failWith(string $message): int
    {
        $payload = ['ok' => false, 'error' => 'invalid_arguments', 'message' => $message];
        $this->line($this->encode($payload));

        return self::FAILURE;
    }

    private function invalidAction(string $action): int
    {
        return $this->failWith("invalid action [{$action}] for atlas:ai:evidence");
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload, callable $human): void
    {
        if ($this->json()) {
            $this->line($this->encode($payload));

            return;
        }
        $human();
    }

    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function json(): bool
    {
        return (bool) $this->option('json');
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function encode(array $payload): string
    {
        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }
}
