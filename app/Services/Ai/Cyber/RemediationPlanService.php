<?php

namespace App\Services\Ai\Cyber;

use App\Models\AiRemediationPlan;
use Illuminate\Support\Str;

class RemediationPlanService
{
    public const SEVERITY_LOW = 'low';

    public const SEVERITY_MEDIUM = 'medium';

    public const SEVERITY_HIGH = 'high';

    public const SEVERITY_CRITICAL = 'critical';

    public const ALLOWED_SEVERITIES = [
        self::SEVERITY_LOW,
        self::SEVERITY_MEDIUM,
        self::SEVERITY_HIGH,
        self::SEVERITY_CRITICAL,
    ];

    public const STATUS_PROPOSED = 'proposed';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_REJECTED = 'rejected';

    /**
     * @param  array<string,mixed>  $args
     */
    public function propose(array $args): AiRemediationPlan
    {
        $title = (string) ($args['title'] ?? '');
        if ($title === '') {
            throw CyberDomainException::missingField('remediation_plan', 'title');
        }
        $severity = (string) ($args['severity'] ?? self::SEVERITY_MEDIUM);
        if (! in_array($severity, self::ALLOWED_SEVERITIES, true)) {
            throw CyberDomainException::invalidValue('remediation_plan', 'severity',
                'must be one of ['.implode(',', self::ALLOWED_SEVERITIES).']');
        }
        $findingsRefs = (array) ($args['findings_refs'] ?? []);
        if ($findingsRefs === []) {
            throw CyberDomainException::missingField('remediation_plan', 'findings_refs');
        }
        $actions = (array) ($args['actions'] ?? []);
        if ($actions === []) {
            throw CyberDomainException::missingField('remediation_plan', 'actions');
        }
        $owners = (array) ($args['owners'] ?? []);
        if ($owners === []) {
            throw CyberDomainException::missingField('remediation_plan', 'owners');
        }
        $timeline = (array) ($args['timeline'] ?? []);
        if ($timeline === []) {
            throw CyberDomainException::missingField('remediation_plan', 'timeline');
        }

        $planId = (string) ($args['plan_id'] ?? Str::slug($title));

        $hashInput = [
            'plan_id' => $planId,
            'engagement_id' => $args['engagement_id'] ?? null,
            'appsec_review_id' => $args['appsec_review_id'] ?? null,
            'title' => $title,
            'severity' => $severity,
            'findings_refs' => $findingsRefs,
            'actions' => $actions,
            'owners' => $owners,
            'timeline' => $timeline,
            'rollback_plan' => $args['rollback_plan'] ?? null,
        ];

        return AiRemediationPlan::query()->create([
            'uuid' => (string) Str::uuid(),
            'engagement_id' => $args['engagement_id'] ?? null,
            'appsec_review_id' => $args['appsec_review_id'] ?? null,
            'plan_id' => $planId,
            'title' => $title,
            'severity' => $severity,
            'findings_refs' => $findingsRefs,
            'actions' => $actions,
            'owners' => $owners,
            'timeline' => $timeline,
            'rollback_plan' => $args['rollback_plan'] ?? null,
            'status' => (string) ($args['status'] ?? self::STATUS_PROPOSED),
            'plan_hash' => CyberCanonicalHash::sha256($hashInput),
        ]);
    }
}
