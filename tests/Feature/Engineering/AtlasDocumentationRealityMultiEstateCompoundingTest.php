<?php

declare(strict_types=1);

namespace Tests\Feature\Engineering;

use App\Services\Engineering\AtlasDocumentationRealityMultiEstateCompoundingService;
use Tests\TestCase;

/**
 * L2-O3 (first increment) — the cross-estate immunity propagation proposer. No
 * RefreshDatabase: the service reads the domain registry (which degrades to a static
 * catalog without a DB) and is otherwise pure, so no schema is needed.
 *
 * THE CARDINAL RULE under test (local-first sovereignty): the sovereignty data
 * classes sensitive/secret/cyber NEVER cross an estate boundary — such an antibody
 * STAYS LOCAL and NOTHING crosses (what_crosses is null, target_estates empty,
 * blocked_reason=sovereignty_class_must_not_leave_machine). For public/internal only
 * the ABSTRACT, domain-agnostic pattern crosses — never raw failure content, paths,
 * secrets, or evidence. EVERY response carries the sovereignty block with
 * sensitive_secret_cyber_never_cross=true and auto_propagates=false, and writes=false.
 */
final class AtlasDocumentationRealityMultiEstateCompoundingTest extends TestCase
{
    /**
     * A representative antibody (the P3 shape) whose ORIGINAL outline carries concrete
     * specifics — raw content, a file path, an error excerpt, a secret. The crossing
     * payload must NOT carry any of these; only the abstract pattern may cross.
     *
     * @return array<string,mixed>
     */
    private function antibodyWithSensitiveSpecifics(): array
    {
        return [
            'failure_kind' => 'type_error',
            'reproducing_test_outline' => [
                'description' => 'Reproduce the escape',
                'arrange_act_assert' => [
                    'arrange' => 'set up inputs that reproduce: SECRET_TOKEN=sk-live-abc123 in /srv/atlas/app/Services/Foo.php',
                    'act' => 'invoke /srv/atlas/app/Services/Foo.php with the trigger',
                    'assert' => 'assert the wrong outcome',
                ],
                'steps' => [
                    'arrange the inputs at /srv/atlas/app/Services/Foo.php',
                    'act: exercise the trigger that leaked sk-live-abc123',
                ],
                'target_test_path_suggestion' => 'tests/Feature/Foo/SecretLeakTest.php',
                'must_fail_before_fix' => true,
            ],
            'proposed_detector' => [
                'kind' => 'static_scan',
                'where' => 'the static-analysis / architecture-validate layer',
                'description' => 'Add a static scan that catches the leak of sk-live-abc123 at /srv/atlas/app/Services/Foo.php',
                'example_assertion' => 'assert the scan fails on sk-live-abc123 at /srv/atlas/app/Services/Foo.php',
                'derived_from_suggested_repair' => 'redact the token before logging in /srv/atlas/app/Services/Foo.php',
            ],
        ];
    }

    /**
     * The concrete, sensitive strings that must NEVER appear in a crossing payload.
     *
     * @return array<int,string>
     */
    private function forbiddenSpecifics(): array
    {
        return [
            'sk-live-abc123',
            '/srv/atlas/app/Services/Foo.php',
            'tests/Feature/Foo/SecretLeakTest.php',
            'SECRET_TOKEN',
            'redact the token before logging',
        ];
    }

    private function service(): AtlasDocumentationRealityMultiEstateCompoundingService
    {
        return app(AtlasDocumentationRealityMultiEstateCompoundingService::class);
    }

