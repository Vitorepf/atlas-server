<?php

namespace App\Console\Commands;

use App\Services\Ai\Mobile\InsightInboxEmitter;
use Illuminate\Console\Command;

class AtlasInsightCommand extends Command
{
    protected $signature = 'atlas:insight
        {title : Titulo do insight}
        {--summary= : Resumo curto para Inbox}
        {--body= : Corpo detalhado para Inbox}
        {--category=general : Categoria do insight}
        {--severity=info : debug, info, warning ou critical}
        {--dedupe= : Dedupe key explicita}
        {--dry-run : Apenas renderiza payload planejado}';

    protected $description = 'Cria um insight contextual no Inbox operacional do Atlas.';

    public function handle(InsightInboxEmitter $insights): int
    {
        $payload = [
            'title' => (string) $this->argument('title'),
            'summary' => $this->option('summary'),
            'body' => $this->option('body'),
            'category' => $this->option('category'),
            'severity' => $this->option('severity'),
            'dedupe_key' => $this->option('dedupe'),
            'source_type' => 'operator_cli',
            'insight_kind' => $this->option('category'),
        ];

        if ($this->option('dry-run')) {
            $this->line(json_encode(['would_emit' => true, 'payload' => $payload], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $item = $insights->emit($payload);

        $this->line(json_encode([
            'emitted' => $item !== null,
            'item_id' => $item?->id,
            'type' => $item?->type,
            'deep_link' => $item?->deep_link,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
