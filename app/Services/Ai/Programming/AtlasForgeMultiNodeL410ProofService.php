<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming;

use App\Console\Commands\AtlasLoopMorningDigestCommand;
use App\Services\Ai\AtlasForge\AtlasForgeParallelDurableCoordinatorService;
use App\Services\Ai\AutonomousEvolution\AtlasLoopMorningDigestService;
use App\Services\Ai\Obra\AtlasObraReceiptStamp;
use App\Services\Ai\Programming\Forge\ForgeMultiAgentScheduleCanon;
use App\Services\Ai\Programming\Forge\ForgeMultiAgentSchedulerService;
use Illuminate\Support\Carbon;

/**
 * L4-10 proof harness.
 *
 * This report intentionally never runs providers. It composes the existing Forge
 * multi-agent planner with the L4-6 digest and accepts green only from an
 * explicit real execution receipt.
 */
final class AtlasForgeMultiNodeL410ProofService
{
    public const SCHEMA_VERSION = 'atlas.forge.l4_10.multi_node_proof.v1';

    public const REAL_RECEIPT_SCHEMA_VERSION = 'atlas.forge.l4_10.real_execution_receipt.v1';

    private const ITEM_ID = 'L4-6';

    private const REQUIRED_PROVIDER = 'hermes_cli';

    private const DONE_RECEIPT_STATUSES = ['done', 'completed', 'certified', 'success', 'succeeded'];

    private const REAL_EXECUTION_MODES = [
        'operator_submitted_real_obra_run',
        'real_multi_node_obra_run',
        'real_provider_obra_run',
    ];

    private const REQUIRED_L4_6_MATERIAL_FILES = [
        'app/Services/Ai/AutonomousEvolution/AtlasLoopMorningDigestService.php',
        'app/Console/Commands/AtlasLoopMorningDigestCommand.php',
        'tests/Feature/Loop/AtlasLoopMorningDigestTest.php',
    ];

    public function __construct(
        private readonly ForgeMultiAgentSchedulerService $scheduler,
        private readonly AtlasForgeParallelDurableCoordinatorService $parallelDurable,
        private readonly AtlasLoopMorningDigestService $morningDigest,
        // L4-10 — verifies the receipt's executor self-stamp (HMAC over the load-bearing
        // facts). Default-constructed so the proof always provenance-verifies when a
        // receipt declares itself executor-stamped. A hand-edit invalidates the signature.
        private readonly ?AtlasObraReceiptStamp $receiptStamp = null,
    ) {}

