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
    public const FIELD_AT = 'at';
    public const FIELD_EVENT_THRESHOLD = 'event_threshold';
    public const SCHEMA_VERSION = 'atlas.cognition.frontier_ladder.v1';

    /** ~5 external events on wave N before wave N+1 may activate (obra20 §15). */
    public const EVENT_THRESHOLD = 5;

    public const ACTIVATION_ACTIVE = 'active';

    public const ACTIVATION_AGUARDANDO_EVENTOS = 'aguardando_eventos';

    /** The three external-event kinds that count (obra20 §15). */
    public const EVENT_KINDS = [
        self::FIELD_PACK_DIFF_MERGED,            // um pack que mudou um diff mergeado
        self::FIELD_CONTRADICTION_PREVENTED_ERROR, // contradição que impediu erro real
        self::FIELD_MEMORY_CITED_BY_FOREIGN_SESSION, // memória citada por sessão que não a gerou
    ];

    public const FIELD_SCHEMA_VERSION = 'schema_version';
    public const FIELD_KEY = 'key';
    public const FIELD_SYSTEMS = 'systems';
    public const FIELD_SUMMARY = 'summary';
    public const FIELD_WAVE = 'wave';
    public const FIELD_KIND = 'kind';
    public const FIELD_WAVES = 'waves';
    public const FIELD_ACTIVATION = 'activation';
    public const FIELD_CONSTITUICAO = 'constituicao';
    public const FIELD_EXTERNAL_EVENTS = 'external_events';
    public const FIELD_PRIOR_EVENTS = 'prior_events';
    public const FIELD_THRESHOLD = 'threshold';
    public const FIELD_GENERATED_AT = 'generated_at';
    public const FIELD_NOTE = 'note';
    public const FIELD_REF = 'ref';
    public const FIELD_FASE_0 = 'fase_0';
    public const FIELD_ONDA_1 = 'onda_1';
    public const FIELD_ONDA_2 = 'onda_2';
    public const FIELD_ONDA_3 = 'onda_3';
    public const FIELD_DEPRECIACAO = 'depreciacao';
    public const FIELD_ONDA_4 = 'onda_4';
    public const FIELD_STORAGE_PATH = 'storage_path';
    public const FIELD_CONTRADICTION_PREVENTED_ERROR = 'contradiction_prevented_error';
    public const FIELD_ECONOMIA = 'economia';
    public const FIELD_GRADUACAO = 'graduacao';
    public const FIELD_MEMORY_CITED_BY_FOREIGN_SESSION = 'memory_cited_by_foreign_session';
    public const FIELD_PACK_DIFF_MERGED = 'pack_diff_merged';
    public const FIELD_PORTAO = 'portao';
    public const FIELD_SIS2 = 'SIS2';
    public const FIELD_SIS3 = 'SIS3';

    /**
     * Waves in activation order (obra20 §Fase-0 + contexto-mestre §5).
     *
     * @var array<int,array{key:string,systems:list<string>,summary:string}>
     */
    public const WAVES = [
        [self::FIELD_KEY => self::FIELD_FASE_0, self::FIELD_SYSTEMS => [self::FIELD_CONSTITUICAO], self::FIELD_SUMMARY => 'Constituição: scorecard 10× congelado por hash + quarentena de síntese + razão de transações + journal-first (Sistema 8) — o PORTÃO'],
        [self::FIELD_KEY => self::FIELD_ONDA_1, self::FIELD_SYSTEMS => [self::FIELD_SIS2], self::FIELD_SUMMARY => 'SIS2 ALIS self-host (TETO: soberania + custo R$0, NÃO paridade; juiz assimétrico junto)'],
        [self::FIELD_KEY => self::FIELD_ONDA_2, self::FIELD_SYSTEMS => [self::FIELD_SIS3, 'SIS5'], self::FIELD_SUMMARY => 'SIS3 causal ∥ SIS5 curiosidade + auto-construção fechada'],
        [self::FIELD_KEY => self::FIELD_ONDA_3, self::FIELD_SYSTEMS => ['SIS6', 'SIS7'], self::FIELD_SUMMARY => 'SIS6 fábrica de frotas ∥ SIS7 simbiose/multi-domínio (trading SHADOW-ONLY, execução real PROIBIDA)'],
        [self::FIELD_KEY => self::FIELD_ONDA_4, self::FIELD_SYSTEMS => [self::FIELD_ECONOMIA, self::FIELD_DEPRECIACAO, self::FIELD_GRADUACAO], self::FIELD_SUMMARY => 'Economia de arms + depreciação + graduação em regime'],
    ];

    private string $path;

    public function __construct(?string $path = null)
    {
        $this->path = $path ?? (function_exists(self::FIELD_STORAGE_PATH)
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
                self::FIELD_WAVE => $wave,
                self::FIELD_KIND => $kind,
                self::FIELD_REF => $ref,
                self::FIELD_AT => now()->toIso8601String(),
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
                $activation = self::FIELD_PORTAO; // Fase 0 constitution — build first, gates the rest
                $priorEvents = null;
            } else {
                $priorKey = self::WAVES[$i - 1][self::FIELD_KEY];
                $priorEvents = $counts[$priorKey] ?? 0;
                $activation = $priorEvents >= self::EVENT_THRESHOLD ? self::ACTIVATION_ACTIVE : self::ACTIVATION_AGUARDANDO_EVENTOS;
            }
            $waves[] = [
                self::FIELD_WAVE => $wave[self::FIELD_KEY],
                self::FIELD_SYSTEMS => $wave[self::FIELD_SYSTEMS],
                self::FIELD_SUMMARY => $wave[self::FIELD_SUMMARY],
                self::FIELD_ACTIVATION => $activation,
                self::FIELD_EXTERNAL_EVENTS => $counts[$wave[self::FIELD_KEY]] ?? 0,
                self::FIELD_PRIOR_EVENTS => $priorEvents,
                self::FIELD_THRESHOLD => self::EVENT_THRESHOLD,
            ];
        }

        return [
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_GENERATED_AT => gmdate('c'),
            self::FIELD_EVENT_THRESHOLD => self::EVENT_THRESHOLD,
            self::FIELD_WAVES => $waves,
            self::FIELD_NOTE => 'Ativação sequencial por eventos externos REAIS no ledger — nenhuma frente declara sucesso sobre si mesma (obra20 §15). Desenho/spec paralelos; ativação gated.',
        ];
    }

    /** @return array<string,int> wave => external event count */
    private function eventCounts(): array
    {
        $counts = [];
        try {
            foreach (AppendOnlyJsonlStore::read($this->path) as $row) {
                $wave = (AiValueNormalizer::trimmedStringOrNull($row[self::FIELD_WAVE] ?? null) ?? '');
                if ($wave !== '' && in_array((AiValueNormalizer::trimmedStringOrNull($row[self::FIELD_KIND] ?? null) ?? ''), self::EVENT_KINDS, true)) {
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
            if ($w[self::FIELD_KEY] === $wave) {
                return true;
            }
        }

        return false;
    }
}
