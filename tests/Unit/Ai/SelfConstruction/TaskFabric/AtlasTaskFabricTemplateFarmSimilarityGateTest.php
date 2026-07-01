<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskFabric;

use App\Services\Ai\SelfConstruction\TaskFabric\AtlasTaskFabricTemplateFarmSimilarityGate;
use Tests\TestCase;

final class AtlasTaskFabricTemplateFarmSimilarityGateTest extends TestCase
{
    private function svc(): AtlasTaskFabricTemplateFarmSimilarityGate
    {
        return new AtlasTaskFabricTemplateFarmSimilarityGate;
    }

    private function packet(string $objective, array $acceptance = [], array $files = []): array
    {
        return [
            'objective' => $objective,
            'acceptance_criteria' => $acceptance,
            'allowed_files' => $files,
        ];
    }

    // ── single packet ─────────────────────────────────────────────────────────

    public function test_single_packet_gives_zero_similarity(): void
    {
        $r = $this->svc()->assess([
            $this->packet('Implement AtlasFooBar to compute something useful.'),
        ]);

        $this->assertEqualsWithDelta(0.0, $r['similarity_score'], 0.001);
        $this->assertFalse($r['blocking']);
        $this->assertSame([], $r['repeated_stems']);
    }

    // ── noun-substitution disguised template ────────────────────────────────────

    public function test_batch_differing_only_by_noun_substitution_is_blocked(): void
    {
        $acceptance = ['/opt/homebrew/bin/php artisan test --filter=FooTest exits 0'];
        $files = ['app/Services/Foo.php'];

        $r = $this->svc()->assess([
            $this->packet('implement service to validate user accounts thoroughly', $acceptance, $files),
            $this->packet('implement service to validate order accounts thoroughly', $acceptance, $files),
            $this->packet('implement service to validate ticket accounts thoroughly', $acceptance, $files),
        ]);

        $this->assertNotEmpty($r['repeated_noun_substitution_templates']);
        $this->assertTrue($r['blocking']);
    }

    public function test_diverse_batch_touching_distinct_layers_is_not_blocked(): void
    {
        $r = $this->svc()->assess([
            $this->packet(
                'harden the queue repository so leases never leak across workers',
                ['/opt/homebrew/bin/php artisan test --filter=QueueRepoTest exits 0'],
                ['app/Services/Queue/Repository.php'],
            ),
            $this->packet(
                'teach the drift detector to flag stale capability maps automatically',
                ['vendor/bin/phpunit --filter=DriftDetectorTest'],
                ['app/Services/Drift/Detector.php'],
            ),
            $this->packet(
                'extend the console command to print a formatted proof report',
                ['/opt/homebrew/bin/php artisan test --filter=ProofReportCommandTest exits 0'],
                ['app/Console/Commands/ProofReportCommand.php'],
            ),
        ]);

        $this->assertFalse($r['blocking']);
    }

    // ── template farm detection ───────────────────────────────────────────────

    public function test_identical_objectives_produce_repeated_stems(): void
    {
        $sharedStem = 'implement to compute a score and return a result';
        $r = $this->svc()->assess([
            $this->packet("Implement AtlasFooBar to compute a score and return a result"),
            $this->packet("Implement AtlasBazQux to compute a score and return a result"),
            $this->packet("Implement AtlasZapWow to compute a score and return a result"),
        ]);

        $this->assertNotEmpty($r['repeated_stems']);
        $this->assertGreaterThan(0.0, $r['similarity_score']);
    }

    public function test_high_stem_overlap_triggers_blocking(): void
    {
        // 3/3 packets with same stem → score = 1.0 → blocking
        $packets = [];
        for ($i = 0; $i < 3; $i++) {
            $packets[] = $this->packet(
                "Implement AtlasVariant{$i} to evaluate the packet and return a score for the task",
                ['the gate must reject packets that fail validation and return a reason'],
            );
        }

        $r = $this->svc()->assess($packets);

        $this->assertTrue($r['blocking']);
        $this->assertGreaterThanOrEqual(AtlasTaskFabricTemplateFarmSimilarityGate::BLOCKING_THRESHOLD, $r['similarity_score']);
    }

    public function test_repeated_acceptance_fragments_listed(): void
    {
        $sharedCriterion = 'the implementation must be pure and must not call external services';
        $r = $this->svc()->assess([
            $this->packet('Implement AtlasFoo for discovery.', [$sharedCriterion]),
            $this->packet('Implement AtlasBar for origination.', [$sharedCriterion]),
        ]);

        $this->assertNotEmpty($r['repeated_acceptance_fragments']);
    }

