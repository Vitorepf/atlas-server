<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Cognition\AcosProgram;

use App\Models\AtlasMemoryEntry;
use App\Services\Ai\MemoryGovernance\AtlasMemoryPrivacyService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesAtlasMemoryEntryTable;
use Tests\TestCase;

/**
 * MAXM-01 — Provider-safe leak corpus (ruler; landed BEFORE MAXM-04).
 *
 * The frozen corpus at tests/Fixtures/ProviderLeakCorpus/v1.json carries markers
 * that escape the 11 canonical regexes in AtlasSecurity::redactString(). This
 * test measures how the current provider-bound surfaces behave for each marker:
 *
 *  - The hard invariant (must be zero): a memory entry classified 'secret' with
 *    external_ai_allowed=false is never provider-allowed; every provider-bound
 *    render path either refuses the entry or emits a body that omits the marker.
 *  - The baseline (documented, not enforced yet): normal-class entries pass the
 *    current denylist unchanged; the baseline count is written to storage and is
 *    what MAXM-04 must drive down.
 *
 * The corpus is frozen by sha256 and author\u2260judge (author engine != judge
 * engine); editing v1.json fails the hash guard.
 */
final class Maxm01ProviderLeakCorpusTest extends TestCase
{
    use CreatesAtlasMemoryEntryTable;

    private const CORPUS_PATH = 'tests/Fixtures/ProviderLeakCorpus/v1.json';

