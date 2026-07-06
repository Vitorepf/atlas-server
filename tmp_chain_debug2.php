<?php

use App\Services\Ai\SelfConstruction\AgentControlPlaneChainIntegrityAuditService;
use App\Services\Ai\SelfConstruction\AgentControlPlaneChainIntegritySurfaceAuditor;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReadinessService;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/vendor/autoload.php';

$app = require __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$readiness = app(AtlasSelfConstructionReadinessService::class);

$surfaceAuditor = new class extends AgentControlPlaneChainIntegritySurfaceAuditor
{
    public function __construct() {}

    public function documentationAudit(array $deepChain): array
    {
        return ['contract_doc_present' => true, 'missing_doc_bullets' => [], 'duplicate_slice_bullets' => []];
    }

    public function cliSurface(array $deepChain): array
    {
        return ['missing_options' => [], 'handlers_aligned' => true];
    }

    public function invokerSurface(array $deepChain): array
    {
        return ['missing_invokers' => []];
    }

    public function testSurface(): array
    {
        return ['missing_tests' => []];
    }
};

$service = new AgentControlPlaneChainIntegrityAuditService($readiness, null, $surfaceAuditor);

$result = $service->audit([
    'override_projection' => [
        'current_capability' => [
            'agent_control_plane_chain_integrity_contract',
            'agent_control_plane_chain_integrity_preflight',
            'agent_control_plane_chain_integrity_implementation_packet',
            'agent_control_plane_chain_integrity_invoker_service',
            'agent_control_plane_chain_integrity_status_projection',
        ],
        'not_yet_runtime_capable' => ['', '', '', 'agent_control_plane_chain_integrity'],
        'next_required_slice' => 'agent_control_plane_chain_integrity',
        'next_build_slices' => ['agent_control_plane_chain_integrity'],
    ],
    'override_slices' => [
        [
            'slice_key' => 'agent_control_plane_chain_integrity',
            'method_prefix' => 'agentControlPlaneChainIntegrity',
            'invoker_class' => 'App\\Services\\Ai\\SelfConstruction\\AgentControlPlaneChainIntegrityInvoker',
            'prepare_method' => 'prepare',
            'doc_bullet' => 'chain integrity audit',
            'activate_key' => 'agent_control_plane_chain_integrity',
            'runtime_key' => 'agent_control_plane_chain_integrity',
        ],
    ],
]);

echo 'verdict: '.$result['verdict']."\n";
echo 'status: '.$result['status']."\n";
echo 'gap_summary count: '.count($result['gap_summary'])."\n";
foreach ($result['gap_summary'] as $gap) {
    echo json_encode($gap)."\n";
}
