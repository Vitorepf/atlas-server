<?php

namespace App\Services\Ai\Strategy;

use App\Models\AiVentureBlueprint;

/**
 * GTM Plan is captured inside `AiVentureBlueprint::gtm`. This service ensures
 * GTM payloads are well-formed (channels, ICP, positioning, pricing, motion)
 * before they are written to a blueprint. Used both as a validator (call
 * `validate()` directly) and as a builder (`attach()` updates a blueprint).
 */
class GTMPlanService
{
    public const MOTION_INBOUND = 'inbound';

    public const MOTION_OUTBOUND = 'outbound';

    public const MOTION_PRODUCT_LED = 'product_led';

    public const MOTION_SALES_LED = 'sales_led';

    public const MOTION_COMMUNITY = 'community';

    public const ALLOWED_MOTIONS = [
        self::MOTION_INBOUND,
        self::MOTION_OUTBOUND,
        self::MOTION_PRODUCT_LED,
        self::MOTION_SALES_LED,
        self::MOTION_COMMUNITY,
    ];

    /**
     * Validate a GTM payload. Returns the normalized payload on success.
     *
     * @param  array<string,mixed>  $gtm
     * @return array<string,mixed>
     */
    public function validate(array $gtm): array
    {
        foreach (['positioning', 'icp', 'channels', 'pricing', 'motion'] as $field) {
            if (! array_key_exists($field, $gtm) || $gtm[$field] === '' || $gtm[$field] === [] || $gtm[$field] === null) {
                throw StrategyDomainException::missingField('gtm_plan', $field);
            }
        }

        $motion = (string) $gtm['motion'];
        if (! in_array($motion, self::ALLOWED_MOTIONS, true)) {
            throw StrategyDomainException::invalidValue('gtm_plan', 'motion', 'must be one of ['.implode(',', self::ALLOWED_MOTIONS).']');
        }

        return [
            'positioning' => (string) $gtm['positioning'],
            'icp' => (string) $gtm['icp'],
            'channels' => (array) $gtm['channels'],
            'pricing' => (array) $gtm['pricing'],
            'motion' => $motion,
            'launch_plan' => $gtm['launch_plan'] ?? null,
            'success_metrics' => $gtm['success_metrics'] ?? null,
            'gtm_hash' => StrategyCanonicalHash::sha256([
                'positioning' => $gtm['positioning'],
                'icp' => $gtm['icp'],
                'channels' => (array) $gtm['channels'],
                'pricing' => (array) $gtm['pricing'],
                'motion' => $motion,
            ]),
        ];
    }

    /**
     * Attach a validated GTM payload to an existing venture blueprint.
     *
     * @param  array<string,mixed>  $gtm
     */
    public function attach(AiVentureBlueprint $blueprint, array $gtm): AiVentureBlueprint
    {
        $validated = $this->validate($gtm);
        $blueprint->gtm = $validated;
        $blueprint->save();

        return $blueprint;
    }
}
