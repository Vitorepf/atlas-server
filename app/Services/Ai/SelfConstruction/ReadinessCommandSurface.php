<?php

namespace App\Services\Ai\SelfConstruction;

final class ReadinessCommandSurface
{
    public static function packetCommand(string $option, mixed $packetId): string
    {
        $command = 'php artisan atlas:ai:self-construction --'.$option;

        if (is_string($packetId) && $packetId !== '') {
            $command .= ' --packet='.$packetId;
        }

        return $command.' --json';
    }

    /**
     * @param  list<string>  $queueTags
     */
    public static function queueTagCommandArgs(array $queueTags): string
    {
        if ($queueTags === []) {
            return '';
        }

        return ' '.implode(' ', array_map(
            fn (string $tag): string => '--queue-tag='.self::safeCommandValue($tag),
            $queueTags,
        ));
    }

    public static function safeCommandValue(string $value): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_.:@\/-]/', '-', trim($value)) ?: '';
        $safe = trim($safe, '-');

        return $safe === '' ? 'queue' : $safe;
    }

    /**
     * @param  array{actor?: string|null}  $options
     */
    public static function reservationActor(array $options): string
    {
        $actor = trim((string) ($options['actor'] ?? 'codex'));

        return $actor === '' ? 'codex' : $actor;
    }

    /**
     * @param  array{session?: string|null}  $options
     */
    public static function reservationSession(array $options): string
    {
        $session = trim((string) ($options['session'] ?? 'local-session'));

        return $session === '' ? 'local-session' : $session;
    }

    /**
     * @return array{provider: string, role: string, receives: string, best_for: list<string>}
     */
    public static function providerRoleForActor(string $actor): array
    {
        $normalized = strtolower(trim($actor));

        if (str_starts_with($normalized, 'claude')) {
            return [
                'provider' => 'claude',
                'role' => 'planner_or_reviewer',
                'receives' => 'architecture_and_acceptance_packet',
                'best_for' => ['architecture_review', 'acceptance_criteria', 'critique', 'integration_review'],
            ];
        }

        if (str_starts_with($normalized, 'gemini')) {
            return [
                'provider' => 'gemini',
                'role' => 'scout_or_long_context_mapper',
                'receives' => 'source_map_and_research_packet',
                'best_for' => ['long_context_mapping', 'source_inventory', 'research_synthesis', 'cross_file_scan'],
            ];
        }

        if (str_starts_with($normalized, 'local')) {
            return [
                'provider' => 'local_runtime',
                'role' => 'deterministic_gate_runner',
                'receives' => 'commands_and_expected_outputs',
                'best_for' => ['test_execution', 'linting', 'docs_health', 'architecture_validation'],
            ];
        }

        return [
            'provider' => str_starts_with($normalized, 'codex') ? 'codex' : 'generic_ai_agent',
            'role' => 'implementation_worker',
            'receives' => 'packet_scope_bootstrap',
            'best_for' => ['scoped_implementation', 'tests', 'docs_updates', 'evidence_reporting'],
        ];
    }

    public static function recommendedProviderForLane(string $lane): string
    {
        return match ($lane) {
            'docs', 'packet_contracts' => 'claude',
            'research', 'source_mapping', 'long_context' => 'gemini',
            'gates', 'validation' => 'local',
            default => 'codex',
        };
    }
}
