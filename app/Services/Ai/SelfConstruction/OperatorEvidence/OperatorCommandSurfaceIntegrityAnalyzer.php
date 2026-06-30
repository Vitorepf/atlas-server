<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\OperatorEvidence;

use Illuminate\Support\Facades\Artisan;

/**
 * Analyzes operator command surface integrity for the Atlas Self-Construction
 * operator evidence submission readiness service.
 *
 * Extracted from AtlasSelfConstructionOperatorEvidenceSubmissionReadinessService
 * to reduce the god-class. All methods are stateless.
 */
final class OperatorCommandSurfaceIntegrityAnalyzer
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function integrity(array $payload): array
    {
        $commands = self::collectOperatorCommands($payload);
        $knownOptions = self::selfConstructionCommandOptions();
        $knownOptionNames = array_fill_keys($knownOptions, true);
        $legacyAliases = self::legacySelfConstructionCommandAliases();
        $rows = [];
        $missing = [];
        $legacyAliasHits = [];

        foreach ($commands as $path => $command) {
            $options = self::extractCommandOptions($command);
            $missingOptions = array_values(array_filter(
                $options,
                static fn (string $option): bool => ! isset($knownOptionNames[$option]),
            ));
            $legacyAliasesDetected = array_values(array_filter(
                $options,
                static fn (string $option): bool => in_array($option, $legacyAliases, true),
            ));
            foreach ($missingOptions as $option) {
                $missing[] = [
                    'payload_path' => $path,
                    'option' => $option,
                    'command' => $command,
                ];
            }
            foreach ($legacyAliasesDetected as $option) {
                $legacyAliasHits[] = [
                    'payload_path' => $path,
                    'option' => $option,
                    'command' => $command,
                ];
            }
            $rows[] = [
                'payload_path' => $path,
                'command' => $command,
                'option_count' => count($options),
                'options' => $options,
                'missing_option_count' => count($missingOptions),
                'missing_options' => $missingOptions,
                'legacy_alias_count' => count($legacyAliasesDetected),
                'legacy_aliases_detected' => $legacyAliasesDetected,
                'surface_ok' => $missingOptions === [] && $legacyAliasesDetected === [],
            ];
        }

        $steadyStateBlockers = [];
        foreach ($rows as $row) {
            if (self::isOrdinaryRuntimePath((string) $row['payload_path'])) {
                $steadyStateBlockers[] = [
                    'payload_path' => $row['payload_path'],
                    'command' => $row['command'],
                    'blocker' => 'steady_state_operator_dependency',
                ];
            }
        }

        $aligned = $missing === [] && $legacyAliasHits === [] && $steadyStateBlockers === [];

        $integrity = [
            'schema_version' => 'atlas.self_construction.operator_command_surface_integrity.v1',
            'mode' => 'read_only_operator_command_surface_integrity',
            'status' => $aligned ? 'command_surface_aligned' : 'command_surface_attention_required',
            'command_name' => 'atlas:ai:self-construction',
            'command_count' => count($commands),
            'checked_option_count' => count(array_unique(array_merge(...array_map(
                static fn (array $row): array => (array) $row['options'],
                $rows ?: [['options' => []]],
            )))),
            'missing_option_count' => count($missing),
            'missing_options' => $missing,
            'legacy_alias_free' => $legacyAliasHits === [],
            'legacy_alias_count' => count($legacyAliasHits),
            'legacy_aliases_detected' => $legacyAliasHits,
            'steady_state_dependency_free' => $steadyStateBlockers === [],
            'steady_state_dependency_blocker_count' => count($steadyStateBlockers),
            'steady_state_dependency_blockers' => $steadyStateBlockers,
            'commands' => $rows,
            'can_execute_commands_from_integrity_check' => false,
            'can_persist_from_integrity_check' => false,
            'non_execution_guarantees' => [
                'operator_command_surface_integrity_does_not_run_operator_commands',
                'operator_command_surface_integrity_does_not_persist_evidence',
                'operator_command_surface_integrity_does_not_call_provider',
                'operator_command_surface_integrity_does_not_spend_tokens',
                'operator_command_surface_integrity_does_not_dispatch_work',
                'operator_command_surface_integrity_does_not_promote_completion',
            ],
        ];
        $integrity['command_surface_integrity_hash'] = hash('sha256', (string) json_encode(self::ksortRecursive($integrity), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $integrity;
    }

    /**
     * @param  array<mixed, mixed>  $value
     * @return array<string, string>
     */
    public static function collectOperatorCommands(array $value, string $path = 'payload'): array
    {
        $commands = [];
        foreach ($value as $key => $entry) {
            $entryPath = $path.'.'.(string) $key;
            if (is_array($entry)) {
                $commands = array_merge($commands, self::collectOperatorCommands($entry, $entryPath));

                continue;
            }

            if (! is_string($entry)) {
                continue;
            }
            if (! str_contains($entry, 'php artisan atlas:ai:self-construction')) {
                continue;
            }
            $commands[$entryPath] = $entry;
        }

        ksort($commands);

        return $commands;
    }

    /**
     * @return list<string>
     */
    public static function extractCommandOptions(string $command): array
    {
        preg_match_all('/(?:^|\s)--([A-Za-z0-9][A-Za-z0-9-]*)/', $command, $matches);
        $options = array_values(array_unique(array_map('strval', $matches[1] ?? [])));
        sort($options);

        return $options;
    }

    /**
     * @return list<string>
     */
    public static function selfConstructionCommandOptions(): array
    {
        $command = Artisan::all()['atlas:ai:self-construction'] ?? null;
        if ($command === null) {
            return [];
        }

        $options = array_keys($command->getDefinition()->getOptions());
        sort($options);

        return array_values(array_map('strval', $options));
    }

    /**
     * @return list<string>
     */
    public static function legacySelfConstructionCommandAliases(): array
    {
        return [
            'runtime-gap-matrix',
            'runtime-promotion-receipt-draft',
            'runtime-promotion-receipt-runbook',
            'human-completion-receipt-closure-execution-pack',
            'operator-evidence-submission-readiness',
        ];
    }

    private static function isOrdinaryRuntimePath(string $path): bool
    {
        return str_contains(strtolower($path), 'ordinary_runtime');
    }

    /** @param array<string, mixed> $value */
    private static function ksortRecursive(array $value): array
    {
        foreach ($value as $key => $entry) {
            if (is_array($entry)) {
                $value[$key] = self::ksortRecursive($entry);
            }
        }
        if ($value !== [] && array_keys($value) !== range(0, count($value) - 1)) {
            ksort($value);
        }

        return $value;
    }
}