    // ── diverse batch allowed ─────────────────────────────────────────────────

    public function test_diverse_batch_with_distinct_objectives_not_blocked(): void
    {
        $r = $this->svc()->assess([
            $this->packet(
                'Implement AtlasFoo to detect gate holes in the certification pipeline',
                ['given a gate report the system must surface missing gates'],
            ),
            $this->packet(
                'Implement AtlasBar to compress evidence records using adaptive sampling',
                ['given an evidence set the compressor must reduce size by thirty percent'],
            ),
            $this->packet(
                'Implement AtlasBaz to route stale capabilities to the retirement queue',
                ['given a capability with age above threshold the router must emit retire action'],
            ),
        ]);

        $this->assertFalse($r['blocking']);
    }

    public function test_macro_batch_shared_thesis_distinct_proof_paths_not_blocked(): void
    {
        // All say "implement to plan X" but each has a different failure mode / proof path
        $r = $this->svc()->assess([
            $this->packet(
                'Implement AtlasAlpha to plan the dependency resolution for missing owners',
                ['given a missing owner the system produces an unblock action with wiring evidence'],
            ),
            $this->packet(
                'Implement AtlasBeta to plan the merge schedule for redundant capabilities',
                ['given two overlapping organs the merger selects canonical owner by evidence delta'],
            ),
            $this->packet(
                'Implement AtlasGamma to plan the retirement path for zero-consumer capabilities',
                ['given an orphaned capability the planner emits retire with stall evidence attached'],
            ),
        ]);

        $this->assertFalse($r['blocking']);
    }

    // ── verdict fields ────────────────────────────────────────────────────────

    public function test_verdict_includes_all_required_fields(): void
    {
        $r = $this->svc()->assess([
            $this->packet('Implement AtlasFoo to do something.'),
            $this->packet('Implement AtlasBar to do something.'),
        ]);

        $this->assertArrayHasKey('similarity_score', $r);
        $this->assertArrayHasKey('repeated_stems', $r);
        $this->assertArrayHasKey('repeated_acceptance_fragments', $r);
        $this->assertArrayHasKey('blocking', $r);
        $this->assertArrayHasKey('packet_count', $r);
        $this->assertSame(2, $r['packet_count']);
    }

    public function test_schema_version_present(): void
    {
        $r = $this->svc()->assess([]);

        $this->assertSame(AtlasTaskFabricTemplateFarmSimilarityGate::SCHEMA, $r['schema_version']);
    }

    // ── allowed_files shape repetition ──────────────────────────────────────────

    public function test_repeated_allowed_files_shapes_detected_despite_distinct_basenames(): void
    {
        $packets = [];
        for ($i = 0; $i < 3; $i++) {
            $packets[] = $this->packet(
                "Implement AtlasVariant{$i} to handle case {$i} for the pipeline entirely",
                ["criterion for case {$i} number {$i}"],
                ["app/Services/Ai/SelfConstruction/AtlasVariant{$i}.php", "tests/Unit/Ai/SelfConstruction/AtlasVariant{$i}Test.php"],
            );
        }

        $r = $this->svc()->assess($packets);

        $this->assertNotEmpty($r['repeated_allowed_files_shapes']);
        $this->assertTrue($r['blocking']);
    }

    public function test_distinct_allowed_files_shapes_not_flagged(): void
    {
        $r = $this->svc()->assess([
            $this->packet('Implement AtlasFoo for X.', ['c1'], ['app/Services/Ai/AtlasFoo.php']),
            $this->packet('Implement AtlasBar for Y.', ['c2'], ['app/Console/Commands/AtlasBarCommand.php', 'config/atlas.php']),
            $this->packet('Implement AtlasBaz for Z.', ['c3'], ['database/migrations/2026_create_baz.php']),
        ]);

        $this->assertSame([], $r['repeated_allowed_files_shapes']);
    }

    public function test_missing_allowed_files_does_not_create_false_shape_repetition(): void
    {
        // Macro-batch style packets with NO allowed_files declared — must not collapse to a
        // shared "empty shape" repetition.
        $r = $this->svc()->assess([
            $this->packet('Implement AtlasAlpha to plan the dependency resolution for missing owners', ['c1']),
            $this->packet('Implement AtlasBeta to plan the merge schedule for redundant capabilities', ['c2']),
            $this->packet('Implement AtlasGamma to plan the retirement path for zero-consumer capabilities', ['c3']),
        ]);

        $this->assertSame([], $r['repeated_allowed_files_shapes']);
    }

