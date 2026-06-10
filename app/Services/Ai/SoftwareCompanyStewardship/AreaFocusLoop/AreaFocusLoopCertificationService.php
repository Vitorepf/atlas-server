<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Console\Commands\AtlasNightShiftAreaFocusOperateCommand;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\NightShift\AtlasNightShiftAreaFocusContractRegistry;
use App\Services\Ai\SelfDirectedEvolution\SelfDirectedEvolutionCurationInboxService;

/**
 * Area Focus Loop · Structural Certification (AP-725).
 *
 * Atlas Software Company Stewardship Stack é stack/capability family dentro do
 * Atlas Autonomous Software Company Runtime, não OS novo.
 *
 * Proves the Area Focus Loop family EXISTS and is wired for
 * `agentic_engineering_os` by checking presence/health of the stack/Product Mode
 * docs, the AP contracts (AP-715, AP-716..AP-725), the read model, finding
 * engine, inbox/spec bridge, Dev/Forge router, cycle/evidence services, the
 * orchestrator + operational certification, the operator command, the canonical
 * schemas, the safety gates, the operator decision receipts and the declared
 * validations.
 *
 * It COMPLEMENTS the AP-722 runtime operational certification (which proves the
 * loop RUNS): the operational certification is itself one of the components this
 * structural certification checks for presence. It re-runs nothing, executes no
 * loop, opens no branch, dispatches no work and never merges/deploys/touches
 * secrets. It only inspects presence/health (files, classes, methods, schema
 * constants).
 *
 * Status is `ready_read_only` (everything present) or `blocked` (with
 * `missing_components`). It NEVER returns `ready_for_autonomous_mutation` and
 * always declares `mutation_ready = false`.
 */
class AreaFocusLoopCertificationService
{
    public const CERT_SCHEMA = 'atlas.software_company_stewardship.area_focus_certification.v1';

    public const STATUS_READY_READ_ONLY = 'ready_read_only';

    public const STATUS_BLOCKED = 'blocked';

    public const SUPPORTED_AREA = 'agentic_engineering_os';

    public const KIND_AP_DOC = 'ap_doc';

    public const KIND_KB_DOC = 'kb_doc';

    public const KIND_SERVICE = 'service_class';

    public const KIND_COMMAND = 'command_class';

    public const KIND_SCHEMA = 'schema_const';

    public const KIND_CAPABILITY = 'capability';

    /** APs in the declared range that are not yet allocated; reported, never blocking. */
    private const PENDING_SLICE_APS = ['AP-723', 'AP-724'];

    /** Canonical validations the certified loop must satisfy (declared, not executed here). */
    private const VALIDATION_REFS = [
        'php artisan test',
        'php artisan atlas:engineering:knowledge docs-health --json',
        'php artisan atlas:ai:architecture-validate --json',
        'git diff --check',
        'php artisan atlas:engineering:knowledge sync --prune --json',
    ];

