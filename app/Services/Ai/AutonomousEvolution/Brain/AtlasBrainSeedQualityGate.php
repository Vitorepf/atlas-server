<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\Governance\Recursion\HypothesisV1;
use App\Services\Ai\SelfConstruction\AtlasTaskPacketQualityInspector;
use App\Services\Ai\SelfConstruction\Governance\AtlasAaeosAcosLaneScope;

/**
 * SEED-boundary quality gate for the external brain.
 *
 * {@see AtlasTaskPacketQualityInspector::inspect()} runs UNIVERSALLY (replenisher, internal/minimal packets too),
 * so three excellence signals it emits — vague_objective, acceptance_not_runnable, blind_orphan_wiring_proxy —
 * are ADVISORY there on purpose: promoting them to its BLOCKING_DEFICIENCIES const would starve the
 * auto-replenisher. Here, at the brain's SEED boundary (where a cold worker actually receives an originated
 * packet), they are FATAL. This gate composes inspect() and intersects its deficiencies against those three —
 * it does NOT edit the universal inspector and does NOT enqueue.
 *
 * AAEOS+ACOS elite lane: structural seeds also require ELEV-31 `alternatives_compared`
 * (`do_nothing` / `simplify_existing` / `remove_a_layer`) — scoped only to that lane.
 *
 * ponytail: the proxy detector this leans on is a substring proxy-term list (objectiveIsBlindOrphanWiringProxy)
 * — a paraphrase ("attach this dormant class to the flow") escapes it. That ceiling is fine: the real
 * anti-Goodhart backstop is S7 {@see AtlasBrainCycleProgressVerdict}, which only counts a cycle as progress
 * when doc+enqueue+non-rejected-class+grounded-citation ALL hold. This gate is the cheap first screen.
 */
final class AtlasBrainSeedQualityGate
{
    public const SCHEMA = 'atlas.brain.seed_quality_gate.v1';

    /**
     * The three advisory flags inspect() emits that this seed boundary treats as BLOCKING.
     *
     * @var list<string>
     */
    public const BRAIN_FATAL_ADVISORY = [
        'vague_objective',
        'acceptance_not_runnable',
        'blind_orphan_wiring_proxy',
    ];

    /** @var list<string> */
    public const CREDIT_REQUIRED_FIELDS = [
        'problem',
        'expected_delta',
        'value',
        'duplicate_key',
        'freshness_check',
        'anti_proxy',
    ];

    public function __construct(
        private readonly ?AtlasTaskPacketQualityInspector $inspector = null,
    ) {}

    /**
     * Evaluate a builder-shaped packet. Blocks on ANY universal BLOCKING deficiency plus the three advisory
     * flags this seed boundary promotes to fatal. Pure — no enqueue, no mutation.
     *
     * @param  array<string, mixed>  $packet
     * @return array{admit:bool, blocking:list<string>, reasons:list<string>, credit:array<string,mixed>}
     */
    public function evaluate(array $packet): array
    {
        $inspection = ($this->inspector ?? new AtlasTaskPacketQualityInspector)->inspect($packet);

        $deficiencies = array_values(array_map('strval', (array) ($inspection['deficiencies'] ?? [])));
        $universalBlocking = array_values(array_map('strval', (array) ($inspection['blocking_deficiencies'] ?? [])));
        $advisoryFatal = array_values(array_intersect($deficiencies, self::BRAIN_FATAL_ADVISORY));

        $credit = $this->credit($packet);
        $creditBlocking = array_values(array_map('strval', (array) ($credit['blocking'] ?? [])));

        $blocking = array_values(array_unique(array_merge($universalBlocking, $advisoryFatal, $creditBlocking)));

        return [
            'admit' => $blocking === [],
            'blocking' => $blocking,
            'reasons' => $blocking,
            'credit' => $credit,
        ];
    }

