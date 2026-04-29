<?php

namespace App\Services\Semantic;

use App\Models\SemanticNote;
use App\Models\SemanticNoteActivation;
use App\Models\VaultHealthSnapshot;
use App\Support\Metadata;

class VaultGovernanceService
{
    public function snapshot(): VaultHealthSnapshot
    {
        $date = now()->toDateString();
        $total = SemanticNote::query()->whereNull('deleted_at')->count();
        $active = SemanticNote::query()->whereNull('deleted_at')->whereIn('status', ['active', 'testing', 'validated'])->count();
        $inbox = SemanticNote::query()->whereNull('deleted_at')->where('status', 'inbox')->count();
        $invalid = SemanticNote::query()->whereNull('deleted_at')->where('status', 'invalid')->count();
        $stale = SemanticNote::query()
            ->whereNull('deleted_at')
            ->whereIn('status', ['active', 'testing', 'validated'])
            ->where(function ($query): void {
                $query->whereNull('last_activated_at')->orWhere('last_activated_at', '<', now()->subMonths(6));
            })
            ->count();
        $withoutTriggers = SemanticNote::query()
            ->whereNull('deleted_at')
            ->whereIn('status', ['active', 'testing', 'validated'])
            ->whereRaw('jsonb_array_length(trigger_signals) = 0')
            ->count();
        $withoutLinks = SemanticNote::query()
            ->whereNull('semantic_notes.deleted_at')
            ->whereDoesntHave('sourceLinks')
            ->whereDoesntHave('targetLinks')
            ->count();
        $activations7d = SemanticNoteActivation::query()
            ->where('created_at', '>=', now()->subDays(7))
            ->count();
        $useful7d = SemanticNoteActivation::query()
            ->where('created_at', '>=', now()->subDays(7))
            ->where('usefulness_score', '>=', 4)
            ->count();

        [$state, $recommendations] = $this->stateAndRecommendations(
            total: $total,
            active: $active,
            inbox: $inbox,
            invalid: $invalid,
            stale: $stale,
            withoutTriggers: $withoutTriggers,
            withoutLinks: $withoutLinks,
            activations7d: $activations7d,
            useful7d: $useful7d,
        );

        return VaultHealthSnapshot::updateOrCreate(
            ['snapshot_date' => $date],
            [
                'total_notes' => $total,
                'active_notes' => $active,
                'inbox_notes' => $inbox,
                'invalid_notes' => $invalid,
                'stale_notes' => $stale,
                'notes_without_triggers' => $withoutTriggers,
                'notes_without_links' => $withoutLinks,
                'activations_7d' => $activations7d,
                'useful_activations_7d' => $useful7d,
                'health_state' => $state,
                'recommendations' => Metadata::forStorage($recommendations),
                'metadata' => Metadata::forStorage(['engine' => 'vault-governance-v1']),
            ],
        );
    }

    public function latest(): ?VaultHealthSnapshot
    {
        return VaultHealthSnapshot::query()->latest('snapshot_date')->first();
    }

    /**
     * @return array{0:string, 1:array<int, string>}
     */
    private function stateAndRecommendations(
        int $total,
        int $active,
        int $inbox,
        int $invalid,
        int $stale,
        int $withoutTriggers,
        int $withoutLinks,
        int $activations7d,
        int $useful7d,
    ): array {
        $recommendations = [];
        $state = 'healthy';

        if ($invalid > 0) {
            $state = 'needs_attention';
            $recommendations[] = "{$invalid} nota(s) com frontmatter invalido precisam de revisao.";
        }
        if ($inbox > 12) {
            $state = 'inflated';
            $recommendations[] = 'Inbox semantica acima de 12 notas; agendar curadoria antes de adicionar mais.';
        }
        if ($withoutTriggers > max(3, (int) floor($active * 0.25))) {
            $state = 'needs_attention';
            $recommendations[] = 'Muitas notas ativas nao possuem trigger_signals; Atlas nao sabera quando usa-las.';
        }
        if ($stale > max(5, (int) floor($active * 0.35))) {
            $state = 'cold';
            $recommendations[] = 'Parte relevante do vault nao foi ativada em 6 meses; revisar ou arquivar.';
        }
        if ($activations7d > 14 && $useful7d < 3) {
            $state = 'anxious';
            $recommendations[] = 'Ativacoes demais com pouca utilidade; reduzir frequencia de lembrancas.';
        }
        if ($total >= 30 && $active >= 8 && $invalid === 0 && $withoutTriggers <= 3 && $useful7d >= 3) {
            $state = 'mature';
            $recommendations[] = 'Vault maduro: manter governanca semanal e promover hipoteses testadas.';
        }
        if ($recommendations === []) {
            $recommendations[] = 'Vault em estado saudavel para o volume atual.';
        }

        return [$state, $recommendations];
    }
}
