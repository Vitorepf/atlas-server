<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Framework\AtlasLoopFrameworkMaterializer;
use App\Services\Ai\Support\AiStringListNormalizer;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Compiles a narrow human intent into a frozen executable verifier packet.
 *
 * This is the step before P4 grinding: do not let a provider implement until the
 * Atlas side has a RED, sealed verifier that the candidate cannot edit.
 */
final class AtlasLoopIntentVerifierFactory
{
    public const SCHEMA = 'atlas.loop.intent_verifier_factory.v1';

    private ?AtlasLoopFrozenTestSourceRenderer $frozenTestSourceRenderer = null;

    public function __construct(
        private readonly AtlasLoopFrameworkMaterializer $frameworkMaterializer,
        // Legacy optional collaborator kept ONLY to preserve the public constructor signature.
        // The active source-emitter is AtlasLoopFrozenTestSourceRenderer, lazily self-resolved.
        private readonly ?AtlasLoopFrozenTestContentBuilder $frozenTestContentBuilderCollaborator = null,
    ) {}

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function compileFrameworkPacket(string $repoRoot, string $intent, array $payload = []): array
    {
        $repoRoot = rtrim($repoRoot, '/');
        $target = $this->normalizeRelative((string) ($payload['target_relative_path'] ?? $payload['target_path'] ?? ''));
        $targetPath = $target === '' ? '' : $repoRoot.'/'.$target;
        $metadata = $targetPath !== '' && is_file($targetPath) ? $this->classMetadata($targetPath) : [
            'class' => null,
            'constructor_required_params' => 0,
        ];

        $atoms = $this->verificationAtoms($payload, $intent);
        $blockers = $this->compileBlockers($repoRoot, $intent, $target, $metadata, $atoms, $payload);
        $testPath = (string) ($payload['frozen_test_path'] ?? $this->defaultTestPath($target, $intent, $atoms));

        $packet = [
            'schema_version' => self::SCHEMA,
            'status' => 'blocked',
            'ready' => false,
            'intent' => $intent,
            'target_relative_path' => $target,
            'target_class' => $metadata['class'],
            'verification_atoms' => $atoms,
            'blockers' => $blockers,
            'critic' => [
                'schema_version' => self::SCHEMA.'.critic.v1',
                'status' => $blockers === [] ? 'clean' : 'blocked',
                'blocking_issue_count' => count($blockers),
            ],
            'proposal_only' => true,
            'merged_to_main' => false,
        ];

        // ACDE F2 — carry the FEATURE COMPLETENESS CHECKLIST (one falsifiable criterion per verification atom)
        // on the packet so a delivery dossier reports per-criterion completeness instead of one opaque green
        // bit. Pure restatement of the atoms the verifier already enforces (no self-grading). Additive +
        // flag-gated => OFF => key absent => byte-identical (the verifier_hash is computed over intent/target/
        // atoms/acceptance/test_content, never the whole packet, so this never shifts the hash either way).
        if ((bool) config('atlas.loop.feature_completeness_checklist_enabled', false)) {
            $packet['completeness_checklist'] = (new AtlasLoopFeatureCompletenessResolver)->resolve($atoms);
        }

        // ACDE F8 (honest, non-Goodhart form) — paraphrase audit over the HUMAN-frozen atoms: flag near-
        // duplicate criterion pairs (high Jaccard similarity) as a read-only operator ADVISORY. It NEVER
        // infers, authors, weakens, removes, or grades an atom (the literal "abstain on inferred atoms" is
        // forbidden by the canon) — it only points at a possible authoring redundancy for the human to
        // resolve. Additive + flag-gated => OFF => key absent => byte-identical (never touches verifier_hash).
        if ((bool) config('atlas.loop.atom_paraphrase_audit_enabled', false)) {
            $threshold = (float) config('atlas.loop.atom_paraphrase_audit_threshold', 0.85);
            $packet['atom_paraphrase_audit'] = (new AtlasLoopAtomParaphraseAudit)->nearDuplicatePairs($atoms, $threshold);
        }

        // ACDE F1 — carry the SEQUENCED-FEATURE plan: one huge feature's human-frozen atoms partitioned into an
        // ordered chain of small steps (each step's frozen sub-acceptance is exactly its atom subset, compiled
        // by THIS factory). Lets the loop build a big feature incrementally instead of one-shotting it. Pure
        // grouping of the atoms the human authored — never a re-authored bar. Additive + flag-gated => OFF =>
        // key absent => byte-identical (and never touches verifier_hash, which is over atoms/acceptance/test).
        if ((bool) config('atlas.loop.feature_sequence_enabled', false)) {
            $planner = new AtlasLoopFeatureSequencePlanner;
            $maxStepSize = max(1, (int) config('atlas.loop.feature_sequence_max_step_atoms', 2));
            $packet['feature_sequence_id'] = $planner->sequenceId($target, $intent);
            $packet['feature_sequence_plan'] = $planner->plan($atoms, $maxStepSize);

            // ACDE F4 — close the verified orphan: attach the EXECUTABLE walk of the plan (each step's ACTIVE
            // sub-acceptance atoms + every prior step's atoms held as REGRESSION), so a consumer can grind the
            // feature incrementally instead of only reading the grouping. Pure grouping of the human-frozen
            // atoms — never a re-authored bar; the last step's cumulative atoms ARE the full feature. Gated
            // separately => OFF => key absent => byte-identical (and never touches verifier_hash).
            if ((bool) config('atlas.loop.feature_sequence_walk_enabled', false)) {
                $packet['feature_sequence_steps'] = (new AtlasLoopFeatureSequenceWalker($planner))->steps($atoms, $maxStepSize);
            }
        }

        if ($blockers !== []) {
            return $packet;
        }

        $class = (string) $metadata['class'];
        $timeout = max(1, (int) ($payload['timeout_seconds'] ?? data_get($payload, 'acceptance.timeout_seconds', 120)));
        $testContent = $this->frozenTestContent($testPath, $target, $class, $atoms);
        $acceptanceCommand = 'php '.$testPath;
        $acceptance = [
            'commands' => [$acceptanceCommand],
            'allowed_globs' => $this->allowedFiles($payload, $target),
            'frozen_globs' => [$testPath],
            'metric_kind' => AtlasEvolutionFrozenJudge::METRIC_GATE,
            'revert_recheck' => true,
            'timeout_seconds' => $timeout,
        ];
        $sealedHoldouts = AiStringListNormalizer::uniqueMergedStrings(
            AiStringListNormalizer::trimmedStrings($payload['sealed_holdout_commands'] ?? []),
            [
                'php -l '.escapeshellarg($target),
                'php -r '.escapeshellarg("require 'vendor/autoload.php'; exit(class_exists(".var_export($class, true).") ? 0 : 1);"),
            ],
        );

        $compiledPayload = array_merge($payload, [
            'materializer' => 'framework',
            'target_relative_path' => $target,
            'frozen_tests' => [[
                'path' => $testPath,
                'content' => $testContent,
            ]],
            'acceptance' => $acceptance,
            'allowed_files' => $this->allowedFiles($payload, $target),
            'validation_commands' => [$acceptanceCommand],
            'sealed_holdout_commands' => $sealedHoldouts,
            'intent_verifier_factory_compiled' => true,
            'intent_verifier_factory_hash' => '',
        ]);
        $compiledPayload['intent_verifier_factory_hash'] = $this->stableHash([
            'intent' => $intent,
            'target' => $target,
            'atoms' => $atoms,
            'acceptance' => $acceptance,
            'test_content' => $testContent,
        ]);

        $packet = array_merge($packet, [
            'status' => 'ready',
            'ready' => true,
            'verifier_hash' => $compiledPayload['intent_verifier_factory_hash'],
            'frozen_tests' => $compiledPayload['frozen_tests'],
            'acceptance' => $acceptance,
            'sealed_holdout_commands' => $sealedHoldouts,
            'task_payload' => $compiledPayload,
            'red_preflight' => null,
            'verifier_refuters' => null,
        ]);

        if ((bool) ($payload['intent_verifier_preflight'] ?? true)) {
            $packet['red_preflight'] = $this->redPreflight($repoRoot, $intent, $compiledPayload);
            if (($packet['red_preflight']['status'] ?? null) !== 'red') {
                $packet['status'] = 'blocked';
                $packet['ready'] = false;
                $packet['blockers'] = AiStringListNormalizer::uniqueMergedStrings(
                    $blockers,
                    ['compiled_verifier_not_red_on_baseline'],
                );
            }
        }

        $packet['verifier_refuters'] = $this->runVerifierRefuters($repoRoot, $packet, $payload);
        if (! (bool) data_get($packet, 'verifier_refuters.met_required', true) || (int) data_get($packet, 'verifier_refuters.refuted', 0) > 0) {
            $packet['status'] = 'blocked';
            $packet['ready'] = false;
            $packet['blockers'] = AiStringListNormalizer::uniqueMergedStrings(
                (array) ($packet['blockers'] ?? []),
                ['verifier_refuted_or_missing_required_refuter'],
            );
        }

        return $packet;
    }

