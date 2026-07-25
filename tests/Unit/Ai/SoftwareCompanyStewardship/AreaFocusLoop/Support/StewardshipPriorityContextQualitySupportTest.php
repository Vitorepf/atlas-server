<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop\Support;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\ContextQualityScoreContract;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\Support\StewardshipPriorityContextQualitySupport as Support;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Pure Support peel for AP-785 priority context-quality residual — no I/O, no host service, no DB.
 *
 * Explicit path proof: StewardshipPriorityEngineService imports Support and no
 * longer declares the peeled private context-quality / candidates / identity methods.
 */
final class StewardshipPriorityContextQualitySupportTest extends TestCase
{
    private const SUPPORT_PATH = 'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/Support/StewardshipPriorityContextQualitySupport.php';

    private const HOST_PATH = 'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipPriorityEngineService.php';

    /** @var list<string> */
    private const PEELED = [
        'validateScoreInput',
        'score',
        'candidateFindingKind',
        'applyPriorityBoost',
        'identity',
        'candidatesFromInput',
    ];

    /** Host private method names before peel (must be gone). */
    /** @var list<string> */
    private const PEELED_HOST_PRIVATES = [
        'validateContextQualityScoreInput',
        'candidateFindingKind',
        'applyContextQualityPriorityBoost',
        'identity',
        'candidates',
    ];

    #[Test]
    public function explicit_path_proof_support_and_host_files_exist_and_host_calls_support(): void
    {
        $root = dirname(__DIR__, 6);
        $supportAbs = $root.'/'.self::SUPPORT_PATH;
        $hostAbs = $root.'/'.self::HOST_PATH;

        $this->assertFileExists($supportAbs, 'Support peel must live at '.self::SUPPORT_PATH);
        $this->assertFileExists($hostAbs, 'Host must remain at '.self::HOST_PATH);

        $hostSrc = (string) file_get_contents($hostAbs);
        $this->assertStringContainsString(
            'use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\Support\StewardshipPriorityContextQualitySupport;',
            $hostSrc,
            'Host must import StewardshipPriorityContextQualitySupport',
        );
        $this->assertStringContainsString(
            'StewardshipPriorityContextQualitySupport::score',
            $hostSrc,
            'Host must call Support::score',
        );
        $this->assertStringContainsString(
            'StewardshipPriorityContextQualitySupport::applyPriorityBoost',
            $hostSrc,
            'Host must call Support::applyPriorityBoost',
        );
        $this->assertStringContainsString(
            'StewardshipPriorityContextQualitySupport::candidatesFromInput',
            $hostSrc,
            'Host must call Support::candidatesFromInput',
        );
        $this->assertStringContainsString(
            'StewardshipPriorityContextQualitySupport::identity',
            $hostSrc,
            'Host must call Support::identity',
        );

        foreach (self::PEELED_HOST_PRIVATES as $method) {
            $this->assertStringNotContainsString(
                'private function '.$method,
                $hostSrc,
                "Peeled method residual on host: {$method}",
            );
            $this->assertStringNotContainsString(
                'private static function '.$method,
                $hostSrc,
                "Peeled static residual on host: {$method}",
            );
        }
    }

    #[Test]
    public function pure_support_is_static_and_final_with_no_instance_state(): void
    {
        $ref = new ReflectionClass(Support::class);
        $this->assertTrue($ref->isFinal());
        $this->assertTrue($ref->getConstructor()?->isPrivate() ?? false);

        foreach (self::PEELED as $method) {
            $m = new ReflectionMethod(Support::class, $method);
            $this->assertTrue($m->isPublic() && $m->isStatic(), "{$method} must be public static");
        }
    }

