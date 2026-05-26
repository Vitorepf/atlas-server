<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\Governance;

/**
 * Finance domain — Skill Pack Gate.
 *
 * Closes the matrix gap "Finance domain: Skill packs/agentes financeiros
 * profundos e benchmarks contra agentes especializados". This is the
 * finance-specific gate that complements the generic
 * `SkillPackPromotionGate`: it enforces invariants specific to financial
 * operations (cash flow, ledger, payments, taxes, compliance reports).
 *
 * Canonical invariants enforced:
 *
 *   - Money-moving skills (`payments.*`, `transfers.*`, `payroll.*`)
 *     require `operator_authority_required=true` AND a two-person
 *     authorisation list AND a value cap declaration.
 *   - Tax/compliance skills (`tax.*`, `compliance.*`) require a
 *     `jurisdiction` declaration and a `non_clinical_disclaimer`.
 *   - All money-moving skills must declare a daily aggregate cap.
 *   - No finance skill is allowed without a registered `audit_trail`
 *     contract.
 */
final class FinanceSkillPackGate
{
    public const SCHEMA_VERSION = 'atlas.finance.skill_pack_gate.v1';

    public const MONEY_MOVING_PREFIXES = ['payments.', 'transfers.', 'payroll.', 'refunds.'];

    public const TAX_COMPLIANCE_PREFIXES = ['tax.', 'compliance.', 'reporting.'];

    /**
     * @param  array<string,mixed>  $pack
     * @return array{
     *   schema_version: string,
     *   pack_id: ?string,
     *   gate_decision: string,
     *   passed_checks: list<string>,
     *   failed_checks: array<string,string>,
     *   detail: string,
     *   evaluated_at: string
     * }
     */
    public function evaluate(array $pack): array
    {
        $passed = [];
        $failed = [];

        $packId = isset($pack['pack_id']) && is_string($pack['pack_id']) ? $pack['pack_id'] : null;
        if ($packId === null) {
            $failed['pack_id'] = 'required';
        }

        $auditTrail = $pack['audit_trail_contract'] ?? null;
        if (! is_string($auditTrail) || $auditTrail === '') {
            $failed['audit_trail_contract'] = 'finance pack must declare audit_trail_contract';
        } else {
            $passed[] = 'audit_trail_contract_declared';
        }

        $skills = (array) ($pack['skills'] ?? []);
        if ($skills === []) {
            $failed['skills'] = 'pack must declare >= 1 skill';
        }

        foreach ($skills as $i => $skill) {
            if (! is_array($skill)) {
                $failed["skills[{$i}]"] = 'must be array';

                continue;
            }
            $skillId = (string) ($skill['skill_id'] ?? '');
            if ($skillId === '') {
                $failed["skills[{$i}].skill_id"] = 'required';

                continue;
            }

            // Money-moving guardrails
            foreach (self::MONEY_MOVING_PREFIXES as $prefix) {
                if (str_starts_with($skillId, $prefix)) {
                    if (($skill['operator_authority_required'] ?? null) !== true) {
                        $failed["skills[{$i}].operator_authority_required"] = sprintf(
                            'money-moving skill "%s" requires operator_authority_required=true',
                            $skillId,
                        );
                    }
                    $authorisers = (array) ($skill['authorisers_required'] ?? []);
                    if (count($authorisers) < 2) {
                        $failed["skills[{$i}].authorisers_required"] = sprintf(
                            'money-moving skill "%s" requires >= 2 authorisers',
                            $skillId,
                        );
                    }
                    if (! isset($skill['value_cap_currency_units']) || ! is_int($skill['value_cap_currency_units']) || $skill['value_cap_currency_units'] <= 0) {
                        $failed["skills[{$i}].value_cap_currency_units"] = 'money-moving skill requires positive value_cap_currency_units';
                    }
                    if (! isset($skill['daily_aggregate_cap']) || ! is_int($skill['daily_aggregate_cap']) || $skill['daily_aggregate_cap'] <= 0) {
                        $failed["skills[{$i}].daily_aggregate_cap"] = 'money-moving skill requires daily_aggregate_cap';
                    }
                    break;
                }
            }

            // Tax / compliance guardrails
            foreach (self::TAX_COMPLIANCE_PREFIXES as $prefix) {
                if (str_starts_with($skillId, $prefix)) {
                    if (! isset($skill['jurisdiction']) || ! is_string($skill['jurisdiction']) || $skill['jurisdiction'] === '') {
                        $failed["skills[{$i}].jurisdiction"] = sprintf(
                            'tax/compliance skill "%s" requires jurisdiction declaration',
                            $skillId,
                        );
                    }
                    if (($skill['non_clinical_disclaimer_present'] ?? null) !== true) {
                        $failed["skills[{$i}].non_clinical_disclaimer_present"] = sprintf(
                            'tax/compliance skill "%s" requires explicit non_clinical_disclaimer_present=true',
                            $skillId,
                        );
                    }
                    break;
                }
            }
        }

        if (! array_filter(array_keys($failed), static fn ($k) => str_starts_with($k, 'skills['))) {
            $passed[] = 'all_skills_pass_finance_guardrails';
        }

        $decision = $failed === [] ? 'approved_for_finance' : 'blocked_by_finance_guardrail';

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'pack_id' => $packId,
            'gate_decision' => $decision,
            'passed_checks' => $passed,
            'failed_checks' => $failed,
            'detail' => $decision === 'approved_for_finance'
                ? sprintf('Finance pack "%s" passed all finance guardrails.', $packId ?? 'unknown')
                : sprintf('Blocked: %d finance guardrail check(s) failed.', count($failed)),
            'evaluated_at' => now()->toAtomString(),
        ];
    }
}
