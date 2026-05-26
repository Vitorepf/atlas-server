<?php

namespace App\Services\Ai\CognitiveMemory;

/**
 * Atlas Cognition Operating System — working-set memory policy.
 *
 * Schemas canon: atlas.cognitive_memory.{budget|working_set|hot_context_item|delta_receipt|spillover_receipt|pressure_event}.v1
 * Doc canon: atlas-cognitive-memory-fabric.md (AUCRI bloco #15).
 *
 * RESPONSABILIDADE:
 *  - Gerenciar working memory hot context na RAM como cache curto.
 *  - Calcular heat score (frequency + recency + must-keep).
 *  - Decidir delta envio (nao reenviar o que ja foi visto).
 *  - Spillover para disco quando RAM aperta.
 *  - Emitir pressure events quando memoria fica apertada.
 *
 * Phase 1 (esta versao):
 *  - Working set em memoria via array indexado por scope.
 *  - Budget canonico (max items, max bytes estimados).
 *  - Heat score determinismo (formula explicita).
 *  - Delta receipt: lista hashes que ja foram entregues -> proximo cycle pula.
 *  - In-process apenas; no spillover real ainda.
 *
 * Phase 2 (proximo AP):
 *  - Persistencia opcional via cache driver (Redis/file).
 *  - Spillover real para disco com receipts.
 *  - Pressure events ligados a metricas SLO.
 *  - Hot context items por workspace_id (AWIS scope).
 *  - Integracao com AMPG (Atlas Memory Pressure Governor) — phase 3.
 *
 * Cognitive immune compliance:
 *  - hot context items respeitam ARPTL privacy class.
 *  - Spillover preserva must_keep_coverage = 1.0.
 *  - Delta receipt nao deduplica items must_keep (sempre re-enviados).
 */
class AtlasCognitiveWorkingSetMemoryService
{
    public const MODE_EMERGENCY_TRIM = 'emergency_trim';

    public const MODE_MINIMAL = 'minimal';

    public const MODE_BALANCED = 'balanced';

    public const MODE_PERFORMANCE = 'performance';

    public const MODE_DEEP_WORK = 'deep_work';

    public const ALLOWED_MODES = [
        self::MODE_EMERGENCY_TRIM,
        self::MODE_MINIMAL,
        self::MODE_BALANCED,
        self::MODE_PERFORMANCE,
        self::MODE_DEEP_WORK,
    ];

    /**
     * Limites canonicos por modo. Phase 1: in-memory budget; phase 2 le metricas reais.
     *
     * @var array<string,array{max_items:int,max_bytes_estimate:int}>
     */
    private const MODE_BUDGETS = [
        self::MODE_EMERGENCY_TRIM => ['max_items' => 5, 'max_bytes_estimate' => 5_000],
        self::MODE_MINIMAL => ['max_items' => 20, 'max_bytes_estimate' => 20_000],
        self::MODE_BALANCED => ['max_items' => 60, 'max_bytes_estimate' => 100_000],
        self::MODE_PERFORMANCE => ['max_items' => 150, 'max_bytes_estimate' => 300_000],
        self::MODE_DEEP_WORK => ['max_items' => 300, 'max_bytes_estimate' => 600_000],
    ];

    /**
     * @var array<string,array<int,array<string,mixed>>> scope => [items]
     */
    private array $workingSet = [];

    /**
     * @var array<string,array<string,bool>> scope => [content_hash => true]  já entregue.
     */
    private array $deltaSeen = [];

    /**
     * Calcula budget canonico para um modo.
     *
     * @return array{schema_version:string,mode:string,max_items:int,max_bytes_estimate:int}
     */
    public function budget(string $mode = self::MODE_BALANCED): array
    {
        $mode = in_array($mode, self::ALLOWED_MODES, true) ? $mode : self::MODE_BALANCED;
        $limits = self::MODE_BUDGETS[$mode];

        return [
            'schema_version' => 'atlas.cognitive_memory.budget.v1',
            'mode' => $mode,
            'max_items' => $limits['max_items'],
            'max_bytes_estimate' => $limits['max_bytes_estimate'],
        ];
    }

