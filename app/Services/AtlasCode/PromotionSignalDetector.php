<?php

declare(strict_types=1);

namespace App\Services\AtlasCode;

/**
 * Atlas Code · Dev-to-Forge Promotion · Signal Detector.
 *
 * Canon: docs/engineering-knowledge-base/atlas-ai-conversation-surface-and-atlas-dev-v1.md
 *        docs/engineering-knowledge-base/atlas-code-programming-obras-operating-system.md
 *
 * Reads a thread snapshot (messages + traces + heuristic metadata) and
 * scores whether the Atlas Dev conversation should be promoted to a heavier
 * unit of work. The detector is intentionally HEURISTIC — final decision
 * always belongs to the human via the Attention queue. We never auto-create
 * an Obra; we only suggest a target tier.
 *
 * Three promotion tiers (canon):
 *   - quick_intervention  · pequena mudança clara, reversível, sem ampliação
 *   - obra_candidate      · descoberta estruturada antes de virar Obra
 *   - forge_obra          · trabalho pesado, multi-arquivo, gates + evidence
 *
 * Heuristic axes:
 *   - message_density  · muitas mensagens sem convergir
 *   - context_length   · contexto cumulativo grande
 *   - file_breadth     · múltiplos arquivos/subsistemas mencionados
 *   - architecture     · keywords de arquitetura/spec/redesign
 *   - risk             · keywords de risco/produção/migration
 *   - recurring_failure · falhas/erros recorrentes na conversa
 *   - operator_request · humano pediu "promover" / "criar obra" / "forge"
 *
 * Schema: atlas.code.dev_to_forge.signal_report.v1
 */
final class PromotionSignalDetector
{
    public const SCHEMA_VERSION = 'atlas.code.dev_to_forge.signal_report.v1';

    public const TARGET_QUICK_INTERVENTION = 'quick_intervention';
    public const TARGET_OBRA_CANDIDATE = 'obra_candidate';
    public const TARGET_FORGE_OBRA = 'forge_obra';
    public const TARGET_NONE = 'none';

    // Tunable thresholds. Defaults chosen so the unit tests document the
    // exact boundary — change here, not in service code.
    private const MESSAGE_DENSITY_OBRA = 12;     // ≥12 msgs sem convergir
    private const MESSAGE_DENSITY_FORGE = 24;
    private const CONTEXT_CHARS_OBRA = 12000;
    private const CONTEXT_CHARS_FORGE = 40000;
    private const FILE_BREADTH_OBRA = 3;
    private const FILE_BREADTH_FORGE = 6;
    private const FAILURE_REPEAT_OBRA = 2;
    private const FAILURE_REPEAT_FORGE = 4;

    private const ARCHITECTURE_KEYWORDS = [
        'arquitetura', 'architecture', 'redesign', 'refactor', 'refatoração', 'refatoracao',
        'spec', 'mother spec', 'rfc', 'design doc', 'sistema novo', 'novo subsistema',
        'multi-provider', 'multi-projeto', 'plugin system',
    ];

    private const RISK_KEYWORDS = [
        'produção', 'producao', 'production', 'breaking change', 'breaking', 'migration',
        'rollback', 'data loss', 'perda de dado', 'irreversível', 'irreversivel',
        'compliance', 'security', 'segurança', 'seguranca', 'pii', 'gdpr',
        'auth', 'oauth', 'pagamento', 'payment', 'billing',
    ];

    private const FAILURE_KEYWORDS = [
        'falhou', 'failed', 'erro', 'error', 'broken', 'quebrou', 'regression',
        'rejected', 'rejeitada', 'rejeitado', 'aborted', 'crashed', 'panic',
    ];

    private const OPERATOR_PROMOTION_KEYWORDS = [
        'promover', 'criar obra', 'virar obra', 'isso virou obra', 'candidato de obra',
        'candidato a obra', 'forge', 'send to forge', 'promote to forge', 'promote',
        'intervenção rápida', 'intervencao rapida', 'quick intervention',
    ];

