<?php

namespace App\Services\Ai\Cyber;

use App\Models\AiAppSecReview;
use Illuminate\Support\Str;

class AppSecReviewService
{
    public const TARGET_REPO = 'repo';

    public const TARGET_SERVICE = 'service';

    public const TARGET_API = 'api';

    public const TARGET_FRONTEND = 'frontend';

    public const TARGET_INFRA = 'infra';

    public const ALLOWED_TARGETS = [
        self::TARGET_REPO,
        self::TARGET_SERVICE,
        self::TARGET_API,
        self::TARGET_FRONTEND,
        self::TARGET_INFRA,
    ];

    public const STATUS_OPEN = 'open';

    public const STATUS_REMEDIATED = 'remediated';

    public const STATUS_RISK_ACCEPTED = 'risk_accepted';

    /**
     * Defensive AppSec review. Catalogs findings against OWASP categories
     * without executing scans or exploits.
     *
     * @param  array<string,mixed>  $args
     */
    public function review(array $args): AiAppSecReview
    {
        $title = (string) ($args['title'] ?? '');
        if ($title === '') {
            throw CyberDomainException::missingField('appsec_review', 'title');
        }
        $targetKind = (string) ($args['target_kind'] ?? '');
        if (! in_array($targetKind, self::ALLOWED_TARGETS, true)) {
            throw CyberDomainException::invalidValue('appsec_review', 'target_kind',
                'must be one of ['.implode(',', self::ALLOWED_TARGETS).']');
        }
        $targetRef = (string) ($args['target_ref'] ?? '');
        if ($targetRef === '') {
            throw CyberDomainException::missingField('appsec_review', 'target_ref');
        }
        $owasp = (array) ($args['owasp_categories'] ?? []);
        if ($owasp === []) {
            throw CyberDomainException::missingField('appsec_review', 'owasp_categories');
        }
        $findings = (array) ($args['findings'] ?? []);
        $recommendations = (array) ($args['recommendations'] ?? []);
        if ($recommendations === []) {
            throw CyberDomainException::missingField('appsec_review', 'recommendations');
        }

        $reviewId = (string) ($args['review_id'] ?? Str::slug($title));

        $riskScore = $args['risk_score'] ?? null;
        if ($riskScore !== null) {
            $riskScore = (float) $riskScore;
            if ($riskScore < 0.0 || $riskScore > 10.0) {
                throw CyberDomainException::invalidValue('appsec_review', 'risk_score',
                    'must be between 0 and 10 (CVSS-style).');
            }
        }

        $hashInput = [
            'review_id' => $reviewId,
            'engagement_id' => $args['engagement_id'] ?? null,
            'title' => $title,
            'target_kind' => $targetKind,
            'target_ref' => $targetRef,
            'owasp_categories' => $owasp,
            'findings' => $findings,
            'recommendations' => $recommendations,
            'risk_score' => $riskScore,
        ];

        return AiAppSecReview::query()->create([
            'uuid' => (string) Str::uuid(),
            'engagement_id' => $args['engagement_id'] ?? null,
            'review_id' => $reviewId,
            'title' => $title,
            'target_kind' => $targetKind,
            'target_ref' => $targetRef,
            'owasp_categories' => $owasp,
            'findings' => $findings,
            'recommendations' => $recommendations,
            'artifact_refs' => $args['artifact_refs'] ?? null,
            'risk_score' => $riskScore,
            'status' => (string) ($args['status'] ?? self::STATUS_OPEN),
            'review_hash' => CyberCanonicalHash::sha256($hashInput),
        ]);
    }
}
