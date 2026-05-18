<?php

namespace App\Services\Ai\Cyber;

use App\Models\AiCyberEngagement;
use App\Models\AiCyberScopeRules;
use Illuminate\Support\Str;

class CyberScopeRulesOfEngagementService
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    /**
     * @param  array<string,mixed>  $args
     */
    public function define(AiCyberEngagement $engagement, array $args): AiCyberScopeRules
    {
        $inScope = (array) ($args['in_scope_targets'] ?? []);
        if ($inScope === []) {
            throw CyberDomainException::missingField('scope_rules', 'in_scope_targets');
        }
        $outOfScope = (array) ($args['out_of_scope_targets'] ?? []);
        $allowed = (array) ($args['allowed_techniques'] ?? []);
        if ($allowed === []) {
            throw CyberDomainException::missingField('scope_rules', 'allowed_techniques');
        }
        $forbidden = (array) ($args['forbidden_techniques'] ?? []);
        if ($forbidden === []) {
            throw CyberDomainException::missingField('scope_rules', 'forbidden_techniques');
        }
        $escalation = (array) ($args['escalation_contacts'] ?? []);
        if ($escalation === []) {
            throw CyberDomainException::missingField('scope_rules', 'escalation_contacts');
        }

        $scopeId = (string) ($args['scope_id'] ?? 'roe-'.Str::random(8));

        $hashInput = [
            'engagement_id' => $engagement->id,
            'scope_id' => $scopeId,
            'in_scope_targets' => $inScope,
            'out_of_scope_targets' => $outOfScope,
            'allowed_techniques' => $allowed,
            'forbidden_techniques' => $forbidden,
            'rate_limits' => $args['rate_limits'] ?? null,
            'time_windows' => $args['time_windows'] ?? null,
            'legal_constraints' => $args['legal_constraints'] ?? null,
            'privacy_constraints' => $args['privacy_constraints'] ?? null,
            'escalation_contacts' => $escalation,
        ];

        return AiCyberScopeRules::query()->create([
            'uuid' => (string) Str::uuid(),
            'engagement_id' => $engagement->id,
            'scope_id' => $scopeId,
            'in_scope_targets' => $inScope,
            'out_of_scope_targets' => $outOfScope,
            'allowed_techniques' => $allowed,
            'forbidden_techniques' => $forbidden,
            'rate_limits' => $args['rate_limits'] ?? null,
            'time_windows' => $args['time_windows'] ?? null,
            'legal_constraints' => $args['legal_constraints'] ?? null,
            'privacy_constraints' => $args['privacy_constraints'] ?? null,
            'escalation_contacts' => $escalation,
            'status' => (string) ($args['status'] ?? self::STATUS_DRAFT),
            'rules_hash' => CyberCanonicalHash::sha256($hashInput),
        ]);
    }
}
