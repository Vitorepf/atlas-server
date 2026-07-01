<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Maestro\Provenance;

use App\Services\Ai\SelfConstruction\Maestro\Provenance\AtlasMaestroPacketProvenanceComposer;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AtlasMaestroPacketProvenanceComposerTest extends TestCase
{
    public function test_it_is_idempotent_for_identical_inputs(): void
    {
        $composer = new AtlasMaestroPacketProvenanceComposer;
        $inputs = [
            'origin_kind' => 'cortex_fact',
            'origin_id' => 'fact-123',
            'chain' => [
                [
                    'parent_id' => null,
                    'source_kind' => 'cortex_fact',
                    'source_id' => 'fact-123',
                    'captured_at' => '2026-06-24T07:00:00-03:00',
                    'content' => ['objective' => 'seed'],
                ],
                [
                    'parent_id' => 'root',
                    'source_kind' => 'operator_intent',
                    'source_id' => 'intent-456',
                    'captured_at' => '2026-06-24T10:15:00Z',
                    'content' => ['operator' => 'vitorepf'],
                ],
            ],
        ];

        $first = $composer->compose('packet-1', $inputs);
        $second = $composer->compose('packet-1', $inputs);

        $this->assertSame(
            hash('sha256', json_encode($first, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
            hash('sha256', json_encode($second, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))
        );
        $this->assertSame($first, $second);
    }

    public function test_it_rejects_unsupported_origin_kind_and_empty_chain(): void
    {
        $composer = new AtlasMaestroPacketProvenanceComposer;

        $this->expectException(InvalidArgumentException::class);
        $composer->compose('packet-1', [
            'origin_kind' => 'made_up_kind',
            'origin_id' => 'x',
            'chain' => [['source_kind' => 'cortex_fact', 'source_id' => 'x', 'captured_at' => '2026-06-24T10:00:00Z']],
        ]);
    }

    public function test_it_rejects_empty_chain(): void
    {
        $composer = new AtlasMaestroPacketProvenanceComposer;

        $this->expectException(InvalidArgumentException::class);
        $composer->compose('packet-1', [
            'origin_kind' => 'operator_intent',
            'origin_id' => 'intent-1',
            'chain' => [],
        ]);
    }

    public function test_each_chain_link_carries_required_structural_fields(): void
    {
        $record = (new AtlasMaestroPacketProvenanceComposer)->compose('packet-1', [
            'origin_kind' => 'loop_emergence',
            'origin_id' => 'emergence-1',
            'chain' => [
                [
                    'parent_id' => null,
                    'source_kind' => 'loop_emergence',
                    'source_id' => 'emergence-1',
                    'captured_at' => '2026-06-24T10:15:00+02:00',
                    'content' => ['slice' => 'M-PROV-1320'],
                ],
            ],
        ]);

        $this->assertCount(1, $record['chain']);
        $link = $record['chain'][0];

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $link['link_id']);
        $this->assertSame('loop_emergence', $link['source_kind']);
        $this->assertSame('emergence-1', $link['source_id']);
        $this->assertSame('2026-06-24T08:15:00Z', $link['captured_at']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $link['content_hash']);
        $this->assertArrayHasKey('parent_id', $link);
    }

    private function minimalInputs(): array
    {
        return [
            'origin_kind' => 'cortex_fact',
            'origin_id'   => 'fact-min',
            'chain'       => [['parent_id' => null, 'source_kind' => 'cortex_fact', 'source_id' => 'fact-min', 'captured_at' => '2026-06-30T10:00:00Z', 'content' => []]],
        ];
    }

    public function test_allowed_files_hash_is_deterministic_and_order_independent(): void
    {
        $base = $this->minimalInputs();
        $base['allowed_files'] = ['app/B.php', 'app/A.php']; // unsorted intentionally
        $r1 = (new AtlasMaestroPacketProvenanceComposer)->compose('pkt-af', $base);

        $base2 = $base;
        $base2['allowed_files'] = ['app/A.php', 'app/B.php']; // sorted already
        $r2 = (new AtlasMaestroPacketProvenanceComposer)->compose('pkt-af', $base2);

        $this->assertArrayHasKey('allowed_files_hash', $r1);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $r1['allowed_files_hash']);
        $this->assertSame($r1['allowed_files_hash'], $r2['allowed_files_hash'], 'order must not affect hash');
    }

    public function test_acceptance_hash_is_deterministic(): void
    {
        $base = $this->minimalInputs();
        $base['acceptance_criteria'] = ['passes all gates', 'green tests'];
        $r = (new AtlasMaestroPacketProvenanceComposer)->compose('pkt-ac', $base);

        $this->assertArrayHasKey('acceptance_hash', $r);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $r['acceptance_hash']);

        $r2 = (new AtlasMaestroPacketProvenanceComposer)->compose('pkt-ac', $base);
        $this->assertSame($r['acceptance_hash'], $r2['acceptance_hash']);
    }

    public function test_missing_source_marking_is_included_when_declared(): void
    {
        $base = $this->minimalInputs();
        $base['source_missing'] = true;
        $r = (new AtlasMaestroPacketProvenanceComposer)->compose('pkt-ms', $base);

        $this->assertArrayHasKey('source_missing', $r);
        $this->assertTrue($r['source_missing']);
    }

    public function test_author_and_critic_are_separated_in_record(): void
    {
        $base = $this->minimalInputs();
        $base['author'] = 'atlas-originator';
        $base['critic'] = 'atlas-critic';
        $r = (new AtlasMaestroPacketProvenanceComposer)->compose('pkt-ac-sep', $base);

        $this->assertSame('atlas-originator', $r['author']);
        $this->assertSame('atlas-critic', $r['critic']);
        $this->assertNotSame($r['author'], $r['critic'], 'author and critic must be distinct');
    }

    public function test_record_with_custom_content_key_verifies_ok_no_false_hash_mismatch(): void
    {
        $composer = new AtlasMaestroPacketProvenanceComposer;
        $record = $composer->compose('packet-prov', [
            'origin_kind' => 'cortex_fact',
            'origin_id' => 'fact-999',
            'chain' => [
                [
                    'parent_id' => null,
                    'source_kind' => 'cortex_fact',
                    'source_id' => 'fact-999',
                    'captured_at' => '2026-06-24T07:00:00-03:00',
                    'content' => ['objective' => 'seed'],
                ],
            ],
        ]);

        $verifier = new \App\Services\Ai\SelfConstruction\Maestro\Provenance\AtlasMaestroPacketProvenanceVerifier;
        $verdict = $verifier->verify($record);

        $this->assertTrue($verdict['ok']);
        $this->assertSame('OK', $verdict['reason_code']);
    }

    // ── AC: content_hash from canonical task fields ───────────────────────────

    private function packet(array $overrides = []): array
    {
        return array_merge([
            'objective' => 'Fix the widget so it renders correctly',
            'allowed_files' => ['app/Widget.php', 'tests/Unit/WidgetTest.php'],
            'acceptance_criteria' => ['php artisan test tests/Unit/WidgetTest.php exits 0'],
            'required_evidence' => ['tests_or_gates_result'],
        ], $overrides);
    }

    public function test_content_hash_derived_from_objective_allowed_files_acceptance_and_required_evidence(): void
    {
        $composer = new \App\Services\Ai\SelfConstruction\Maestro\Provenance\AtlasMaestroPacketProvenanceComposer;

        $r1 = $composer->composeContentHash($this->packet());
        $r2 = $composer->composeContentHash($this->packet());
        $this->assertSame($r1['content_hash'], $r2['content_hash']);

        $changed = $composer->composeContentHash($this->packet(['objective' => 'Different objective entirely']));
        $this->assertNotSame($r1['content_hash'], $changed['content_hash']);
    }

    public function test_ignores_mutable_transient_fields_when_computing_content_hash(): void
    {
        $composer = new \App\Services\Ai\SelfConstruction\Maestro\Provenance\AtlasMaestroPacketProvenanceComposer;

        $withoutTransient = $composer->composeContentHash($this->packet());
        $withTransient = $composer->composeContentHash($this->packet([
            'lease_id' => 'lease-abc',
            'status' => 'claimed',
            'give_back_count' => 5,
            'updated_at' => '2026-06-30T00:00:00Z',
        ]));

        $this->assertSame($withoutTransient['content_hash'], $withTransient['content_hash']);
        $this->assertContains('lease_id', $withTransient['omitted_transient_fields']);
        $this->assertContains('status', $withTransient['omitted_transient_fields']);
    }

    public function test_content_hash_output_includes_provenance_record_canonical_fields_and_omitted_transient_fields(): void
    {
        $composer = new \App\Services\Ai\SelfConstruction\Maestro\Provenance\AtlasMaestroPacketProvenanceComposer;

        $r = $composer->composeContentHash($this->packet(['lease_id' => 'lease-abc']));

        foreach (['provenance_record', 'content_hash', 'canonical_fields', 'omitted_transient_fields'] as $key) {
            $this->assertArrayHasKey($key, $r, "Missing key: {$key}");
        }
        $this->assertSame(
            ['objective', 'allowed_files', 'acceptance_criteria', 'required_evidence'],
            $r['canonical_fields'],
        );
        $this->assertContains('lease_id', $r['omitted_transient_fields']);
    }

    // ── AC: content_hash stable across associative key order ──────────────────

    public function test_content_hash_is_stable_across_associative_key_order(): void
    {
        $composer = new AtlasMaestroPacketProvenanceComposer;

        $inOrder = [
            'objective' => 'Fix the widget so it renders correctly',
            'allowed_files' => ['app/Widget.php', 'tests/Unit/WidgetTest.php'],
            'acceptance_criteria' => ['php artisan test tests/Unit/WidgetTest.php exits 0'],
            'required_evidence' => ['tests_or_gates_result'],
        ];
        $reordered = [
            'required_evidence' => ['tests_or_gates_result'],
            'acceptance_criteria' => ['php artisan test tests/Unit/WidgetTest.php exits 0'],
            'objective' => 'Fix the widget so it renders correctly',
            'allowed_files' => ['app/Widget.php', 'tests/Unit/WidgetTest.php'],
        ];

        $r1 = $composer->composeContentHash($inOrder);
        $r2 = $composer->composeContentHash($reordered);

        $this->assertSame($r1['content_hash'], $r2['content_hash']);
    }

    // ── AC: provenance includes source, allowed_files_fingerprint, acceptance_fingerprint ──

    public function test_composed_content_hash_includes_source_and_fingerprints(): void
    {
        $composer = new AtlasMaestroPacketProvenanceComposer;

        $r = $composer->composeContentHash($this->packet(['source' => 'cortex_fact']));

        $this->assertSame('cortex_fact', $r['source']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $r['allowed_files_fingerprint']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $r['acceptance_fingerprint']);
        $this->assertSame($r['source'], $r['provenance_record']['source']);
        $this->assertSame($r['allowed_files_fingerprint'], $r['provenance_record']['allowed_files_fingerprint']);
        $this->assertSame($r['acceptance_fingerprint'], $r['provenance_record']['acceptance_fingerprint']);
    }

    public function test_allowed_files_fingerprint_changes_when_allowed_files_change_but_not_when_objective_changes(): void
    {
        $composer = new AtlasMaestroPacketProvenanceComposer;

        $base = $composer->composeContentHash($this->packet());
        $sameFilesDifferentObjective = $composer->composeContentHash($this->packet(['objective' => 'A totally different objective']));
        $differentFiles = $composer->composeContentHash($this->packet(['allowed_files' => ['app/Other.php']]));

        $this->assertSame($base['allowed_files_fingerprint'], $sameFilesDifferentObjective['allowed_files_fingerprint']);
        $this->assertNotSame($base['allowed_files_fingerprint'], $differentFiles['allowed_files_fingerprint']);
    }

    // ── AC: raw provider prompts or secrets are omitted from composed provenance ──

    public function test_composed_content_hash_omits_raw_provider_prompts_or_secrets(): void
    {
        $composer = new AtlasMaestroPacketProvenanceComposer;

        $r = $composer->composeContentHash($this->packet([
            'source' => 'cortex_fact',
            'raw_provider_prompt' => 'SYSTEM PROMPT: you are a super secret internal assistant...',
            'api_key' => 'sk-super-secret-provider-key',
            'provider_raw_response' => 'entire raw completion body from the provider',
        ]));

        $encoded = (string) json_encode($r);

        $this->assertStringNotContainsString('SYSTEM PROMPT', $encoded);
        $this->assertStringNotContainsString('sk-super-secret-provider-key', $encoded);
        $this->assertStringNotContainsString('entire raw completion body', $encoded);
        $this->assertContains('raw_provider_prompt', $r['omitted_transient_fields']);
        $this->assertContains('api_key', $r['omitted_transient_fields']);
        $this->assertContains('provider_raw_response', $r['omitted_transient_fields']);
    }
}
