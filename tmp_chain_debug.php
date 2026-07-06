<?php

use App\Services\Ai\SelfConstruction\AgentControlPlaneChainIntegrityAuditService;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/vendor/autoload.php';

$app = require __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$service = app(AgentControlPlaneChainIntegrityAuditService::class);

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
            'doc_bullet' => 'nonexistent doc bullet for chain integrity ok',
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
