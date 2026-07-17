<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognition;

use App\Services\Ai\Support\AiValueNormalizer;
use App\Services\Ai\Support\AppendOnlyJsonlStore;
use Throwable;

/**
 * E — #20 frontier "escada de eventos externos" (obra20 §Fase-0 / linha 15).
 *
 * The sovereign-organism frontier ships by WAVES, and a wave N+1 only ACTIVATES
 * once wave N has accrued ~5 real EXTERNAL events in the ledger (a merged pack
 * that changed a diff; a contradiction that prevented a real error; a memory
 * cited by a session that did not generate it). Design/spec may be parallel;
 * activation is sequential. "Nenhuma frente declara sucesso sobre si mesma."
 *
 * This is the honest gate + the frontier registry that ETIQUETA the five
 * systems (SIS2-7 + economia): each wave is `active` only when the PRIOR wave's
 * external-event count clears the threshold — otherwise `aguardando_eventos`,
 * never self-declared. Fase 0 (constitution) is the portão and must be built
 * before wave 1 activates. Nothing here fabricates readiness.
 */
final class AtlasFrontierWaveLadder
{
    public const SCHEMA_VERSION = 'atlas.cognition.frontier_ladder.v1';

    /** ~5 external events on wave N before wave N+1 may activate (obra20 §15). */
    public const EVENT_THRESHOLD = 5;

    /** The three external-event kinds that count (obra20 §15). */
    public const EVENT_KINDS = [
        'pack_diff_merged',            // um pack que mudou um diff mergeado
        'contradiction_prevented_error', // contradição que impediu erro real
        'memory_cited_by_foreign_session', // memória citada por sessão que não a gerou
    ];

    /**
     * Waves in activation order (obra20 §Fase-0 + contexto-mestre §5).
     *
     * @var array<int,array{key:string,systems:list<string>,summary:string}>
     */
    public const WAVES = [
        ['key' => 'fase_0', 'systems' => ['constituicao'], 'summary' => 'Constituição: scorecard 10× congelado por hash + quarentena de síntese + razão de transações + journal-first (Sistema 8) — o PORTÃO'],
        ['key' => 'onda_1', 'systems' => ['SIS2'], 'summary' => 'SIS2 ALIS self-host (TETO: soberania + custo R$0, NÃO paridade; juiz assimétrico junto)'],
        ['key' => 'onda_2', 'systems' => ['SIS3', 'SIS5'], 'summary' => 'SIS3 causal ∥ SIS5 curiosidade + auto-construção fechada'],
        ['key' => 'onda_3', 'systems' => ['SIS6', 'SIS7'], 'summary' => 'SIS6 fábrica de frotas ∥ SIS7 simbiose/multi-domínio (trading SHADOW-ONLY, execução real PROIBIDA)'],
        ['key' => 'onda_4', 'systems' => ['economia', 'depreciacao', 'graduacao'], 'summary' => 'Economia de arms + depreciação + graduação em regime'],
    ];

    private string $path;

    public function __construct(?string $path = null)
    {
        $this->path = $path ?? (function_exists('storage_path')
            ? storage_path('atlas/frontier/external_events.jsonl')
            : sys_get_temp_dir().'/atlas/frontier/external_events.jsonl');
    }

    public function path(): string
    {
        return $this->path;
    }

    /** Record ONE real external event for a wave. Ignores unknown wave/kind. */
    public function recordExternalEvent(string $wave, string $kind, string $ref = ''): void
    {
        if (! $this->isWave($wave) || ! in_array($kind, self::EVENT_KINDS, true)) {
            return;
        }
        try {
            AppendOnlyJsonlStore::append($this->path, [
                'wave' => $wave,
                'kind' => $kind,
                'ref' => $ref,
                'at' => now()->toIso8601String(),
            ]);
        } catch (Throwable) {
            // fail-open
        }
    }

    /**
     * Frontier ladder status. A wave is `active` only when the PRIOR wave has
     * >= EVENT_THRESHOLD external events; `aguardando_eventos` otherwise. Fase 0
     * (index 0) is the gate itself — always eligible to be built, never gated.
     *
     * @return array<string,mixed>
     */
    public function status(): array
    {
        $counts = $this->eventCounts();

        $waves = [];
        foreach (self::WAVES as $i => $wave) {
            if ($i === 0) {
                $activation = 'portao'; // Fase 0 constitution — build first, gates the rest
                $priorEvents = null;
            } else {
                $priorKey = self::WAVES[$i - 1]['key'];
                $priorEvents = $counts[$priorKey] ?? 0;
                $activation = $priorEvents >= self::EVENT_THRESHOLD ? 'active' : 'aguardando_eventos';
            }
            $waves[] = [
                'wave' => $wave['key'],
                'systems' => $wave['systems'],
                'summary' => $wave['summary'],
                'activation' => $activation,
                'external_events' => $counts[$wave['key']] ?? 0,
                'prior_events' => $priorEvents,
                'threshold' => self::EVENT_THRESHOLD,
            ];
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => gmdate('c'),
            'event_threshold' => self::EVENT_THRESHOLD,
            'waves' => $waves,
            'note' => 'Ativação sequencial por eventos externos REAIS no ledger — nenhuma frente declara sucesso sobre si mesma (obra20 §15). Desenho/spec paralelos; ativação gated.',
        ];
    }

    /** @return array<string,int> wave => external event count */
    private function eventCounts(): array
    {
        $counts = [];
        try {
            foreach (AppendOnlyJsonlStore::read($this->path) as $row) {
                $wave = (AiValueNormalizer::trimmedStringOrNull($row['wave'] ?? null) ?? '');
                if ($wave !== '' && in_array((AiValueNormalizer::trimmedStringOrNull($row['kind'] ?? null) ?? ''), self::EVENT_KINDS, true)) {
                    $counts[$wave] = ($counts[$wave] ?? 0) + 1;
                }
            }
        } catch (Throwable) {
            return [];
        }

        return $counts;
    }

    private function isWave(string $wave): bool
    {
        foreach (self::WAVES as $w) {
            if ($w['key'] === $wave) {
                return true;
            }
        }

        return false;
    }
}
