<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasVaultCartographySchemaContractsService;
use Tests\TestCase;

/**
 * Pins the documented Atlas Vault Cartography Schema Contracts rules.
 *
 * @see docs/engineering-knowledge-base/vault/atlas-vault-cartography-schema-contracts.md
 */
class AtlasVaultCartographySchemaContractsTest extends TestCase
{
    private function service(): AtlasVaultCartographySchemaContractsService
    {
        return new AtlasVaultCartographySchemaContractsService();
    }

    /**
     * Required Semantic Fields → Rules: `status: implemented` requires evidence,
     * and `next_actions` is required unless status is implemented/obsolete/archive.
     */
    public function test_implemented_requires_evidence_and_next_actions_rule(): void
    {
        $svc = $this->service();

        // implemented + no evidence => rejected for missing evidence; and because
        // implemented is exempt from next_actions, that rule must NOT fire.
        $noEvidence = $svc->validateSemantic([
            'graph_id' => 'some-piece',
            'status' => 'implemented',
            'evidence' => [],
            'graph_source' => 'vault',
            'graph_kind' => 'note',
        ]);
        $this->assertFalse($noEvidence['ok']);
        $this->assertContains("status 'implemented' requires non-empty evidence", $noEvidence['violations']);
        // next_actions rule is exempt for implemented -> not present.
        foreach ($noEvidence['violations'] as $v) {
            $this->assertStringNotContainsString('next_actions is required', $v);
        }

        // active + no next_actions => next_actions rule fires.
        $active = $svc->validateSemantic([
            'graph_id' => 'some-piece',
            'status' => 'active',
            'graph_source' => 'vault',
            'graph_kind' => 'note',
        ]);
        $this->assertFalse($active['ok']);
        $this->assertContains(
            "next_actions is required unless status is implemented, obsolete or archive (status: 'active')",
            $active['violations'],
        );

        // implemented + evidence present + (exempt next_actions) => valid.
        $ok = $svc->validateSemantic([
            'graph_id' => 'some-piece',
            'status' => 'implemented',
            'evidence' => ['docs/engineering-knowledge-base/x.md'],
            'graph_source' => 'vault',
            'graph_kind' => 'note',
        ]);
        $this->assertTrue($ok['ok'], implode(' | ', $ok['violations']));
    }

    /**
     * Required Semantic Fields → Rules: `graph_id` is a stable ascii slug and
     * should match the filename; `canonical_doc` is required for repo-sourced
     * non-note pieces.
     */
    public function test_graph_id_slug_filename_and_repo_canonical_doc_rules(): void
    {
        $svc = $this->service();

        // Non-ascii / non-slug graph_id is rejected.
        $badId = $svc->validateSemantic([
            'graph_id' => 'Atlas Decide!',
            'status' => 'active',
            'next_actions' => ['x'],
            'graph_source' => 'vault',
            'graph_kind' => 'note',
        ]);
        $this->assertFalse($badId['ok']);
        $this->assertTrue(
            (bool) array_filter($badId['violations'], static fn (string $v): bool => str_contains($v, 'stable ascii slug')),
        );

        // graph_id disagreeing with filename is reported.
        $mismatch = $svc->validateSemantic(
            [
                'graph_id' => 'atlas-decide',
                'status' => 'active',
                'next_actions' => ['x'],
                'graph_source' => 'vault',
                'graph_kind' => 'note',
            ],
            'some-other-file.md',
        );
        $this->assertFalse($mismatch['ok']);
        $this->assertTrue(
            (bool) array_filter($mismatch['violations'], static fn (string $v): bool => str_contains($v, 'should match the filename')),
        );

        // Repo-sourced NON-note piece without canonical_doc => rejected.
        $repoNoDoc = $svc->validateSemantic([
            'graph_id' => 'atlas-decide',
            'status' => 'active',
            'next_actions' => ['x'],
            'graph_source' => 'repo',
            'graph_kind' => 'step',
        ]);
        $this->assertFalse($repoNoDoc['ok']);
        $this->assertContains('canonical_doc is required for repo-sourced non-note pieces', $repoNoDoc['violations']);
    }

    /**
     * Source Rules: `graph_source` is required for architectural kinds, and an
     * architectural piece with `graph_source: vault` is invalid.
     */
    public function test_source_rules_block_vault_architecture_and_require_source(): void
    {
        $svc = $this->service();

        $vaultArch = $svc->validateSourceRules([
            'graph_kind' => 'system',
            'graph_source' => 'vault',
        ]);
        $this->assertFalse($vaultArch['ok']);
        $this->assertTrue(
            (bool) array_filter($vaultArch['violations'], static fn (string $v): bool => str_contains($v, 'cannot use graph_source: vault')),
        );

        $missingSource = $svc->validateSourceRules([
            'graph_kind' => 'pipeline',
        ]);
        $this->assertFalse($missingSource['ok']);
        $this->assertContains("graph_source is required for architectural kind 'pipeline'", $missingSource['violations']);

        // A note has a default source -> not architectural -> no violation.
        $note = $svc->validateSourceRules([
            'graph_kind' => 'note',
        ]);
        $this->assertTrue($note['ok']);
    }