    /**
     * Certify the structural readiness of the Area Focus Loop family.
     *
     * `$input`:
     *   - area_id:        string  default agentic_engineering_os
     *   - probes:         array<string,bool>  per-component presence override (test seam)
     *   - probes_default: bool    fallback presence for components without a real probe (test seam)
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function certify(array $input = []): array
    {
        $areaId = trim((string) ($input['area_id'] ?? self::SUPPORTED_AREA)) ?: self::SUPPORTED_AREA;

        if ($areaId !== self::SUPPORTED_AREA) {
            return $this->finalize($this->blockedUnsupportedArea($areaId));
        }

        $overrides = is_array($input['probes'] ?? null) ? $input['probes'] : [];
        $hasDefault = array_key_exists('probes_default', $input);
        $default = (bool) ($input['probes_default'] ?? false);

        $components = [];
        $missing = [];
        foreach ($this->manifest() as $component) {
            $id = $component['id'];
            $present = match (true) {
                array_key_exists($id, $overrides) => (bool) $overrides[$id],
                $hasDefault => $default,
                default => $this->probe($component),
            };
            $row = [
                'id' => $id,
                'label' => $component['label'],
                'kind' => $component['kind'],
                'ap' => $component['ap'],
                'target' => $this->targetLabel($component),
                'required' => $component['required'],
                'present' => $present,
                'health' => $present ? 'ok' : 'missing',
            ];
            $components[] = $row;
            if ($component['required'] && ! $present) {
                $missing[] = $id;
            }
        }

        $status = $missing === [] ? self::STATUS_READY_READ_ONLY : self::STATUS_BLOCKED;

        $payload = [
            'schema_version' => self::CERT_SCHEMA,
            'status' => $status,
            'ap_contract' => 'AP-725',
            'area_id' => $areaId,
            'mutation_ready' => false,
            'read_only' => true,
            'stewardship_stack' => $this->stewardshipStack(),
            'component_count' => count($components),
            'present_count' => count(array_filter($components, static fn (array $c): bool => $c['present'] === true)),
            'missing_count' => count($missing),
            'missing_components' => $missing,
            'components' => $components,
            'coverage' => $this->coverage($components),
            'pending_slice_aps' => $this->pendingSliceAps($overrides, $hasDefault, $default),
            'safety_gates' => $this->safetyGatesSummary(),
            'operator_receipts' => $this->operatorReceiptsSummary(),
            'validations' => $this->validationRefs(),
            'reused_owners' => $this->reusedOwners(),
            'claim_policy' => $this->claimPolicy(),
        ];

        return $this->finalize($payload);
    }

    // ---------- component manifest ----------

    /**
     * @return list<array<string,mixed>>
     */
    private function manifest(): array
    {
        $m = [];

        // Canonical docs.
        $m[] = $this->c('doc_stack', 'Stewardship Stack doc', self::KIND_KB_DOC, 'AP-715', 'docs/engineering-knowledge-base/atlas-software-company-stewardship-stack.md');
        $m[] = $this->c('doc_product_mode', 'Night Shift Product Mode doc', self::KIND_KB_DOC, 'AP-712', 'docs/engineering-knowledge-base/atlas-autonomous-software-company-night-shift-product-mode.md');

        // AP contracts (required AP-715..AP-722 and AP-725).
        $m[] = $this->c('ap_715', 'AP-715 Stewardship Stack', self::KIND_AP_DOC, 'AP-715', 'AP-715');
        $m[] = $this->c('ap_716', 'AP-716 Core Read Model', self::KIND_AP_DOC, 'AP-716', 'AP-716');
        $m[] = $this->c('ap_717', 'AP-717 Finding Engine', self::KIND_AP_DOC, 'AP-717', 'AP-717');
        $m[] = $this->c('ap_718', 'AP-718 Inbox/Spec Bridge', self::KIND_AP_DOC, 'AP-718', 'AP-718');
        $m[] = $this->c('ap_719', 'AP-719 Dev/Forge Router', self::KIND_AP_DOC, 'AP-719', 'AP-719');
        $m[] = $this->c('ap_720', 'AP-720 Cycle/Evidence', self::KIND_AP_DOC, 'AP-720', 'AP-720');
        $m[] = $this->c('ap_721', 'AP-721 Product Mode Surface', self::KIND_AP_DOC, 'AP-721', 'AP-721');
        $m[] = $this->c('ap_722', 'AP-722 Orchestrator/Operational Cert', self::KIND_AP_DOC, 'AP-722', 'AP-722');
        $m[] = $this->c('ap_725', 'AP-725 Certification (this)', self::KIND_AP_DOC, 'AP-725', 'AP-725');

        // Services.
        $m[] = $this->c('svc_read_model', 'Area Focus read model', self::KIND_SERVICE, 'AP-716', AtlasAreaFocusLoopReadModelService::class);
        $m[] = $this->c('svc_finding_engine', 'Finding engine', self::KIND_SERVICE, 'AP-717', AgenticEngineeringOsFindingEngineService::class);
        $m[] = $this->c('svc_inbox', 'Operator inbox', self::KIND_SERVICE, 'AP-718', AreaFocusInboxService::class);
        $m[] = $this->c('svc_spec_bridge', 'Spec draft bridge', self::KIND_SERVICE, 'AP-718', AreaFocusSpecDraftBridge::class);
        $m[] = $this->c('svc_router', 'Dev/Forge work order router', self::KIND_SERVICE, 'AP-719', AreaFocusDevForgeRouterService::class);
        $m[] = $this->c('svc_cycle_recorder', 'Durable cycle recorder', self::KIND_SERVICE, 'AP-720', AreaFocusCycleRecorderService::class);
        $m[] = $this->c('svc_evidence_pack', 'Evidence pack', self::KIND_SERVICE, 'AP-720', AreaFocusEvidencePackService::class);
        $m[] = $this->c('svc_orchestrator', 'Operational orchestrator', self::KIND_SERVICE, 'AP-722', AreaFocusLoopOperationalOrchestratorService::class);
        $m[] = $this->c('svc_operational_cert', 'Operational certification', self::KIND_SERVICE, 'AP-722', AreaFocusLoopOperationalCertificationService::class);
        $m[] = $this->c('svc_contract_registry', 'Area contract registry', self::KIND_SERVICE, 'AP-712', AtlasNightShiftAreaFocusContractRegistry::class);

        // Operator command.
        $m[] = $this->c('cmd_operate', 'Operational orchestrator command', self::KIND_COMMAND, 'AP-722', AtlasNightShiftAreaFocusOperateCommand::class);

        // Canonical schemas declared by the services.
        $m[] = $this->c('schema_read_model', 'Read model report schema', self::KIND_SCHEMA, 'AP-716', [AtlasAreaFocusLoopReadModelService::class, 'REPORT_SCHEMA']);
        $m[] = $this->c('schema_finding', 'Finding schema', self::KIND_SCHEMA, 'AP-717', [AgenticEngineeringOsFindingEngineService::class, 'FINDING_SCHEMA']);
        $m[] = $this->c('schema_inbox_item', 'Inbox item schema', self::KIND_SCHEMA, 'AP-718', [AreaFocusInboxService::class, 'ITEM_SCHEMA']);
        $m[] = $this->c('schema_work_order', 'Work order schema', self::KIND_SCHEMA, 'AP-719', [AreaFocusDevForgeRouterService::class, 'WORK_ORDER_SCHEMA']);
        $m[] = $this->c('schema_cycle', 'Cycle schema', self::KIND_SCHEMA, 'AP-720', [AreaFocusCycleRecorderService::class, 'CYCLE_SCHEMA']);
        $m[] = $this->c('schema_pack', 'Evidence pack schema', self::KIND_SCHEMA, 'AP-720', [AreaFocusEvidencePackService::class, 'PACK_SCHEMA']);
        $m[] = $this->c('schema_operational_cert', 'Operational cert schema', self::KIND_SCHEMA, 'AP-722', [AreaFocusLoopOperationalCertificationService::class, 'CERT_SCHEMA']);

        // Safety gates + operator decision receipts (capability presence).
        $m[] = $this->c('gate_safety', 'Safety gates wired (no merge/deploy/secrets/destructive)', self::KIND_CAPABILITY, 'AP-712/AP-715', 'safety_gates');
        $m[] = $this->c('receipts_operator', 'Operator decision receipts (gated, no auto-approval)', self::KIND_CAPABILITY, 'AP-718', 'operator_receipts');

        return $m;
    }