    /**
     * @param  array{evidence_path?:string|null,hours?:int}  $options
     * @return array<string,mixed>
     */
    public function report(array $options = []): array
    {
        $hours = max(1, min(168, (int) ($options['hours'] ?? 24)));
        $packets = $this->workPackets();
        $expectedFiles = array_values(array_unique(array_merge(...array_map(
            static fn (array $packet): array => (array) ($packet['expected_files'] ?? []),
            $packets,
        ))));

        $schedule = $this->scheduler->plan(
            taskSummary: 'Fable Lista 4 L4-10: six-node Forge Obra delivers L4-6 morning digest panel',
            workPackets: $packets,
            riskBand: ForgeMultiAgentScheduleCanon::RISK_CRITICAL,
            options: [
                'verification_required' => true,
                'reviewer_required' => true,
                'mission_id' => 'fable-lista-4-l4-10',
                'work_order_id' => 'fable-lista-4-l4-6-morning-digest',
            ],
        );

        $assignment = $this->parallelDurable->propose(
            tickets: $this->ticketsFor($packets),
            agents: $this->agentsFor(count($packets)),
            existingReservations: [],
        );

        $digest = $this->morningDigest->digest($hours);
        $deliveredItem = $this->deliveredItem($digest, $expectedFiles);

        $evidence = $this->loadEvidence($this->stringOrNull($options['evidence_path'] ?? null));
        $validation = $this->validateEvidence($evidence, $expectedFiles);
        $certified = (bool) ($validation['certified'] ?? false);
        $blockers = $certified ? [] : array_values(array_unique((array) ($validation['blockers'] ?? [])));

        $status = match (true) {
            $certified => 'certified',
            ($evidence['status'] ?? null) === 'loaded' => 'real_execution_evidence_rejected',
            default => 'real_execution_blocked',
        };

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'certified' => $certified,
            'generated_at' => Carbon::now()->toIso8601String(),
            'item' => [
                'id' => 'L4-10',
                'title' => 'Obra Forge multi-node real delivers L4-6',
                'target_delivered_item' => self::ITEM_ID,
            ],
            'planned_obra' => [
                'work_node_count' => count($packets),
                'expected_node_range' => ['min' => 6, 'max' => 10],
                'work_packets' => $packets,
                'schedule' => $schedule,
                'parallel_durable_assignment' => $assignment,
            ],
            'delivered_item' => $deliveredItem + [
                'delivered_by_real_multi_node_obra' => $certified,
                'real_obra_evidence_path' => $evidence['path'] ?? null,
            ],
            'real_execution_evidence' => $evidence,
            'validation' => $validation,
            'blockers' => $blockers,
            'claim_policy' => [
                'provider_dispatches_now' => false,
                'external_provider_call_made_by_report' => false,
                'real_provider_call_required_for_green' => true,
                'synthetic_certification_allowed' => false,
                'fixture_or_simulation_receipt_accepted' => false,
                'completion_claim_allowed' => $certified,
            ],
            'commands_next' => [
                'prove_l4_6_digest' => 'php artisan atlas:loop:morning-digest --json',
                'run_real_obra_when_operator_authorizes_spend' => 'php artisan atlas:obra:run <obra-plan-id> --provider=hermes_cli --integrated-check="/opt/homebrew/bin/php artisan atlas:loop:morning-digest --json" --json',
                'certify_real_receipt' => 'php artisan atlas:forge:l4-10-proof --evidence=<receipt.json> --json --strict',
            ],
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function workPackets(): array
    {
        return [
            [
                'packet_id' => 'l4-6-digest-service',
                'objective' => 'Build the 24h Loop morning digest read model.',
                'expected_files' => ['app/Services/Ai/AutonomousEvolution/AtlasLoopMorningDigestService.php'],
                'dependencies' => [],
                'suggested_tests' => ['tests/Feature/Loop/AtlasLoopMorningDigestTest.php'],
            ],
            [
                'packet_id' => 'l4-6-digest-cli',
                'objective' => 'Expose the digest as a one-command operator answer.',
                'expected_files' => ['app/Console/Commands/AtlasLoopMorningDigestCommand.php'],
                'dependencies' => ['l4-6-digest-service'],
                'suggested_tests' => ['tests/Feature/Loop/AtlasLoopMorningDigestTest.php'],
            ],
            [
                'packet_id' => 'l4-6-keepalive-ledger',
                'objective' => 'Record keepalive respawn/revive events into a bounded digest ledger.',
                'expected_files' => ['app/Console/Commands/AtlasLoopKeepaliveCommand.php'],
                'dependencies' => ['l4-6-digest-service'],
                'suggested_tests' => ['tests/Feature/Loop/AtlasLoopKeepaliveReviveStarvedTest.php'],
            ],
            [
                'packet_id' => 'l4-6-config-schedule',
                'objective' => 'Activate and schedule the morning digest with explicit flags.',
                'expected_files' => ['config/atlas.php', 'routes/console.php', 'bootstrap/app.php'],
                'dependencies' => ['l4-6-digest-cli'],
                'suggested_tests' => ['tests/Feature/Loop/AtlasLoopMorningDigestTest.php'],
            ],
            [
                'packet_id' => 'l4-6-frozen-tests',
                'objective' => 'Freeze the digest and keepalive ledger contracts.',
                'expected_files' => ['tests/Feature/Loop/AtlasLoopMorningDigestTest.php'],
                'dependencies' => ['l4-6-digest-service', 'l4-6-digest-cli', 'l4-6-keepalive-ledger'],
                'suggested_tests' => ['tests/Feature/Loop/AtlasLoopMorningDigestTest.php'],
            ],
            [
                'packet_id' => 'l4-6-ledger-capture',
                'objective' => 'Capture the L4-6/L4-10 status and evidence in the campaign ledger.',
                'expected_files' => ['docs/fable-lista-4-14-itens.md'],
                'dependencies' => ['l4-6-frozen-tests'],
                'suggested_tests' => ['php artisan atlas:forge:l4-10-proof --json'],
            ],
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $packets
     * @return list<array<string,mixed>>
     */
    private function ticketsFor(array $packets): array
    {
        $tickets = [];
        foreach ($packets as $index => $packet) {
            $tickets[] = [
                'ticket_id' => (string) $packet['packet_id'],
                'locked_paths' => array_values((array) ($packet['expected_files'] ?? [])),
                'priority' => 100 - $index,
            ];
        }

        return $tickets;
    }

    /**
     * @return list<array{agent_id:string,available:bool}>
     */
    private function agentsFor(int $count): array
    {
        $agents = [];
        for ($i = 1; $i <= $count; $i++) {
            $agents[] = [
                'agent_id' => sprintf('l4-10-worker-%02d', $i),
                'available' => true,
            ];
        }

        return $agents;
    }

    /**
     * @param  array<string,mixed>  $digest
     * @param  list<string>  $expectedFiles
     * @return array<string,mixed>
     */
    private function deliveredItem(array $digest, array $expectedFiles): array
    {
        $commandAvailable = class_exists(AtlasLoopMorningDigestCommand::class);

        return [
            'id' => self::ITEM_ID,
            'local_digest_status' => (string) ($digest['status'] ?? 'unknown'),
            'local_digest_command_available' => $commandAvailable,
            'local_digest_one_command_answer' => data_get($digest, 'claim_policy.one_command_answer'),
            'local_digest_headline' => (string) ($digest['headline'] ?? ''),
            'expected_files' => $expectedFiles,
            'delivered_by_local_implementation' => $commandAvailable && ($digest['status'] ?? null) === 'ok',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function loadEvidence(?string $path): array
    {
        if ($path === null) {
            return [
                'schema_version' => self::REAL_RECEIPT_SCHEMA_VERSION,
                'status' => 'missing',
                'path' => null,
                'payload' => null,
            ];
        }

        if (! is_file($path)) {
            return [
                'schema_version' => self::REAL_RECEIPT_SCHEMA_VERSION,
                'status' => 'missing',
                'path' => $path,
                'payload' => null,
                'load_error' => 'file_not_found',
            ];
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            return [
                'schema_version' => self::REAL_RECEIPT_SCHEMA_VERSION,
                'status' => 'invalid',
                'path' => $path,
                'payload' => null,
                'load_error' => 'invalid_json',
            ];
        }

        return [
            'schema_version' => $decoded['schema_version'] ?? self::REAL_RECEIPT_SCHEMA_VERSION,
            'status' => 'loaded',
            'path' => $path,
            'payload' => $decoded,
        ];
    }

    /**
     * @param  array<string,mixed>  $evidence
     * @param  list<string>  $expectedFiles
     * @return array<string,mixed>
     */
    private function validateEvidence(array $evidence, array $expectedFiles): array
    {
        $payload = is_array($evidence['payload'] ?? null) ? $evidence['payload'] : [];
        if (($evidence['status'] ?? null) !== 'loaded') {
            return [
                'certified' => false,
                'blockers' => [
                    'real_provider_obra_run_evidence_missing',
                    'kill_resume_live_evidence_missing',
                ],
                'checks' => [],
            ];
        }

        $checks = [];
        $blockers = [];

        $schemaVersion = $this->firstString($payload, ['schema_version']);
        $checks['schema_version'] = $schemaVersion;
        if ($schemaVersion !== self::REAL_RECEIPT_SCHEMA_VERSION) {
            $blockers[] = 'real_receipt_schema_version_mismatch';
        }

        $receiptStatus = $this->firstString($payload, ['status', 'result.status', 'run.status']);
        $checks['receipt_status'] = $receiptStatus;
        if (! $this->receiptStatusIsDone($receiptStatus)) {
            $blockers[] = 'real_receipt_done_status_missing';
        }

        $certified = $this->truthyFirst($payload, ['certified', 'result.certified', 'obra.certified']);
        $checks['certified_flag'] = $certified;
        if (! $certified) {
            $blockers[] = 'real_obra_not_certified';
        }

        $nodeCount = $this->nodeCount($payload);
        $checks['node_count'] = $nodeCount;
        if ($nodeCount < 6 || $nodeCount > 10) {
            $blockers[] = 'node_count_out_of_6_to_10_range';
        }

        $providerCall = $this->truthyFirst($payload, [
            'provider_calls_made',
            'provider_called',
            'real_provider_call',
            'external_provider_call',
            'evidence.provider_calls_made',
            'provider.provider_calls_made',
        ]);
        $checks['real_provider_call'] = $providerCall;
        if (! $providerCall) {
            $blockers[] = 'real_provider_call_missing';
        }

        $provider = $this->firstString($payload, [
            'provider.name',
            'provider.provider',
            'provider_id',
            'runtime.provider',
            'run.provider',
            'provider',
        ]);
        $checks['provider'] = $provider;
        if (! $this->isHermesProvider($provider)) {
            $blockers[] = 'hermes_cli_provider_evidence_missing';
        }

        $model = $this->firstString($payload, ['provider.model', 'model', 'provider_model', 'runtime.model']);
        $checks['model'] = $model;
        if ($model === null || ! str_contains(strtolower($model), 'gpt-5.5')) {
            $blockers[] = 'gpt_5_5_model_evidence_missing';
        }

        $obraRef = $this->firstString($payload, ['obra_id', 'obra.uuid', 'obra.id', 'plan_id', 'run.obra_id']);
        $checks['obra_ref'] = $obraRef;
        if ($this->isFakeOrMissingObraRef($obraRef)) {
            $blockers[] = 'real_obra_ref_missing_or_fake';
        }

        $executionMode = $this->firstString($payload, ['execution_mode', 'mode', 'runtime.execution_mode', 'run.mode']);
        $checks['execution_mode'] = $executionMode;
        if ($this->hasTemplateOrPlaceholderMarker($payload)) {
            $blockers[] = 'template_or_placeholder_evidence_not_allowed';
        }
        if ($this->hasForbiddenIdentityMarker($payload) || ! $this->executionModeIsAllowedReal($executionMode)) {
            $blockers[] = 'non_real_or_fixture_execution_evidence';
        }

        $killExercised = $this->truthyFirst($payload, ['kill_resume.kill_exercised', 'kill_exercised']);
        $resumeExercised = $this->truthyFirst($payload, ['kill_resume.resume_exercised', 'resume_exercised']);
        $killResumeRefs = $this->killResumeEvidenceRefs($payload);
        $executorResumed = $this->truthyFirst($payload, [
            'resumed',
            'resume.resumed',
            'runtime.resumed',
            'obra_runtime.resumed',
            'executor_resume.resumed',
            'result.resumed',
        ]);
        $resumeCount = $this->firstInt($payload, [
            'resume_count',
            'resume.count',
            'runtime.resume_count',
            'obra_runtime.resume_count',
            'executor_resume.resume_count',
            'result.resume_count',
        ]) ?? 0;
        $checks['kill_resume'] = [
            'kill_exercised' => $killExercised,
            'resume_exercised' => $resumeExercised,
            'evidence_refs' => $killResumeRefs,
            'executor_resumed' => $executorResumed,
            'resume_count' => $resumeCount,
        ];
        if (! $killExercised || ! $resumeExercised) {
            $blockers[] = 'kill_resume_live_evidence_missing';
        }
        if ($killResumeRefs === []) {
            $blockers[] = 'kill_resume_evidence_refs_missing';
        }
        if (count($killResumeRefs) < 2) {
            $blockers[] = 'kill_resume_separate_evidence_refs_missing';
        }
        if (! $executorResumed || $resumeCount < 1) {
            $blockers[] = 'obra_executor_resume_evidence_missing';
        }

        $deliveredItem = $this->firstString($payload, ['delivered_item_id', 'delivered_item', 'item.id', 'result.delivered_item_id']);
        $checks['delivered_item'] = $deliveredItem;
        if ($deliveredItem === null || ! str_contains(strtoupper($deliveredItem), self::ITEM_ID)) {
            $blockers[] = 'l4_6_delivered_item_evidence_missing';
        }

        $deliveredFiles = $this->pathList($payload['delivered_files'] ?? $payload['changed_files'] ?? data_get($payload, 'result.changed_files') ?? []);
        $checks['delivered_files'] = $deliveredFiles;
        $checks['delivered_files_intersection'] = array_values(array_intersect($deliveredFiles, $expectedFiles));
        if ($checks['delivered_files_intersection'] === []) {
            $blockers[] = 'l4_6_changed_files_evidence_missing';
        }
        $checks['required_l4_6_material_files_present'] = array_values(array_intersect(self::REQUIRED_L4_6_MATERIAL_FILES, $deliveredFiles));
        $checks['required_l4_6_material_files_missing'] = array_values(array_diff(self::REQUIRED_L4_6_MATERIAL_FILES, $deliveredFiles));
        if ($checks['required_l4_6_material_files_missing'] !== []) {
            $blockers[] = 'l4_6_material_files_evidence_missing';
        }

        $digestCommandPassed = $this->digestCommandPassed($payload);
        $checks['digest_command_passed'] = $digestCommandPassed;
        if (! $digestCommandPassed) {
            $blockers[] = 'l4_6_digest_command_proof_missing';
        }

        // --- L4-10 — PROVENANCE: the receipt MUST be an executor SELF-STAMPED OUTPUT,
        //     not a hand-assembled file. The executor emits the receipt with an HMAC
        //     over its load-bearing facts (certified / provider / node_count / step
        //     commits / main_untouched); the proof recomputes that HMAC from the
        //     receipt's OWN fields and rejects any receipt whose signature does not
        //     match. A hand-edit of ANY sealed fact invalidates the stamp → rejected.
        $provenance = $this->verifyExecutorProvenance($payload);
        $checks['executor_provenance'] = $provenance;
        if (! (bool) ($provenance['stamped'] ?? false)) {
            // No executor self-stamp at all — a hand-assembled receipt is no longer
            // accepted (the L4-10 inversion: the proof reads an executor OUTPUT).
            $blockers[] = 'executor_stamped_receipt_required';
        } elseif (! (bool) ($provenance['verified'] ?? false)) {
            // Marked executor-stamped but the signature does not recompute — tampered.
            $blockers[] = 'executor_receipt_provenance_invalid';
        }

        return [
            'certified' => $blockers === [],
            'blockers' => array_values(array_unique($blockers)),
            'checks' => $checks,
        ];
    }

    /**
     * L4-10 — verify the receipt's executor self-stamp. The receipt is provenance-hardened
     * when its `provenance.executor_stamped` block carries an HMAC over the load-bearing
     * facts that recomputes from the receipt's OWN fields (so any hand-edit is rejected).
     *
     * The signed body the executor stamps lives at the receipt's TOP LEVEL; when a real
     * run wraps that signed core inside a richer live receipt (kill/resume + command
     * results), the signed core is carried under `executor_receipt` — verify whichever
     * is present (top-level core first, then the nested core).
     *
     * @param  array<string,mixed>  $payload
     * @return array{stamped:bool,verified:bool,reason:?string,source:?string}
     */
    private function verifyExecutorProvenance(array $payload): array
    {
        $stamp = $this->receiptStamp ?? new AtlasObraReceiptStamp;

        foreach (['<self>' => $payload, 'executor_receipt' => $payload['executor_receipt'] ?? null] as $source => $candidate) {
            if (! is_array($candidate)) {
                continue;
            }
            $provenance = $candidate['provenance'] ?? null;
            if (! is_array($provenance) || ($provenance['executor_stamped'] ?? null) !== true) {
                continue;
            }

            $verdict = $stamp->verify($candidate);

            return [
                'stamped' => true,
                'verified' => (bool) ($verdict['verified'] ?? false),
                'reason' => $verdict['reason'] ?? null,
                'source' => $source,
            ];
        }

        return ['stamped' => false, 'verified' => false, 'reason' => 'executor_stamp_absent', 'source' => null];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function nodeCount(array $payload): int
    {
        $count = $this->firstInt($payload, ['node_count', 'nodes.count', 'obra.node_count', 'run.node_count']);
        if ($count !== null) {
            return $count;
        }

        return is_array($payload['nodes'] ?? null) ? count($payload['nodes']) : 0;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  list<string>  $paths
     */
    private function truthyFirst(array $payload, array $paths): bool
    {
        foreach ($paths as $path) {
            $value = data_get($payload, $path);
            if ($value === true || $value === 1 || $value === '1') {
                return true;
            }
            if (is_string($value) && in_array(strtolower(trim($value)), ['true', 'yes'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  list<string>  $paths
     */
    private function firstString(array $payload, array $paths): ?string
    {
        foreach ($paths as $path) {
            $value = data_get($payload, $path);
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  list<string>  $paths
     */
    private function firstInt(array $payload, array $paths): ?int
    {
        foreach ($paths as $path) {
            $value = data_get($payload, $path);
            if (is_int($value)) {
                return $value;
            }
            if (is_string($value) && ctype_digit($value)) {
                return (int) $value;
            }
        }

        return null;
    }

    private function receiptStatusIsDone(?string $status): bool
    {
        return in_array($this->normalizeIdentifier($status), self::DONE_RECEIPT_STATUSES, true);
    }

    private function isHermesProvider(?string $provider): bool
    {
        return $this->normalizeIdentifier($provider) === self::REQUIRED_PROVIDER;
    }

    private function executionModeIsAllowedReal(?string $mode): bool
    {
        return in_array($this->normalizeIdentifier($mode), self::REAL_EXECUTION_MODES, true);
    }

    private function normalizeIdentifier(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        $normalized = strtolower(trim($value));
        $normalized = (string) preg_replace('/[^a-z0-9]+/', '_', $normalized);

        return trim($normalized, '_');
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function hasForbiddenIdentityMarker(array $payload): bool
    {
        foreach ([
            'execution_mode',
            'mode',
            'receipt_type',
            'run_kind',
            'source',
            'provider.provider',
            'provider.model',
            'run_id',
            'obra_id',
            'plan_id',
        ] as $path) {
            $value = data_get($payload, $path);
            if (! is_string($value)) {
                continue;
            }
            $value = strtolower($value);
            foreach (['synthetic', 'fixture', 'fake', 'mock', 'placeholder', 'test_double', 'local_fake'] as $marker) {
                if (str_contains($value, $marker)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function hasTemplateOrPlaceholderMarker(array $payload): bool
    {
        if ($this->truthyFirst($payload, ['template_only', 'template', 'is_template'])) {
            return true;
        }

        return $this->containsPlaceholderString($payload);
    }

    private function containsPlaceholderString(mixed $value): bool
    {
        if (is_string($value)) {
            return preg_match('/<[^<>]+>/', $value) === 1;
        }

        if (! is_array($value)) {
            return false;
        }

        foreach ($value as $child) {
            if ($this->containsPlaceholderString($child)) {
                return true;
            }
        }

        return false;
    }

    private function isFakeOrMissingObraRef(?string $ref): bool
    {
        if ($ref === null || strlen(trim($ref)) < 6) {
            return true;
        }

        $ref = strtolower(trim($ref));
        if (preg_match('/^0+$/', str_replace(['-', '_'], '', $ref)) === 1) {
            return true;
        }

        foreach (['fake', 'fixture', 'synthetic', 'placeholder', 'test_double'] as $marker) {
            if (str_contains($ref, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return list<string>
     */
    private function killResumeEvidenceRefs(array $payload): array
    {
        $refs = $this->pathList(data_get($payload, 'kill_resume.evidence_refs') ?? []);
        foreach (['kill_resume.kill_event_id', 'kill_resume.resume_event_id', 'kill_resume.kill_receipt', 'kill_resume.resume_receipt'] as $path) {
            $value = data_get($payload, $path);
            if (is_string($value) && trim($value) !== '') {
                $refs[] = trim($value);
            }
        }

        return array_values(array_unique($refs));
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function digestCommandPassed(array $payload): bool
    {
        $direct = data_get($payload, 'delivered_item.command_exit_code');
        $directCommand = $this->firstString($payload, ['delivered_item.command', 'digest_command.command', 'morning_digest.command']);
        if (($direct === 0 || $direct === '0') && $this->isMorningDigestCommand($directCommand)) {
            return true;
        }

        $assoc = data_get($payload, 'command_results.morning_digest.exit_code');
        if ($assoc === 0 || $assoc === '0') {
            return true;
        }

        $commands = data_get($payload, 'command_results');
        if (! is_array($commands)) {
            $commands = data_get($payload, 'validation_commands');
        }
        if (! is_array($commands)) {
            return false;
        }

        foreach ($commands as $command) {
            if (! is_array($command)) {
                continue;
            }
            $text = (string) ($command['command'] ?? '');
            $exit = $command['exit_code'] ?? null;
            if ($this->isMorningDigestCommand($text) && ($exit === 0 || $exit === '0')) {
                return true;
            }
        }

        return false;
    }

    private function isMorningDigestCommand(?string $command): bool
    {
        return $command !== null && str_contains($command, 'atlas:loop:morning-digest');
    }

    /**
     * @return list<string>
     */
    private function pathList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $entry) {
            if (is_string($entry) && trim($entry) !== '') {
                $out[] = trim($entry);
                continue;
            }
            if (! is_array($entry)) {
                continue;
            }
            foreach (['path', 'file', 'target_path', 'ref'] as $key) {
                if (is_string($entry[$key] ?? null) && trim((string) $entry[$key]) !== '') {
                    $out[] = trim((string) $entry[$key]);
                    break;
                }
            }
        }

        return array_values(array_unique($out));
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