    // ── proof path repetition ────────────────────────────────────────────────────

    public function test_repeated_runnable_gate_proof_path_boilerplate_detected(): void
    {
        $packets = [];
        for ($i = 0; $i < 3; $i++) {
            $packets[] = $this->packet(
                "Implement AtlasVariant{$i} to handle case {$i} for the pipeline entirely",
                ["Running php artisan test --filter=AtlasVariant{$i}Test exits 0."],
            );
        }

        $r = $this->svc()->assess($packets);

        $this->assertNotEmpty($r['repeated_proof_paths']);
        $this->assertTrue($r['blocking']);
    }

    public function test_non_runnable_gate_criteria_do_not_produce_proof_path_signal(): void
    {
        $r = $this->svc()->assess([
            $this->packet(
                'Implement AtlasAlpha to plan the dependency resolution for missing owners',
                ['given a missing owner the system produces an unblock action with wiring evidence'],
            ),
            $this->packet(
                'Implement AtlasBeta to plan the merge schedule for redundant capabilities',
                ['given two overlapping organs the merger selects canonical owner by evidence delta'],
            ),
        ]);

        $this->assertSame([], $r['repeated_proof_paths']);
    }

    // ── acceptance verb repetition ───────────────────────────────────────────────

    public function test_repeated_acceptance_verb_after_modal_detected(): void
    {
        $packets = [];
        for ($i = 0; $i < 3; $i++) {
            $packets[] = $this->packet(
                "Implement AtlasVariant{$i} to handle case {$i} for the pipeline entirely",
                ["the gate must reject the candidate when case {$i} fails"],
            );
        }

        $r = $this->svc()->assess($packets);

        $this->assertContains('reject', $r['repeated_acceptance_verbs']);
        $this->assertTrue($r['blocking']);
    }

    public function test_distinct_modal_verbs_not_flagged(): void
    {
        $r = $this->svc()->assess([
            $this->packet('Implement AtlasFoo.', ['given a gate report the system must surface missing gates']),
            $this->packet('Implement AtlasBar.', ['given an evidence set the compressor must reduce size by thirty percent']),
            $this->packet('Implement AtlasBaz.', ['given a capability with age above threshold the router must emit retire action']),
        ]);

        $this->assertSame([], $r['repeated_acceptance_verbs']);
    }

    // ── AC2: arm/wrapper/proxy specs with different names share a mechanism hash ──

    public function test_arm_wrapper_proxy_specs_with_different_names_share_mechanism_hash_and_are_blocked(): void
    {
        $r = $this->svc()->assess([
            $this->packet(
                'Implement AtlasFooArm to proxy requests to the underlying handler',
                ['/opt/homebrew/bin/php artisan test --filter=FooArmTest exits 0'],
            ),
            $this->packet(
                'Implement AtlasBarArm to proxy requests to the underlying handler',
                ['/opt/homebrew/bin/php artisan test --filter=BarArmTest exits 0'],
            ),
            $this->packet(
                'Implement AtlasBazArm to proxy requests to the underlying handler',
                ['/opt/homebrew/bin/php artisan test --filter=BazArmTest exits 0'],
            ),
        ]);

        $this->assertNotEmpty($r['repeated_mechanism_hashes']);
        $this->assertTrue($r['blocking']);
    }

    // ── AC3: genuinely different mechanisms with similar wording are allowed ──────

    public function test_similar_wording_but_genuinely_different_mechanisms_is_not_blocked(): void
    {
        $r = $this->svc()->assess([
            $this->packet(
                'Implement AtlasFoo to proxy requests to the underlying handler with retry backoff',
                ['/opt/homebrew/bin/php artisan test --filter=FooRetryTest exits 0'],
            ),
            $this->packet(
                'Implement AtlasBar to proxy requests to a different downstream via circuit breaker',
                ['vendor/bin/phpunit --filter=BarCircuitBreakerTest'],
            ),
            $this->packet(
                'Implement AtlasBaz to proxy requests using content-based routing rules',
                ['given a routing rule the proxy selects the matching downstream target'],
            ),
        ]);

        $this->assertSame([], $r['repeated_mechanism_hashes']);
        $this->assertFalse($r['blocking']);
    }

    // ── AC4: replacement hint asks for a different mechanism, not rewritten prose ──