    /**
     * @param  array<string,mixed>  $thread     Subset of AiThread row data
     * @param  array<int, array<string,mixed>>  $messages   Ordered messages (asc)
     * @param  array<int, array<string,mixed>>  $traces     Optional trace rows
     * @return array<string,mixed>
     */
    public function analyse(array $thread, array $messages, array $traces = []): array
    {
        $messageCount = count($messages);
        $body = '';
        $userTurns = 0;
        $assistantTurns = 0;
        $failureMatches = 0;
        $files = [];
        $operatorPromotionAt = null;

        foreach ($messages as $msg) {
            $role = (string) ($msg['role'] ?? '');
            if ($role === 'user' || $role === 'human') {
                $userTurns++;
            } elseif ($role === 'assistant' || $role === 'atlas') {
                $assistantTurns++;
            }
            $content = $this->normaliseText((string) ($msg['content'] ?? $msg['body'] ?? ''));
            $body .= "\n".$content;
            foreach ($this->extractFileMentions($content) as $path) {
                $files[$path] = true;
            }
            if ($this->containsAny($content, self::FAILURE_KEYWORDS)) {
                $failureMatches++;
            }
            if ($role !== 'user' && $role !== 'human') {
                continue;
            }
            if ($this->containsAny($content, self::OPERATOR_PROMOTION_KEYWORDS)) {
                $operatorPromotionAt = (string) ($msg['occurred_at'] ?? $msg['created_at'] ?? '');
            }
        }

        $contextChars = strlen($body);
        $fileMentions = array_values(array_keys($files));
        $architectureHits = $this->countHits($body, self::ARCHITECTURE_KEYWORDS);
        $riskHits = $this->countHits($body, self::RISK_KEYWORDS);
        $traceFailureCount = 0;
        foreach ($traces as $trace) {
            $status = (string) ($trace['status'] ?? '');
            if (in_array($status, ['failed', 'error', 'aborted'], true)) {
                $traceFailureCount++;
            }
        }

        $signals = [
            'message_density' => [
                'value' => $messageCount,
                'user_turns' => $userTurns,
                'assistant_turns' => $assistantTurns,
                'obra_threshold' => self::MESSAGE_DENSITY_OBRA,
                'forge_threshold' => self::MESSAGE_DENSITY_FORGE,
            ],
            'context_length' => [
                'chars' => $contextChars,
                'obra_threshold' => self::CONTEXT_CHARS_OBRA,
                'forge_threshold' => self::CONTEXT_CHARS_FORGE,
            ],
            'file_breadth' => [
                'count' => count($fileMentions),
                'files' => array_slice($fileMentions, 0, 32),
                'obra_threshold' => self::FILE_BREADTH_OBRA,
                'forge_threshold' => self::FILE_BREADTH_FORGE,
            ],
            'architecture' => [
                'hits' => $architectureHits,
                'detected' => $architectureHits > 0,
            ],
            'risk' => [
                'hits' => $riskHits,
                'detected' => $riskHits > 0,
            ],
            'recurring_failure' => [
                'message_failure_matches' => $failureMatches,
                'trace_failure_count' => $traceFailureCount,
                'obra_threshold' => self::FAILURE_REPEAT_OBRA,
                'forge_threshold' => self::FAILURE_REPEAT_FORGE,
                'detected' => ($failureMatches + $traceFailureCount) >= self::FAILURE_REPEAT_OBRA,
            ],
            'operator_request' => [
                'detected' => $operatorPromotionAt !== null,
                'at' => $operatorPromotionAt,
            ],
        ];

        // Scoring: weight each axis. Forge requires multiple strong signals;
        // obra_candidate triggers from a single strong heuristic or operator
        // request. quick_intervention is the soft fallback when only mild
        // signals are present.
        $score = 0;
        $reasons = [];

        if ($messageCount >= self::MESSAGE_DENSITY_FORGE) {
            $score += 3;
            $reasons[] = 'message_density_forge';
        } elseif ($messageCount >= self::MESSAGE_DENSITY_OBRA) {
            $score += 2;
            $reasons[] = 'message_density_obra';
        }

        if ($contextChars >= self::CONTEXT_CHARS_FORGE) {
            $score += 3;
            $reasons[] = 'context_length_forge';
        } elseif ($contextChars >= self::CONTEXT_CHARS_OBRA) {
            $score += 1;
            $reasons[] = 'context_length_obra';
        }

        if (count($fileMentions) >= self::FILE_BREADTH_FORGE) {
            $score += 3;
            $reasons[] = 'file_breadth_forge';
        } elseif (count($fileMentions) >= self::FILE_BREADTH_OBRA) {
            $score += 2;
            $reasons[] = 'file_breadth_obra';
        }

        if ($architectureHits >= 2) {
            $score += 3;
            $reasons[] = 'architecture_keywords';
        } elseif ($architectureHits === 1) {
            $score += 1;
            $reasons[] = 'architecture_keyword_single';
        }

        if ($riskHits >= 2) {
            $score += 3;
            $reasons[] = 'risk_keywords';
        } elseif ($riskHits === 1) {
            $score += 1;
            $reasons[] = 'risk_keyword_single';
        }

        $failureTotal = $failureMatches + $traceFailureCount;
        if ($failureTotal >= self::FAILURE_REPEAT_FORGE) {
            $score += 3;
            $reasons[] = 'recurring_failure_forge';
        } elseif ($failureTotal >= self::FAILURE_REPEAT_OBRA) {
            $score += 2;
            $reasons[] = 'recurring_failure_obra';
        }

        // Operator explicit request — highest single-signal weight. We do NOT
        // jump straight to forge_obra; the operator can refine in preview.
        if ($operatorPromotionAt !== null) {
            $score += 4;
            $reasons[] = 'operator_promotion_request';
        }

        $target = self::TARGET_NONE;
        if ($score >= 7) {
            $target = self::TARGET_FORGE_OBRA;
        } elseif ($score >= 4) {
            $target = self::TARGET_OBRA_CANDIDATE;
        } elseif ($score >= 2) {
            $target = self::TARGET_QUICK_INTERVENTION;
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'thread_id' => (string) ($thread['id'] ?? ''),
            'workspace_slug' => isset($thread['workspace']) && $thread['workspace'] !== ''
                ? (string) $thread['workspace']
                : null,
            'message_count' => $messageCount,
            'score' => $score,
            'reasons' => $reasons,
            'recommended_target' => $target,
            'signals' => $signals,
            'detected_files' => $fileMentions,
            'detected_at' => now()->toJSON(),
        ];
    }

