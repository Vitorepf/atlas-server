<?php

namespace App\Console\Commands;

use App\Services\Ai\Mobile\ProposalInboxEmitter;
use Illuminate\Console\Command;

class AtlasProposalCommand extends Command
{
    protected $signature = 'atlas:proposal
        {title : Titulo da proposta}
        {--problem= : Problema real identificado}
        {--solution= : Solucao proposta}
        {--worth-it= : Por que vale ou nao vale a pena}
        {--branch= : Branch/diff preparado para revisao}
        {--dedupe= : Dedupe key explicita}
        {--dry-run : Apenas renderiza payload planejado}';

    protected $description = 'Cria uma proposal segura no Inbox operacional, sem commit ou merge automatico.';

    public function handle(ProposalInboxEmitter $proposals): int
    {
        $payload = [
            'title' => (string) $this->argument('title'),
            'problem' => $this->option('problem'),
            'solution' => $this->option('solution'),
            'worth_it' => $this->option('worth-it'),
            'branch' => $this->option('branch'),
            'dedupe_key' => $this->option('dedupe'),
            'source_type' => 'operator_cli',
        ];

        if ($this->option('dry-run')) {
            $this->line(json_encode(['would_emit' => true, 'payload' => $payload], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $item = $proposals->emit($payload);

        $this->line(json_encode([
            'emitted' => $item !== null,
            'item_id' => $item?->id,
            'type' => $item?->type,
            'deep_link' => $item?->deep_link,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
