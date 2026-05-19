<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\LongHorizon\AtlasLongHorizonCanon;
use App\Services\Ai\LongHorizon\Causal\LongHorizonCausalDecisionGraphService;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

/**
 * TEOS-I2 · Causal Decision Graph Lite CLI.
 *
 *   php artisan atlas:long-horizon:causal-graph --scope-type=mission --scope-id=<uuid> --json
 *
 * Read-only. Sem provider call. Sem mutação de estado. Exit:
 *   0 — payload emitido (gaps são parte do payload, não erro)
 *   1 — runtime exception inesperada
 *   2 — usage error (--scope-type/--scope-id ausentes ou inválidos)
 */
class AtlasLongHorizonCausalGraphCommand extends Command
{
    protected $signature = 'atlas:long-horizon:causal-graph
        {--scope-type= : '.AtlasLongHorizonCanon::SCOPE_TYPE_MISSION.'|'.AtlasLongHorizonCanon::SCOPE_TYPE_WORK_ORDER.'|'.AtlasLongHorizonCanon::SCOPE_TYPE_OBRA.'|'.AtlasLongHorizonCanon::SCOPE_TYPE_FORGE_OBRA.'}
        {--scope-id= : uuid do scope alvo}
        {--json : output JSON pretty}';

    protected $description = 'Atlas Long-Horizon · Causal Decision Graph Lite (read-only view sobre Mission Foundation + Router decisions + Forge intake).';

    public function handle(LongHorizonCausalDecisionGraphService $service): int
    {
        $scopeType = (string) ($this->option('scope-type') ?? '');
        $scopeId = (string) ($this->option('scope-id') ?? '');

        if (trim($scopeType) === '' || trim($scopeId) === '') {
            return $this->emit([
                'ok' => false,
                'action' => 'causal-graph',
                'error' => 'usage_error',
                'message' => 'requires --scope-type and --scope-id',
                'allowed_scopes' => AtlasLongHorizonCanon::CAUSAL_GRAPH_LITE_ALLOWED_SCOPES,
            ], exit: 2);
        }

        try {
            $payload = $service->build($scopeType, $scopeId);
        } catch (InvalidArgumentException $e) {
            return $this->emit([
                'ok' => false,
                'action' => 'causal-graph',
                'error' => 'invalid_argument',
                'message' => $e->getMessage(),
                'allowed_scopes' => AtlasLongHorizonCanon::CAUSAL_GRAPH_LITE_ALLOWED_SCOPES,
            ], exit: 2);
        } catch (Throwable $e) {
            return $this->emit([
                'ok' => false,
                'action' => 'causal-graph',
                'error' => 'exception',
                'exception_class' => $e::class,
                'message' => $e->getMessage(),
            ], exit: 1);
        }

        return $this->emit([
            'ok' => true,
            'action' => 'causal-graph',
            'graph' => $payload,
        ]);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload, int $exit = 0): int
    {
        $this->line(json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));

        return $exit;
    }
}
