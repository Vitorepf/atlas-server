<?php

declare(strict_types=1);

namespace App\Services\Ai\AcosMax;

use App\Services\Ai\Support\AiValueNormalizer;

final class DogfoodingFrictionLeadMiner
{
    public const SCHEMA_VERSION = 'atlas.originator.dogfooding_friction_leads.v1';

    public const MIN_OCCURRENCES = 3;

    public const STATUS_OK = 'ok';

    public const STATUS_INSUFFICIENT_SIGNAL = 'insufficient_signal';
    public const FIELD_SCHEMA_VERSION = 'schema_version';
    public const FIELD_CLASS = 'class';
    public const FIELD_SIGNATURE = 'signature';
    public const FIELD_OCCURRENCES = 'occurrences';
    public const FIELD_TARGET = 'target';
    public const FIELD_OBJECTIVE = 'objective';
    public const FIELD_EVIDENCE_REFS = 'evidence_refs';
    public const FIELD_SOURCE = 'source';
    public const FIELD_LEAD_ONLY_NOT_SEED = 'lead_only_not_seed';
    public const FIELD_LEADS = 'leads';
    public const FIELD_OPERATOR_TEXT_IN_OBJECTIVE = 'operator_text_in_objective';
    public const FIELD_PROVIDER_CALLS_MADE = 'provider_calls_made';
    public const FIELD_STATUS = 'status';
    public const FIELD_KIND = 'kind';
    public const FIELD_DOGFOODING = 'dogfooding';
    public const FIELD_UNKNOWN_TARGET = 'unknown_target';

    /**
     * @param  list<array<string,mixed>>  $events
     * @return array<string,mixed>
     */
    public static function mine(array $events): array
    {
        $groups = [];
        foreach ($events as $event) {
            $signature = AiValueNormalizer::trimmedStringOrNull($event[self::FIELD_SIGNATURE] ?? null);
            if ($signature === null) {
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
                self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
                self::FIELD_CLASS => self::FIELD_DOGFOODING,
                self::FIELD_SIGNATURE => $signature,
                self::FIELD_OCCURRENCES => count($group),
                self::FIELD_TARGET => $target,
                self::FIELD_OBJECTIVE => 'Improve recurring Atlas operator friction around '.$target,
                self::FIELD_EVIDENCE_REFS => array_values(array_map(
                    static fn (array $event): string => 'friction:'.sha1(json_encode([
                        $event[self::FIELD_SIGNATURE] ?? '',
                        $event[self::FIELD_KIND] ?? '',
                        $event[self::FIELD_TARGET] ?? '',
                    ], JSON_UNESCAPED_SLASHES)),
                    $group,
                )),
                self::FIELD_SOURCE => [
                    self::FIELD_OPERATOR_TEXT_IN_OBJECTIVE => false,
                    self::FIELD_LEAD_ONLY_NOT_SEED => true,
                    self::FIELD_PROVIDER_CALLS_MADE => false,
                ],
            ];
        }

        return [
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_STATUS => $leads === [] ? self::STATUS_INSUFFICIENT_SIGNAL : self::STATUS_OK,
            self::FIELD_LEADS => $leads,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $group
     */
    private static function target(array $group): string
    {
        foreach ($group as $event) {
            $target = AiValueNormalizer::trimmedStringOrNull($event[self::FIELD_TARGET] ?? null);
            if ($target !== null) {
                return $target;
            }
        }

        return self::FIELD_UNKNOWN_TARGET;
    }
}