    public function test_replacement_hint_asks_for_a_different_mechanism_when_blocked(): void
    {
        $packets = [];
        for ($i = 0; $i < 3; $i++) {
            $packets[] = $this->packet(
                "Implement AtlasVariant{$i} to evaluate the packet and return a score for the task",
                ['the gate must reject packets that fail validation and return a reason'],
            );
        }

        $r = $this->svc()->assess($packets);

        $this->assertTrue($r['blocking']);
        $this->assertNotNull($r['replacement_hint']);
        $this->assertStringContainsString('leverage mechanism', $r['replacement_hint']);
        $this->assertStringContainsString('Renaming', $r['replacement_hint']);
    }

    public function test_replacement_hint_is_null_when_not_blocked(): void
    {
        $r = $this->svc()->assess([
            $this->packet('Implement AtlasFoo to detect gate holes in the certification pipeline'),
            $this->packet('Implement AtlasBar to compress evidence records using adaptive sampling'),
        ]);

        $this->assertFalse($r['blocking']);
        $this->assertNull($r['replacement_hint']);
    }

    public function test_macro_batch_distinct_files_proof_paths_and_verbs_stays_unblocked(): void
    {
        $r = $this->svc()->assess([
            $this->packet(
                'Implement AtlasAlpha to plan the dependency resolution for missing owners',
                ['given a missing owner the system produces an unblock action with wiring evidence'],
                ['app/Services/Ai/SelfConstruction/AtlasAlphaPlanner.php'],
            ),
            $this->packet(
                'Implement AtlasBeta to plan the merge schedule for redundant capabilities',
                ['given two overlapping organs the merger selects canonical owner by evidence delta'],
                ['app/Services/Ai/AutonomousEvolution/AtlasBetaMerger.php'],
            ),
            $this->packet(
                'Implement AtlasGamma to plan the retirement path for zero-consumer capabilities',
                ['given an orphaned capability the planner emits retire with stall evidence attached'],
                ['app/Services/Ai/SelfConstruction/Cortex/AtlasGammaRetirer.php'],
            ),
        ]);

        $this->assertFalse($r['blocking']);
    }

    // ── corroboration floor: canonical proof phrase alone is compliance, not farming ──

    public function test_pairwise_packets_sharing_only_canonical_proof_phrase_not_blocked(): void
    {
        // Pairwise admission shape (candidate vs one queued packet): distinct objectives,
        // distinct allowed-files shapes, distinct acceptance semantics — sharing ONLY the
        // canonical runnable proof phrase the quality inspector REQUIRES from every packet.
        $r = $this->svc()->assess([
            $this->packet(
                'Extend AtlasDeltaResolver so lease reaping honors canonical path prefixes before pruning.',
                ['php artisan test --filter=AtlasDeltaResolverTest passes green', 'a stale lease under a canonical prefix is pruned exactly once'],
                ['app/Services/Ai/SelfConstruction/AtlasDeltaResolver.php', 'tests/Unit/Ai/SelfConstruction/AtlasDeltaResolverTest.php'],
            ),
            $this->packet(
                'Teach AtlasEpsilonForecast to weigh giveback pressure when projecting queue drain windows.',
                ['php artisan test --filter=AtlasEpsilonForecastTest passes green', 'repeated giveback facts raise the projected drain window monotonically'],
                ['app/Services/Ai/SelfConstruction/Maestro/AtlasEpsilonForecast.php'],
            ),
        ]);

        $this->assertSame(1, $r['corroborating_signal_families']);
        $this->assertFalse($r['blocking']);
    }

    public function test_pairwise_true_clone_still_blocked_with_corroboration(): void
    {
        $r = $this->svc()->assess([
            $this->packet(
                'Implement AtlasZetaGate as a proof-backed invariant gate that changes admission when signals repeat.',
                ['php artisan test --filter=AtlasZetaGateTest passes green'],
                ['app/Services/Ai/SelfConstruction/TaskFabric/AtlasZetaGate.php'],
            ),
            $this->packet(
                'Implement AtlasEtaGate as a proof-backed invariant gate that changes admission when signals repeat.',
                ['php artisan test --filter=AtlasEtaGateTest passes green'],
                ['app/Services/Ai/SelfConstruction/TaskFabric/AtlasEtaGate.php'],
            ),
        ]);

        $this->assertGreaterThanOrEqual(2, $r['corroborating_signal_families']);
        $this->assertTrue($r['blocking']);
    }
}
