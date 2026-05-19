<?php

namespace App\Services\Ai\OperatorApproval;

/**
 * Atlas AI Operator Approval Gate · risk policy resolver.
 *
 * Determinístico: dado (requested_action, risk_level, context) retorna
 * (gate_mode, risk_level_final, reasons). Sem LLM, sem provider call.
 *
 * Regras canônicas (alinhadas com o briefing do operador):
 *   - destructive command  → block ou require_confirmation forte
 *   - file edits em massa  → require_review
 *   - finance/trade        → require_review (escala para block se autonomy=suggest)
 *   - cyber active/exploit → require_review / block conforme severidade
 *   - Forge/Obra grande    → escalate_to_forge
 *   - explain/research/conversation → allow_auto
 *   - mission certify      → require_review se sem evidence; allow_auto com evidence
 *   - mission handoff_dev  → allow_auto (low risk)
 *   - mission handoff_forge → escalate_to_forge (sempre)
 *   - default              → require_confirmation (fail-safe)
 *
 * Risk override: requested_action carrega risk_level explícito; se for
 * critical, eleva gate_mode mesmo quando a categoria seria mais permissiva.
 */
class OperatorApprovalRiskPolicy
{
    /**
     * Resolve (gate_mode, risk_level_effective, reasons).
     *
     * @param  array<string,mixed>  $context
     * @return array{gate_mode:string,risk_level:string,reasons:array<int,string>}
     */
    public function resolve(string $requestedAction, string $riskLevel, array $context = []): array
    {
        $action = strtolower(trim($requestedAction));
        $risk = $this->normalizeRisk($riskLevel);
        $reasons = [];

        if ($action === '') {
            $reasons[] = 'requested_action_empty_fail_safe';

            return [
                'gate_mode' => OperatorApprovalCanon::MODE_REQUIRE_CONFIRMATION,
                'risk_level' => OperatorApprovalCanon::RISK_MEDIUM,
                'reasons' => $reasons,
            ];
        }

        // Hard rules — categorias com semântica fixa.
        $byCategory = $this->byCategory($action, $risk, $context, $reasons);
        if ($byCategory !== null) {
            return $this->applyRiskOverride($byCategory, $risk, $reasons);
        }

        // Default: prompt the operator. Fail-safe.
        $reasons[] = 'no_category_match_fail_safe_require_confirmation';

        return [
            'gate_mode' => OperatorApprovalCanon::MODE_REQUIRE_CONFIRMATION,
            'risk_level' => $risk,
            'reasons' => $reasons,
        ];
    }

