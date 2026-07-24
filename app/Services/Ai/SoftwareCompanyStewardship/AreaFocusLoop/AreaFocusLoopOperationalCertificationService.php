<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\NightShift\AreaFocusLoopReadModelService;
use Throwable;
use App\Support\YesNo;

/**
 * Area Focus Loop · Operational Certification (AP-722).
 *
 * Atlas Software Company Stewardship Stack é stack/capability family dentro do
 * Atlas Autonomous Software Company Runtime, não OS novo.
 *
 * Closes the Area Focus Loop for `agentic_engineering_os` by COMPOSING the
 * existing slices into one read-only, decision-oriented end-to-end run and
 * emitting an operational verdict (`operational | partial | blocked`):
 *
 *   AP-716 AreaFocusLoopReadModelService (NightShift, integrated) -> report
 *   AP-718 AreaFocusInboxService                                  -> operator inbox
 *   AP-719 AreaFocusDevForgeRouterService                         -> work order plan
 *   AP-720 AreaFocusCycleRecorderService                          -> durable cycle (evidence)
 *   AP-720 AreaFocusEvidencePackService                           -> evidence pack
 *
 * It REUSES those owners and reimplements none of them. It is a certification
 * capstone, not a new OS, runtime or registry. Its only write is the AP-720
 * append-only local evidence cycle (idempotent); it NEVER mutates the target
 * repo, invokes a provider, drafts a fix, routes work for execution, opens a
 * branch, merges, deploys or accesses secrets.
 */
class AreaFocusLoopOperationalCertificationService
{
    public const CERT_SCHEMA = 'atlas.software_company_stewardship.area_focus_loop_certification.v1';

    public const STATUS_OPERATIONAL = 'operational';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_BLOCKED = 'blocked';

    public const CHECK_PASS = 'pass';

    public const CHECK_DEGRADED = 'degraded';

    public const CHECK_BLOCKED = 'blocked';

    public const CHECK_SKIPPED = 'skipped';

    /** claim_policy flags that, if true anywhere, are a hard governance violation. */
    private const FORBIDDEN_FLAGS = [
        'merge_performed',
        'deploy_performed',
        'secrets_accessed',
        'destructive_change',
        'routing_executed',
        'execution_executed',
        'execution_enabled',
        'autoapproval_allowed',
        'autoimplementation_allowed',
        'parallel_runtime_created',
        'parallel_proposal_registry_created',
        'new_os_created',
    ];

    public function __construct(
        private readonly AreaFocusLoopReadModelService $readModel,
        private readonly AreaFocusInboxService $inbox,
        private readonly AreaFocusDevForgeRouterService $router,
        private readonly AreaFocusCycleRecorderService $cycleRecorder,
        private readonly AreaFocusEvidencePackService $evidencePack,
    ) {}

