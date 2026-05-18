<?php

namespace App\Services\Ai\Cyber;

use App\Models\AiCyberEngagement;
use Illuminate\Support\Str;

class CyberEngagementIntakeService
{
    public const KIND_APPSEC_REVIEW = 'appsec_review';

    public const KIND_GRC_AUDIT = 'grc_audit';

    public const KIND_DEFENSIVE_REVIEW = 'defensive_review';

    public const KIND_AUTHORIZED_BUG_BOUNTY = 'authorized_bug_bounty';

    public const KIND_INCIDENT_REVIEW = 'incident_review';

    public const ALLOWED_KINDS = [
        self::KIND_APPSEC_REVIEW,
        self::KIND_GRC_AUDIT,
        self::KIND_DEFENSIVE_REVIEW,
        self::KIND_AUTHORIZED_BUG_BOUNTY,
        self::KIND_INCIDENT_REVIEW,
    ];

    public const STATUS_INTAKE = 'intake';

    public const STATUS_AUTHORIZED = 'authorized';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_CLOSED = 'closed';

    /**
     * Persist a new cyber engagement. Authorization is REQUIRED for
     * authorized_bug_bounty; for defensive/appsec/GRC/incident review the
     * authorization flag may be marked false but engagement starts as `intake`
     * with a blocker until legal/privacy/scope evidence is attached.
     *
     * @param  array<string,mixed>  $args
     */
    public function intake(array $args): AiCyberEngagement
    {
        $title = (string) ($args['title'] ?? '');
        if ($title === '') {
            throw CyberDomainException::missingField('engagement', 'title');
        }
        $kind = (string) ($args['engagement_kind'] ?? '');
        if (! in_array($kind, self::ALLOWED_KINDS, true)) {
            throw CyberDomainException::invalidValue(
                'engagement', 'engagement_kind',
                'must be one of ['.implode(',', self::ALLOWED_KINDS).']',
            );
        }
        $requester = (string) ($args['requester'] ?? '');
        if ($requester === '') {
            throw CyberDomainException::missingField('engagement', 'requester');
        }
        $targets = (array) ($args['targets'] ?? []);
        if ($targets === []) {
            throw CyberDomainException::missingField('engagement', 'targets');
        }

        $authorizationPresent = (bool) ($args['authorization_present'] ?? false);
        $authorization = $args['authorization'] ?? null;

        if ($kind === self::KIND_AUTHORIZED_BUG_BOUNTY && ! $authorizationPresent) {
            throw CyberDomainException::unauthorized(
                'engagement',
                'authorized_bug_bounty requires authorization_present=true with documented authorization payload.',
            );
        }
        if ($authorizationPresent && ! is_array($authorization)) {
            throw CyberDomainException::invalidValue(
                'engagement', 'authorization',
                'authorization payload (doc/url/contacts) is required when authorization_present=true.',
            );
        }

        $blockers = (array) ($args['blockers'] ?? []);
        $initialStatus = $authorizationPresent ? self::STATUS_AUTHORIZED : self::STATUS_INTAKE;
        if (! $authorizationPresent && $kind !== self::KIND_AUTHORIZED_BUG_BOUNTY) {
            $blockers[] = [
                'kind' => 'authorization_pending',
                'detail' => 'Defensive review can proceed under intake status; attach legal/privacy gate before delivery.',
            ];
        }

        $engagementId = (string) ($args['engagement_id'] ?? Str::slug($title));

        $hashInput = [
            'engagement_id' => $engagementId,
            'engagement_kind' => $kind,
            'title' => $title,
            'requester' => $requester,
            'targets' => $targets,
            'authorization_present' => $authorizationPresent,
            'authorization' => $authorization,
        ];

        return AiCyberEngagement::query()->create([
            'uuid' => (string) Str::uuid(),
            'mission_id' => $args['mission_id'] ?? null,
            'work_order_id' => $args['work_order_id'] ?? null,
            'engagement_id' => $engagementId,
            'engagement_kind' => $kind,
            'title' => $title,
            'summary' => $args['summary'] ?? null,
            'requester' => $requester,
            'targets' => $targets,
            'authorization_present' => $authorizationPresent,
            'authorization' => $authorization,
            'legal_review' => $args['legal_review'] ?? null,
            'privacy_review' => $args['privacy_review'] ?? null,
            'status' => $initialStatus,
            'blockers' => $blockers !== [] ? $blockers : null,
            'next_action' => $args['next_action'] ?? 'define_scope_and_roe',
            'engagement_hash' => CyberCanonicalHash::sha256($hashInput),
        ]);
    }
}
