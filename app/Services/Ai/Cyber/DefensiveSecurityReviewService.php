<?php

namespace App\Services\Ai\Cyber;

use App\Models\AiDefensiveSecurityReview;
use Illuminate\Support\Str;

class DefensiveSecurityReviewService
{
    public const KIND_THREAT_MODEL = 'threat_model';

    public const KIND_NETWORK_REVIEW = 'network_review';

    public const KIND_IDENTITY_REVIEW = 'identity_review';

    public const KIND_INCIDENT_REVIEW = 'incident_review';

    public const KIND_DETECTION_REVIEW = 'detection_review';

    public const KIND_DATA_PROTECTION = 'data_protection_review';

    public const ALLOWED_KINDS = [
        self::KIND_THREAT_MODEL,
        self::KIND_NETWORK_REVIEW,
        self::KIND_IDENTITY_REVIEW,
        self::KIND_INCIDENT_REVIEW,
        self::KIND_DETECTION_REVIEW,
        self::KIND_DATA_PROTECTION,
    ];

    public const STATUS_OPEN = 'open';

    public const STATUS_RECOMMENDATIONS_ISSUED = 'recommendations_issued';

    public const STATUS_REMEDIATED = 'remediated';

    /**
     * @param  array<string,mixed>  $args
     */
    public function review(array $args): AiDefensiveSecurityReview
    {
        $title = (string) ($args['title'] ?? '');
        if ($title === '') {
            throw CyberDomainException::missingField('defensive_review', 'title');
        }
        $kind = (string) ($args['review_kind'] ?? '');
        if (! in_array($kind, self::ALLOWED_KINDS, true)) {
            throw CyberDomainException::invalidValue('defensive_review', 'review_kind',
                'must be one of ['.implode(',', self::ALLOWED_KINDS).']');
        }
        $scope = (array) ($args['scope'] ?? []);
        if ($scope === []) {
            throw CyberDomainException::missingField('defensive_review', 'scope');
        }
        $controlsInspected = (array) ($args['controls_inspected'] ?? []);
        if ($controlsInspected === []) {
            throw CyberDomainException::missingField('defensive_review', 'controls_inspected');
        }
        $recommendations = (array) ($args['recommendations'] ?? []);
        if ($recommendations === []) {
            throw CyberDomainException::missingField('defensive_review', 'recommendations');
        }

        $reviewId = (string) ($args['review_id'] ?? Str::slug($title));

        $hashInput = [
            'review_id' => $reviewId,
            'engagement_id' => $args['engagement_id'] ?? null,
            'review_kind' => $kind,
            'title' => $title,
            'scope' => $scope,
            'controls_inspected' => $controlsInspected,
            'findings' => (array) ($args['findings'] ?? []),
            'recommendations' => $recommendations,
        ];

        return AiDefensiveSecurityReview::query()->create([
            'uuid' => (string) Str::uuid(),
            'engagement_id' => $args['engagement_id'] ?? null,
            'review_id' => $reviewId,
            'review_kind' => $kind,
            'title' => $title,
            'scope' => $scope,
            'controls_inspected' => $controlsInspected,
            'findings' => (array) ($args['findings'] ?? []),
            'recommendations' => $recommendations,
            'artifact_refs' => $args['artifact_refs'] ?? null,
            'status' => (string) ($args['status'] ?? self::STATUS_OPEN),
            'review_hash' => CyberCanonicalHash::sha256($hashInput),
        ]);
    }
}
