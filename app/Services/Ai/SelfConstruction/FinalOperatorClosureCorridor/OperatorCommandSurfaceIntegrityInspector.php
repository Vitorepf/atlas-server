<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\FinalOperatorClosureCorridor;

use Illuminate\Support\Facades\Artisan;

/**
 * Command-surface integrity inspector for the final operator evidence closure
 * corridor service.
 *
 * Extracted from AtlasSelfConstructionFinalOperatorEvidenceClosureCorridorService
 * to reduce the god-class. All methods are stateless.
 */
final class OperatorCommandSurfaceIntegrityInspector
{
    /**
     * @param  array<string, mixed>  $surface
     * @return array<string, mixed>
     */
    public static function integrity(array $surface): array
    {
        $commands = self::collectOperatorCommands($surface);
        $commands = self::uniqueCommandsByText($commands);
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
                'command_hash' => hash('sha256', $command),
                'option_count' => count($options),
                'options' => $options,
                'missing_option_count' => count($missingOptions),
                'missing_options' => $missingOptions,
                'legacy_alias_count' => count($legacyAliasesDetected),
                'legacy_aliases_detected' => $legacyAliasesDetected,
                'surface_ok' => $missingOptions === [] && $legacyAliasesDetected === [],
            ];
        }

        $uniqueOptions = [];
        foreach ($rows as $row) {
            $uniqueOptions = array_merge($uniqueOptions, (array) $row['options']);
        }

        $integrity = [
            'schema_version' => 'atlas.self_construction.final_operator_closure_command_surface_integrity.v1',
            'mode' => 'read_only_final_operator_closure_command_surface_integrity',
            'status' => $missing === [] && $legacyAliasHits === [] ? 'command_surface_aligned' : 'command_surface_attention_required',
            'command_name' => 'atlas:ai:self-construction',
            'command_count' => count($commands),
            'checked_option_count' => count(array_unique($uniqueOptions)),
            'missing_option_count' => count($missing),
            'missing_options' => $missing,
            'legacy_alias_free' => $legacyAliasHits === [],
            'legacy_alias_count' => count($legacyAliasHits),
            'legacy_aliases_detected' => $legacyAliasHits,
            'commands' => $rows,
            'can_execute_commands_from_integrity_check' => false,
            'can_persist_from_integrity_check' => false,
            'can_call_provider_from_integrity_check' => false,
            'can_sign_for_operator_from_integrity_check' => false,
            'non_execution_guarantees' => [
                'final_operator_closure_command_surface_integrity_does_not_run_operator_commands',
                'final_operator_closure_command_surface_integrity_does_not_persist_evidence',
                'final_operator_closure_command_surface_integrity_does_not_call_provider',
                'final_operator_closure_command_surface_integrity_does_not_spend_tokens',
                'final_operator_closure_command_surface_integrity_does_not_dispatch_work',
                'final_operator_closure_command_surface_integrity_does_not_promote_completion',
            ],
        ];
        $integrity['command_surface_integrity_hash'] = ClosureCorridorCanonicalHasher::stableHash($integrity);

        return $integrity;
    }

    /**
     * @param  array<string, string>  $commands
     * @return array<string, string>
     */
    public static function uniqueCommandsByText(array $commands): array
    {
        $unique = [];
        $seen = [];
        foreach ($commands as $path => $command) {
            if (isset($seen[$command])) {
                continue;
            }
            $seen[$command] = true;
            $unique[$path] = $command;
        }

        return $unique;
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
}
