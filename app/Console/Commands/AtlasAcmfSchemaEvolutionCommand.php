<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Cognition\AtlasCognitiveMemoryFabricSchemaEvolutionService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

class AtlasAcmfSchemaEvolutionCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:acmf:schema-evolution
        {--action=propose : propose|list}
        {--input-json= : JSON envelope of the proposal input}
        {--limit=10 : tail size for list}
        {--json : Emit JSON envelope}';

    protected $description = 'Atlas ACMF Schema Evolution — propose v+1 of existing schemas (operator-driven or pressure-triggered).';

    public function handle(AtlasCognitiveMemoryFabricSchemaEvolutionService $svc): int
    {
        $json = (bool) $this->option('json');
        $action = (string) $this->option('action');

        try {
            switch ($action) {
                case 'propose':
                    $raw = (string) ($this->option('input-json') ?? '');
                    if ($raw === '') {
                        $this->error('--input-json=... obrigatório.');

                        return self::FAILURE;
                    }
                    $input = json_decode($raw, true);
                    if (! is_array($input)) {
                        $this->error('input-json inválido.');

                        return self::FAILURE;
                    }

                    return $this->emit($svc->propose($input), $json);
                case 'list':
                    $list = $svc->listProposals();
                    $limit = max(1, (int) $this->option('limit'));

                    return $this->emit(['count' => count($list), 'tail' => array_slice($list, -$limit)], $json);
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
            $this->line($this->encode($payload));
        } else {
            foreach ($payload as $k => $v) {
                $this->line(is_scalar($v) ? "{$k}: {$v}" : "{$k}: ".json_encode($v));
            }
        }

        return self::SUCCESS;
    }
}
