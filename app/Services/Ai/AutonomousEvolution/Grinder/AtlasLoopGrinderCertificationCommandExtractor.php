<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Grinder;

use App\Services\Ai\Support\AiStringListNormalizer;

/**
 * Cohesive payload-parsing helpers for the Atlas loop task grinder.
 *
 * Extracted from AtlasLoopTaskGrinder to reduce the god-class. Pure static
 * methods; no instance state.
 */
final class AtlasLoopGrinderCertificationCommandExtractor
{
    /**
     * @param  array<string,mixed>  $payload
     * @return list<string>
     */
    public static function sealedHoldoutCommands(array $payload): array
    {
        $commands = [];
        foreach (['sealed_holdout_commands', 'wide_holdout_commands', 'final_holdout_commands'] as $key) {
            foreach (AiStringListNormalizer::trimmedStrings($payload[$key] ?? []) as $command) {
                $commands[] = $command;
            }
        }

        return AiStringListNormalizer::uniqueStrings($commands);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return list<string>
     */
    public static function semanticRefuterCommands(array $payload): array
    {
        $commands = [];
        foreach (['semantic_refuter_commands', 'provider_refuter_commands', 'refuter_commands'] as $key) {
            foreach (AiStringListNormalizer::trimmedStrings($payload[$key] ?? []) as $command) {
                $commands[] = $command;
            }
        }

        return AiStringListNormalizer::uniqueStrings($commands);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return list<string>
     */
    public static function mutationPropertyCommands(array $payload): array
    {
        $commands = [];
        foreach (['mutation_property_commands', 'property_commands', 'property_based_commands'] as $key) {
            foreach (AiStringListNormalizer::trimmedStrings($payload[$key] ?? []) as $command) {
                $commands[] = $command;
            }
        }

        return AiStringListNormalizer::uniqueStrings($commands);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return list<string>
     */
    public static function crossFileConsumerCommands(array $payload): array
    {
        $commands = [];
        foreach (['cross_file_consumer_commands', 'consumer_commands', 'consumer_contract_commands'] as $key) {
            foreach (AiStringListNormalizer::trimmedStrings($payload[$key] ?? []) as $command) {
                $commands[] = $command;
            }
        }

        return AiStringListNormalizer::uniqueStrings($commands);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return list<array<string,mixed>>
     */
    public static function crossFileConsumerContracts(array $payload): array
    {
        $contracts = [];
        foreach (['cross_file_consumer_contracts', 'consumer_contracts', 'code_graph_consumer_contracts'] as $key) {
            foreach ((array) ($payload[$key] ?? []) as $contract) {
                if (is_array($contract)) {
                    $contracts[] = $contract;
                }
            }
        }

        return $contracts;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public static function semanticRefutersRequired(array $payload, int $configured): int
    {
        foreach (['provider_refuters_required', 'refuters_required', 'refuters'] as $key) {
            if (isset($payload[$key]) && is_numeric($payload[$key])) {
                return max(0, (int) $payload[$key]);
            }
        }

        return $configured;
    }
}