    /**
     * @param  string|list<string>  $target
     * @return array<string,mixed>
     */
    private function c(string $id, string $label, string $kind, string $ap, string|array $target, bool $required = true): array
    {
        return ['id' => $id, 'label' => $label, 'kind' => $kind, 'ap' => $ap, 'target' => $target, 'required' => $required];
    }

    // ---------- real probes (read-only inspection) ----------

    /**
     * @param  array<string,mixed>  $component
     */
    private function probe(array $component): bool
    {
        return match ($component['kind']) {
            self::KIND_AP_DOC => $this->apDocExists((string) $component['target']),
            self::KIND_KB_DOC => $this->fileExists((string) $component['target']),
            self::KIND_SERVICE, self::KIND_COMMAND => class_exists((string) $component['target']),
            self::KIND_SCHEMA => $this->schemaConstHealthy($component['target']),
            self::KIND_CAPABILITY => $this->capabilityHealthy((string) $component['target']),
            default => false,
        };
    }

    private function apDocExists(string $apId): bool
    {
        $base = $this->basePath();
        $matches = glob($base.'/docs/ap/'.$apId.'-*.md') ?: [];

        return $matches !== [];
    }

    private function fileExists(string $relativePath): bool
    {
        return is_file($this->basePath().'/'.ltrim($relativePath, '/'));
    }

    /**
     * @param  string|list<string>  $target
     */
    private function schemaConstHealthy(string|array $target): bool
    {
        if (! is_array($target) || count($target) !== 2) {
            return false;
        }
        [$class, $const] = $target;
        if (! class_exists($class) || ! defined($class.'::'.$const)) {
            return false;
        }
        $value = constant($class.'::'.$const);

        return is_string($value) && str_starts_with($value, 'atlas.');
    }

    private function capabilityHealthy(string $capability): bool
    {
        return match ($capability) {
            'safety_gates' => class_exists(AreaFocusDevForgeRouterService::class)
                && method_exists(AreaFocusDevForgeRouterService::class, 'project')
                && class_exists(AreaFocusLoopOperationalCertificationService::class)
                && class_exists(AreaFocusLoopOperationalOrchestratorService::class),
            'operator_receipts' => class_exists(AreaFocusInboxService::class)
                && method_exists(AreaFocusInboxService::class, 'project')
                && defined(SelfDirectedEvolutionCurationInboxService::class.'::OPERATOR_ACTIONS'),
            default => false,
        };
    }

    // ---------- summaries / policy ----------

    /**
     * @param  list<array<string,mixed>>  $components
     * @return array<string,array<string,int>>
     */
    private function coverage(array $components): array
    {
        $byKind = [];
        foreach ($components as $c) {
            $kind = (string) $c['kind'];
            $byKind[$kind] ??= ['present' => 0, 'missing' => 0];
            $byKind[$kind][$c['present'] ? 'present' : 'missing']++;
        }
        ksort($byKind);

        return $byKind;
    }

