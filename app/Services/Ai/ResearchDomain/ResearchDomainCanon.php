<?php

namespace App\Services\Ai\ResearchDomain;

/**
 * Canonical enums for the Research Company Runtime (Meta 8A).
 */
final class ResearchDomainCanon
{
    public const DOMAIN_ID = 'research';

    // Run status
    public const STATUS_PLANNED = 'planned';

    public const STATUS_COLLECTING = 'collecting';

    public const STATUS_SYNTHESIZING = 'synthesizing';

    public const STATUS_CERTIFYING = 'certifying';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const RUN_STATUSES = [
        self::STATUS_PLANNED,
        self::STATUS_COLLECTING,
        self::STATUS_SYNTHESIZING,
        self::STATUS_CERTIFYING,
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
    ];

    // Source types (used to enforce diversity)
    public const SOURCE_PRIMARY = 'primary';

    public const SOURCE_ACADEMIC = 'academic';

    public const SOURCE_STANDARD = 'standard';

    public const SOURCE_INDUSTRY = 'industry';

    public const SOURCE_NEWS = 'news';

    public const SOURCE_BLOG = 'blog';

    public const SOURCE_FORUM = 'forum';

    public const SOURCE_DOCS = 'docs';

    public const SOURCE_TYPES = [
        self::SOURCE_PRIMARY,
        self::SOURCE_ACADEMIC,
        self::SOURCE_STANDARD,
        self::SOURCE_INDUSTRY,
        self::SOURCE_NEWS,
        self::SOURCE_BLOG,
        self::SOURCE_FORUM,
        self::SOURCE_DOCS,
    ];

    // Source status: accepted means used to back claims; rejected means cited
    // by source_plan but excluded by quality / safety reasons. planned means
    // proposed but not yet evaluated.
    public const SOURCE_STATUS_PLANNED = 'planned';

    public const SOURCE_STATUS_ACCEPTED = 'accepted';

    public const SOURCE_STATUS_REJECTED = 'rejected';

    public const SOURCE_STATUSES = [
        self::SOURCE_STATUS_PLANNED,
        self::SOURCE_STATUS_ACCEPTED,
        self::SOURCE_STATUS_REJECTED,
    ];

    // Claim status
    public const CLAIM_PROPOSED = 'proposed';

    public const CLAIM_SUPPORTED = 'supported';

    public const CLAIM_CONTRADICTED = 'contradicted';

    public const CLAIM_INSUFFICIENT = 'insufficient_evidence';

    public const CLAIM_STATUSES = [
        self::CLAIM_PROPOSED,
        self::CLAIM_SUPPORTED,
        self::CLAIM_CONTRADICTED,
        self::CLAIM_INSUFFICIENT,
    ];

    // Contradiction status
    public const CONTRADICTION_NONE = 'none';

    public const CONTRADICTION_DETECTED = 'detected';

    public const CONTRADICTION_RESOLVED = 'resolved';

    public const CONTRADICTION_DECLARED = 'declared';

    public const CONTRADICTION_STATUSES = [
        self::CONTRADICTION_NONE,
        self::CONTRADICTION_DETECTED,
        self::CONTRADICTION_RESOLVED,
        self::CONTRADICTION_DECLARED,
    ];

    // Certification status
    public const CERT_PASSED = 'passed';

    public const CERT_FAILED = 'failed';

    public const CERT_BLOCKED = 'blocked';

    /**
     * High-quality source types weighted up by the quality scorer.
     */
    public const QUALITY_PRIMARY_TYPES = [
        self::SOURCE_PRIMARY,
        self::SOURCE_ACADEMIC,
        self::SOURCE_STANDARD,
    ];

    /**
     * Source types that, alone, are not enough to support a claim — they
     * need triangulation with another type.
     */
    public const LOW_TRIANGULATION_TYPES = [
        self::SOURCE_BLOG,
        self::SOURCE_FORUM,
        self::SOURCE_NEWS,
    ];

    /**
     * Minimum diversity required to pass certification (number of distinct
     * source_types among accepted sources).
     */
    public const MIN_SOURCE_DIVERSITY = 2;

    /**
     * Minimum number of accepted sources to pass certification.
     */
    public const MIN_ACCEPTED_SOURCES = 2;

    /**
     * Minimum number of claims to pass certification.
     */
    public const MIN_CLAIMS = 1;
}