    /**
     * @param  array<string,mixed>  $packet
     * @return array<string,mixed>
     */
    public function taskPayloadOrFail(array $packet): array
    {
        if (! (bool) ($packet['ready'] ?? false) || ! is_array($packet['task_payload'] ?? null)) {
            throw new RuntimeException('intent verifier factory blocked: '.implode(',', (array) ($packet['blockers'] ?? ['unknown'])));
        }

        return $packet['task_payload'];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array{class:?string,constructor_required_params:int}  $metadata
     * @param  list<array<string,mixed>>  $atoms
     * @return list<string>
     */
    private function compileBlockers(string $repoRoot, string $intent, string $target, array $metadata, array $atoms, array $payload): array
    {
        $blockers = [];
        if ($repoRoot === '' || ! is_dir($repoRoot.'/.git')) {
            $blockers[] = 'repo_root_not_git';
        }
        if (trim($intent) === '') {
            $blockers[] = 'intent_missing';
        }
        if ($target === '' || ! is_file($repoRoot.'/'.$target)) {
            $blockers[] = 'target_relative_path_missing_or_not_found';
        }
        if (! is_string($metadata['class'] ?? null) || $metadata['class'] === '') {
            $blockers[] = 'target_class_not_resolved';
        }
        $constructorArgs = $this->literalList($payload['constructor_args'] ?? []);
        if ((int) ($metadata['constructor_required_params'] ?? 0) > count($constructorArgs)) {
            $blockers[] = 'constructor_requires_explicit_args';
        }
        if ($atoms === []) {
            $blockers[] = 'no_executable_verification_atom';
        }
        foreach ($atoms as $atom) {
            $type = (string) ($atom['type'] ?? '');
            if ($type === 'method_return' && is_string($atom['method'] ?? null) && $atom['method'] !== '') {
                continue;
            }
            if ($type === 'command_output' && is_string($atom['command'] ?? null) && $atom['command'] !== '') {
                continue;
            }
            if ($type === 'http_response' && is_string($atom['path'] ?? null) && $atom['path'] !== '') {
                continue;
            }
            if ($type === 'event_dispatched' && is_string($atom['event_class'] ?? null) && $atom['event_class'] !== '' && is_array($atom['trigger'] ?? null)) {
                continue;
            }
            if ($type === 'job_dispatched' && is_string($atom['job_class'] ?? null) && $atom['job_class'] !== '' && is_array($atom['trigger'] ?? null)) {
                continue;
            }
            if ($type === 'db_state' && is_string($atom['table'] ?? null) && $atom['table'] !== '' && is_array($atom['trigger'] ?? null) && ($atom['setup_sql'] ?? []) !== []) {
                continue;
            }
            if (! in_array($type, ['method_return', 'command_output', 'http_response', 'event_dispatched', 'job_dispatched', 'db_state'], true)) {
                $blockers[] = 'unsupported_or_incomplete_verification_atom';
            }
        }

        return AiStringListNormalizer::uniqueStrings($blockers);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return list<array<string,mixed>>
     */
    private function verificationAtoms(array $payload, string $intent): array
    {
        $atoms = [];
        foreach (is_array($payload['verification_atoms'] ?? null) ? $payload['verification_atoms'] : [] as $atom) {
            if (is_array($atom)) {
                $normalized = $this->normalizeAtom($atom);
                if ($normalized !== null) {
                    $atoms[] = $normalized;
                }
            }
        }

        $method = trim((string) ($payload['method'] ?? $payload['expected_method'] ?? ''));
        if ($method !== '' && array_key_exists('returns', $payload)) {
            $atoms[] = $this->normalizeAtom([
                'type' => 'method_return',
                'method' => $method,
                'expected' => $payload['returns'],
                'constructor_args' => $payload['constructor_args'] ?? [],
                'method_args' => $payload['method_args'] ?? [],
            ]);
        }
        $command = trim((string) ($payload['command'] ?? $payload['expected_command'] ?? ''));
        if ($command !== '' && (array_key_exists('output_contains', $payload) || array_key_exists('output_exact', $payload))) {
            $atoms[] = $this->normalizeAtom([
                'type' => 'command_output',
                'command' => $command,
                'output_contains' => $payload['output_contains'] ?? null,
                'output_exact' => $payload['output_exact'] ?? null,
                'exit_code' => $payload['exit_code'] ?? 0,
            ]);
        }
        $httpPath = trim((string) ($payload['http_path'] ?? ''));
        if ($httpPath !== '' && (array_key_exists('http_body_contains', $payload) || array_key_exists('http_body_exact', $payload) || array_key_exists('http_status', $payload))) {
            $atoms[] = $this->normalizeAtom([
                'type' => 'http_response',
                'method' => $payload['http_method'] ?? 'GET',
                'path' => $httpPath,
                'status' => $payload['http_status'] ?? 200,
                'body_contains' => $payload['http_body_contains'] ?? null,
                'body_exact' => $payload['http_body_exact'] ?? null,
            ]);
        }
        $event = trim((string) ($payload['event_class'] ?? $payload['expected_event'] ?? ''));
        if ($event !== '') {
            $eventAtom = $this->eventAtomFromPayload($event, $payload);
            if ($eventAtom !== null) {
                $atoms[] = $eventAtom;
            }
        }
        $job = trim((string) ($payload['job_class'] ?? $payload['expected_job'] ?? ''));
        if ($job !== '') {
            $jobAtom = $this->jobAtomFromPayload($job, $payload);
            if ($jobAtom !== null) {
                $atoms[] = $jobAtom;
            }
        }
        $table = trim((string) ($payload['db_table'] ?? $payload['expected_db_table'] ?? ''));
        if ($table !== '') {
            $dbAtom = $this->dbStateAtomFromPayload($table, $payload);
            if ($dbAtom !== null) {
                $atoms[] = $dbAtom;
            }
        }

        if ($atoms === []) {
            $inferred = $this->inferMethodReturnAtom($intent) ?? $this->inferCommandOutputAtom($intent);
            if ($inferred !== null) {
                $atoms[] = $inferred;
            }
        }

        return array_values(array_filter($atoms));
    }

    /**
     * @param  array<string,mixed>  $atom
     * @return array<string,mixed>|null
     */
    private function normalizeAtom(array $atom): ?array
    {
        $type = (string) ($atom['type'] ?? 'method_return');
        $handlers = $this->atomHandlers();
        $handler = $handlers[$type] ?? $handlers['method_return'];

        return $handler($atom);
    }

    /**
     * Type-discriminator dispatch table for normalizeAtom(). One closure per atom type
     * plus a default `method_return` fallback; the table itself is branch-free and is
     * consulted by lookup only — collapsing the previous if/elseif chain into a single
     * dispatch preserves the exact prior branch inventory in the per-type helpers.
     *
     * @return array<string, callable(array<string,mixed>): ?array>
     */
    private function atomHandlers(): array
    {
        return [
            'command_output' => fn (array $atom): ?array => $this->normalizeCommandOutputAtom($atom),
            'http_response' => fn (array $atom): ?array => $this->normalizeHttpResponseAtom($atom),
            'event_dispatched' => fn (array $atom): ?array => $this->normalizeEventDispatchedAtom($atom),
            'job_dispatched' => fn (array $atom): ?array => $this->normalizeJobDispatchedAtom($atom),
            'db_state' => fn (array $atom): ?array => $this->normalizeDbStateAtom($atom),
            'method_return' => fn (array $atom): ?array => $this->normalizeMethodReturnAtom($atom),
        ];
    }

    /**
     * @param  array<string,mixed>  $atom
     * @return array<string,mixed>|null
     */
    private function normalizeCommandOutputAtom(array $atom): ?array
    {
        $command = trim((string) ($atom['command'] ?? ''));
        if ($command === '') {
            return null;
        }
        $contains = array_key_exists('output_contains', $atom) ? $this->literal($atom['output_contains']) : null;
        $exact = array_key_exists('output_exact', $atom) ? $this->literal($atom['output_exact']) : null;
        if (! is_string($contains) && ! is_string($exact)) {
            return null;
        }

        return [
            'type' => 'command_output',
            'command' => $command,
            'output_contains' => is_string($contains) ? $contains : null,
            'output_exact' => is_string($exact) ? $exact : null,
            'exit_code' => is_numeric($atom['exit_code'] ?? null) ? (int) $atom['exit_code'] : 0,
        ];
    }

    /**
     * @param  array<string,mixed>  $atom
     * @return array<string,mixed>|null
     */
    private function normalizeHttpResponseAtom(array $atom): ?array
    {
        $path = trim((string) ($atom['path'] ?? ''));
        if ($path === '' || ! str_starts_with($path, '/')) {
            return null;
        }
        $contains = array_key_exists('body_contains', $atom) ? $this->literal($atom['body_contains']) : null;
        $exact = array_key_exists('body_exact', $atom) ? $this->literal($atom['body_exact']) : null;
        if (! is_string($contains) && ! is_string($exact)) {
            return null;
        }

        return [
            'type' => 'http_response',
            'method' => strtoupper(trim((string) ($atom['method'] ?? 'GET'))) ?: 'GET',
            'path' => $path,
            'status' => is_numeric($atom['status'] ?? null) ? (int) $atom['status'] : 200,
            'body_contains' => is_string($contains) ? $contains : null,
            'body_exact' => is_string($exact) ? $exact : null,
        ];
    }

    /**
     * @param  array<string,mixed>  $atom
     * @return array<string,mixed>|null
     */
    private function normalizeEventDispatchedAtom(array $atom): ?array
    {
        $event = trim((string) ($atom['event_class'] ?? $atom['event'] ?? ''));
        $trigger = is_array($atom['trigger'] ?? null) ? $this->normalizeEventTrigger($atom['trigger']) : null;
        if ($event === '' || $trigger === null) {
            return null;
        }

        return [
            'type' => 'event_dispatched',
            'event_class' => $event,
            'trigger' => $trigger,
        ];
    }

    /**
     * @param  array<string,mixed>  $atom
     * @return array<string,mixed>|null
     */
    private function normalizeJobDispatchedAtom(array $atom): ?array
    {
        $job = trim((string) ($atom['job_class'] ?? $atom['job'] ?? ''));
        $trigger = is_array($atom['trigger'] ?? null) ? $this->normalizeEventTrigger($atom['trigger']) : null;
        if ($job === '' || $trigger === null) {
            return null;
        }

        return [
            'type' => 'job_dispatched',
            'job_class' => ltrim($job, '\\'),
            'trigger' => $trigger,
        ];
    }

    /**
     * @param  array<string,mixed>  $atom
     * @return array<string,mixed>|null
     */
    private function normalizeDbStateAtom(array $atom): ?array
    {
        $table = trim((string) ($atom['table'] ?? ''));
        $trigger = is_array($atom['trigger'] ?? null) ? $this->normalizeEventTrigger($atom['trigger']) : null;
        $setupSql = AiStringListNormalizer::trimmedStrings($atom['setup_sql'] ?? []);
        $where = $this->normalizeWhere($atom['where'] ?? []);
        $operator = $this->normalizeCountOperator((string) ($atom['count_operator'] ?? '>='));
        if (! $this->isSafeSqlIdentifier($table) || $trigger === null || $setupSql === []) {
            return null;
        }

        return [
            'type' => 'db_state',
            'table' => $table,
            'where' => $where,
            'expected_count' => is_numeric($atom['expected_count'] ?? null) ? max(0, (int) $atom['expected_count']) : 1,
            'count_operator' => $operator,
            'setup_sql' => $setupSql,
            'trigger' => $trigger,
        ];
    }

    /**
     * @param  array<string,mixed>  $atom
     * @return array<string,mixed>|null
     */
    private function normalizeMethodReturnAtom(array $atom): ?array
    {
        $method = trim((string) ($atom['method'] ?? ''));
        if ($method === '' || ! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $method)) {
            return null;
        }

        return [
            'type' => 'method_return',
            'method' => $method,
            'expected' => $this->literal($atom['expected'] ?? null),
            'constructor_args' => $this->literalList($atom['constructor_args'] ?? []),
            'method_args' => $this->literalList($atom['method_args'] ?? []),
            'static' => (bool) ($atom['static'] ?? false),
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>|null
     */
    private function eventAtomFromPayload(string $event, array $payload): ?array
    {
        $method = trim((string) ($payload['event_method'] ?? $payload['method'] ?? ''));
        if ($method !== '') {
            return $this->normalizeAtom([
                'type' => 'event_dispatched',
                'event_class' => $event,
                'trigger' => [
                    'type' => 'method_call',
                    'method' => $method,
                    'constructor_args' => $payload['constructor_args'] ?? [],
                    'method_args' => $payload['method_args'] ?? [],
                    'static' => (bool) ($payload['static'] ?? false),
                ],
            ]);
        }

        $httpPath = trim((string) ($payload['http_path'] ?? $payload['event_http_path'] ?? ''));
        if ($httpPath !== '') {
            return $this->normalizeAtom([
                'type' => 'event_dispatched',
                'event_class' => $event,
                'trigger' => [
                    'type' => 'http_request',
                    'method' => $payload['http_method'] ?? $payload['event_http_method'] ?? 'GET',
                    'path' => $httpPath,
                    'status' => $payload['http_status'] ?? $payload['event_http_status'] ?? 200,
                ],
            ]);
        }

        $artisan = trim((string) ($payload['artisan_command'] ?? $payload['event_artisan_command'] ?? ''));
        if ($artisan !== '') {
            return $this->normalizeAtom([
                'type' => 'event_dispatched',
                'event_class' => $event,
                'trigger' => [
                    'type' => 'artisan_call',
                    'command' => $artisan,
                    'parameters' => is_array($payload['artisan_parameters'] ?? null) ? $payload['artisan_parameters'] : [],
                    'exit_code' => $payload['exit_code'] ?? 0,
                ],
            ]);
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>|null
     */
    private function jobAtomFromPayload(string $job, array $payload): ?array
    {
        $method = trim((string) ($payload['job_method'] ?? $payload['method'] ?? ''));
        if ($method !== '') {
            return $this->normalizeAtom([
                'type' => 'job_dispatched',
                'job_class' => $job,
                'trigger' => [
                    'type' => 'method_call',
                    'method' => $method,
                    'constructor_args' => $payload['constructor_args'] ?? [],
                    'method_args' => $payload['method_args'] ?? [],
                    'static' => (bool) ($payload['static'] ?? false),
                ],
            ]);
        }

        $httpPath = trim((string) ($payload['http_path'] ?? $payload['job_http_path'] ?? ''));
        if ($httpPath !== '') {
            return $this->normalizeAtom([
                'type' => 'job_dispatched',
                'job_class' => $job,
                'trigger' => [
                    'type' => 'http_request',
                    'method' => $payload['http_method'] ?? $payload['job_http_method'] ?? 'GET',
                    'path' => $httpPath,
                    'status' => $payload['http_status'] ?? $payload['job_http_status'] ?? 200,
                ],
            ]);
        }

        $artisan = trim((string) ($payload['artisan_command'] ?? $payload['job_artisan_command'] ?? ''));
        if ($artisan !== '') {
            return $this->normalizeAtom([
                'type' => 'job_dispatched',
                'job_class' => $job,
                'trigger' => [
                    'type' => 'artisan_call',
                    'command' => $artisan,
                    'parameters' => is_array($payload['artisan_parameters'] ?? null) ? $payload['artisan_parameters'] : [],
                    'exit_code' => $payload['exit_code'] ?? 0,
                ],
            ]);
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>|null
     */
    private function dbStateAtomFromPayload(string $table, array $payload): ?array
    {
        $trigger = $this->triggerFromPayload($payload, 'db');
        if ($trigger === null) {
            return null;
        }

        return $this->normalizeAtom([
            'type' => 'db_state',
            'table' => $table,
            'where' => is_array($payload['db_where'] ?? null) ? $payload['db_where'] : [],
            'expected_count' => $payload['db_expected_count'] ?? $payload['db_count'] ?? 1,
            'count_operator' => $payload['db_count_operator'] ?? '>=',
            'setup_sql' => $payload['db_setup_sql'] ?? [],
            'trigger' => $trigger,
        ]);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>|null
     */
    private function triggerFromPayload(array $payload, string $prefix): ?array
    {
        $method = trim((string) ($payload[$prefix.'_method'] ?? $payload['method'] ?? ''));
        if ($method !== '') {
            return [
                'type' => 'method_call',
                'method' => $method,
                'constructor_args' => $payload['constructor_args'] ?? [],
                'method_args' => $payload['method_args'] ?? [],
                'static' => (bool) ($payload['static'] ?? false),
            ];
        }

        $httpPath = trim((string) ($payload['http_path'] ?? $payload[$prefix.'_http_path'] ?? ''));
        if ($httpPath !== '') {
            return [
                'type' => 'http_request',
                'method' => $payload['http_method'] ?? $payload[$prefix.'_http_method'] ?? 'GET',
                'path' => $httpPath,
                'status' => $payload['http_status'] ?? $payload[$prefix.'_http_status'] ?? 200,
            ];
        }

        $artisan = trim((string) ($payload['artisan_command'] ?? $payload[$prefix.'_artisan_command'] ?? ''));
        if ($artisan !== '') {
            return [
                'type' => 'artisan_call',
                'command' => $artisan,
                'parameters' => is_array($payload['artisan_parameters'] ?? null) ? $payload['artisan_parameters'] : [],
                'exit_code' => $payload['exit_code'] ?? 0,
            ];
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $trigger
     * @return array<string,mixed>|null
     */
    private function normalizeEventTrigger(array $trigger): ?array
    {
        $type = (string) ($trigger['type'] ?? 'method_call');
        if ($type === 'method_call') {
            $method = trim((string) ($trigger['method'] ?? ''));
            if ($method === '' || ! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $method)) {
                return null;
            }

            return [
                'type' => 'method_call',
                'method' => $method,
                'constructor_args' => $this->literalList($trigger['constructor_args'] ?? []),
                'method_args' => $this->literalList($trigger['method_args'] ?? []),
                'static' => (bool) ($trigger['static'] ?? false),
            ];
        }
        if ($type === 'http_request') {
            $path = trim((string) ($trigger['path'] ?? ''));
            if ($path === '' || ! str_starts_with($path, '/')) {
                return null;
            }

            return [
                'type' => 'http_request',
                'method' => strtoupper(trim((string) ($trigger['method'] ?? 'GET'))) ?: 'GET',
                'path' => $path,
                'status' => is_numeric($trigger['status'] ?? null) ? (int) $trigger['status'] : 200,
            ];
        }
        if ($type === 'artisan_call') {
            $command = trim((string) ($trigger['command'] ?? ''));
            if ($command === '') {
                return null;
            }

            return [
                'type' => 'artisan_call',
                'command' => $command,
                'parameters' => is_array($trigger['parameters'] ?? null) ? $trigger['parameters'] : [],
                'exit_code' => is_numeric($trigger['exit_code'] ?? null) ? (int) $trigger['exit_code'] : 0,
            ];
        }

        return null;
    }

    /**
     * @param  mixed  $where
     * @return array<string,mixed>
     */
    private function normalizeWhere(mixed $where): array
    {
        if (! is_array($where)) {
            return [];
        }
        $normalized = [];
        foreach ($where as $column => $value) {
            if (! is_string($column) || ! $this->isSafeSqlIdentifier($column)) {
                continue;
            }
            $normalized[$column] = $this->literal($value);
        }

        return $normalized;
    }

    private function normalizeCountOperator(string $operator): string
    {
        return in_array($operator, ['=', '>=', '<=', '>', '<'], true) ? $operator : '>=';
    }

    private function isSafeSqlIdentifier(string $identifier): bool
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier) === 1;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function inferMethodReturnAtom(string $intent): ?array
    {
        $quoted = '(?:"([^"]*)"|\'([^\']*)\'|`([^`]*)`)';
        $bare = '([A-Za-z0-9_.:\\/-]+|true|false|null)';
        $value = '(?:'.$quoted.'|'.$bare.')';
        $patterns = [
            '/\b(?:method|metodo|m[eé]todo)\s+([A-Za-z_][A-Za-z0-9_]*)\s*(?:\(\))?\s+(?:returns?|returning|retorna|retorne|deve retornar|should return)\s+'.$value.'/iu',
            '/\b([A-Za-z_][A-Za-z0-9_]*)\s*\(\)\s+(?:returns?|retorna|deve retornar|should return)\s+'.$value.'/iu',
            '/\b(?:add|create|adicionar|adicione|criar|crie)\b.*?\b([A-Za-z_][A-Za-z0-9_]*)\s*(?:\(\)|method|m[eé]todo)?\b.*?\b(?:returns?|returning|retorna|retorne|retornar)\s+'.$value.'/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $intent, $m) === 1) {
                $method = (string) $m[1];
                $raw = null;
                for ($i = 2; $i <= 5; $i++) {
                    if (array_key_exists($i, $m) && $m[$i] !== '') {
                        $raw = $m[$i];
                        break;
                    }
                }

                return $this->normalizeAtom([
                    'type' => 'method_return',
                    'method' => $method,
                    'expected' => $this->literal($raw),
                ]);
            }
        }

        return null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function inferCommandOutputAtom(string $intent): ?array
    {
        $pattern = '/\b(?:command|comando)\s+(?:"([^"]+)"|`([^`]+)`|\'([^\']+)\')\s+(?:outputs?|imprime|deve imprimir|should output)\s+(?:"([^"]*)"|`([^`]*)`|\'([^\']*)\')/iu';
        if (preg_match($pattern, $intent, $m) !== 1) {
            return null;
        }
        $command = null;
        for ($i = 1; $i <= 3; $i++) {
            if (array_key_exists($i, $m) && $m[$i] !== '') {
                $command = $m[$i];
                break;
            }
        }
        $expected = null;
        for ($i = 4; $i <= 6; $i++) {
            if (array_key_exists($i, $m) && $m[$i] !== '') {
                $expected = $m[$i];
                break;
            }
        }
        if (! is_string($command) || ! is_string($expected)) {
            return null;
        }

        return $this->normalizeAtom([
            'type' => 'command_output',
            'command' => $command,
            'output_contains' => $expected,
            'exit_code' => 0,
        ]);
    }

    /**
     * @param  mixed  $value
     * @return mixed
     */
    private function literal(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }
        $trimmed = trim($value);
        if ((str_starts_with($trimmed, "'") && str_ends_with($trimmed, "'"))
            || (str_starts_with($trimmed, '"') && str_ends_with($trimmed, '"'))
            || (str_starts_with($trimmed, '`') && str_ends_with($trimmed, '`'))) {
            return substr($trimmed, 1, -1);
        }
        $lower = strtolower($trimmed);

        return match (true) {
            $lower === 'true' => true,
            $lower === 'false' => false,
            $lower === 'null' => null,
            preg_match('/^-?\d+$/', $trimmed) === 1 => (int) $trimmed,
            preg_match('/^-?\d+\.\d+$/', $trimmed) === 1 => (float) $trimmed,
            default => $trimmed,
        };
    }

    /**
     * @param  mixed  $values
     * @return list<mixed>
     */
    private function literalList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_map(fn (mixed $v): mixed => $this->literal($v), $values));
    }

    /**
     * @return array{class:?string,constructor_required_params:int}
     */
    private function classMetadata(string $file): array
    {
        $src = (string) @file_get_contents($file);
        $parser = (new ParserFactory)->createForHostVersion();
        $finder = new NodeFinder;
        try {
            $stmts = $parser->parse($src) ?? [];
        } catch (Throwable) {
            return ['class' => null, 'constructor_required_params' => 0];
        }

        $namespace = null;
        $ns = $finder->findFirstInstanceOf($stmts, Node\Stmt\Namespace_::class);
        if ($ns instanceof Node\Stmt\Namespace_ && $ns->name !== null) {
            $namespace = $ns->name->toString();
        }
        $class = $finder->findFirstInstanceOf($stmts, Node\Stmt\Class_::class);
        if (! $class instanceof Node\Stmt\Class_ || $class->name === null) {
            return ['class' => null, 'constructor_required_params' => 0];
        }

        $required = 0;
        foreach ($class->getMethods() as $method) {
            if ($method->name->toString() !== '__construct') {
                continue;
            }
            foreach ($method->params as $param) {
                if ($param->default === null && ! $param->variadic) {
                    $required++;
                }
            }
        }

        return [
            'class' => ($namespace !== null ? $namespace.'\\' : '').$class->name->toString(),
            'constructor_required_params' => $required,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $atoms
     */
    private function frozenTestContent(string $testPath, string $target, string $class, array $atoms): string
    {
        return $this->frozenTestSourceRenderer()->frozenTestContent($testPath, $target, $class, $atoms);
    }

    private function frozenTestSourceRenderer(): AtlasLoopFrozenTestSourceRenderer
    {
        return $this->frozenTestSourceRenderer ??= (app()->bound(AtlasLoopFrozenTestSourceRenderer::class)
            ? app(AtlasLoopFrozenTestSourceRenderer::class)
            : new AtlasLoopFrozenTestSourceRenderer());
    }


    /**
     * @param  array<string,mixed>  $payload
     * @return list<string>
     */
    private function allowedFiles(array $payload, string $target): array
    {
        $files = AiStringListNormalizer::trimmedStrings($payload['allowed_files'] ?? []);
        if ($files === []) {
            $files = [$target];
        }

        return AiStringListNormalizer::uniqueStrings($files);
    }

    /**
     * @param  array<string,mixed>  $compiledPayload
     * @return array<string,mixed>
     */
    private function redPreflight(string $repoRoot, string $intent, array $compiledPayload): array
    {
        try {
            [$task, $cleanup] = $this->frameworkMaterializer->materializeBase($repoRoot, $intent, $compiledPayload);
            $workspace = (string) ($task['base_workspace'] ?? '');
            $results = [];
            $allGreen = true;
            foreach (AiStringListNormalizer::trimmedStrings(data_get($compiledPayload, 'acceptance.commands', [])) as $command) {
                $result = $this->runCommand($command, $workspace, (int) data_get($compiledPayload, 'acceptance.timeout_seconds', 120));
                $results[] = $result;
                if (! (bool) ($result['passed'] ?? false)) {
                    $allGreen = false;
                    break;
                }
            }
            $cleanup();

            return [
                'schema_version' => self::SCHEMA.'.red_preflight.v1',
                'status' => $allGreen ? 'green_on_baseline' : 'red',
                'baseline_red' => ! $allGreen,
                'command_results' => $results,
            ];
        } catch (Throwable $e) {
            return [
                'schema_version' => self::SCHEMA.'.red_preflight.v1',
                'status' => 'blocked',
                'baseline_red' => false,
                'error' => mb_substr($e->getMessage(), 0, 300),
            ];
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $packet
     * @return array<string,mixed>
     */
    private function runVerifierRefuters(string $repoRoot, array $packet, array $payload): array
    {
        $commands = AiStringListNormalizer::uniqueMergedStrings(
            AiStringListNormalizer::trimmedStrings($payload['verifier_refuter_commands'] ?? []),
            AiStringListNormalizer::trimmedStrings($payload['spec_refuter_commands'] ?? []),
        );
        $required = $this->requiredRefuters($payload, count($commands));
        $timeout = max(1, (int) ($payload['verifier_refuter_timeout_seconds'] ?? 120));
        $packetPath = tempnam(sys_get_temp_dir(), 'atlas-intent-verifier-');
        if ($packetPath === false) {
            throw new RuntimeException('intent verifier factory: cannot create refuter packet');
        }

        file_put_contents($packetPath, json_encode($packet, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $verdicts = [];
        try {
            foreach ($commands as $index => $command) {
                $process = Process::fromShellCommandline($command, $repoRoot, AtlasLoopHermeticCommandEnvironment::forAcceptance([
                    'ATLAS_INTENT_VERIFIER_PACKET' => $packetPath,
                    'ATLAS_INTENT_VERIFIER_REFUTER_INDEX' => (string) ($index + 1),
                ]), null, (float) $timeout);
                $process->run();
                $verdicts[] = $this->refuterVerdict($index + 1, $command, $process);
            }
        } finally {
            @unlink($packetPath);
        }

        $refuted = count(array_filter($verdicts, static fn (array $v): bool => (bool) ($v['refuted'] ?? false)));

        return [
            'schema_version' => self::SCHEMA.'.verifier_refuters.v1',
            'required' => $required,
            'configured' => count($commands),
            'executed' => count($verdicts),
            'met_required' => count($verdicts) >= $required,
            'refuted' => $refuted,
            'verdicts' => $verdicts,
            'fail_closed' => true,
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function requiredRefuters(array $payload, int $configured): int
    {
        foreach (['verifier_refuters_required', 'spec_refuters_required'] as $key) {
            if (isset($payload[$key]) && is_numeric($payload[$key])) {
                return max(0, (int) $payload[$key]);
            }
        }

        return $configured;
    }

    /**
     * @return array<string,mixed>
     */
    private function refuterVerdict(int $index, string $command, Process $process): array
    {
        $stdout = trim((string) $process->getOutput());
        $stderr = trim((string) $process->getErrorOutput());
        $json = $stdout !== '' ? json_decode($stdout, true) : null;
        $parsed = is_array($json);
        $exit = $process->getExitCode() ?? 1;
        $refuted = $exit !== 0;
        $reason = $refuted ? 'command_exit_'.$exit : 'no_refutation';
        if ($parsed) {
            $refuted = (bool) ($json['refuted'] ?? $refuted);
            $reason = trim((string) ($json['reason'] ?? $json['detail'] ?? $reason)) ?: $reason;
        }

        return [
            'index' => $index,
            'command' => $command,
            'exit_code' => $exit,
            'refuted' => $refuted,
            'reason' => $reason,
            'stdout' => $this->excerpt($stdout),
            'stderr' => $this->excerpt($stderr),
            'parsed_json' => $parsed,
        ];
    }

    /**
     * @return array{command:string,passed:bool,exit_code:int,stdout:string,stderr:string}
     */
    private function runCommand(string $command, string $workspace, int $timeout): array
    {
        $process = Process::fromShellCommandline(
            $command,
            $workspace,
            AtlasLoopHermeticCommandEnvironment::forAcceptance(),
            null,
            (float) $timeout,
        );
        $process->run();

        return [
            'command' => $command,
            'passed' => $process->isSuccessful(),
            'exit_code' => $process->getExitCode() ?? 1,
            'stdout' => $this->excerpt($process->getOutput()),
            'stderr' => $this->excerpt($process->getErrorOutput()),
        ];
    }

    private function defaultTestPath(string $target, string $intent, array $atoms): string
    {
        $hash = substr($this->stableHash([$target, $intent, $atoms]), 0, 12);

        return 'tests/Feature/Loop/IntentVerifier/'.$hash.'.php';
    }

    private function stableHash(array $payload): string
    {
        return hash('sha256', (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function normalizeRelative(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        $path = ltrim($path, '/');
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                return '';
            }
            $parts[] = $part;
        }

        return implode('/', $parts);
    }

    private function excerpt(string $text): string
    {
        $text = trim($text);

        return mb_strlen($text) > 1200 ? mb_substr($text, 0, 1200).'...' : $text;
    }
}