    #[Test]
    public function validate_score_input_keeps_only_bounded_keys(): void
    {
        $validated = Support::validateScoreInput([
            'area_id' => 'aeos',
            'focus' => 'dev_forge',
            'certification_quality_score' => 8.0,
            'certification_target_score' => 9.8,
            'certification_status' => 'ready',
            'finding_kind' => ContextQualityScoreContract::FINDING_KIND_CONTEXT_MEMORY_RETRIEVAL_GAP,
            'noise_key' => 'drop-me',
            'extra' => ['nested' => true],
        ]);

        $this->assertSame([
            'area_id' => 'aeos',
            'focus' => 'dev_forge',
            'certification_quality_score' => 8.0,
            'certification_target_score' => 9.8,
            'certification_status' => 'ready',
            'finding_kind' => ContextQualityScoreContract::FINDING_KIND_CONTEXT_MEMORY_RETRIEVAL_GAP,
        ], $validated);
        $this->assertArrayNotHasKey('noise_key', $validated);
        $this->assertSame([], Support::validateScoreInput([]));
    }

    #[Test]
    public function score_empty_returns_defaults_and_degraded_gap_yields_boost_points(): void
    {
        $defaults = Support::score([]);
        $this->assertSame(ContextQualityScoreContract::defaults()->toArray(), $defaults);
        $this->assertSame(0, $defaults['outputs']['priority_boost_points']);
        $this->assertFalse($defaults['outputs']['context_quality_degraded']);

        $degraded = Support::score([
            'certification_quality_score' => 8.0,
            'certification_target_score' => 9.8,
            'certification_status' => 'ready',
            'finding_kind' => ContextQualityScoreContract::FINDING_KIND_CONTEXT_MEMORY_RETRIEVAL_GAP,
            'noise' => 'ignored',
        ]);

        $this->assertTrue($degraded['outputs']['context_quality_degraded']);
        $this->assertSame(
            ContextQualityScoreContract::PRIORITY_BOOST_WHEN_DEGRADED,
            $degraded['outputs']['priority_boost_points'],
        );
        $this->assertTrue($degraded['outputs']['boosts_context_memory_retrieval_gap_finding']);
    }

    #[Test]
    public function candidate_finding_kind_reads_kind_aliases_case_insensitively(): void
    {
        $gap = ContextQualityScoreContract::FINDING_KIND_CONTEXT_MEMORY_RETRIEVAL_GAP;

        $this->assertSame($gap, Support::candidateFindingKind(['finding_kind' => $gap]));
        $this->assertSame($gap, Support::candidateFindingKind(['kind' => strtoupper($gap)]));
        $this->assertSame($gap, Support::candidateFindingKind(['type' => '  '.$gap.'  ']));
        $this->assertSame($gap, Support::candidateFindingKind(['classification' => $gap]));
        $this->assertSame('', Support::candidateFindingKind(['type' => 'ui_cosmetic']));
        $this->assertSame('', Support::candidateFindingKind([]));
    }

    #[Test]
    public function apply_priority_boost_only_when_degraded_context_meets_gap_candidate(): void
    {
        $ranked = [
            'item_id' => 'context_gap_finding',
            'final_priority_score' => 40.0,
            'priority_score' => 40.0,
            'reason_machine' => ['gap'],
            'score_breakdown' => [
                'final_priority_score' => 40.0,
            ],
        ];
        $gapCandidate = [
            'id' => 'context_gap_finding',
            'kind' => ContextQualityScoreContract::FINDING_KIND_CONTEXT_MEMORY_RETRIEVAL_GAP,
        ];
        $cosmetic = [
            'id' => 'cosmetic_ui',
            'type' => 'ui_cosmetic',
        ];
        $contextInput = [
            'context_quality_score' => [
                'certification_quality_score' => 8.0,
                'certification_target_score' => 9.8,
                'certification_status' => 'ready',
            ],
        ];

        $boosted = Support::applyPriorityBoost($contextInput, $gapCandidate, $ranked);
        $this->assertSame(
            ContextQualityScoreContract::PRIORITY_BOOST_WHEN_DEGRADED,
            $boosted['context_quality_priority_boost_points'],
        );
        $this->assertSame(
            round(40.0 + ContextQualityScoreContract::PRIORITY_BOOST_WHEN_DEGRADED, 2),
            $boosted['final_priority_score'],
        );
        $this->assertSame($boosted['final_priority_score'], $boosted['priority_score']);
        $this->assertContains('context_quality_priority_boost', $boosted['reason_machine']);
        $this->assertContains('gap', $boosted['reason_machine']);
        $this->assertSame(
            ContextQualityScoreContract::PRIORITY_BOOST_WHEN_DEGRADED,
            $boosted['score_breakdown']['context_quality_priority_boost_points'],
        );

        $noInput = Support::applyPriorityBoost([], $gapCandidate, $ranked);
        $this->assertSame($ranked, $noInput);
        $this->assertArrayNotHasKey('context_quality_priority_boost_points', $noInput);

        $wrongKind = Support::applyPriorityBoost($contextInput, $cosmetic, $ranked);
        $this->assertSame($ranked, $wrongKind);

        $healthy = Support::applyPriorityBoost([
            'context_quality_score' => [
                'certification_quality_score' => 9.8,
                'certification_target_score' => 9.8,
                'certification_status' => 'ready',
            ],
        ], $gapCandidate, $ranked);
        $this->assertSame($ranked, $healthy);
        $this->assertArrayNotHasKey('context_quality_priority_boost_points', $healthy);
    }

