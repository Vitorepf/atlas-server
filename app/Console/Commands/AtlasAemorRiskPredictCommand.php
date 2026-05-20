<?php

namespace App\Console\Commands;

use App\Services\Ai\Aemor\AtlasAemorJudgmentService;
use Illuminate\Console\Command;

class AtlasAemorRiskPredictCommand extends Command
{
    protected $signature = 'atlas:aemor:risk-predict
        {--goal= : Goal/prompt}
        {--scope-type=workspace : Scope type}
        {--scope-id= : Scope id}
        {--json : Emit JSON}';

    protected $description = 'Predict execution risks from AEMOR outcome memory.';

    public function handle(AtlasAemorJudgmentService $judgment): int
    {
        $payload = $judgment->riskPredict((string) ($this->option('goal') ?: ''), [
            'scope_type' => $this->option('scope-type'),
            'scope_id' => $this->option('scope-id'),
        ]);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
        } else {
            $this->components->twoColumnDetail('AEMOR risk', (string) ($payload['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Similar failures', (string) count((array) ($payload['similar_failures'] ?? [])));
        }

        return self::SUCCESS;
    }
}