    /**
     * Adiciona/atualiza item no working set.
     * Items must_keep nunca sao removidos por trim.
     *
     * @param  array<string,mixed>  $item  Required keys: content_hash, content, must_keep (bool, default false).
     *                                     Optional: type, scope_ref, recorded_at, last_used_at.
     */
    public function track(string $scope, array $item): void
    {
        $hash = (string) ($item['content_hash'] ?? '');
        if ($hash === '') {
            $hash = hash('sha256', json_encode($item, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
            $item['content_hash'] = $hash;
        }

        $item['must_keep'] = (bool) ($item['must_keep'] ?? false);
        $item['recorded_at'] = $item['recorded_at'] ?? now()->toIso8601String();
        $item['last_used_at'] = $item['last_used_at'] ?? $item['recorded_at'];
        $item['hit_count'] = (int) ($item['hit_count'] ?? 1);

        if (! isset($this->workingSet[$scope])) {
            $this->workingSet[$scope] = [];
        }

        // Atualiza existing OR insere novo.
        $found = false;
        foreach ($this->workingSet[$scope] as &$existing) {
            if (($existing['content_hash'] ?? null) === $hash) {
                $existing['last_used_at'] = $item['last_used_at'];
                $existing['hit_count'] = (int) ($existing['hit_count'] ?? 0) + 1;
                $existing['must_keep'] = $existing['must_keep'] || $item['must_keep'];
                $found = true;
                break;
            }
        }
        unset($existing);

        if (! $found) {
            $this->workingSet[$scope][] = $item;
        }
    }

    /**
     * Retorna working set canonico apos aplicacao de budget e heat-score sort.
     *
     * @return array{schema_version:string,scope:string,mode:string,budget:array<string,mixed>,items:array<int,array<string,mixed>>,total_tracked:int,kept:int,trimmed:int}
     */
    public function workingSet(string $scope, string $mode = self::MODE_BALANCED): array
    {
        $items = $this->workingSet[$scope] ?? [];
        $budget = $this->budget($mode);
        $totalTracked = count($items);

        // Calcula heat score por item.
        $now = now();
        foreach ($items as &$it) {
            $hit = (int) ($it['hit_count'] ?? 1);
            $recordedAt = $it['recorded_at'] ?? $now->toIso8601String();
            $lastUsedAt = $it['last_used_at'] ?? $recordedAt;
            $ageSec = max(1, $now->getTimestamp() - strtotime((string) $lastUsedAt));
            $recencyScore = 1.0 / log(1 + $ageSec);
            $frequencyScore = log(1 + $hit);
            $mustKeepBoost = (bool) ($it['must_keep'] ?? false) ? 100.0 : 0.0;
            $it['heat_score'] = round($recencyScore + $frequencyScore + $mustKeepBoost, 6);
        }
        unset($it);

        // Sort por heat_score desc.
        usort($items, fn ($a, $b) => $b['heat_score'] <=> $a['heat_score']);

        // Trim para budget; must_keep sempre preservado.
        $kept = [];
        $trimmed = 0;
        $maxItems = $budget['max_items'];

        foreach ($items as $it) {
            if (count($kept) < $maxItems || (bool) ($it['must_keep'] ?? false)) {
                $kept[] = $it;
            } else {
                $trimmed++;
            }
        }

        return [
            'schema_version' => 'atlas.cognitive_memory.working_set.v1',
            'scope' => $scope,
            'mode' => $mode,
            'budget' => $budget,
            'items' => $kept,
            'total_tracked' => $totalTracked,
            'kept' => count($kept),
            'trimmed' => $trimmed,
        ];
    }

    /**
     * Calcula delta canonico — items que AINDA NÃO foram entregues neste scope.
     * Items must_keep sempre incluidos no delta (sao re-enviados a cada cycle por design).
     *
     * @param  array<int,array<string,mixed>>  $candidateItems
     * @return array{schema_version:string,scope:string,items:array<int,array<string,mixed>>,delta_size:int,already_seen:int,must_keep_included:int}
     */
    public function delta(string $scope, array $candidateItems): array
    {
        $seen = $this->deltaSeen[$scope] ?? [];

        $deltaItems = [];
        $alreadySeen = 0;
        $mustKeepIncluded = 0;

        foreach ($candidateItems as $item) {
            $hash = (string) ($item['content_hash'] ?? '');
            if ($hash === '') {
                continue;
            }
            $mustKeep = (bool) ($item['must_keep'] ?? false);

            if ($mustKeep) {
                $deltaItems[] = $item;
                $mustKeepIncluded++;

                continue;
            }

            if (isset($seen[$hash])) {
                $alreadySeen++;

                continue;
            }

            $deltaItems[] = $item;
            $seen[$hash] = true;
        }

        $this->deltaSeen[$scope] = $seen;

        return [
            'schema_version' => 'atlas.cognitive_memory.delta_receipt.v1',
            'scope' => $scope,
            'items' => $deltaItems,
            'delta_size' => count($deltaItems),
            'already_seen' => $alreadySeen,
            'must_keep_included' => $mustKeepIncluded,
        ];
    }

    /**
     * Reseta delta seen para um scope (cycle restart).
     */
    public function resetDelta(string $scope): void
    {
        $this->deltaSeen[$scope] = [];
    }

    /**
     * Reseta working set para um scope.
     */
    public function resetWorkingSet(string $scope): void
    {
        $this->workingSet[$scope] = [];
    }

    /**
     * Detect pressure: working set excede budget threshold.
     *
     * @return array{schema_version:string,scope:string,mode:string,pressure:bool,severity:string,total:int,budget_max:int}
     */
    public function pressureEvent(string $scope, string $mode = self::MODE_BALANCED): array
    {
        $budget = $this->budget($mode);
        $total = count($this->workingSet[$scope] ?? []);
        $ratio = $budget['max_items'] > 0 ? $total / $budget['max_items'] : 0.0;

        $severity = 'none';
        $pressure = false;
        if ($ratio >= 1.5) {
            $severity = 'critical';
            $pressure = true;
        } elseif ($ratio >= 1.0) {
            $severity = 'high';
            $pressure = true;
        } elseif ($ratio >= 0.8) {
            $severity = 'watch';
        }

        return [
            'schema_version' => 'atlas.cognitive_memory.pressure_event.v1',
            'scope' => $scope,
            'mode' => $mode,
            'pressure' => $pressure,
            'severity' => $severity,
            'total' => $total,
            'budget_max' => $budget['max_items'],
        ];
    }

    public static function isValidMode(string $mode): bool
    {
        return in_array($mode, self::ALLOWED_MODES, true);
    }
}