    #[Test]
    public function identity_drops_generated_hash_fields_only(): void
    {
        $payload = [
            'schema_version' => 'v1',
            'status' => 'ready',
            'priority_hash' => 'sha256:deadbeef',
            'generated_at' => '2026-07-25T00:00:00Z',
            'ranked_items' => [['id' => 'a']],
        ];

        $identity = Support::identity($payload);
        $this->assertSame([
            'schema_version' => 'v1',
            'status' => 'ready',
            'ranked_items' => [['id' => 'a']],
        ], $identity);
        $this->assertArrayHasKey('priority_hash', $payload);
        $this->assertArrayHasKey('generated_at', $payload);
    }

    #[Test]
    public function candidates_from_input_reads_known_list_keys_in_priority_order(): void
    {
        $fromCandidates = Support::candidatesFromInput([
            'candidates' => [['id' => 'c1'], 'skip', ['id' => 'c2']],
            'findings' => [['id' => 'f1']],
        ]);
        $this->assertSame([['id' => 'c1'], ['id' => 'c2']], $fromCandidates);

        $fromFindings = Support::candidatesFromInput([
            'findings' => [['id' => 'f1']],
        ]);
        $this->assertSame([['id' => 'f1']], $fromFindings);

        $fromDeepScan = Support::candidatesFromInput([
            'deep_scan_report' => [
                'findings' => [['id' => 'd1']],
            ],
        ]);
        $this->assertSame([['id' => 'd1']], $fromDeepScan);

        $fromBranches = Support::candidatesFromInput([
            'branches' => [['id' => 'b1']],
        ]);
        $this->assertSame([['id' => 'b1']], $fromBranches);

        $fromSpecs = Support::candidatesFromInput([
            'specs' => [['id' => 's1']],
        ]);
        $this->assertSame([['id' => 's1']], $fromSpecs);

        $fromWorkOrders = Support::candidatesFromInput([
            'work_orders' => [['id' => 'w1']],
        ]);
        $this->assertSame([['id' => 'w1']], $fromWorkOrders);

        $fromQueue = Support::candidatesFromInput([
            'queue_items' => [['id' => 'q1']],
        ]);
        $this->assertSame([['id' => 'q1']], $fromQueue);

        $this->assertSame([], Support::candidatesFromInput([]));
        $this->assertSame([], Support::candidatesFromInput(['candidates' => 'not-a-list']));
    }

    #[Test]
    public function support_source_has_no_io_or_di_seams(): void
    {
        $root = dirname(__DIR__, 6);
        $src = (string) file_get_contents($root.'/'.self::SUPPORT_PATH);

        foreach ([
            'app(',
            'base_path(',
            'config(',
            'file_exists(',
            'file_get_contents(',
            'Schema::',
            'DB::',
            'Storage::',
            'now(',
            'AreaFocusUtcClock',
        ] as $needle) {
            $this->assertStringNotContainsString(
                $needle,
                $src,
                "Support must remain pure; found seam: {$needle}",
            );
        }
    }
}