    /**
     * Compatibility / Anti-Canon: forbidden drift-papering fields are rejected,
     * status/graph_status must share the same enum, and a previously-optional
     * field may never be made required.
     */
    public function test_anti_canon_forbidden_fields_and_status_enum_agreement(): void
    {
        $svc = $this->service();

        $forbidden = $svc->validateCompatibility([
            'status' => 'active',
            'graph_status' => 'active',
            'sync_status' => 'ok',
            'last_verified' => '2026-01-01',
        ]);
        $this->assertFalse($forbidden['ok']);
        $this->assertContains("forbidden field 'sync_status' (anti-canon: do not paper over drift)", $forbidden['violations']);
        $this->assertContains("forbidden field 'last_verified' (anti-canon: do not paper over drift)", $forbidden['violations']);

        // status/graph_status disagreement is rejected (shared enum, must match).
        $mismatch = $svc->validateCompatibility([
            'status' => 'active',
            'graph_status' => 'building',
        ]);
        $this->assertFalse($mismatch['ok']);
        $this->assertTrue(
            (bool) array_filter($mismatch['violations'], static fn (string $v): bool => str_contains($v, 'share the exact same enum and must match')),
        );

        // Non-additive change: a previously-optional field made required.
        $nonAdditive = $svc->validateCompatibility(
            ['status' => 'active', 'graph_status' => 'active'],
            ['made_required' => ['graph_pos']],
        );
        $this->assertFalse($nonAdditive['ok']);
        $this->assertContains("field 'graph_pos' was previously optional and may never be required (additive-only)", $nonAdditive['violations']);
    }

    /**
     * Visual Fields → Defaults: graph_kind defaults to note, graph_view to
     * vault-universe, graph_status mirrors semantic status, a note defaults its
     * graph_source to vault, and graph_layer derives from kind.
     */
    public function test_visual_field_defaults(): void
    {
        $svc = $this->service();

        $defaults = $svc->resolveVisual([
            'graph_id' => 'cartas',
            'status' => 'active',
        ]);
        $this->assertSame('note', $defaults['graph_kind']);
        $this->assertSame('vault-universe', $defaults['graph_view']);
        $this->assertSame('active', $defaults['graph_status']); // mirrors status
        $this->assertSame('vault', $defaults['graph_source']);  // note default
        $this->assertSame('flow', $defaults['graph_layer']);    // derived for note
        $this->assertNull($defaults['graph_pos']);              // omitted by default

        // Architectural kind has NO source default -> source_required is true.
        $arch = $svc->resolveVisual([
            'graph_id' => 'atlas-decide',
            'status' => 'active',
            'graph_kind' => 'system',
        ]);
        $this->assertNull($arch['graph_source']);
        $this->assertTrue($arch['source_required']);
        $this->assertSame('system', $arch['graph_layer']); // derived for system
    }

    /**
     * Source Authority / Anti-Canon: a repo-sourced piece may not point its
     * canonical_doc into the vault (synced projection), and an unknown
     * graph_source is blocked. The full validate() composes every surface.
     */
    public function test_source_authority_blocks_synced_projection_and_composes(): void
    {
        $svc = $this->service();

        // repo source pointing canonical_doc into the vault => projection blocked.
        $projection = $svc->validateSourceBinding([
            'graph_source' => 'repo',
            'canonical_doc' => '~/AtlasVault/01-acervo/livros/cartas.md',
        ]);
        $this->assertFalse($projection['ok']);
        $this->assertTrue(
            (bool) array_filter($projection['violations'], static fn (string $v): bool => str_contains($v, 'synced projection of repo docs is forbidden')),
        );

        // Unknown source is blocked.
        $unknown = $svc->validateSourceBinding(['graph_source' => 'dropbox']);
        $this->assertFalse($unknown['ok']);
        $this->assertTrue(
            (bool) array_filter($unknown['violations'], static fn (string $v): bool => str_contains($v, "unknown graph_source 'dropbox'")),
        );

        // A fully valid repo pipeline step composes to verdict=valid.
        $valid = $svc->validate(
            [
                'graph_id' => 'atlas-decide',
                'status' => 'active',
                'graph_status' => 'active',
                'graph_kind' => 'step',
                'graph_source' => 'repo',
                'canonical_doc' => 'docs/engineering-knowledge-base/atlas-ai-master-architecture.md',
                'next_actions' => ['Add mandatory dry-run above budget threshold X.'],
            ],
            'atlas-decide',
        );
        $this->assertSame(AtlasVaultCartographySchemaContractsService::VERDICT_VALID, $valid['verdict']);
        $this->assertTrue($valid['ok'], implode(' | ', $valid['violations']));
        $this->assertSame([], $valid['violations']);
    }
}