    /**
     * Credit is the anti-Goodhart layer: high quotas stay high, but only seeds carrying a real gap, delta,
     * value, freshness, dedupe key, and anti-proxy contract can increment progress.
     *
     * @param  array<string,mixed>  $packet
     * @return array{schema:string, credited:bool, blocking:list<string>, duplicate_key:string, credit_bucket:string}
     */
    private function credit(array $packet): array
    {
        $blocking = [];
        foreach (self::CREDIT_REQUIRED_FIELDS as $field) {
            if (mb_strlen(trim((string) ($packet[$field] ?? data_get($packet, 'credit.'.$field, '')))) < 8) {
                $blocking[] = 'missing_credit_'.$field;
            }
        }

        $allowed = array_values(array_map('strval', (array) ($packet['allowed_files'] ?? [])));
        $existing = array_values(array_filter($allowed, static fn (string $path): bool => $path !== '' && is_file(base_path($path))));
        if ($allowed !== [] && count($existing) === count($allowed) && ! (bool) ($packet['modifies_existing_files'] ?? false) && trim((string) ($packet['existing_file_delta'] ?? '')) === '') {
            $blocking[] = 'allowed_files_already_exist_without_delta';
        }

        if ($this->weakAcceptance((array) ($packet['acceptance_criteria'] ?? []))) {
            $blocking[] = 'weak_acceptance_no_behavior_assertion';
        }

        if ($this->paddingValue((string) ($packet['value'] ?? data_get($packet, 'credit.value', '')), (string) ($packet['expected_delta'] ?? data_get($packet, 'credit.expected_delta', '')))) {
            $blocking[] = 'credit_value_too_thin';
        }

        if ($this->isTestOnly($allowed) && ! $this->hasStrongTestOnlyContract($packet)) {
            $blocking[] = 'test_only_microtask_requires_contract';
        }
        if ($this->isDormantCliArmProxy((string) ($packet['objective'] ?? ''))) {
            $blocking[] = 'dormant_cli_arm_proxy';
        }

        if ($this->requiresElev31Alternatives($packet, $allowed)) {
            $blocking = array_merge($blocking, $this->elev31Blocking($packet));
        }

        $duplicateKey = trim((string) ($packet['duplicate_key'] ?? data_get($packet, 'credit.duplicate_key', '')));

        return [
            'schema' => 'atlas.brain.seed_credit.v1',
            'credited' => $blocking === [],
            'blocking' => array_values(array_unique($blocking)),
            'duplicate_key' => $duplicateKey,
            'credit_bucket' => $this->creditBucket($allowed),
        ];
    }

    /**
     * ELEV-31 applies only to AAEOS+ACOS elite-lane structural evolution seeds.
     *
     * @param  array<string,mixed>  $packet
     * @param  list<string>  $allowed
     */
    private function requiresElev31Alternatives(array $packet, array $allowed): bool
    {
        if (! $this->isAaeosAcosLanePacket($packet, $allowed)) {
            return false;
        }

        return $this->isStructuralEvolution($packet, $allowed);
    }

    /**
     * @param  array<string,mixed>  $packet
     * @param  list<string>  $allowed
     */
    private function isAaeosAcosLanePacket(array $packet, array $allowed): bool
    {
        foreach (['brain_scope', 'lane_scope', 'scope'] as $key) {
            if (trim((string) ($packet[$key] ?? '')) === AtlasAaeosAcosLaneScope::SLUG) {
                return true;
            }
        }

        return AtlasAaeosAcosLaneScope::inferFromAllowedFiles($allowed) === AtlasAaeosAcosLaneScope::SLUG;
    }

