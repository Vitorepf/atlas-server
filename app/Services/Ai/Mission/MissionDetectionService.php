<?php

namespace App\Services\Ai\Mission;

/**
 * Mission Mode detection · keyword heuristics determinístico.
 *
 * Decide se um prompt deve ativar Mission Mode (criar AiMission persistido +
 * decompor + planejar work_orders) ou seguir como trivial/task one-shot.
 *
 * Estratégia (paridade com IntentKernelService): zero LLM, 100% determinístico
 * via keyword matching sobre o prompt normalizado (lowercase + ASCII translit).
 *
 * Sinais coletados:
 *   - persistence_keywords: missão, meta, objetivo, "até concluir", "não pare", …
 *   - obra_keywords: obra, épico, "projeto inteiro", "feature complete", …
 *   - factory_type vindo de {@see MissionFactoryService::classify()}
 *
 * Regras de promoção:
 *   - obra keyword OU factory_type=obra → MISSION_TYPE_OBRA
 *   - persistence keyword sobre TRIVIAL/TASK → promove para MISSION
 *   - sem sinal → segue factory_type (TRIVIAL/TASK fica sem record)
 *
 * Mission Mode só ativa quando suggested_mission_type ∈ {mission, obra}.
 *
 * @see MissionSignal contrato de saída
 */
class MissionDetectionService
{
    /**
     * Palavras-chave que indicam commitment/persistência. Versões em PT + EN
     * sem acento (o normalizador remove diacritics).
     *
     * @var list<string>
     */
    private const PERSISTENCE_KEYWORDS = [
        // PT — substantivos de meta/missão
        'missao',
        'meta',
        'objetivo',
        // EN — equivalentes
        'mission',
        'goal',
        'objective',
        // PT — frases de persistência
        'ate concluir',
        'ate completar',
        'ate terminar',
        'ate o fim',
        'nao pare',
        'nao desista',
        'persista',
        'faca ate',
        'execute ate',
        'continue ate',
        'mantenha ate',
        'siga ate',
        // EN — equivalentes
        'until done',
        'until completed',
        'until finished',
        'do not stop',
        'dont stop',
        'keep going',
        'do until',
        'complete until',
        'finish until',
        // Long-horizon hints
        'longo prazo',
        'longa duracao',
        'long horizon',
        'long-horizon',
    ];

    /**
     * Palavras-chave que indicam Obra/escopo pesado. Promovem direto para
     * mission_type=obra (sobrepondo factory_type).
     *
     * @var list<string>
     */
    private const OBRA_KEYWORDS = [
        // PT — obra/épico
        'obra',
        'epico',
        'projeto inteiro',
        'sistema novo',
        'sistema inteiro',
        'reescrever tudo',
        'refator grande',
        'refator pesado',
        'feature completa',
        // EN — equivalentes
        'epic',
        'epic feature',
        'whole project',
        'entire system',
        'rewrite all',
        'feature complete',
        'major refactor',
    ];

    public function __construct(private readonly MissionFactoryService $factory) {}

    /**
     * @param  array<string,mixed>  $context  reservado para futura expansão
     *                                        (surface_id, atlas_focus, mode, …) — hoje não influencia.
     */
    public function detect(string $rawPrompt, array $context = []): MissionSignal
    {
        // O `MissionFactoryService::normalizeIntent()` usa `iconv(..., ASCII//TRANSLIT)`
        // que em macOS produz marcadores residuais (`miss~ao`, `at'e`) em vez do
        // strip GNU completo (`missao`, `ate`). Removemos os marcadores aqui para
        // que as keywords (escritas no shape canônico ASCII) façam match.
        $normalized = $this->stripDiacriticArtifacts(
            $this->factory->normalizeIntent($rawPrompt),
        );

        $persistenceHits = $this->findKeywords($normalized, self::PERSISTENCE_KEYWORDS);
        $obraHits = $this->findKeywords($normalized, self::OBRA_KEYWORDS);

        $factoryType = $this->factory->classify($rawPrompt);
        $hasPersistence = $persistenceHits !== [];
        $hasObra = $obraHits !== [];

        // Word-boundary keyword match no MissionDetectionService é mais preciso
        // que o `str_contains` do `MissionFactoryService::classify()` (que tem
        // false positives como `cobranca → obra`). Quando há keyword obra real
        // (boundary check), promove direto. Quando há persistence, MISSION.
        // Caso contrário cai no factory_type (pode ser OBRA legítimo via outro
        // path, ou TASK/TRIVIAL).
        $finalType = match (true) {
            $hasObra => MissionFactoryService::TYPE_OBRA,
            $hasPersistence => MissionFactoryService::TYPE_MISSION,
            default => $factoryType,
        };

        $shouldActivate = in_array(
            $finalType,
            [MissionFactoryService::TYPE_MISSION, MissionFactoryService::TYPE_OBRA],
            true,
        );

        $confidence = match (true) {
            $hasObra => 0.92,
            $hasPersistence => 0.84,
            $factoryType === MissionFactoryService::TYPE_OBRA => 0.86,
            $factoryType === MissionFactoryService::TYPE_MISSION => 0.70,
            $factoryType === MissionFactoryService::TYPE_TASK => 0.40,
            default => 0.20,
        };

        $reason = match (true) {
            $hasObra => 'obra_keyword_match:'.implode(',', $obraHits),
            $hasPersistence => 'persistence_keyword_match:'.implode(',', $persistenceHits),
            $factoryType === MissionFactoryService::TYPE_OBRA => 'factory_classified_obra',
            $factoryType === MissionFactoryService::TYPE_MISSION => 'factory_classified_mission',
            $factoryType === MissionFactoryService::TYPE_TASK => 'factory_classified_task_no_persistence',
            default => 'no_persistence_signal',
        };

        return new MissionSignal(
            shouldActivateMissionMode: $shouldActivate,
            suggestedMissionType: $finalType,
            persistenceKeywords: $persistenceHits,
            obraKeywords: $obraHits,
            factoryType: $factoryType,
            confidence: $confidence,
            reason: $reason,
            normalizedIntent: $normalized,
        );
    }

    /**
     * Match each keyword against the normalized text with word-boundary
     * semantics — evita false positives clássicos como `cobranca → obra`
     * (substring sem boundary). Frases multi-palavra (com espaço interno)
     * funcionam igual; chars não a-z0-9 (pontuação, espaço, edges) contam
     * como boundary.
     *
     * @param  list<string>  $keywords
     * @return list<string>
     */
    private function findKeywords(string $normalized, array $keywords): array
    {
        $hits = [];
        foreach ($keywords as $keyword) {
            $pattern = '/(?<![a-z0-9])'.preg_quote($keyword, '/').'(?![a-z0-9])/u';
            if (preg_match($pattern, $normalized) === 1) {
                $hits[] = $keyword;
            }
        }

        return $hits;
    }

    /**
     * Remove combining diacritic markers that survive iconv TRANSLIT on macOS
     * (tilde `~`, apostrophe `'`, grave `` ` ``, circumflex `^`). Keeps
     * everything else intact. Idempotent.
     */
    private function stripDiacriticArtifacts(string $normalized): string
    {
        $stripped = preg_replace('/[~\'`\^]/u', '', $normalized);

        return $stripped === null ? $normalized : $stripped;
    }
}