    /**
     * @param  array<int,string>  $keywords
     */
    private function containsAny(string $haystack, array $keywords): bool
    {
        foreach ($keywords as $needle) {
            if ($needle !== '' && stripos($haystack, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param  array<int,string>  $keywords
     */
    private function countHits(string $haystack, array $keywords): int
    {
        $count = 0;
        foreach ($keywords as $needle) {
            if ($needle === '') {
                continue;
            }
            $pos = stripos($haystack, $needle);
            while ($pos !== false) {
                $count++;
                $pos = stripos($haystack, $needle, $pos + 1);
                if ($count > 12) {
                    return $count; // saturation cap
                }
            }
        }
        return $count;
    }

    /**
     * Naive file-path extraction: matches things like `src/foo/bar.ts`,
     * `app/Services/X.php`, `.atlas/packets/wp_*.md`. Avoids URLs.
     *
     * @return array<int, string>
     */
    private function extractFileMentions(string $text): array
    {
        if ($text === '') {
            return [];
        }
        // Match backticked filenames first (most reliable).
        $out = [];
        if (preg_match_all('/`([^`\s]+\.(?:tsx?|jsx?|php|rs|go|py|md|yml|yaml|json|css|html|sh))`/i', $text, $m)) {
            foreach ($m[1] as $hit) {
                $out[$hit] = true;
            }
        }
        // Match plain-text paths with a slash.
        if (preg_match_all('#\b([A-Za-z0-9_./-]+/[A-Za-z0-9_./-]+\.(?:tsx?|jsx?|php|rs|go|py|md|yml|yaml|json|css|html|sh))\b#i', $text, $m2)) {
            foreach ($m2[1] as $hit) {
                if (! str_contains($hit, '://')) {
                    $out[$hit] = true;
                }
            }
        }
        return array_keys($out);
    }

    private function normaliseText(string $raw): string
    {
        // Strip JSON-ish noise so risk/architecture keywords don't match on
        // structural fields; keep enough text for human content.
        return preg_replace('/\s+/u', ' ', $raw) ?? $raw;
    }
}