    /**
     * Certify the Area Focus Loop as operational for one area.
     *
     * `$input`:
     *   - area_id:         string  default agentic_engineering_os
     *   - record_evidence: bool    default true — record the append-only cycle + pack
     *   - area_findings:   array   forwarded to the read model (deterministic tests)
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function certify(array $input = []): array
    {
        $areaId = (string) ($input['area_id'] ?? AreaFocusLoopReadModelService::PRIORITY_AREA);
        $recordEvidence = (bool) ($input['record_evidence'] ?? true);

        $checks = [];
        $policies = [];

        // ---- AP-716: integrated read model ----
        $report = null;
        try {
            $report = $this->readModel->project($input);
            $rmStatus = (string) ($report['status'] ?? 'unknown');
            $hasFindings = array_key_exists('findings', $report);
            $checks[] = $this->check(
                'read_model', 'AP-716', AreaFocusLoopReadModelService::class,
                $rmStatus === AreaFocusLoopReadModelService::STATUS_BLOCKED ? self::CHECK_BLOCKED
                    : ($hasFindings ? self::CHECK_PASS : self::CHECK_DEGRADED),
                "read model status={$rmStatus}, findings_key=".(YesNo::format($hasFindings)),
                ['report_hash' => (string) ($report['report_hash'] ?? '')],
            );
            $policies['read_model'] = $report['claim_policy'] ?? [];
        } catch (Throwable $e) {
            $checks[] = $this->check('read_model', 'AP-716', AreaFocusLoopReadModelService::class, self::CHECK_BLOCKED, 'read model threw: '.$e->getMessage());
        }

        $findings = $this->certificationFindings(is_array($report) ? $report : []);

        // ---- AP-718: operator inbox ----
        try {
            $inbox = $this->inbox->project(['findings' => $findings, 'area_id' => $areaId]);
            $items = is_array($inbox['items'] ?? null) ? $inbox['items'] : [];
            $allGated = $this->everyItemOperatorGated($items);
            $noAuto = ($inbox['claim_policy']['autoapproval_allowed'] ?? true) === false;
            $checks[] = $this->check(
                'inbox', 'AP-718', AreaFocusInboxService::class,
                ($allGated && $noAuto) ? self::CHECK_PASS : self::CHECK_BLOCKED,
                'inbox items='.count($items).', operator_gated='.(YesNo::format($allGated)).', autoapproval='.($noAuto ? 'off' : 'ON'),
                ['inbox_hash' => (string) ($inbox['inbox_hash'] ?? '')],
            );
            $policies['inbox'] = $inbox['claim_policy'] ?? [];
        } catch (Throwable $e) {
            $checks[] = $this->check('inbox', 'AP-718', AreaFocusInboxService::class, self::CHECK_BLOCKED, 'inbox threw: '.$e->getMessage());
        }

        // ---- AP-719: governed work order plan (no execution) ----
        try {
            $plan = $this->router->project(['findings' => $findings, 'area_id' => $areaId]);
            $planStatus = (string) ($plan['status'] ?? 'unknown');
            $noExec = $this->planHasNoExecution($plan);
            $checks[] = $this->check(
                'work_order_router', 'AP-719', AreaFocusDevForgeRouterService::class,
                ($planStatus !== 'blocked' && $noExec) ? self::CHECK_PASS : ($noExec ? self::CHECK_DEGRADED : self::CHECK_BLOCKED),
                'plan status='.$planStatus.', work_orders='.(int) ($plan['work_order_count'] ?? 0).', execution='.($noExec ? 'none' : 'DETECTED'),
            );
            $policies['router'] = $plan['claim_policy'] ?? [];
        } catch (Throwable $e) {
            $checks[] = $this->check('work_order_router', 'AP-719', AreaFocusDevForgeRouterService::class, self::CHECK_BLOCKED, 'router threw: '.$e->getMessage());
        }

        // ---- AP-720: durable cycle + evidence pack ----
        $evidence = ['recorded' => false];
        if ($recordEvidence && $report !== null) {
            try {
                $cycle = $this->cycleRecorder->record(['report' => $report, 'area_id' => $areaId]);
                $cycleOk = ($cycle['cycle_hash'] ?? '') !== '';
                $checks[] = $this->check(
                    'durable_cycle', 'AP-720', AreaFocusCycleRecorderService::class,
                    $cycleOk ? self::CHECK_PASS : self::CHECK_DEGRADED,
                    'cycle_id='.(string) ($cycle['cycle_id'] ?? '?').', findings='.(int) ($cycle['finding_count'] ?? 0),
                    ['cycle_hash' => (string) ($cycle['cycle_hash'] ?? '')],
                );
                $policies['cycle'] = $cycle['claim_policy'] ?? [];

                $pack = $this->evidencePack->build($cycle);
                $packComplete = ($pack['completeness']['complete'] ?? false) === true;
                $checks[] = $this->check(
                    'evidence_pack', 'AP-720', AreaFocusEvidencePackService::class,
                    $packComplete ? self::CHECK_PASS : self::CHECK_DEGRADED,
                    'pack_id='.(string) ($pack['pack_id'] ?? '?').', complete='.(YesNo::format($packComplete)).', morning_inbox_ready='.(YesNo::format($pack['morning_inbox_ready'] ?? false)),
                    ['pack_hash' => (string) ($pack['pack_hash'] ?? '')],
                );
                $policies['evidence_pack'] = $pack['claim_policy'] ?? [];
                $evidence = [
                    'recorded' => true,
                    'cycle_id' => (string) ($cycle['cycle_id'] ?? ''),
                    'cycle_hash' => (string) ($cycle['cycle_hash'] ?? ''),
                    'pack_id' => (string) ($pack['pack_id'] ?? ''),
                    'pack_complete' => $packComplete,
                    'morning_inbox_ready' => (bool) ($pack['morning_inbox_ready'] ?? false),
                ];
            } catch (Throwable $e) {
                $checks[] = $this->check('durable_cycle', 'AP-720', AreaFocusCycleRecorderService::class, self::CHECK_BLOCKED, 'cycle/evidence threw: '.$e->getMessage());
            }
        } else {
            $checks[] = $this->check('durable_cycle', 'AP-720', AreaFocusCycleRecorderService::class, self::CHECK_SKIPPED, 'record_evidence=false (read-only structural certification)');
            $checks[] = $this->check('evidence_pack', 'AP-720', AreaFocusEvidencePackService::class, self::CHECK_SKIPPED, 'record_evidence=false');
        }

        // ---- governance invariants ----
        [$governancePass, $violations] = $this->governance($policies);
        $checks[] = $this->check(
            'governance', 'AP-712/AP-715/AP-722', self::class,
            $governancePass ? self::CHECK_PASS : self::CHECK_BLOCKED,
            $governancePass ? 'no forbidden flag set across composed slices' : 'forbidden flags: '.implode(', ', $violations),
        );

        $verdict = $this->verdict($checks);

        $payload = [
            'schema_version' => self::CERT_SCHEMA,
            'status' => $verdict,
            'operational' => $verdict === self::STATUS_OPERATIONAL,
            'ap_contract' => 'AP-722',
            'area_id' => $areaId,
            'stewardship_stack' => $this->stewardshipStack(),
            'slice_checks' => $checks,
            'slice_coverage' => $this->sliceCoverage($checks),
            'evidence' => $evidence,
            'governance' => [
                'passed' => $governancePass,
                'violations' => $violations,
                'forbidden_flags_checked' => self::FORBIDDEN_FLAGS,
            ],
            'reused_owners' => $this->reusedOwners(),
            'claim_policy' => $this->claimPolicy($recordEvidence),
        ];
        $payload['cert_hash'] = 'sha256:'.MissionCanonicalHash::sha256($payload);
        $payload['generated_at'] = AreaFocusUtcClock::atomNow();

        return $payload;
    }

    // ---------- check helpers ----------

    /**
     * @param  array<string,mixed>  $evidence
     * @return array<string,mixed>
     */
    private function check(string $id, string $ap, string $owner, string $status, string $detail, array $evidence = []): array
    {
        return [
            'id' => $id,
            'ap_contract' => $ap,
            'owner_service' => $owner,
            'status' => $status,
            'detail' => $detail,
            'evidence' => $evidence,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $items
     */
    private function everyItemOperatorGated(array $items): bool
    {
        foreach ($items as $item) {
            if (! is_array($item)) {
                return false;
            }
            if (($item['operator_decision_required'] ?? false) !== true) {
                return false;
            }
            if (($item['autoapproval_allowed'] ?? false) === true) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string,mixed>  $plan
     */
    private function planHasNoExecution(array $plan): bool
    {
        $policy = is_array($plan['claim_policy'] ?? null) ? $plan['claim_policy'] : [];
        foreach (['execution_executed', 'routing_executed', 'execution_enabled'] as $flag) {
            if (($policy[$flag] ?? false) === true) {
                return false;
            }
        }
        foreach (is_array($plan['work_orders'] ?? null) ? $plan['work_orders'] : [] as $wo) {
            if (is_array($wo) && ($wo['executed'] ?? false) === true) {
                return false;
            }
        }

        return true;
    }

    /**
     * AP-716 keeps AP-717 findings under `area_finding_engine` so the read model
     * does not replace Self-Directed Evolution as canonical gap owner. The
     * operational certification still has to compose that read-only signal into
     * AP-718/AP-719, otherwise deterministic AP-717 evidence certifies as
     * degraded despite having routable findings.
     *
     * @param  array<string,mixed>  $report
     * @return list<array<string,mixed>>
     */
    private function certificationFindings(array $report): array
    {
        $findings = [];
        $seen = [];

        foreach ([
            is_array($report['findings'] ?? null) ? $report['findings'] : [],
            is_array($report['area_finding_engine']['findings'] ?? null) ? $report['area_finding_engine']['findings'] : [],
        ] as $sourceFindings) {
            foreach ($sourceFindings as $finding) {
                if (! is_array($finding)) {
                    continue;
                }
                $hash = (string) ($finding['finding_hash'] ?? '');
                if ($hash !== '' && isset($seen[$hash])) {
                    continue;
                }
                if ($hash !== '') {
                    $seen[$hash] = true;
                }
                $findings[] = $finding;
            }
        }

        return array_values($findings);
    }

    /**
     * Aggregate the composed slices' claim policies and look for any forbidden
     * flag set to true.
     *
     * @param  array<string,array<string,mixed>>  $policies
     * @return array{0:bool,1:list<string>}
     */
    private function governance(array $policies): array
    {
        $violations = [];
        foreach ($policies as $source => $policy) {
            if (! is_array($policy)) {
                continue;
            }
            foreach (self::FORBIDDEN_FLAGS as $flag) {
                if (($policy[$flag] ?? false) === true) {
                    $violations[] = $source.'.'.$flag;
                }
            }
        }

        return [$violations === [], AreaFocusStringListNormalizer::uniqueStringValues($violations)];
    }

    /**
     * @param  list<array<string,mixed>>  $checks
     */
    private function verdict(array $checks): string
    {
        $hasBlocked = false;
        $hasDegraded = false;
        foreach ($checks as $check) {
            $status = (string) ($check['status'] ?? '');
            if ($status === self::CHECK_BLOCKED) {
                $hasBlocked = true;
            } elseif ($status === self::CHECK_DEGRADED || $status === self::CHECK_SKIPPED) {
                $hasDegraded = true;
            }
        }

        return match (true) {
            $hasBlocked => self::STATUS_BLOCKED,
            $hasDegraded => self::STATUS_PARTIAL,
            default => self::STATUS_OPERATIONAL,
        };
    }

    /**
     * @param  list<array<string,mixed>>  $checks
     * @return array<string,string>
     */
    private function sliceCoverage(array $checks): array
    {
        $coverage = [];
        foreach ($checks as $check) {
            $coverage[(string) ($check['id'] ?? '')] = (string) ($check['status'] ?? '');
        }

        return $coverage;
    }

    /**
     * @return array<string,mixed>
     */
    private function stewardshipStack(): array
    {
        return [
            'umbrella' => 'Atlas Software Company Stewardship Stack',
            'level_name' => 'Area Focus Loop',
            'parent_runtime' => 'Atlas Autonomous Software Company Runtime',
            'canonical_statement' => 'Atlas Software Company Stewardship Stack é stack/capability family dentro do Atlas Autonomous Software Company Runtime, não OS novo.',
            'aps' => ['AP-712', 'AP-715', 'AP-716', 'AP-717', 'AP-718', 'AP-719', 'AP-720', 'AP-721', 'AP-722'],
            'capstone' => 'AP-722 operational certification',
            'new_os_created' => false,
            'parallel_runtime_created' => false,
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function reusedOwners(): array
    {
        return [
            'read_model' => ['ap' => 'AP-716', 'owner_service' => AreaFocusLoopReadModelService::class, 'reused_methods' => ['project']],
            'inbox' => ['ap' => 'AP-718', 'owner_service' => AreaFocusInboxService::class, 'reused_methods' => ['project']],
            'router' => ['ap' => 'AP-719', 'owner_service' => AreaFocusDevForgeRouterService::class, 'reused_methods' => ['project']],
            'cycle_recorder' => ['ap' => 'AP-720', 'owner_service' => AreaFocusCycleRecorderService::class, 'reused_methods' => ['record']],
            'evidence_pack' => ['ap' => 'AP-720', 'owner_service' => AreaFocusEvidencePackService::class, 'reused_methods' => ['build']],
        ];
    }

    /**
     * @return array<string,bool>
     */
    private function claimPolicy(bool $recordEvidence): array
    {
        return [
            'read_only' => true,
            'decision_oriented' => true,
            'appends_evidence_ledger' => $recordEvidence,
            'mutates_target_repo' => false,
            'provider_invoked' => false,
            'execution_executed' => false,
            'branch_created' => false,
            'merge_performed' => false,
            'deploy_performed' => false,
            'secrets_accessed' => false,
            'destructive_change' => false,
            'autoapproval_allowed' => false,
            'autoimplementation_allowed' => false,
            'parallel_runtime_created' => false,
            'parallel_proposal_registry_created' => false,
            'new_os_created' => false,
            'operator_review_required' => true,
        ];
    }
}
