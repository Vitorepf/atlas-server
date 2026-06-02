<?php

namespace Tests\Unit\Ai\Skills\Hermes;

use App\Models\HermesSkillCandidate;
use App\Services\Ai\Hermes\HermesSkillProvisioner;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class HermesSkillProvisionerTest extends TestCase
{
    private string $atlasDir;

    private Filesystem $files;

    protected function setUp(): void
    {
        parent::setUp();

        (require database_path('migrations/2026_06_02_000400_create_hermes_skill_candidates_table.php'))->up();

        $this->files = new Filesystem;
        $this->atlasDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'atlas-hermes-skills-'.bin2hex(random_bytes(6));
        $this->files->ensureDirectoryExists($this->atlasDir);
    }

    protected function tearDown(): void
    {
        if ($this->files->isDirectory($this->atlasDir)) {
            $this->files->deleteDirectory($this->atlasDir);
        }

        Schema::dropIfExists('hermes_skill_candidates');

        parent::tearDown();
    }

    public function test_returns_no_promoted_skills_when_empty(): void
    {
        $receipt = $this->provisioner()->provision([], $this->mission(), $this->invocation(), 'atlas_adapter', $this->atlasDir);

        $this->assertSame('no_promoted_skills', data_get($receipt, 'status'));
        $this->assertSame(0, data_get($receipt, 'provisioned_count'));
        $this->assertEmpty($this->files->glob($this->atlasDir.'/*/*/SKILL.md'));
    }

    public function test_policy_off_skips_and_writes_nothing(): void
    {
        $receipt = $this->provisioner()->provision(
            [$this->promotedRecord()],
            $this->mission(),
            $this->invocation(),
            'off',
            $this->atlasDir,
        );

        $this->assertSame('skipped_by_policy', data_get($receipt, 'status'));
        $this->assertSame(1, data_get($receipt, 'skipped_count'));
        $this->assertSame('skill_provision_policy_not_atlas_adapter', data_get($receipt, 'skipped.0.reason'));
        $this->assertFalse((bool) data_get($receipt, 'provision_allowed_now'));
        $this->assertEmpty($this->files->glob($this->atlasDir.'/*/*/SKILL.md'));
    }

    public function test_only_promoted_and_allowed_record_is_provisioned_unpromoted_skipped(): void
    {
        $receipt = $this->provisioner()->provision(
            [$this->promotedRecord(), $this->unpromotedRecord()],
            $this->mission(),
            $this->invocation(),
            'atlas_adapter',
            $this->atlasDir,
        );

        $this->assertSame('partially_provisioned', data_get($receipt, 'status'));
        $this->assertSame(1, data_get($receipt, 'provisioned_count'));
        $this->assertSame(1, data_get($receipt, 'skipped_count'));
        $this->assertSame('promotion_not_approved', data_get($receipt, 'skipped.0.reason'));
        $this->assertTrue((bool) data_get($receipt, 'provision_allowed_now'));

        // The promoted skill got a SKILL.md; the unpromoted skill did not.
        $provisionedPath = data_get($receipt, 'provisioned_skills.0.path');
        $this->assertNotNull($provisionedPath);
        $this->assertTrue($this->files->exists($provisionedPath));
        $this->assertSame(1, count($this->files->glob($this->atlasDir.'/*/*/SKILL.md')));
        $this->assertFalse($this->files->isDirectory($this->atlasDir.'/programming/draft-skill'));
    }

    public function test_skill_markdown_frontmatter_is_correct_and_parseable(): void
    {
        $receipt = $this->provisioner()->provision(
            [$this->promotedRecord()],
            $this->mission(),
            $this->invocation(),
            'atlas_adapter',
            $this->atlasDir,
        );

        $path = data_get($receipt, 'provisioned_skills.0.path');
        $markdown = $this->files->get($path);

        // Parseable frontmatter: opens and closes with a --- fence.
        $this->assertMatchesRegularExpression('/\A---\n.*?\n---\n/s', $markdown);
        $frontmatter = $this->frontmatter($markdown);

        $this->assertStringContainsString('name: "repair-orchestrator"', $frontmatter);
        $this->assertStringContainsString('description: "Orchestrates repair-order runs end to end."', $frontmatter);
        $this->assertStringContainsString('version: "2.1.0"', $frontmatter);
        $this->assertStringContainsString('category: "programming"', $frontmatter);
        $this->assertStringContainsString('platforms:', $frontmatter);
        $this->assertStringContainsString('  - "local"', $frontmatter);
        $this->assertStringContainsString('metadata:', $frontmatter);
        $this->assertStringContainsString('  hermes:', $frontmatter);
        $this->assertStringContainsString('    tags:', $frontmatter);
        $this->assertStringContainsString('      - "repair"', $frontmatter);
        $this->assertStringContainsString('    requires_toolsets:', $frontmatter);
        $this->assertStringContainsString('    fallback_for_toolsets:', $frontmatter);
        $this->assertStringContainsString('    canonical_skill_authority: "atlas"', $frontmatter);
        $this->assertStringContainsString('required_environment_variables:', $frontmatter);
    }

    public function test_required_environment_variables_are_name_only_no_secret_value(): void
    {
        $receipt = $this->provisioner()->provision(
            [$this->promotedRecord([
                'required_environment_variables' => ['REPAIR_API_TOKEN=sk-supersecretvalue-123456', 'WORKSPACE_ROOT'],
            ])],
            $this->mission(),
            $this->invocation(),
            'atlas_adapter',
            $this->atlasDir,
        );

        $markdown = $this->files->get(data_get($receipt, 'provisioned_skills.0.path'));

        $this->assertStringContainsString('  - "REPAIR_API_TOKEN"', $markdown);
        $this->assertStringContainsString('  - "WORKSPACE_ROOT"', $markdown);
        // The secret value must NEVER reach disk.
        $this->assertStringNotContainsString('sk-supersecretvalue-123456', $markdown);
        $this->assertStringNotContainsString('=sk-', $markdown);
    }

    public function test_provenance_marker_written_with_receipt_hash(): void
    {
        $receipt = $this->provisioner()->provision(
            [$this->promotedRecord()],
            $this->mission(),
            $this->invocation(),
            'atlas_adapter',
            $this->atlasDir,
        );

        $skillPath = data_get($receipt, 'provisioned_skills.0.path');
        $markerPath = dirname($skillPath).DIRECTORY_SEPARATOR.'.atlas-provisioned.json';
        $this->assertTrue($this->files->exists($markerPath));

        $marker = json_decode($this->files->get($markerPath), true);
        $this->assertSame('programming.repair_orchestrator', data_get($marker, 'skill_id'));
        $this->assertSame('atlas.skills.pack_promotion.v1', data_get($marker, 'promotion_gate_ref'));
        $this->assertSame(data_get($receipt, 'receipt_hash'), data_get($marker, 'receipt_hash'));
        $this->assertNotEmpty(data_get($marker, 'provisioned_at'));
    }

    public function test_select_mission_skills_intersects_and_drops_non_provisioned(): void
    {
        $selection = $this->provisioner()->selectMissionSkills(
            ['repair-orchestrator', 'ghost-skill', 'repair-orchestrator'],
            ['repair-orchestrator', 'other-provisioned'],
        );

        $this->assertSame(['repair-orchestrator'], $selection['selection']);
        $this->assertSame(['ghost-skill'], $selection['dropped']);
        $this->assertSame('dropped_skills_not_provisioned_by_atlas', $selection['reason']);
    }

    public function test_mission_selection_recorded_in_receipt_drops_unprovisioned_request(): void
    {
        $receipt = $this->provisioner()->provision(
            [$this->promotedRecord()],
            $this->mission(['requested_skills' => ['repair-orchestrator', 'unprovisioned-skill']]),
            $this->invocation(),
            'atlas_adapter',
            $this->atlasDir,
        );

        $this->assertSame(['repair-orchestrator'], data_get($receipt, 'skills_selection.selected'));
        $this->assertSame(['unprovisioned-skill'], data_get($receipt, 'skills_selection.dropped'));
    }

    public function test_non_atlas_owned_external_dir_is_refused_by_gate(): void
    {
        $hermesManaged = rtrim((string) getenv('HOME'), '/').'/.hermes/skills';

        $receipt = $this->provisioner()->provision(
            [$this->promotedRecord()],
            $this->mission(),
            $this->invocation(),
            'atlas_adapter',
            $hermesManaged,
        );

        $this->assertSame('skipped_by_gate', data_get($receipt, 'status'));
        $this->assertFalse((bool) data_get($receipt, 'external_dir_is_atlas_owned'));
        $this->assertSame('external_dir_not_atlas_owned', data_get($receipt, 'skipped.0.reason'));
        $this->assertFalse((bool) data_get($receipt, 'provision_allowed_now'));
    }

    public function test_uninstall_removes_only_provenance_marked_dir(): void
    {
        $provision = $this->provisioner()->provision(
            [$this->promotedRecord()],
            $this->mission(),
            $this->invocation(),
            'atlas_adapter',
            $this->atlasDir,
        );

        $skillDir = dirname(data_get($provision, 'provisioned_skills.0.path'));
        $this->assertTrue($this->files->isDirectory($skillDir));

        $receipt = $this->provisioner()->uninstall('repair-orchestrator', $this->atlasDir);

        $this->assertSame('uninstalled', data_get($receipt, 'status'));
        $this->assertSame(1, data_get($receipt, 'uninstalled_count'));
        $this->assertFalse($this->files->isDirectory($skillDir));
    }

    public function test_uninstall_refuses_dir_without_provenance_marker(): void
    {
        // An operator/hub skill Atlas never provisioned (no provenance marker).
        $operatorDir = $this->atlasDir.'/community/operator-skill';
        $this->files->ensureDirectoryExists($operatorDir);
        $this->files->put($operatorDir.'/SKILL.md', "---\nname: operator-skill\n---\n");

        $receipt = $this->provisioner()->uninstall('operator-skill', $this->atlasDir);

        $this->assertSame('skipped_by_gate', data_get($receipt, 'status'));
        $this->assertSame('not_atlas_provisioned', data_get($receipt, 'skipped.0.reason'));
        $this->assertSame(0, data_get($receipt, 'uninstalled_count'));
        // The operator skill is untouched.
        $this->assertTrue($this->files->exists($operatorDir.'/SKILL.md'));
    }

    public function test_receipt_is_sealed_and_provision_allowed_now_only_on_approved_write(): void
    {
        $receipt = $this->provisioner()->provision(
            [$this->promotedRecord()],
            $this->mission(),
            $this->invocation(),
            'atlas_adapter',
            $this->atlasDir,
        );

        $this->assertSame('atlas.hermes.skill_provision_receipt.v1', data_get($receipt, 'schema_version'));
        $this->assertSame('hermes_skill_provisioner', data_get($receipt, 'adapter'));
        $this->assertTrue((bool) data_get($receipt, 'provision_allowed_now'));
        $this->assertNotEmpty(data_get($receipt, 'receipt_hash'));

        $expected = hash('sha256', json_encode(
            collect($receipt)->except('receipt_hash')->all(),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));
        $this->assertSame($expected, data_get($receipt, 'receipt_hash'));
    }

    public function test_hub_catalog_ingest_quarantines_never_installs(): void
    {
        $receipt = $this->provisioner()->ingestHubCatalog(
            [
                ['id' => 'agentskills.io/pdf-extract', 'name' => 'PDF Extract', 'description' => 'Extract text', 'category' => 'documents'],
                ['id' => 'agentskills.io/web-scrape', 'name' => 'Web Scrape'],
            ],
            'atlas_adapter',
        );

        $this->assertSame('atlas.hermes.skill_hub_ingest_receipt.v1', data_get($receipt, 'schema_version'));
        $this->assertSame('ingested_as_quarantined', data_get($receipt, 'status'));
        $this->assertSame(2, data_get($receipt, 'ingested_count'));
        $this->assertFalse((bool) data_get($receipt, 'auto_install_allowed'));
        $this->assertFalse((bool) data_get($receipt, 'install_allowed_now'));
        $this->assertNotEmpty(data_get($receipt, 'receipt_hash'));

        $this->assertSame(2, HermesSkillCandidate::query()->count());
        $row = HermesSkillCandidate::query()->where('hub_skill_id', 'agentskills.io/pdf-extract')->first();
        $this->assertNotNull($row);
        $this->assertSame('quarantined_for_review', $row->status);
        $this->assertSame('hermes_skills_hub', $row->source);
        $this->assertFalse($row->install_allowed);
        $this->assertTrue($row->review_required);
        $this->assertFalse((bool) data_get($row->payload_json, 'install_allowed_now'));
        $this->assertFalse((bool) data_get($row->payload_json, 'auto_install_allowed'));
    }

    public function test_hub_catalog_ingest_dedupes_on_second_run(): void
    {
        $entries = [['id' => 'agentskills.io/pdf-extract', 'name' => 'PDF Extract']];

        $this->provisioner()->ingestHubCatalog($entries, 'atlas_adapter');
        $second = $this->provisioner()->ingestHubCatalog($entries, 'atlas_adapter');

        $this->assertSame('deduplicated', data_get($second, 'status'));
        $this->assertSame(1, data_get($second, 'duplicate_count'));
        $this->assertSame(0, data_get($second, 'ingested_count'));
        $this->assertSame(1, HermesSkillCandidate::query()->count());
    }

    public function test_hub_catalog_ingest_skipped_by_policy(): void
    {
        $receipt = $this->provisioner()->ingestHubCatalog(
            [['id' => 'agentskills.io/pdf-extract', 'name' => 'PDF Extract']],
            'off',
        );

        $this->assertSame('skipped_by_policy', data_get($receipt, 'status'));
        $this->assertSame(0, HermesSkillCandidate::query()->count());
    }

    private function provisioner(): HermesSkillProvisioner
    {
        return app(HermesSkillProvisioner::class);
    }

    private function frontmatter(string $markdown): string
    {
        $parts = explode("---\n", $markdown, 3);

        return $parts[1] ?? '';
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function promotedRecord(array $overrides = []): array
    {
        return array_merge([
            'skill_id' => 'programming.repair_orchestrator',
            'name' => 'Repair Orchestrator',
            'description' => 'Orchestrates repair-order runs end to end.',
            'version' => '2.1.0',
            'platforms' => ['local'],
            'risk_level' => 'medium',
            'promotion_allowed' => true,
            'promotion_gate_verdict' => ['promotion_decision' => 'promotion_approved'],
            'required_environment_variables' => ['REPAIR_API_TOKEN', 'WORKSPACE_ROOT'],
            'metadata' => [
                'hermes' => [
                    'tags' => ['repair', 'orchestration'],
                    'category' => 'programming',
                    'fallback_for_toolsets' => ['shell'],
                    'requires_toolsets' => ['filesystem'],
                    'config' => ['max_steps'],
                ],
            ],
            'instructions' => 'Run the repair orchestrator with governed steps.',
        ], $overrides);
    }

    /**
     * @return array<string,mixed>
     */
    private function unpromotedRecord(): array
    {
        return [
            'skill_id' => 'programming.draft_skill',
            'name' => 'Draft Skill',
            'description' => 'Not yet promoted.',
            'version' => '0.1.0',
            'promotion_allowed' => false,
            'promotion_gate_verdict' => ['promotion_decision' => 'promotion_blocked'],
            'metadata' => ['hermes' => ['category' => 'programming']],
        ];
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function mission(array $overrides = []): array
    {
        return array_merge([
            'mission_id' => 'hermes_mission_test',
            'mission_hash' => 'mission_hash_test',
        ], $overrides);
    }

    /**
     * @return array<string,mixed>
     */
    private function invocation(): array
    {
        return [
            'provider_cli' => 'hermes_cli',
            'command' => ['hermes', 'chat', '--quiet'],
        ];
    }
}
