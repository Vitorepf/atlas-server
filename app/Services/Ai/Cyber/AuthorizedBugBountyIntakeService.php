<?php

namespace App\Services\Ai\Cyber;

use App\Models\AiBugBountyIntake;
use App\Models\AiCyberEngagement;
use Illuminate\Support\Str;

/**
 * Bug bounty / pentest INTAKE only. This service NEVER executes exploits or
 * scans. It documents the authorization, scope, RoE, legal and privacy gates
 * that must pass before any external offensive operation could be considered.
 *
 * Status starts as `blocked` and only transitions to `authorized` (handoff
 * planning) when all gates are explicitly true.
 */
class AuthorizedBugBountyIntakeService
{
    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_AUTHORIZED = 'authorized';

    public const STATUS_HANDOFF_PLANNED = 'handoff_planned';

    public const STATUS_REJECTED = 'rejected';

    /**
     * @param  array<string,mixed>  $args
     */
    public function intake(AiCyberEngagement $engagement, array $args): AiBugBountyIntake
    {
        if (! $engagement->authorization_present) {
            throw CyberDomainException::unauthorized(
                'bug_bounty_intake',
                'engagement.authorization_present must be true with documented authorization payload before intake.',
            );
        }

        $program = (string) ($args['program'] ?? '');
        if ($program === '') {
            throw CyberDomainException::missingField('bug_bounty_intake', 'program');
        }
        $authPresent = (bool) ($args['authorization_present'] ?? false);
        $authDoc = $args['authorization_doc'] ?? null;
        if (! $authPresent || ! is_array($authDoc) || $authDoc === []) {
            throw CyberDomainException::unauthorized(
                'bug_bounty_intake',
                'authorization_present=true AND authorization_doc payload (program url, contacts, dates) are mandatory.',
            );
        }

        $scopeParsed = (bool) ($args['scope_parsed'] ?? false);
        $roeDocumented = (bool) ($args['roe_documented'] ?? false);
        $legalGate = (bool) ($args['legal_gate_passed'] ?? false);
        $privacyGate = (bool) ($args['privacy_gate_passed'] ?? false);

        $blockers = [];
        if (! $scopeParsed) {
            $blockers[] = ['kind' => 'scope_not_parsed', 'detail' => 'parse program scope before intake.'];
        }
        if (! $roeDocumented) {
            $blockers[] = ['kind' => 'roe_not_documented', 'detail' => 'document rules of engagement.'];
        }
        if (! $legalGate) {
            $blockers[] = ['kind' => 'legal_gate_pending', 'detail' => 'legal review required before intake.'];
        }
        if (! $privacyGate) {
            $blockers[] = ['kind' => 'privacy_gate_pending', 'detail' => 'privacy review required before intake.'];
        }

        $allGreen = $scopeParsed && $roeDocumented && $legalGate && $privacyGate;
        $status = $allGreen ? self::STATUS_AUTHORIZED : self::STATUS_BLOCKED;

        $intakeId = (string) ($args['intake_id'] ?? 'bbi-'.Str::random(8));

        $hashInput = [
            'engagement_id' => $engagement->id,
            'intake_id' => $intakeId,
            'program' => $program,
            'program_url' => $args['program_url'] ?? null,
            'authorization_doc' => $authDoc,
            'scope_parsed' => $scopeParsed,
            'roe_documented' => $roeDocumented,
            'legal_gate_passed' => $legalGate,
            'privacy_gate_passed' => $privacyGate,
        ];

        return AiBugBountyIntake::query()->create([
            'uuid' => (string) Str::uuid(),
            'engagement_id' => $engagement->id,
            'intake_id' => $intakeId,
            'program' => $program,
            'program_url' => $args['program_url'] ?? null,
            'authorization_present' => true,
            'authorization_doc' => $authDoc,
            'scope_parsed' => $scopeParsed,
            'roe_documented' => $roeDocumented,
            'legal_gate_passed' => $legalGate,
            'privacy_gate_passed' => $privacyGate,
            'handoff_plan' => $args['handoff_plan'] ?? null,
            'status' => $status,
            'blockers' => $blockers !== [] ? $blockers : null,
            'intake_hash' => CyberCanonicalHash::sha256($hashInput),
        ]);
    }
}