    /**
     * Structural = explicit objective_kind, multi-file production touch, or elite simplify lexicon.
     *
     * @param  array<string,mixed>  $packet
     * @param  list<string>  $allowed
     */
    private function isStructuralEvolution(array $packet, array $allowed): bool
    {
        $kind = strtolower(trim((string) ($packet['objective_kind'] ?? data_get($packet, 'credit.objective_kind', ''))));
        if (in_array($kind, ['refactor', 'structural', 'simplify', 'collapse', 'remove_layer', 'defator'], true)) {
            return true;
        }

        $production = [];
        foreach ($allowed as $path) {
            $path = str_replace('\\', '/', trim($path));
            if ($path === '' || str_starts_with($path, 'tests/') || str_contains($path, '/tests/') || str_ends_with($path, 'Test.php')) {
                continue;
            }
            $production[] = $path;
        }
        if (count($production) >= 3) {
            return true;
        }

        $hay = strtolower(implode(' ', [
            (string) ($packet['objective'] ?? ''),
            (string) ($packet['problem'] ?? data_get($packet, 'credit.problem', '')),
            (string) ($packet['expected_delta'] ?? data_get($packet, 'credit.expected_delta', '')),
        ]));

        foreach ([
            'collapse',
            'duplicate',
            'remove a layer',
            'remove_a_layer',
            'remove layer',
            'consolidate',
            'simplify',
            'defator',
            'facade',
            'dead layer',
            'dead_layer',
            'peel',
        ] as $needle) {
            if (str_contains($hay, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $packet
     * @return list<string>
     */
    private function elev31Blocking(array $packet): array
    {
        $alternatives = $packet['alternatives_compared']
            ?? data_get($packet, 'credit.alternatives_compared')
            ?? data_get($packet, 'elev31.alternatives_compared');

        if (! is_array($alternatives)) {
            return ['missing_elev31_alternatives_compared'];
        }

        $blocking = [];
        foreach (HypothesisV1::REQUIRED_ALTERNATIVES as $key) {
            if (! array_key_exists($key, $alternatives)) {
                $blocking[] = 'missing_elev31_'.$key;

                continue;
            }
            $value = $alternatives[$key];
            $ok = (is_string($value) && mb_strlen(trim($value)) >= 8)
                || (is_array($value) && $value !== []);
            if (! $ok) {
                $blocking[] = 'empty_elev31_'.$key;
            }
        }

        return $blocking;
    }

    /** @param list<mixed> $acceptance */
    private function weakAcceptance(array $acceptance): bool
    {
        $text = strtolower(implode(' ', array_map('strval', $acceptance)));
        if ($text === '') {
            return true;
        }
        if (str_contains($text, 'class_exists') || str_contains($text, 'snapshot vazio') || str_contains($text, 'empty snapshot')) {
            return true;
        }

        return preg_match('/^\s*(php artisan )?tests? pass(es)?\s*$/i', trim($text)) === 1;
    }

    private function paddingValue(string $value, string $delta): bool
    {
        $hay = strtolower($value.' '.$delta);
        foreach (['wrapper', 'renomear', 'rename', 'format', 'whitespace', 'organizar', 'preparar base', 'melhorar clareza'] as $needle) {
            if (str_contains($hay, $needle)) {
                return true;
            }
        }

        foreach (['bug', 'runtime', 'autonom', 'garantia', 'invariant', 'prova', 'proof', 'test', 'gate', 'give_back', 'stale', 'duplicate'] as $needle) {
            if (str_contains($hay, $needle)) {
                return false;
            }
        }

        return true;
    }

    /** @param list<string> $allowed */
    private function isTestOnly(array $allowed): bool
    {
        return $allowed !== [] && array_all($allowed, static fn (string $path): bool => str_starts_with($path, 'tests/'));
    }

    /** @param array<string,mixed> $packet */
    private function hasStrongTestOnlyContract(array $packet): bool
    {
        $contract = (array) ($packet['test_only_contract'] ?? data_get($packet, 'credit.test_only_contract', []));
        $target = trim((string) ($contract['target_behavior'] ?? ''));
        $risk = trim((string) ($contract['risk_if_missing'] ?? ''));
        $cases = array_values(array_filter(
            array_map('strval', (array) ($contract['cases'] ?? [])),
            static fn (string $case): bool => mb_strlen(trim($case)) >= 8,
        ));
        $uniqueCases = array_unique(array_map(static fn (string $case): string => mb_strtolower(trim($case)), $cases));
        $min = max(3, (int) ($contract['min_distinct_cases'] ?? 3));

        return mb_strlen($target) >= 12
            && mb_strlen($risk) >= 12
            && count($uniqueCases) >= $min;
    }

    private function isDormantCliArmProxy(string $objective): bool
    {
        $o = mb_strtolower($objective);

        return (str_contains($o, 'arm the dormant') || str_contains($o, 'built-but-dormant-at-cli'))
            && str_contains($o, 'read-only')
            && str_contains($o, 'php artisan atlas:loop:arm-')
            && str_contains($o, 'prints ')
            && str_contains($o, 'schema_version')
            && str_contains($o, 'asserting exit 0')
            && ! str_contains($o, 'changes decision')
            && ! str_contains($o, 'changes the decision')
            && ! str_contains($o, 'fail-closed')
            && ! str_contains($o, 'reads live')
            && ! str_contains($o, 'consumes live');
    }

    /** @param list<string> $allowed */
    private function creditBucket(array $allowed): string
    {
        $first = (string) ($allowed[0] ?? '');
        $parts = array_values(array_filter(explode('/', $first), static fn (string $part): bool => $part !== ''));

        return implode('/', array_slice($parts, 0, 3));
    }
}
