<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Context\AtlasContextObservabilityToRankingReflexiveBridgeService;
use Illuminate\Console\Command;

class AtlasAcopAcrsBridgeCommand extends Command
{
    protected $signature = 'atlas:acop-acrs:bridge
        {--action=emit : emit|list|summary}
        {--kind= : signal kind (context_quality|retrieval_latency|leak_risk|freshness_drift|cost_pressure)}
        {--severity=low : signal severity (low|medium|high)}
        {--value= : numeric/string value for the signal}
        {--scope-json= : JSON scope envelope}
        {--rationale= : free-form rationale}
        {--limit=20 : tail size for list}
        {--json : Emit JSON envelope}';

    protected $description = 'Atlas ACOP→ACRS Reflexive Streaming Bridge — emit observability signals that ACRS can consume to adapt ranking weights.';

    public function handle(AtlasContextObservabilityToRankingReflexiveBridgeService $svc): int
    {
        $json = (bool) $this->option('json');
        $action = (string) $this->option('action');

        try {
            switch ($action) {
                case 'emit':
                    $kind = (string) ($this->option('kind') ?? '');
                    if ($kind === '') {
                        $this->error('--kind=... obrigatório.');

                        return self::FAILURE;
                    }
                    $rawScope = (string) ($this->option('scope-json') ?? '');
                    $scope = $rawScope !== '' ? json_decode($rawScope, true) : [];
                    if (! is_array($scope)) {
                        $scope = [];
                    }
                    $env = $svc->emit([
                        'kind' => $kind,
                        'severity' => (string) $this->option('severity'),
                        'value' => $this->option('value'),
                        'scope' => $scope,
                        'rationale' => (string) ($this->option('rationale') ?? ''),
                    ]);

                    return $this->emit($env, $json);
                case 'list':
                    $list = $svc->listSignals((int) $this->option('limit'));

                    return $this->emit(['count' => count($list), 'tail' => $list], $json);
                case 'summary':
                    return $this->emit($svc->summary(), $json);
                default:
                    $this->error("Unknown action '{$action}'.");

                    return self::FAILURE;
            }
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function emit(array $payload, bool $json): int
    {
        if ($json) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        } else {
            foreach ($payload as $k => $v) {
                $this->line(is_scalar($v) ? "{$k}: {$v}" : "{$k}: ".json_encode($v));
            }
        }

        return self::SUCCESS;
    }
}
