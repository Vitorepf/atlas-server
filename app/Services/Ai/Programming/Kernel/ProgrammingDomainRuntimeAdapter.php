<?php

namespace App\Services\Ai\Programming\Kernel;

use App\Models\AiDomainRuntimeRecord;
use App\Models\AiMission;
use App\Models\AiWorkOrder;
use App\Services\Ai\DomainRuntime\DomainManifestRegistryService;
use App\Services\Ai\DomainRuntime\DomainRuntimeRecordService;
use App\Services\Ai\Mission\MissionCertificationService;
use App\Services\Ai\Mission\MissionLifecycleService;

class ProgrammingDomainRuntimeAdapter
{
    public function __construct(
        private readonly DomainManifestRegistryService $manifests,
        private readonly DomainRuntimeRecordService $records,
        private readonly MissionLifecycleService $lifecycle,
        private readonly MissionCertificationService $certification,
        private readonly ProgrammingDomainManifestSeeder $seeder,
    ) {}

    /**
     * Score how well the programming domain handles an objective. Heuristic:
     * keyword match over CAPABILITIES + dev intent map. Returns a {score, capability,
     * reason} triple consumed by DomainRuntimeSelectionService callers.
     *
     * @return array<string,mixed>
     */
    public function canHandle(string $objective): array
    {
        $capability = ProgrammingDomainKernelCanon::classifyDevCapability($objective);
        $normalized = mb_strtolower($objective);
        $programmingKeywords = ['code', 'codigo', 'patch', 'feature', 'bug', 'refactor', 'test', 'teste', 'spec', 'migration', 'endpoint', 'api', 'php', 'laravel', 'pest', 'phpunit'];
        $matches = [];
        foreach ($programmingKeywords as $needle) {
            if (str_contains($normalized, $needle)) {
                $matches[] = $needle;
            }
        }

        $score = count($matches) + (str_starts_with($capability, 'programming.') ? 1 : 0);

        return [
            'domain_id' => ProgrammingDomainKernelCanon::DOMAIN_ID,
            'capability' => $capability,
            'score' => $score,
            'reason' => $matches === [] ? 'no_programming_keywords' : 'keyword_match:'.implode(',', $matches),
        ];
    }

    /**
     * Open a DomainRuntimeRecord (Meta 2) tying this programming mission/work_order
     * to the programming manifest. Returns the persisted record.
     *
     * @param  array<string,mixed>  $context
     */
    public function plan(AiMission $mission, ?AiWorkOrder $workOrder = null, array $context = []): AiDomainRuntimeRecord
    {
        $manifest = $this->seeder->seed();
        $capability = (string) ($context['capability'] ?? ProgrammingDomainKernelCanon::classifyDevCapability($mission->raw_prompt));

        $record = $this->records->open($manifest, [
            'mission_id' => $mission->id,
            'work_order_id' => $workOrder?->id,
            'selected_capabilities' => [$capability],
            'execution_plan' => [
                'steps' => [
                    ['stage' => 'plan', 'capability' => $capability],
                    ['stage' => 'execute', 'capability' => $capability],
                    ['stage' => 'validate', 'capability' => 'programming.qa'],
                    ['stage' => 'certify', 'capability' => 'programming.review'],
                ],
                'risk_level' => $mission->risk_level,
                'autonomy_level' => $mission->autonomy_level,
            ],
            'evidence_refs' => $mission->evidenceRefs()->pluck('id')->all(),
            'blockers' => null,
        ]);

        $this->lifecycle->recordEvent(
            $mission,
            'programming.adapter.runtime_record_opened',
            'programming_adapter',
            [
                'domain_runtime_record_id' => $record->id,
                'capability' => $capability,
                'manifest_hash' => $manifest->manifest_hash,
            ],
            null,
            $mission->status,
            $record->receipt_hash,
        );

        return $record;
    }

    /**
     * Mark a domain runtime record as completed and certify the mission, if eligible.
     * Validation/cert decisions happen inside Mission/Domain layers (Meta 1 + 2);
     * this is a thin orchestration call.
     *
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function certify(AiMission $mission, AiDomainRuntimeRecord $record, array $context = []): array
    {
        $this->records->transition($record, DomainRuntimeRecordService::STATUS_COMPLETED, [
            'evidence_refs' => $mission->evidenceRefs()->pluck('id')->all(),
        ]);

        $certification = $this->certification->certify($mission);

        return [
            'domain_runtime_record_id' => $record->id,
            'mission_certification_id' => $certification->id,
            'mission_certification_status' => $certification->status,
            'mission_certification_hash' => $certification->certification_hash,
        ];
    }

    public function describeCapabilities(): array
    {
        $manifest = $this->manifests->findByDomainId(ProgrammingDomainKernelCanon::DOMAIN_ID);
        if (! $manifest) {
            return [];
        }

        return $manifest->capabilities()
            ->orderBy('capability_id')
            ->get()
            ->map(static fn ($capability): array => [
                'capability_id' => $capability->capability_id,
                'risk_level' => $capability->risk_level,
                'required_gates' => $capability->required_gates,
                'evidence_required' => $capability->evidence_required,
                'maturity_level' => $capability->maturity_level,
            ])->all();
    }
}
