<?php

namespace App\Services\Ai\Programming\Forge\Intelligence;

use App\Models\AiForgeIntake;
use App\Models\AiForgeWorkPacket;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Support\AiStringListNormalizer;

final class ForgeTestImpactRuntimeService
{
    public const SCHEMA_VERSION = 'atlas.forge.test_impact_runtime.v1';

    /**
     * @param  array<string,mixed>  $intelligence
     * @return array<string,mixed>
     */
    public function select(AiForgeWorkPacket $packet, ?AiForgeIntake $intake, array $intelligence): array
    {
        $commands = [];
        foreach (AiStringListNormalizer::trimmedStrings($packet->suggested_tests ?? []) as $test) {
            $commands[] = [
                'command' => str_starts_with($test, 'php ') || str_contains($test, ' artisan ')
                    ? $test
                    : 'php artisan test '.$test,
                'reason' => 'packet_suggested_test',
                'confidence' => 'high',
            ];
        }

        foreach (AiStringListNormalizer::trimmedStrings($packet->expected_files ?? []) as $file) {
            if (str_starts_with($file, 'tests/')) {
                $commands[] = [
                    'command' => 'php artisan test '.$file,
                    'reason' => 'expected_file_is_test',
                    'confidence' => 'high',
                ];
            } elseif (str_starts_with($file, 'app/Services/Ai/Programming/Forge')) {
                $commands[] = [
                    'command' => 'php artisan test tests/Feature/Ai/Programming/Forge',
                    'reason' => 'forge_service_touched',
                    'confidence' => 'medium',
                ];
            } elseif (str_starts_with($file, 'app/Services/Ai/Programming')) {
                $commands[] = [
                    'command' => 'php artisan test tests/Feature/Ai/Programming',
                    'reason' => 'programming_service_touched',
                    'confidence' => 'medium',
                ];
            }
        }

        if ($commands === []) {
            $commands[] = [
                'command' => 'php artisan test tests/Feature/Ai/Programming/Forge',
                'reason' => 'forge_packet_default_safety_net',
                'confidence' => 'medium',
            ];
        }

        $unique = [];
        foreach ($commands as $command) {
            $unique[$command['command']] = $command;
        }

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'packet_id' => (string) $packet->packet_id,
            'milestone' => 'implementation',
            'commands' => array_values($unique),
            'coverage_reason' => 'packet_scope_plus_forge_module_safety_net',
            'requires_operator_for_full_suite' => true,
        ];
        $payload['test_selection_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

}
