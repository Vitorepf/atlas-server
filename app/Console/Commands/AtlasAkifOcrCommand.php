<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Knowledge\AtlasKnowledgeIngestionFabricOcrConfidenceService;
use Illuminate\Console\Command;

class AtlasAkifOcrCommand extends Command
{
    protected $signature = 'atlas:akif:ocr
        {--action=record : record|list|list-promotable}
        {--input-json= : JSON record envelope}
        {--limit=20 : tail size for list/list-promotable}
        {--json : Emit JSON envelope}';

    protected $description = 'Atlas AKIF OCR Confidence-Scored Ingestion — wrap OCR/transcription content with confidence so downstream promotion can gate.';

    public function handle(AtlasKnowledgeIngestionFabricOcrConfidenceService $svc): int
    {
        $json = (bool) $this->option('json');
        $action = (string) $this->option('action');
        $limit = max(1, (int) $this->option('limit'));

        try {
            switch ($action) {
                case 'record':
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

                    return $this->emit($svc->record($input), $json);
                case 'list':
                    $list = $svc->listArtifacts($limit);

                    return $this->emit(['count' => count($list), 'tail' => $list], $json);
                case 'list-promotable':
                    $list = $svc->listPromotable($limit);

                    return $this->emit(['count' => count($list), 'tail' => $list], $json);
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
