<?php

namespace App\Services\Ai\MarketingDomain;

final class MarketingDomainCanon
{
    public const DOMAIN_ID = 'marketing';

    // Run statuses
    public const STATUS_PLANNED = 'planned';

    public const STATUS_DRAFTING = 'drafting';

    public const STATUS_CERTIFYING = 'certifying';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const RUN_STATUSES = [
        self::STATUS_PLANNED,
        self::STATUS_DRAFTING,
        self::STATUS_CERTIFYING,
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
    ];

    // Artifact types
    public const ARTIFACT_ICP = 'icp';

    public const ARTIFACT_POSITIONING = 'positioning';

    public const ARTIFACT_CAMPAIGN = 'campaign';

    public const ARTIFACT_COPY = 'copy';

    public const ARTIFACT_CREATIVE = 'creative';

    public const ARTIFACT_FUNNEL = 'funnel';

    public const ARTIFACT_ANALYTICS_PLAN = 'analytics_plan';

    public const ARTIFACT_TYPES = [
        self::ARTIFACT_ICP,
        self::ARTIFACT_POSITIONING,
        self::ARTIFACT_CAMPAIGN,
        self::ARTIFACT_COPY,
        self::ARTIFACT_CREATIVE,
        self::ARTIFACT_FUNNEL,
        self::ARTIFACT_ANALYTICS_PLAN,
    ];

    // Artifact status
    public const ARTIFACT_DRAFT = 'draft';

    public const ARTIFACT_READY_FOR_REVIEW = 'ready_for_review';

    public const ARTIFACT_APPROVED = 'approved';

    public const ARTIFACT_REJECTED = 'rejected';

    public const ARTIFACT_STATUSES = [
        self::ARTIFACT_DRAFT,
        self::ARTIFACT_READY_FOR_REVIEW,
        self::ARTIFACT_APPROVED,
        self::ARTIFACT_REJECTED,
    ];

    // Experiment status
    public const EXPERIMENT_PROPOSED = 'proposed';

    public const EXPERIMENT_READY = 'ready';

    public const EXPERIMENT_BLOCKED = 'blocked';

    public const EXPERIMENT_STATUSES = [
        self::EXPERIMENT_PROPOSED,
        self::EXPERIMENT_READY,
        self::EXPERIMENT_BLOCKED,
    ];

    // Approval gate types
    public const GATE_PUBLISH = 'publish';

    public const GATE_PAID_MEDIA = 'paid_media';

    public const GATE_SEND_EMAIL = 'send_email';

    public const GATE_LAUNCH_EXPERIMENT = 'launch_experiment';

    public const GATE_TYPES = [
        self::GATE_PUBLISH,
        self::GATE_PAID_MEDIA,
        self::GATE_SEND_EMAIL,
        self::GATE_LAUNCH_EXPERIMENT,
    ];

    // Gate status
    public const GATE_PENDING = 'pending';

    public const GATE_APPROVED = 'approved';

    public const GATE_REJECTED = 'rejected';

    public const GATE_EXPIRED = 'expired';

    public const GATE_STATUSES = [
        self::GATE_PENDING,
        self::GATE_APPROVED,
        self::GATE_REJECTED,
        self::GATE_EXPIRED,
    ];

    // Certification status
    public const CERT_PASSED = 'passed';

    public const CERT_FAILED = 'failed';

    /**
     * Minimum artifacts required for certification to pass. Must include
     * ICP, positioning, campaign, copy and funnel as core deliverables.
     */
    public const REQUIRED_ARTIFACT_TYPES = [
        self::ARTIFACT_ICP,
        self::ARTIFACT_POSITIONING,
        self::ARTIFACT_CAMPAIGN,
        self::ARTIFACT_COPY,
        self::ARTIFACT_FUNNEL,
    ];

    /**
     * Hard-forbidden actions enforced regardless of approval state.
     */
    public const FORBIDDEN_ACTIONS = [
        'publish_without_approval',
        'paid_media_spend_without_approval',
        'send_email_without_approval',
        'launch_experiment_without_approval',
    ];
}
