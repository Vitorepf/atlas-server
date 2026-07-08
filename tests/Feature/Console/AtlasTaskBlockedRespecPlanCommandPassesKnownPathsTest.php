<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTaskPacketQueueRepository;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Proves that atlas:task:blocked-respec-plan passes known_existing_paths to the
 * field recovery miner so slug-based allowed_files recovery can corroborate
 * a codex-meta-* slug against an existing implementation+test pair.
 */
final class AtlasTaskBlockedRespecPlanCommandPassesKnownPathsTest extends TestCase
{
    private const TASK_PREFIX = 'atlas/self-construction/agent-control-plane/task-queue';

    private const REGISTRY_PATH = 'atlas/self-construction/task-packet-queue-registry.json';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function seedBlockedCodexMetaSlug(string $id, array $taskPacketOverrides = []): void
    {
        $safe = (string) preg_replace('/[^A-Za-z0-9_\-]/', '_', $id);
        $now = now()->toIso8601String();

        $basePacket = [
            'task_packet_id' => $id,
            'objective' => "blocked packet {$id}",
            'allowed_files' => [],
            'scope_in' => [],
            'acceptance_criteria' => [],
            'required_evidence' => [],
            'packet_quality' => [
                'self_sufficient' => false,
                'blocking_deficiencies' => ['scope_uncovered_allowed_files'],
                'facts' => [
                    'already_done' => false,
                    'dormant_cli_arm_proxy' => false,
                    'test_only_has_contract' => false,
                ],
            ],
        ];

        $record = [
            'schema_version' => AgentControlPlaneTaskPacketQueueRepository::SCHEMA_VERSION,
            'task_packet_id' => $id,
            'task_packet_hash' => 'test-hash-'.$safe,
            'enqueued_at' => $now,
            'updated_at' => $now,
            'status' => 'blocked',
            'priority' => 5,
            'tags' => [],
            'metadata' => ['give_back_count' => 0],
            'task_packet' => array_merge($basePacket, $taskPacketOverrides),
            'history' => [['event' => 'enqueued', 'at' => $now, 'status' => 'blocked']],
            'receipts' => [],
        ];

        Storage::disk('local')->put(self::TASK_PREFIX."/task_{$safe}.json", json_encode($record));

        $registry = ['entries' => []];
        if (Storage::disk('local')->exists(self::REGISTRY_PATH)) {
            $registry = json_decode((string) Storage::disk('local')->get(self::REGISTRY_PATH), true) ?? ['entries' => []];
        }
        $registry['entries'][] = [
            'task_packet_id' => $id,
            'task_packet_hash' => 'test-hash-'.$safe,
            'status' => 'blocked',
            'updated_at' => $now,
            'tags' => [],
        ];
        Storage::disk('local')->put(self::REGISTRY_PATH, json_encode($registry));
    }

    /** @return array<string,mixed> */
    private function exec(): array
    {
        Artisan::call('atlas:task:blocked-respec-plan', ['--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        return is_array($decoded) ? $decoded : [];
    }

    public function test_slug_recovery_corroborates_when_known_paths_passed(): void
    {
        // A codex-meta slug naming a FooBarValidator impl + test pair.
        // scope_in contains the matching impl+test paths.
        $implPath = 'app/Services/Foo/Bar/FooBarValidator.php';
        $testPath = 'tests/Unit/Services/Foo/Bar/FooBarValidatorTest.php';

        $this->seedBlockedCodexMetaSlug('codex-meta-foo-bar-validator-20260705', [
            'scope_in' => [$implPath, $testPath],
            'allowed_files' => [],
        ]);

        $output = $this->exec();

        $drafts = $output['replacement_drafts'] ?? [];
        // The slug packet may be classified as unknown or respec_candidate;
        // it should still appear in drafts (the command wraps every blocked
        // packet in a draft).
        $slugDraft = null;
        foreach ($drafts as $draft) {
            $ids = $draft['source_packet_ids'] ?? [];
            assert(is_array($ids));
            if (in_array('codex-meta-foo-bar-validator-20260705', $ids, true)) {
                $slugDraft = $draft;
                break;
            }
        }
        $this->assertNotNull($slugDraft, 'a draft for the codex-meta slug must exist');

        // Before the fix (no known_existing_paths passed to recover()):
        //   allowed_files = [] (slug recovery could not corroborate).
        // After the fix: the command passes scope_in as known_existing_paths,
        // so the slug is matched against the impl+test pair and allowed_files
        // is recovered with paths from scope_in.
        //
        // We check allowed_files directly on the completed draft.
        $allowedFiles = $slugDraft['allowed_files'] ?? [];
        $this->assertNotEmpty($allowedFiles, 'allowed_files must be recovered after known_existing_paths is wired');

        // The impl+test pair from scope_in should appear in recovered allowed_files.
        $this->assertContains($implPath, $allowedFiles);
        $this->assertContains($testPath, $allowedFiles);

        // can_submit depends on acceptance_criteria, which the slug recovery
        // doesn't touch — that's fine. The point is allowed_files IS recovered.
    }
}
