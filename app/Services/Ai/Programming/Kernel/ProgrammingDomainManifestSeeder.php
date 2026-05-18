<?php

namespace App\Services\Ai\Programming\Kernel;

use App\Models\AiDomainManifest;
use App\Services\Ai\DomainRuntime\DomainManifestRegistryService;
use App\Services\Ai\DomainRuntime\DomainSeedManifests;

class ProgrammingDomainManifestSeeder
{
    public function __construct(private readonly DomainManifestRegistryService $registry) {}

    public function seed(): AiDomainManifest
    {
        $existing = $this->registry->findByDomainId(ProgrammingDomainKernelCanon::DOMAIN_ID);
        if ($existing) {
            return $existing;
        }

        return $this->registry->register([
            'domain_id' => ProgrammingDomainKernelCanon::DOMAIN_ID,
            'name' => ProgrammingDomainKernelCanon::DOMAIN_NAME,
            'status' => 'active',
            'maturity_stage' => DomainSeedManifests::STAGE_SPECIALIST,
            'owner' => 'atlas-programming',
            'charter' => [
                'mission' => 'Engenharia de programacao governada: prompt vira Mission/WorkOrder, Dev leve/medio executa, Forge recebe obras pesadas por handoff auditavel.',
                'audience' => 'Atlas Dev, Atlas Forge, time interno de programacao.',
                'outcomes' => ['mission_completed', 'work_order_certified', 'evidence_pack_signed', 'forge_handoff_when_required'],
                'frontier' => 'Programacao sob Mission Foundation, Domain Runtime, Policy, Evidence, Tool Runtime e Router; respeita dual-core Dev/Forge.',
                'forbidden' => ProgrammingDomainKernelCanon::FORBIDDEN_ACTIONS,
            ],
            'ontology' => ['mission', 'objective', 'work_order', 'spec', 'task', 'patch', 'test_run', 'review', 'forge_handoff', 'escalation'],
            'departments' => ['dev', 'repair', 'review', 'qa', 'security', 'database', 'visual', 'forge'],
            'flow_profiles' => [
                'programming.dev.quick_fix',
                'programming.dev.senior_loop',
                'programming.repair.diagnose_and_patch',
                'programming.review.pipeline',
                'programming.forge.obra',
            ],
            'tools_allowed' => ProgrammingDomainKernelCanon::TOOLS_ALLOWED,
            'policy_profile' => [
                'autonomy' => 'execute_with_approval',
                'risk' => 'medium',
                'requires_approval_for' => ['programming.forge', 'production deploy', 'destructive migration'],
            ],
            'memory_scope' => [
                'retain_days' => 365,
                'kinds' => ['decisions', 'patches', 'reviews', 'forge_handoffs', 'escalations'],
            ],
            'evidence_schema' => ProgrammingDomainKernelCanon::EVIDENCE_SCHEMA,
            'quality_gates' => ProgrammingDomainKernelCanon::DEFAULT_QUALITY_GATES,
            'handoff_rules' => ProgrammingDomainKernelCanon::HANDOFF_RULES,
            'delivery_types' => ProgrammingDomainKernelCanon::DELIVERY_TYPES,
            'metrics' => ProgrammingDomainKernelCanon::METRICS,
            'forbidden_actions' => ProgrammingDomainKernelCanon::FORBIDDEN_ACTIONS,
            'capabilities' => $this->capabilityDefinitions(),
        ]);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function capabilityDefinitions(): array
    {
        $base = [
            'input_schema' => ['type' => 'object', 'required' => ['mission_id']],
            'output_schema' => ['type' => 'object', 'required' => ['outcome']],
        ];

        return [
            $base + [
                'capability_id' => 'programming.dev',
                'name' => 'Programming dev light/medium loop',
                'description' => 'Executa mission/work_order de Dev leve/medio (fix, endpoint, DTO, refactor pequeno) via AtlasDevRuntimeService.',
                'allowed_tools' => ['command.local_readonly', 'filesystem.read', 'artifact.write_local', 'test.local_command'],
                'risk_level' => 'medium',
                'required_gates' => ['placement_verified', 'spec_present', 'tests_green'],
                'evidence_required' => ['test', 'diff', 'receipt'],
                'maturity_level' => DomainSeedManifests::STAGE_SPECIALIST,
            ],
            $base + [
                'capability_id' => 'programming.repair',
                'name' => 'Programming repair (debug + fix)',
                'description' => 'Diagnostica e corrige falhas via Atlas Dev Repair Pipeline.',
                'allowed_tools' => ['command.local_readonly', 'filesystem.read', 'artifact.write_local', 'test.local_command'],
                'risk_level' => 'medium',
                'required_gates' => ['evidence_attached', 'tests_green'],
                'evidence_required' => ['test', 'diff', 'command'],
                'maturity_level' => DomainSeedManifests::STAGE_SPECIALIST,
            ],
            $base + [
                'capability_id' => 'programming.review',
                'name' => 'Programming review',
                'description' => 'Review estruturado de mudanca proposta; gera findings/risk register.',
                'allowed_tools' => ['filesystem.read', 'docs.search', 'github.readonly'],
                'risk_level' => 'low',
                'required_gates' => ['review_signed', 'evidence_attached'],
                'evidence_required' => ['doc', 'source'],
                'maturity_level' => DomainSeedManifests::STAGE_SPECIALIST,
            ],
            $base + [
                'capability_id' => 'programming.refactor',
                'name' => 'Programming refactor under scope guard',
                'description' => 'Refactor incremental com placement, spec, tests, review.',
                'allowed_tools' => ['filesystem.read', 'artifact.write_local', 'test.local_command'],
                'risk_level' => 'high',
                'required_gates' => ['placement_verified', 'spec_present', 'tests_green', 'review_signed'],
                'evidence_required' => ['diff', 'test', 'review'],
                'maturity_level' => DomainSeedManifests::STAGE_DEPARTMENT,
            ],
            $base + [
                'capability_id' => 'programming.qa',
                'name' => 'Programming QA / test runner',
                'description' => 'Roda suites de teste e empacota resultados como evidence.',
                'allowed_tools' => ['test.local_command', 'command.local_readonly'],
                'risk_level' => 'low',
                'required_gates' => ['tests_green'],
                'evidence_required' => ['test', 'receipt'],
                'maturity_level' => DomainSeedManifests::STAGE_SPECIALIST,
            ],
            $base + [
                'capability_id' => 'programming.security',
                'name' => 'Programming security review',
                'description' => 'Revisa mudanca por superficie de seguranca (auth, secrets, deps).',
                'allowed_tools' => ['filesystem.read', 'docs.search'],
                'risk_level' => 'high',
                'required_gates' => ['review_signed', 'evidence_attached'],
                'evidence_required' => ['doc', 'source', 'receipt'],
                'maturity_level' => DomainSeedManifests::STAGE_DEPARTMENT,
            ],
            $base + [
                'capability_id' => 'programming.database',
                'name' => 'Programming database changes',
                'description' => 'Migracoes, queries e rollback com data safety.',
                'allowed_tools' => ['filesystem.read', 'command.local_readonly', 'artifact.write_local'],
                'risk_level' => 'high',
                'required_gates' => ['placement_verified', 'review_signed', 'evidence_attached'],
                'evidence_required' => ['diff', 'doc', 'command'],
                'maturity_level' => DomainSeedManifests::STAGE_DEPARTMENT,
            ],
            $base + [
                'capability_id' => 'programming.visual',
                'name' => 'Programming visual smoke',
                'description' => 'Smoke visual / responsividade / screenshots.',
                'allowed_tools' => ['command.local_readonly', 'artifact.write_local'],
                'risk_level' => 'low',
                'required_gates' => ['evidence_attached'],
                'evidence_required' => ['artifact', 'doc'],
                'maturity_level' => DomainSeedManifests::STAGE_SPECIALIST,
            ],
            $base + [
                'capability_id' => 'programming.forge',
                'name' => 'Programming forge heavy obra',
                'description' => 'Recepcao de obras pesadas por handoff auditavel para Atlas Forge.',
                'allowed_tools' => ['filesystem.read', 'docs.search', 'github.readonly', 'evidence.attach'],
                'risk_level' => 'high',
                'required_gates' => ['placement_verified', 'spec_present', 'review_signed', 'evidence_attached'],
                'evidence_required' => ['doc', 'diff', 'test', 'review', 'certification'],
                'maturity_level' => DomainSeedManifests::STAGE_DEPARTMENT,
            ],
        ];
    }
}