    /**
     * @param  array<string,mixed>  $context
     * @param  array<int,string>  $reasons
     * @return array{gate_mode:string,risk_level:string}|null
     */
    private function byCategory(string $action, string $risk, array $context, array &$reasons): ?array
    {
        // Destructive — block if critical, require_confirmation otherwise.
        if ($this->startsWith($action, [
            OperatorApprovalCanon::ACTION_TOOL_DESTRUCTIVE,
            'shell.destructive',
            'fs.delete',
        ])) {
            $mode = $risk === OperatorApprovalCanon::RISK_CRITICAL
                ? OperatorApprovalCanon::MODE_BLOCK
                : OperatorApprovalCanon::MODE_REQUIRE_CONFIRMATION;
            $reasons[] = 'category:destructive_command';

            return ['gate_mode' => $mode, 'risk_level' => max($risk, OperatorApprovalCanon::RISK_HIGH) === OperatorApprovalCanon::RISK_CRITICAL
                ? OperatorApprovalCanon::RISK_CRITICAL
                : OperatorApprovalCanon::RISK_HIGH];
        }

        // Mass file edits — require_review.
        if ($this->startsWith($action, [
            OperatorApprovalCanon::ACTION_TOOL_FILE_EDIT_MASS,
            'fs.edit_mass',
            'fs.bulk_edit',
        ])) {
            $reasons[] = 'category:file_edit_mass';

            return ['gate_mode' => OperatorApprovalCanon::MODE_REQUIRE_REVIEW, 'risk_level' => $risk];
        }

        // Finance — review for trades/transfers/publish. Critical risk → block.
        if ($this->startsWith($action, [
            OperatorApprovalCanon::ACTION_FINANCE_TRADE,
            OperatorApprovalCanon::ACTION_FINANCE_TRANSFER,
            'finance.live_trade',
            'finance.publish',
            'finance.allocate',
        ])) {
            $autonomy = (string) ($context['autonomy_level'] ?? '');
            $mode = $risk === OperatorApprovalCanon::RISK_CRITICAL
                ? OperatorApprovalCanon::MODE_BLOCK
                : OperatorApprovalCanon::MODE_REQUIRE_REVIEW;
            if ($autonomy === 'suggest' && $mode === OperatorApprovalCanon::MODE_REQUIRE_REVIEW) {
                $reasons[] = 'autonomy_suggest_elevates_finance_to_review';
            }
            $reasons[] = 'category:finance_sensitive';

            return ['gate_mode' => $mode, 'risk_level' => $risk === OperatorApprovalCanon::RISK_LOW
                ? OperatorApprovalCanon::RISK_MEDIUM
                : $risk];
        }

        // Cyber active/exploit — block on critical, review otherwise.
        if ($this->startsWith($action, [
            OperatorApprovalCanon::ACTION_CYBER_ACTIVE,
            OperatorApprovalCanon::ACTION_CYBER_EXPLOIT,
            'cyber.active',
            'cyber.exfil',
        ])) {
            $mode = $risk === OperatorApprovalCanon::RISK_CRITICAL
                ? OperatorApprovalCanon::MODE_BLOCK
                : OperatorApprovalCanon::MODE_REQUIRE_REVIEW;
            $reasons[] = 'category:cyber_risky';

            return ['gate_mode' => $mode, 'risk_level' => $risk === OperatorApprovalCanon::RISK_LOW
                ? OperatorApprovalCanon::RISK_HIGH
                : $risk];
        }

        // Forge/Obra — escalate to Forge (handoff). Big work goes via Forge, not direct auto.
        if ($this->startsWith($action, [
            OperatorApprovalCanon::ACTION_FORGE_OBRA,
            'mission.handoff_forge',
            'forge.handoff',
        ])) {
            $reasons[] = 'category:forge_obra_escalation';

            return ['gate_mode' => OperatorApprovalCanon::MODE_ESCALATE_TO_FORGE, 'risk_level' => $risk === OperatorApprovalCanon::RISK_LOW
                ? OperatorApprovalCanon::RISK_MEDIUM
                : $risk];
        }

        // Mission certify — needs evidence; without it, force review.
        if ($this->startsWith($action, [OperatorApprovalCanon::ACTION_MISSION_CERTIFY])) {
            $hasEvidence = ! empty($context['evidence_count']) && (int) $context['evidence_count'] > 0;
            if ($hasEvidence && $risk !== OperatorApprovalCanon::RISK_CRITICAL) {
                $reasons[] = 'category:mission_certify_with_evidence';

                return ['gate_mode' => OperatorApprovalCanon::MODE_ALLOW_AUTO, 'risk_level' => $risk];
            }
            $reasons[] = 'category:mission_certify_missing_evidence_or_critical';

            return ['gate_mode' => OperatorApprovalCanon::MODE_REQUIRE_REVIEW, 'risk_level' => $risk];
        }

        // Mission handoff_dev — low risk, allow auto (Atlas Dev runs in its own gate).
        if ($this->startsWith($action, [OperatorApprovalCanon::ACTION_MISSION_HANDOFF_DEV])) {
            $reasons[] = 'category:mission_handoff_dev_low_risk';

            return ['gate_mode' => OperatorApprovalCanon::MODE_ALLOW_AUTO, 'risk_level' => $risk];
        }

        // Mission simulated dispatch — allow auto (no side effect).
        if ($this->startsWith($action, [OperatorApprovalCanon::ACTION_MISSION_SIMULATED, 'mission.simulated'])) {
            $reasons[] = 'category:mission_simulated_safe';

            return ['gate_mode' => OperatorApprovalCanon::MODE_ALLOW_AUTO, 'risk_level' => $risk];
        }

        // Explain / research / conversation — allow auto.
        if ($this->startsWith($action, [
            OperatorApprovalCanon::ACTION_EXPLAIN,
            OperatorApprovalCanon::ACTION_RESEARCH,
            OperatorApprovalCanon::ACTION_CONVERSATION,
        ])) {
            $reasons[] = 'category:read_only_or_conversation';

            return ['gate_mode' => OperatorApprovalCanon::MODE_ALLOW_AUTO, 'risk_level' => $risk];
        }

        return null;
    }

    /**
     * @param  array{gate_mode:string,risk_level:string}  $base
     * @param  array<int,string>  $reasons
     * @return array{gate_mode:string,risk_level:string,reasons:array<int,string>}
     */
    private function applyRiskOverride(array $base, string $requestedRisk, array $reasons): array
    {
        $finalMode = $base['gate_mode'];
        $finalRisk = $base['risk_level'];

        // If caller declared critical risk, never downgrade to allow_auto.
        if ($requestedRisk === OperatorApprovalCanon::RISK_CRITICAL
            && $finalMode === OperatorApprovalCanon::MODE_ALLOW_AUTO) {
            $finalMode = OperatorApprovalCanon::MODE_REQUIRE_REVIEW;
            $reasons[] = 'risk_override:critical_blocks_allow_auto';
            $finalRisk = OperatorApprovalCanon::RISK_CRITICAL;
        }

        return [
            'gate_mode' => $finalMode,
            'risk_level' => $finalRisk,
            'reasons' => $reasons,
        ];
    }

    private function normalizeRisk(string $risk): string
    {
        $risk = strtolower(trim($risk));
        if (! in_array($risk, OperatorApprovalCanon::RISK_LEVELS, true)) {
            return OperatorApprovalCanon::RISK_LOW;
        }

        return $risk;
    }

    /**
     * @param  array<int,string>  $needles
     */
    private function startsWith(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            $needle = strtolower($needle);
            if ($haystack === $needle || str_starts_with($haystack, $needle.'.') || str_starts_with($haystack, $needle.':')) {
                return true;
            }
            if (str_starts_with($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}
