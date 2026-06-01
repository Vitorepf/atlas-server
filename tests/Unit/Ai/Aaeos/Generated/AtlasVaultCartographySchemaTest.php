<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasVaultCartographySchemaService;
use Tests\TestCase;

/**
 * Pins the index-level Atlas Vault Cartography Schema rules: Canon-table source
 * routing, fail-closed missing_source resolution, source-aware open actions and
 * the five Non-Negotiables.
 *
 * @see docs/engineering-knowledge-base/vault/atlas-vault-cartography-schema.md
 */
class AtlasVaultCartographySchemaTest extends TestCase
{
    private function service(): AtlasVaultCartographySchemaService
    {
        return new AtlasVaultCartographySchemaService();
    }

    /**
     * Canon table: repo docs own architecture/contracts/runtime; AtlasVault owns
     * philosophy/books/reflective. An unknown class routes to no root.
     */
    public function test_content_class_routing_matches_canon_table(): void
    {
        $svc = $this->service();

        $arch = $svc->routeContentClass('Architecture');
        $this->assertSame(AtlasVaultCartographySchemaService::ROOT_REPO, $arch['root']);
        $this->assertSame('docs/engineering-knowledge-base/', $arch['root_path']);
        $this->assertTrue($arch['known']);

        $this->assertSame(AtlasVaultCartographySchemaService::ROOT_REPO, $svc->routeContentClass('runtime')['root']);
        $this->assertSame(AtlasVaultCartographySchemaService::ROOT_REPO, $svc->routeContentClass('blockers')['root']);

        $phil = $svc->routeContentClass('philosophy');
        $this->assertSame(AtlasVaultCartographySchemaService::ROOT_VAULT, $phil['root']);
        $this->assertSame('AtlasVault/', $phil['root_path']);

        $this->assertSame(AtlasVaultCartographySchemaService::ROOT_VAULT, $svc->routeContentClass('books')['root']);

        // Unknown content class -> no owning root, fails the known flag.
        $unknown = $svc->routeContentClass('weather');
        $this->assertNull($unknown['root']);
        $this->assertFalse($unknown['known']);
    }

    /**
     * Canon "fails closed when a file is missing": a present source resolves and
     * is renderable as truth; a missing source flips to missing_source, is NOT
     * renderable as truth, disables the open action and emits drift evidence.
     */
    public function test_missing_source_fails_closed_and_is_never_truth(): void
    {
        $svc = $this->service();

        $present = $svc->resolvePiece([
            'graph_source' => 'repo',
            'source_present' => true,
            'expected_path' => 'docs/engineering-knowledge-base/x.md',
        ]);
        $this->assertSame(AtlasVaultCartographySchemaService::STATUS_RESOLVED, $present['status']);
        $this->assertTrue($present['renderable_as_truth']);
        $this->assertFalse($present['open_disabled']);
        $this->assertFalse($present['emit_drift_evidence']);

        $missing = $svc->resolvePiece([
            'graph_source' => 'repo',
            'source_present' => false,
            'expected_path' => 'docs/engineering-knowledge-base/gone.md',
            'cached_content' => 'last known body',
        ]);
        $this->assertSame(AtlasVaultCartographySchemaService::STATUS_MISSING, $missing['status']);
        $this->assertFalse($missing['renderable_as_truth']);
        $this->assertTrue($missing['open_disabled']);
        $this->assertTrue($missing['emit_drift_evidence']);
        // Cached content is shown only because it was supplied.
        $this->assertTrue($missing['show_cached_content']);
        $this->assertSame('docs/engineering-knowledge-base/gone.md', $missing['expected_path']);
    }

    /**
     * Runbook Reader Model → Open: repo opens via OS handler, vault via
     * obsidian://open, and a missing source disables the open action.
     */
    public function test_open_action_is_source_aware_and_disabled_when_missing(): void
    {
        $svc = $this->service();

        $repo = $svc->resolveOpenAction(['graph_source' => 'repo', 'source_present' => true]);
        $this->assertSame(AtlasVaultCartographySchemaService::OPEN_REPO, $repo['handler']);
        $this->assertTrue($repo['enabled']);

        $vault = $svc->resolveOpenAction(['graph_source' => 'vault', 'source_present' => true]);
        $this->assertSame('obsidian://open', $vault['handler']);
        $this->assertTrue($vault['enabled']);

        $missing = $svc->resolveOpenAction(['graph_source' => 'vault', 'source_present' => false]);
        $this->assertSame(AtlasVaultCartographySchemaService::OPEN_DISABLED, $missing['handler']);
        $this->assertFalse($missing['enabled']);
    }

    /**
     * Non-Negotiable (c): an architectural kind (step/pipeline/lane/component)
     * may never use graph_source: vault. A vault note is fine.
     */
    public function test_architectural_kind_cannot_use_vault_source(): void
    {
        $svc = $this->service();

        $bad = $svc->auditNonNegotiables([
            'graph_source' => 'vault',
            'graph_kind' => 'step',
            'source_present' => true,
        ]);
        $this->assertFalse($bad['ok']);
        $rules = array_column($bad['breaches'], 'rule');
        $this->assertContains('no-vault-source-for-architecture', $rules);

        // A genuine vault note (non-architectural) is allowed.
        $note = $svc->auditNonNegotiables([
            'graph_source' => 'vault',
            'graph_kind' => 'note',
            'source_present' => true,
        ]);
        $this->assertTrue($note['ok']);
    }

    /**
     * Non-Negotiables (d) + (e): a visual field outside the graph_* namespace and
     * a missing source rendered as truth both breach, surfacing in the composed
     * assess() verdict as blocked.
     */
    public function test_assess_blocks_off_namespace_field_and_missing_rendered_as_truth(): void
    {
        $svc = $this->service();

        $verdict = $svc->assess([
            'graph_source' => 'repo',
            'graph_kind' => 'step',
            'source_present' => false,
            'renders_as_truth' => true,
            'extra_visual_fields' => ['sync_status', 'graph_view'],
        ]);

        $this->assertSame(AtlasVaultCartographySchemaService::VERDICT_BLOCKED, $verdict['verdict']);
        $this->assertFalse($verdict['ok']);

        $rules = array_column($verdict['non_negotiables']['breaches'], 'rule');
        // sync_status is off-namespace; graph_view is fine and must NOT breach.
        $this->assertContains('graph-namespace-only', $rules);
        $this->assertContains('no-missing-as-truth', $rules);

        // The blocking reasons mention sync_status but never graph_view.
        $joined = implode(' | ', $verdict['blocking_reasons']);
        $this->assertStringContainsString('sync_status', $joined);
        $this->assertStringNotContainsString("'graph_view'", $joined);

        // A fully healthy repo piece is ok.
        $ok = $svc->assess([
            'graph_source' => 'repo',
            'graph_kind' => 'step',
            'source_present' => true,
            'extra_visual_fields' => ['graph_layer'],
        ]);
        $this->assertSame(AtlasVaultCartographySchemaService::VERDICT_OK, $ok['verdict']);
        $this->assertTrue($ok['ok']);
    }
}
