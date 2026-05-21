<?php

declare(strict_types=1);

use App\Services\Engineering\AtlasCodeRealityUsageIntelligenceService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasCodeRealityUsageIntelligenceServiceTest extends TestCase
{
    public function test_classifies_known_command_with_evidence_without_writes(): void
    {
        $payload = app(AtlasCodeRealityUsageIntelligenceService::class)
            ->classify('app/Console/Commands/AtlasDocumentationRealityCommand.php');

        $this->assertSame(AtlasCodeRealityUsageIntelligenceService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertSame('classify', $payload['action']);
        $this->assertSame('active_runtime', $payload['classification']);
        $this->assertSame('app/Console/Commands/AtlasDocumentationRealityCommand.php', $payload['target_path']);
        $this->assertFalse($payload['writes']);
        $this->assertFalse($payload['claim_policy']['deletes_files']);
        $this->assertFalse($payload['claim_policy']['dead_code_confirmation_allowed']);
        $this->assertContains('tests/Feature/Engineering/AtlasCodeRealityUsageIntelligenceServiceTest.php', $payload['evidence']['tests']);
        $this->assertContains('docs/engineering-knowledge-base/atlas-documentation-reality-system.md', $payload['evidence']['owner_docs']);
        $this->assertSame(AtlasCodeRealityUsageIntelligenceService::REACHABILITY_SCHEMA_VERSION, data_get($payload, 'evidence.reachability.schema_version'));
        $this->assertSame('reachable', data_get($payload, 'evidence.reachability.status'));
        $this->assertSame('high', data_get($payload, 'evidence.reachability.confidence'));
        $this->assertTrue(data_get($payload, 'evidence.reachability.signals.has_command_entrypoint'));
        $this->assertTrue(data_get($payload, 'evidence.reachability.signals.has_test_coverage'));
        $this->assertTrue(data_get($payload, 'evidence.reachability.signals.has_owner_doc'));
        $this->assertNotEmpty(data_get($payload, 'evidence.reachability.edges'));

        $codeRealityCommand = app(AtlasCodeRealityUsageIntelligenceService::class)
            ->classify('app/Console/Commands/AtlasCodeRealityCommand.php');
        $this->assertSame('app/Console/Commands/AtlasCodeRealityCommand.php', $codeRealityCommand['target_path']);
        $this->assertContains('tests/Feature/Engineering/AtlasCodeRealityUsageIntelligenceServiceTest.php', $codeRealityCommand['evidence']['tests']);
    }

    public function test_unknown_target_blocks_with_review_policy_not_dead_code(): void
    {
        $payload = app(AtlasCodeRealityUsageIntelligenceService::class)
            ->classify('NoSuchAtlasRuntimeThing');

        $this->assertSame('blocked', $payload['status']);
        $this->assertSame('unknown_requires_audit', $payload['classification']);
        $this->assertSame('target_not_found', $payload['blockers'][0]['reason']);
        $this->assertFalse($payload['claim_policy']['dead_code_confirmation_allowed']);
        $this->assertSame('not_found', data_get($payload, 'evidence.reachability.status'));
        $this->assertSame('none', data_get($payload, 'evidence.reachability.confidence'));
    }

    public function test_dead_code_candidates_never_confirms_dead_code_or_delete(): void
    {
        $payload = app(AtlasCodeRealityUsageIntelligenceService::class)->deadCodeCandidates();

        $this->assertSame('ready', $payload['status']);
        $this->assertSame([], $payload['dead_code_confirmed']);
        $this->assertSame('conservative_no_dead_code_confirmation', $payload['classification_policy']);
        $this->assertContains('human_approval', $payload['required_quarantine_sequence']);
        $this->assertFalse($payload['claim_policy']['deletes_files']);
    }

    public function test_deletion_preflight_blocks_delete_even_for_unused_or_active_targets(): void
    {
        $service = app(AtlasCodeRealityUsageIntelligenceService::class);

        $active = $service->deletionPreflight('app/Console/Commands/AtlasDocumentationRealityCommand.php');
        $unknown = $service->deletionPreflight('NoSuchAtlasRuntimeThing');

        $this->assertSame(AtlasCodeRealityUsageIntelligenceService::DELETION_PREFLIGHT_SCHEMA_VERSION, $active['schema_version']);
        $this->assertSame('ready', $active['status']);
        $this->assertFalse($active['allowed_to_delete']);
        $this->assertSame('block_delete_active_or_available_target', $active['decision']);
        $this->assertFalse($active['claim_policy']['delete_authorization_allowed']);
        $this->assertContains('human_approval', $active['required_before_delete']);
        $this->assertNotEmpty($active['evidence']['reachability_edges']);

        $this->assertSame('ready', $unknown['status']);
        $this->assertFalse($unknown['allowed_to_delete']);
        $this->assertSame('block_delete_until_quarantine_and_human_approval', $unknown['decision']);
        $this->assertFalse($unknown['claim_policy']['dead_code_confirmation_allowed']);
    }

    public function test_reality_audit_scores_adrs_runtime_cluster_without_mutations(): void
    {
        $payload = app(AtlasCodeRealityUsageIntelligenceService::class)->realityAudit();

        $this->assertSame(AtlasCodeRealityUsageIntelligenceService::REALITY_AUDIT_SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertSame('adrs_acrui_aurc_runtime_cluster', $payload['scope']);
        $this->assertSame(6, $payload['target_count']);
        $this->assertSame(0, $payload['unknown_or_unused_count']);
        $this->assertSame(0, $payload['weak_reachability_count']);
        $this->assertTrue($payload['risk_register']['delete_claim_blocked']);
        $this->assertFalse($payload['writes']);

        foreach ($payload['targets'] as $target) {
            $this->assertContains($target['classification'], ['active_runtime', 'active_read_only']);
            $this->assertContains($target['reachability_confidence'], ['high', 'medium']);
            $this->assertGreaterThan(0, $target['owner_doc_count']);
            $this->assertGreaterThan(0, $target['test_count']);
        }
    }

    public function test_anti_duplicate_and_context_pack_are_provider_safe(): void
    {
        $service = app(AtlasCodeRealityUsageIntelligenceService::class);

        $antiDuplicate = $service->antiDuplicate('documentation reality cartography');
        $contextPack = $service->contextPack('implementar runtime de documentation reality');
        $reachability = $service->reachability('app/Console/Commands/AtlasDocumentationRealityCommand.php');

        $this->assertSame('ready', $antiDuplicate['status']);
        $this->assertContains($antiDuplicate['decision'], ['proceed_with_owner_lookup', 'reuse_or_extend_before_new_runtime']);
        $this->assertSame('run_feature_placement_and_read_owner_docs_before_implementation', $antiDuplicate['required_next_step']);

        $this->assertSame('ready', $contextPack['status']);
        $this->assertTrue($contextPack['provider_safe']);
        $this->assertContains('docs/engineering-knowledge-base/atlas-code-reality-usage-intelligence.md', $contextPack['minimal_sources']);
        $this->assertContains('dead_code_confirmed_without_quarantine', $contextPack['do_not_claim']);
        $this->assertContains('php artisan atlas:code-reality reality-audit --json', $contextPack['required_commands']);
        $this->assertContains('php artisan atlas:code-reality reachability --target="<target>" --json', $contextPack['required_commands']);
        $this->assertContains('php artisan atlas:code-reality deletion-preflight --target="<target>" --json', $contextPack['required_commands']);

        $this->assertSame('ready', $reachability['status']);
        $this->assertSame('reachability', $reachability['action']);
        $this->assertSame('reachable', data_get($reachability, 'reachability.status'));
        $this->assertSame('high', data_get($reachability, 'reachability.confidence'));
    }

    public function test_cli_actions_emit_json(): void
    {
        $cases = [
            ['classify', ['--target' => 'app/Console/Commands/AtlasDocumentationRealityCommand.php'], AtlasCodeRealityUsageIntelligenceService::SCHEMA_VERSION],
            ['usage-map', ['--target' => 'app/Console/Commands/AtlasDocumentationRealityCommand.php'], AtlasCodeRealityUsageIntelligenceService::SCHEMA_VERSION],
            ['reachability', ['--target' => 'app/Console/Commands/AtlasDocumentationRealityCommand.php'], AtlasCodeRealityUsageIntelligenceService::SCHEMA_VERSION],
            ['anti-duplicate', ['--feature' => 'documentation reality cartography'], AtlasCodeRealityUsageIntelligenceService::SCHEMA_VERSION],
            ['dead-code-candidates', [], AtlasCodeRealityUsageIntelligenceService::SCHEMA_VERSION],
            ['deletion-preflight', ['--target' => 'app/Console/Commands/AtlasDocumentationRealityCommand.php'], AtlasCodeRealityUsageIntelligenceService::DELETION_PREFLIGHT_SCHEMA_VERSION],
            ['reality-audit', [], AtlasCodeRealityUsageIntelligenceService::REALITY_AUDIT_SCHEMA_VERSION],
            ['context-pack', ['--task' => 'implementar runtime de documentation reality'], AtlasCodeRealityUsageIntelligenceService::SCHEMA_VERSION],
        ];

        foreach ($cases as [$action, $options, $schema]) {
            $exit = Artisan::call('atlas:code-reality', array_merge([
                'action' => $action,
                '--json' => true,
                '--strict' => true,
            ], $options));

            $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame(0, $exit, $action);
            $this->assertSame($schema, $payload['schema_version']);
            $this->assertSame('ready', $payload['status']);
            $this->assertFalse($payload['writes']);
        }
    }
}