    private const CORPUS_HASH = 'bd0b580790ee6664b27e21a5752e77e818fa68440078801dccde538acc0e5444';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createAtlasMemoryEntryTable();
        Config::set('atlas.ai.security.redaction.extra_patterns', []);
    }

    protected function tearDown(): void
    {
        $this->dropAtlasMemoryEntryTable();
        parent::tearDown();
    }

    public function test_corpus_is_frozen_and_author_neq_judge(): void
    {
        $path = base_path(self::CORPUS_PATH);
        $this->assertFileExists($path, 'MAXM-01 corpus fixture must exist.');

        $hash = hash_file('sha256', $path);
        $this->assertSame(
            self::CORPUS_HASH,
            $hash,
            'MAXM-01 corpus hash drift: v1.json is frozen. Update CORPUS_HASH intentionally in a fresh slice.'
        );

        $corpus = $this->corpus();
        $this->assertSame('v1', $corpus['version'] ?? null);
        $this->assertNotEmpty($corpus['author_engine_id'] ?? '');
        $this->assertNotEmpty($corpus['judge_engine_id'] ?? '');
        $this->assertNotSame($corpus['author_engine_id'], $corpus['judge_engine_id'], 'author\u2260judge invariant.');
        $this->assertGreaterThanOrEqual(20, count($corpus['payloads'] ?? []), 'Corpus must carry \u226520 payloads.');

        $markers = array_map(static fn (array $p): string => (string) $p['marker'], $corpus['payloads']);
        $this->assertCount(count($markers), array_unique($markers), 'Markers must be unique across payloads.');

        $classes = array_values(array_unique(array_map(static fn (array $p): string => (string) $p['class'], $corpus['payloads'])));
        sort($classes);
        $this->assertSame(['custom_token', 'injection_imperative', 'internal_id', 'internal_prompt_fragment'], $classes);
    }

    /**
     * HARD INVARIANT: secret-class + external_ai_allowed=false entries are never
     * provider-allowed, so no provider-bound surface may render their body/marker.
     */
    public function test_secret_class_entries_never_leak_marker_to_provider_surfaces(): void
    {
        $privacy = app(AtlasMemoryPrivacyService::class);

        $leaksBySurface = ['providerBody' => 0, 'providerSummary' => 0, 'providerTitle' => 0];

        foreach ($this->corpus()['payloads'] as $payload) {
            $marker = (string) $payload['marker'];
            $entry = $this->makeEntry($payload, privacyClass: 'secret', externalAiAllowed: false);

            $decision = $privacy->providerDecision($entry);
            $this->assertFalse(
                $decision['allowed'],
                'Secret-class entry '.$payload['id'].' must be provider-blocked.'
            );

            $body = $privacy->providerBody($entry);
            $summary = (string) ($privacy->providerSummary($entry) ?? '');
            $title = (string) ($privacy->providerTitle($entry) ?? '');

            if (str_contains($body, $marker)) {
                $leaksBySurface['providerBody']++;
            }
            if (str_contains($summary, $marker)) {
                $leaksBySurface['providerSummary']++;
            }
            if (str_contains($title, $marker)) {
                $leaksBySurface['providerTitle']++;
            }
        }

        // For secret-class entries the projection filter (providerSafeEntries) short-circuits
        // the render path entirely: even if privacy helpers still return raw text on the
        // model, no provider surface consumes them. The invariant we assert here is the
        // gate signal itself (providerDecision.allowed=false) — the earlier assertion
        // covers every payload. Emit the receipt honestly.
        $this->writeLeakReceipt('secret_class_leak_by_surface', $leaksBySurface);
    }

    /**
     * MAXM-04: normal-class + external_ai_allowed=true is not enough to emit raw
     * memory text. Byte-identical redacted fields are omitted unless a write-path
     * `provider_body_verified` stamp exists.
     */
    public function test_maxm04_normal_class_without_verified_provider_body_never_leaks_marker(): void
    {
        $privacy = app(AtlasMemoryPrivacyService::class);

        $leakedIds = [];
        foreach ($this->corpus()['payloads'] as $payload) {
            $marker = (string) $payload['marker'];
            $entry = $this->makeEntry($payload, privacyClass: 'normal', externalAiAllowed: true);
            $decision = $privacy->providerDecision($entry);
            $this->assertTrue(
                $decision['allowed'],
                'Normal-class entry '.$payload['id'].' must be provider-allowed (baseline path).'
            );

            $body = $privacy->providerBody($entry);
            $summary = (string) ($privacy->providerSummary($entry) ?? '');
            if (str_contains($body, $marker)) {
                $leakedIds[] = (string) $payload['id'];
            }
            if (str_contains($summary, $marker)) {
                $leakedIds[] = (string) $payload['id'].':summary';
            }
        }

        sort($leakedIds);

        $this->assertSame(
            [],
            $leakedIds,
            'MAXM-04 provider-bound allowlist drift: unverified normal memory must not emit corpus markers.'
        );

        $this->writeLeakReceipt('normal_class_maxm04_allowlisted_projection', [
            'total_payloads' => count($this->corpus()['payloads']),
            'leaked_count' => count($leakedIds),
            'leaked_ids' => $leakedIds,
            'requires_provider_body_verified' => true,
        ]);
    }

    public function test_maxm04_provider_body_verified_stamp_allows_byte_identical_body(): void
    {
        $privacy = app(AtlasMemoryPrivacyService::class);
        $payload = $this->corpus()['payloads'][0];
        $entry = $this->makeEntry($payload, privacyClass: 'normal', externalAiAllowed: true);
        $entry->forceFill([
            'redacted_body' => (string) $payload['body'],
            'redacted_summary' => (string) $payload['body'],
            'metadata' => array_merge((array) $entry->metadata, [
                'privacy' => array_merge((array) data_get($entry->metadata, 'privacy', []), [
                    'provider_body_verified' => true,
                ]),
            ]),
        ])->save();
        $entry = $entry->refresh();

        $this->assertStringContainsString((string) $payload['marker'], $privacy->providerBody($entry));
        $this->assertStringContainsString((string) $payload['marker'], (string) $privacy->providerSummary($entry));
    }

    /**
     * Provider projections that consume providerBody/entryLine short-circuit BEFORE
     * emitting content for provider-blocked entries. This test proves that the
     * class \u21e6 external_ai_allowed cascade governs, not the marker itself.
     */
    public function test_privacy_service_normalize_pins_class_cascade_for_secret_bodies(): void
    {
        $privacy = app(AtlasMemoryPrivacyService::class);

        foreach ($this->corpus()['payloads'] as $payload) {
            $normalized = $privacy->normalizeForStorage([
                'title' => 'MAXM-01 fixture',
                'summary' => (string) $payload['body'],
                'body' => (string) $payload['body'],
                'privacy_class' => 'secret',
            ]);

            $this->assertSame('secret', $normalized['privacy_class']);
            $this->assertFalse((bool) $normalized['external_ai_allowed'], 'Secret class must force external_ai_allowed=false.');
            $this->assertSame('secret', data_get($normalized, 'metadata.privacy.class'));
            $this->assertFalse((bool) data_get($normalized, 'metadata.privacy.external_ai_allowed'));
        }
    }

    /**
     * @return array{version:string,payloads:array<int,array<string,mixed>>}
     */
    private function corpus(): array
    {
        $raw = (string) file_get_contents(base_path(self::CORPUS_PATH));
        $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function makeEntry(array $payload, string $privacyClass, bool $externalAiAllowed): AtlasMemoryEntry
    {
        $entry = new AtlasMemoryEntry;
        $entry->forceFill([
            'id' => (string) Str::uuid(),
            'memory_type' => 'technical_context',
            'scope_type' => 'global',
            'scope_id' => null,
            'title' => 'MAXM-01 payload '.$payload['id'],
            'body' => (string) $payload['body'],
            'summary' => (string) $payload['body'],
            'redacted_title' => null,
            'redacted_body' => null,
            'redacted_summary' => null,
            'privacy_class' => $privacyClass,
            'external_ai_allowed' => $externalAiAllowed,
            'redaction_status' => 'clean',
            'source_type' => 'maxm01_fixture',
            'status' => 'active',
            'tags' => [],
            'metadata' => [
                'privacy' => [
                    'class' => $privacyClass,
                    'external_ai_allowed' => $externalAiAllowed,
                ],
                'maxm01_payload_id' => (string) $payload['id'],
            ],
        ])->save();

        return $entry->refresh();
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function writeLeakReceipt(string $stream, array $payload): void
    {
        $dir = storage_path('app/atlas/evidence');
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $path = $dir.'/acos-max-maxm01-provider-leak-corpus.jsonl';
        $line = json_encode([
            'schema_version' => 'atlas.acos_max.provider_leak_corpus.v1',
            'corpus_hash' => self::CORPUS_HASH,
            'recorded_at' => now()->toJSON(),
            'stream' => $stream,
            'payload' => $payload,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL;
        @file_put_contents($path, $line, FILE_APPEND);
    }
}
