<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Teos\AtlasTeosI3CounterfactualService;
use Illuminate\Console\Command;

class AtlasTeosCounterfactualRecommendCommand extends Command
{
    protected $signature = 'atlas:teos:counterfactual:recommend
        {--mission-id=}
        {--work-order-id=}
        {--obra-id=}
        {--trigger=operator_request : below_threshold_outcome|operator_request|regression_detected}
        {--json : JSON output}';

    protected $description = 'TEOS-I3 · best replan recommendation for a scope (read-only).';

    public function handle(AtlasTeosI3CounterfactualService $svc): int
    {
        $rec = $svc->recommendReplan([
            'scope' => [
                'mission_id' => $this->option('mission-id'),
                'work_order_id' => $this->option('work-order-id'),
                'obra_id' => $this->option('obra-id'),
            ],
            'trigger' => (string) ($this->option('trigger') ?? 'operator_request'),
        ]);
        if ($this->option('json')) {
            $this->line(json_encode(['ok' => true, 'action' => 'recommend-replan', 'recommendation' => $rec], JSON_PRETTY_PRINT));

            return 0;
        }
        $this->line('[atlas:teos:counterfactual:recommend]');
        $this->line('best_branch='.($rec['best_branch_id'] ?? 'none').' confidence='.$rec['confidence'].' actionable='.($rec['actionable'] ? 'yes' : 'no'));
        $this->line('improvement='.$rec['improvement_delta']);

        return 0;
    }
}