    public function test_public_or_internal_antibody_crosses_only_the_abstract_pattern(): void
    {
        foreach (['public', 'internal'] as $dataClass) {
            $payload = $this->service()->proposePropagation(
                $this->antibodyWithSensitiveSpecifics(),
                ['marketing', 'finance', 'security'],
                $dataClass,
            );

            $this->assertSame(AtlasDocumentationRealityMultiEstateCompoundingService::SCHEMA, $payload['schema_version']);
            $this->assertSame($dataClass, $payload['source_data_class']);
            $this->assertTrue($payload['cross_estate_allowed'], "{$dataClass} antibody must be allowed to cross");
            $this->assertNull($payload['blocked_reason']);

            // what_crosses is the ABSTRACT pattern only.
            $crosses = $payload['what_crosses'];
            $this->assertIsArray($crosses);
            $this->assertTrue($crosses['is_abstract_pattern_only']);
            $this->assertTrue($crosses['carries_no_sensitive_specifics']);
            $this->assertArrayHasKey('detector_kind', $crosses);
            $this->assertArrayHasKey('pattern_description', $crosses);
            $this->assertArrayHasKey('reproducing_test_shape', $crosses);

            // target_estates populated.
            $this->assertNotEmpty($payload['target_estates']);
            $estateIds = array_column($payload['target_estates'], 'estate');
            $this->assertContains('marketing', $estateIds);

            // CRITICAL: no raw failure content / paths / secrets cross. Scan the WHOLE
            // crossing payload (what_crosses), serialized, for any forbidden specific.
            $crossingJson = json_encode($crosses, JSON_THROW_ON_ERROR);
            foreach ($this->forbiddenSpecifics() as $secret) {
                $this->assertStringNotContainsString(
                    $secret,
                    $crossingJson,
                    "Crossing payload must NOT carry the sensitive specific: {$secret}",
                );
            }

            // The abstract pattern carries only domain-agnostic detector fields.
            $this->assertSame('static_scan', $crosses['detector_kind']);
            $this->assertSame('type_error', $crosses['failure_kind']);
        }
    }

    public function test_sensitive_antibody_is_blocked_and_stays_local(): void
    {
        $payload = $this->service()->proposePropagation(
            $this->antibodyWithSensitiveSpecifics(),
            ['marketing', 'finance'],
            'sensitive',
        );

        $this->assertSame('sensitive', $payload['source_data_class']);
        $this->assertFalse($payload['cross_estate_allowed']);
        $this->assertSame(
            AtlasDocumentationRealityMultiEstateCompoundingService::BLOCKED_REASON,
            $payload['blocked_reason'],
        );
        $this->assertSame('sovereignty_class_must_not_leave_machine', $payload['blocked_reason']);

        // The antibody STAYS LOCAL: nothing crosses, no targets.
        $this->assertNull($payload['what_crosses']);
        $this->assertSame([], $payload['target_estates']);
    }

    public function test_secret_and_cyber_classes_are_also_blocked(): void
    {
        foreach (['secret', 'cyber'] as $dataClass) {
            $payload = $this->service()->proposePropagation(
                $this->antibodyWithSensitiveSpecifics(),
                ['marketing', 'finance'],
                $dataClass,
            );

            $this->assertFalse($payload['cross_estate_allowed'], "{$dataClass} must be blocked from crossing");
            $this->assertSame(
                AtlasDocumentationRealityMultiEstateCompoundingService::BLOCKED_REASON,
                $payload['blocked_reason'],
            );
            $this->assertNull($payload['what_crosses']);
            $this->assertSame([], $payload['target_estates']);
        }
    }

    public function test_every_response_carries_sovereignty_guarantees_and_writes_false(): void
    {
        // Across crossable AND blocked classes the sovereignty guarantees and
        // writes:false are invariant — they never depend on the verdict.
        foreach (['public', 'internal', 'sensitive', 'secret', 'cyber'] as $dataClass) {
            $payload = $this->service()->proposePropagation(
                $this->antibodyWithSensitiveSpecifics(),
                ['marketing'],
                $dataClass,
            );

            $this->assertTrue(data_get($payload, 'sovereignty.sensitive_secret_cyber_never_cross'));
            $this->assertTrue(data_get($payload, 'sovereignty.only_abstract_pattern_crosses'));
            $this->assertTrue(data_get($payload, 'sovereignty.read_only'));
            $this->assertFalse(data_get($payload, 'sovereignty.auto_propagates'));
            $this->assertTrue(data_get($payload, 'sovereignty.human_gated'));
            $this->assertFalse($payload['writes']);

            // claim_policy mirrors the sovereignty guarantees.
            $this->assertTrue(data_get($payload, 'claim_policy.read_only'));
            $this->assertFalse(data_get($payload, 'claim_policy.writes'));
            $this->assertFalse(data_get($payload, 'claim_policy.auto_propagates'));
            $this->assertFalse(data_get($payload, 'claim_policy.transmits_cross_machine'));
            $this->assertTrue(data_get($payload, 'claim_policy.sensitive_secret_cyber_never_cross'));
            $this->assertTrue(data_get($payload, 'claim_policy.only_abstract_pattern_crosses'));
            $this->assertIsString($payload['propagation_hash']);
        }
    }

