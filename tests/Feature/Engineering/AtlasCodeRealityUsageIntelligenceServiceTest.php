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
        $this->assertContains('php artisan atlas:code-reality reachability --target="<target>" --json', $contextPack['required_commands']);

        $this->assertSame('ready', $reachability['status']);
        $this->assertSame('reachability', $reachability['action']);
        $this->assertSame('reachable', data_get($reachability, 'reachability.status'));
        $this->assertSame('high', data_get($reachability, 'reachability.confidence'));
    }

    public function test_cli_actions_emit_json(): void
    {
        $cases = [
            ['classify', ['--target' => 'app/Console/Commands/AtlasDocumentationRealityCommand.php']],
            ['usage-map', ['--target' => 'app/Console/Commands/AtlasDocumentationRealityCommand.php']],
            ['reachability', ['--target' => 'app/Console/Commands/AtlasDocumentationRealityCommand.php']],
            ['anti-duplicate', ['--feature' => 'documentation reality cartography']],
            ['dead-code-candidates', []],
            ['context-pack', ['--task' => 'implementar runtime de documentation reality']],
        ];

        foreach ($cases as [$action, $options]) {
            $exit = Artisan::call('atlas:code-reality', array_merge([
                'action' => $action,
                '--json' => true,
                '--strict' => true,
            ], $options));

            $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame(0, $exit, $action);
            $this->assertSame(AtlasCodeRealityUsageIntelligenceService::SCHEMA_VERSION, $payload['schema_version']);
            $this->assertSame('ready', $payload['status']);
            $this->assertFalse($payload['writes']);
        }
    }
}
