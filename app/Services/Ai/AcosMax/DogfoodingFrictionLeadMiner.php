<?php

declare(strict_types=1);

namespace App\Services\Ai\AcosMax;

use App\Services\Ai\Support\AiValueNormalizer;

final class DogfoodingFrictionLeadMiner
{
    public const SCHEMA_VERSION = 'atlas.originator.dogfooding_friction_leads.v1';

    public const MIN_OCCURRENCES = 3;

    /**
     * @param  list<array<string,mixed>>  $events
     * @return array<string,mixed>
     */
    public static function mine(array $events): array
    {
        $groups = [];
        foreach ($events as $event) {
            $signature = AiValueNormalizer::trimmedString($event['signature'] ?? '');
            if ($signature === '') {
                continue;
            }
            $groups[$signature][] = $event;
        }

        $leads = [];
        foreach ($groups as $signature => $group) {
            if (count($group) < self::MIN_OCCURRENCES) {
                continue;
            }
            $target = self::target($group);
            $leads[] = [
                'schema_version' => self::SCHEMA_VERSION,
                'class' => 'dogfooding',
                'signature' => $signature,
                'occurrences' => count($group),
                'target' => $target,
                'objective' => 'Improve recurring Atlas operator friction around '.$target,
                'evidence_refs' => array_values(array_map(
                    static fn (array $event): string => 'friction:'.sha1(json_encode([
                        $event['signature'] ?? '',
                        $event['kind'] ?? '',
                        $event['target'] ?? '',
                    ], JSON_UNESCAPED_SLASHES)),
                    $group,
                )),
                'source' => [
                    'operator_text_in_objective' => false,
                    'lead_only_not_seed' => true,
                    'provider_calls_made' => false,
                ],
            ];
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $leads === [] ? 'insufficient_signal' : 'ok',
            'leads' => $leads,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $group
     */
    private static function target(array $group): string
    {
        foreach ($group as $event) {
            $target = AiValueNormalizer::trimmedString($event['target'] ?? '');
            if ($target !== '') {
                return $target;
            }
        }

        return 'unknown_target';
    }
}