    /**
     * AP-723/AP-724 presence (informational; never blocks read-only readiness).
     *
     * @param  array<string,bool>  $overrides
     * @return array<string,string>
     */
    private function pendingSliceAps(array $overrides, bool $hasDefault, bool $default): array
    {
        $out = [];
        foreach (self::PENDING_SLICE_APS as $ap) {
            $id = 'pending_'.strtolower(str_replace('-', '_', $ap));
            $present = match (true) {
                array_key_exists($id, $overrides) => (bool) $overrides[$id],
                $hasDefault => $default,
                default => $this->apDocExists($ap),
            };
            $out[$ap] = $present ? 'present' : 'not_yet_allocated';
        }

        return $out;
    }

    /**
     * @return array<string,bool>
     */
    private function safetyGatesSummary(): array
    {
        return [
            'merge_without_operator' => false,
            'deploy_without_operator' => false,
            'secret_access' => false,
            'destructive_change' => false,
            'execution_enabled' => false,
            'branch_isolation_required' => true,
            'budget_required' => true,
            'wip_limit_required' => true,
            'kill_switch_required' => true,
            'evidence_pack_required' => true,
            'morning_inbox_required' => true,
        ];
    }

    /**
     * @return array<string,bool>
     */
    private function operatorReceiptsSummary(): array
    {
        return [
            'operator_decision_required' => true,
            'autoapproval_allowed' => false,
            'autoimplementation_allowed' => false,
            'reuses_curation_inbox' => class_exists(SelfDirectedEvolutionCurationInboxService::class),
        ];
    }

    /**
     * @return list<array<string,string>>
     */
    private function validationRefs(): array
    {
        $refs = [];
        foreach (self::VALIDATION_REFS as $ref) {
            $refs[] = ['ref' => $ref, 'status' => 'declared', 'executed' => 'false'];
        }

        return $refs;
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
            'certification_kind' => 'structural_presence',
            'complements' => 'AP-722 runtime operational certification',
            'aps' => ['AP-712', 'AP-715', 'AP-716', 'AP-717', 'AP-718', 'AP-719', 'AP-720', 'AP-721', 'AP-722', 'AP-725'],
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
            'operational_certification' => [
                'ap' => 'AP-722',
                'owner_service' => AreaFocusLoopOperationalCertificationService::class,
                'role' => 'runtime operational certification (RUNS the loop) — checked for presence here, not re-run',
            ],
            'orchestrator' => [
                'ap' => 'AP-722',
                'owner_service' => AreaFocusLoopOperationalOrchestratorService::class,
                'role' => 'operational orchestrator — checked for presence here',
            ],
        ];
    }

    /**
     * @return array<string,bool|string>
     */
    private function claimPolicy(): array
    {
        return [
            'read_only' => true,
            'mutation_ready' => false,
            'ready_for_autonomous_mutation' => false,
            'structural_inspection_only' => true,
            'runs_loop' => false,
            'writes_state' => false,
            'provider_invoked' => false,
            'opens_branch' => false,
            'merge_without_operator' => false,
            'deploy_without_operator' => false,
            'secret_access' => false,
            'destructive_change' => false,
            'parallel_runtime_created' => false,
            'new_os_created' => false,
            'reuses_operational_certification' => true,
            'operator_review_required' => true,
        ];
    }

    // ---------- blocked + finalize helpers ----------

    /**
     * @return array<string,mixed>
     */
    private function blockedUnsupportedArea(string $areaId): array
    {
        return [
            'schema_version' => self::CERT_SCHEMA,
            'status' => self::STATUS_BLOCKED,
            'ap_contract' => 'AP-725',
            'area_id' => $areaId,
            'mutation_ready' => false,
            'read_only' => true,
            'reason' => 'unsupported_area',
            'detail' => "Area '{$areaId}' is not certifiable. The Area Focus Loop certification supports only '".self::SUPPORTED_AREA."'.",
            'stewardship_stack' => $this->stewardshipStack(),
            'missing_components' => ['area_not_supported'],
            'components' => [],
            'claim_policy' => $this->claimPolicy(),
        ];
    }

    /**
     * @param  array<string,mixed>  $component
     */
    private function targetLabel(array $component): string
    {
        $target = $component['target'];

        return is_array($target) ? implode('::', $target) : (string) $target;
    }

    /**
     * Stamp the deterministic certification hash (excludes generated_at).
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function finalize(array $payload): array
    {
        $payload['certification_hash'] = 'sha256:'.MissionCanonicalHash::sha256($payload);
        $payload['generated_at'] = AreaFocusUtcClock::atomNow();

        return $payload;
    }

    private function basePath(): string
    {
        return function_exists('base_path') ? base_path() : getcwd();
    }
}
