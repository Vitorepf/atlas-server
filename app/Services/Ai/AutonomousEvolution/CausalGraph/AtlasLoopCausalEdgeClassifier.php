<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\CausalGraph;

use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\ApiDiff\AtlasCortexApiBreakingChangeReporter;

/**
 * Classifies the causal edge from a changed API symbol to each consumer.
 *
 * The break-kind names are the loop-facing vocabulary for the reporter's API-diff classes
 * ({@see AtlasCortexApiBreakingChangeReporter}: added/removed/changed). This service stays pure: it does
 * not read consumer files, score risk, or mutate the reporter. Callers provide the consumer usage evidence.
 */
final class AtlasLoopCausalEdgeClassifier
{
    public const SCHEMA_VERSION = 'atlas.loop.causal_edge_classifier.v1';

    /** @var list<string> */
    public const BREAK_KINDS = ['none', 'added_only', 'removed_member', 'signature_changed'];

    private const REPORTER_ADDED = 'added';
    private const REPORTER_REMOVED = 'removed';
    private const REPORTER_CHANGED = 'changed';

    /**
     * @param  list<string|array<string,mixed>>  $consumerFqcns
     * @param  array<string,mixed>  $apiChange
     * @return array{schema:string, edges:list<array{consumer_fqcn:string, break_kind:string}>}
     */
    public function classify(string $fqcn, array $consumerFqcns, array $apiChange): array
    {
        $targetFqcn = ltrim(trim($fqcn), '\\');
        $members = [
            'added' => $this->memberSet($apiChange, self::REPORTER_ADDED),
            'removed' => $this->memberSet($apiChange, self::REPORTER_REMOVED),
            'changed' => $this->memberSet($apiChange, self::REPORTER_CHANGED),
        ];

        $consumers = $this->consumers($consumerFqcns, $apiChange, $targetFqcn);
        ksort($consumers, SORT_STRING);

        $edges = [];
        foreach ($consumers as $consumerFqcn => $usage) {
            $edges[] = [
                'consumer_fqcn' => $consumerFqcn,
                'break_kind' => $this->breakKind($usage, $members),
            ];
        }

        return ['schema' => self::SCHEMA_VERSION, 'edges' => $edges];
    }

    /**
     * @param  array<string,bool>  $usage
     * @param  array{added:array<string,bool>, removed:array<string,bool>, changed:array<string,bool>}  $members
     */
    private function breakKind(array $usage, array $members): string
    {
        if ($this->intersects($usage, $members['removed'])) {
            return 'removed_member';
        }

        if ($this->intersects($usage, $members['changed'])) {
            return 'signature_changed';
        }

        if ($this->intersects($usage, $members['added'])
            || ($usage === [] && $members['added'] !== [] && $members['removed'] === [] && $members['changed'] === [])) {
            return 'added_only';
        }

        return 'none';
    }

    /**
     * @param  list<string|array<string,mixed>>  $consumerFqcns
     * @return array<string,array<string,bool>>
     */
    private function consumers(array $consumerFqcns, array $apiChange, string $targetFqcn): array
    {
        $consumers = [];
        foreach ($consumerFqcns as $consumer) {
            $fqcn = $this->consumerFqcn($consumer);
            if ($fqcn === '') {
                continue;
            }

            $usage = $this->consumerUsage($consumer, $apiChange, $targetFqcn, $fqcn);
            $consumers[$fqcn] = array_replace($consumers[$fqcn] ?? [], $usage);
        }

        return $consumers;
    }

    private function consumerFqcn(string|array $consumer): string
    {
        if (is_string($consumer)) {
            return ltrim(trim($consumer), '\\');
        }

        foreach (['consumer_fqcn', 'fqcn', 'class'] as $key) {
            $value = $consumer[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return ltrim(trim($value), '\\');
            }
        }

        return '';
    }

    /** @return array<string,bool> */
    private function consumerUsage(string|array $consumer, array $apiChange, string $targetFqcn, string $consumerFqcn): array
    {
        $members = [];
        if (is_array($consumer)) {
            foreach (['uses', 'used_members', 'members_used', 'method_calls', 'referenced_members', 'target_members'] as $key) {
                $members = array_replace($members, $this->normalMemberSet($consumer[$key] ?? []));
            }
        }

        foreach (['consumer_usage', 'usage_by_consumer', 'consumer_member_usage'] as $key) {
            $map = $apiChange[$key] ?? null;
            if (! is_array($map)) {
                continue;
            }

            foreach ($this->consumerUsageKeys($targetFqcn, $consumerFqcn) as $candidate) {
                if (array_key_exists($candidate, $map)) {
                    $members = array_replace($members, $this->normalMemberSet($map[$candidate]));
                }
            }
        }

        return $members;
    }

    /** @return list<string> */
    private function consumerUsageKeys(string $targetFqcn, string $consumerFqcn): array
    {
        return array_values(array_unique([
            $consumerFqcn,
            '\\'.$consumerFqcn,
            $targetFqcn.'::'.$consumerFqcn,
            '\\'.$targetFqcn.'::'.$consumerFqcn,
        ]));
    }

    /** @return array<string,bool> */
    private function memberSet(array $apiChange, string $classification): array
    {
        $members = [];
        foreach ((array) ($apiChange['diff'] ?? []) as $row) {
            if (! is_array($row) || (string) ($row['classification'] ?? '') !== $classification) {
                continue;
            }
            $members = array_replace($members, $this->normalMemberSet([$row]));
        }

        $keys = match ($classification) {
            self::REPORTER_ADDED => ['added', 'added_members', 'added_methods', 'public_members_added'],
            self::REPORTER_REMOVED => ['removed', 'removed_members', 'removed_methods', 'public_members_removed'],
            self::REPORTER_CHANGED => ['changed', 'changed_members', 'changed_methods', 'signature_changed', 'signature_changed_members', 'signature_changed_methods', 'changed_signatures'],
            default => [],
        };

        foreach ($keys as $key) {
            $members = array_replace($members, $this->normalMemberSet($apiChange[$key] ?? []));
        }

        return $members;
    }

    /** @return array<string,bool> */
    private function normalMemberSet(mixed $value): array
    {
        $members = [];
        foreach ((array) $value as $item) {
            $name = $this->memberName($item);
            if ($name !== '') {
                $members[$name] = true;
            }
        }

        return $members;
    }

    private function memberName(mixed $item): string
    {
        if (is_string($item)) {
            return strtolower(trim($item));
        }

        if (! is_array($item)) {
            return '';
        }

        foreach (['method_name', 'member_name', 'member', 'name'] as $key) {
            $value = $item[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return strtolower(trim($value));
            }
        }

        return '';
    }

    /** @param array<string,bool> $left @param array<string,bool> $right */
    private function intersects(array $left, array $right): bool
    {
        foreach ($left as $member => $_) {
            if (isset($right[$member])) {
                return true;
            }
        }

        return false;
    }
}