    public function test_blocked_class_carries_no_raw_sensitive_content_anywhere(): void
    {
        // For a blocked class, what_crosses is null/empty AND no forbidden specific
        // appears ANYWHERE in the envelope (what_stays_local is labels only, never the
        // content). This is the strongest leak guarantee: even the labels don't leak.
        foreach (['sensitive', 'secret', 'cyber'] as $dataClass) {
            $payload = $this->service()->proposePropagation(
                $this->antibodyWithSensitiveSpecifics(),
                ['marketing', 'finance'],
                $dataClass,
            );

            $this->assertNull($payload['what_crosses']);

            // what_stays_local is a list of LABELS, never the content.
            $this->assertNotEmpty($payload['what_stays_local']);
            $fullEnvelope = json_encode($payload, JSON_THROW_ON_ERROR);
            foreach ($this->forbiddenSpecifics() as $secret) {
                $this->assertStringNotContainsString(
                    $secret,
                    $fullEnvelope,
                    "Blocked envelope must NOT carry the sensitive specific anywhere: {$secret}",
                );
            }
        }
    }

    public function test_secrets_in_echoed_fields_do_not_cross(): void
    {
        // The adversarial catch: failure_kind, detector.kind and detector.where are
        // caller-supplied free-text. If they embed a secret/path/token they must be
        // sanitized to a safe vocabulary — NEVER echoed verbatim across the boundary.
        $antibody = [
            'failure_kind' => 'sk-live-DEADBEEF',
            'proposed_detector' => [
                'kind' => 'secret cyber gate PASSWORD=hunter2',
                'where' => '/srv/atlas/secret/vault PASSWORD=hunter2',
                'description' => 'clean',
            ],
            'reproducing_test_outline' => ['must_fail_before_fix' => true],
        ];

        foreach (['public', 'internal'] as $dataClass) {
            $payload = $this->service()->proposePropagation($antibody, ['marketing'], $dataClass);

            $this->assertTrue($payload['cross_estate_allowed']);
            $crosses = $payload['what_crosses'];
            $crossingJson = json_encode($crosses, JSON_THROW_ON_ERROR);

            foreach (['sk-live-DEADBEEF', 'sk-live-deadbeef', 'PASSWORD=hunter2', '/srv/atlas/secret'] as $secret) {
                $this->assertStringNotContainsString($secret, $crossingJson, "Echoed-field secret must not cross: {$secret}");
            }

            // The unsafe caller values are mapped to safe vocabulary defaults.
            $this->assertSame('unclassified_failure', $crosses['failure_kind']);
            $this->assertSame('unclassified_detector', $crosses['detector_kind']);
            $this->assertTrue($crosses['carries_no_sensitive_specifics']);
        }
    }

    public function test_unstated_or_unknown_data_class_fails_closed(): void
    {
        // A missing/blank/unrecognised source class must NOT default to a crossable one.
        foreach (['', '   ', 'unstated', 'totally-unknown-class'] as $dataClass) {
            $payload = $this->service()->proposePropagation(
                $this->antibodyWithSensitiveSpecifics(),
                ['marketing'],
                $dataClass,
            );

            $this->assertFalse($payload['cross_estate_allowed'], "class '{$dataClass}' must fail closed");
            $this->assertNull($payload['what_crosses']);
            $this->assertSame([], $payload['target_estates']);
        }
    }
}
