<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\AcosMax;

use App\Services\Ai\AcosMax\PromotionProtocol;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class PromotionProtocolTest extends TestCase
{
    private string $originalStoragePath;

    private string $tmpStorage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalStoragePath = storage_path();
        $this->tmpStorage = sys_get_temp_dir().'/atlas-promotion-protocol-'.bin2hex(random_bytes(4));
        mkdir($this->tmpStorage, 0o755, true);
        $this->app->useStoragePath($this->tmpStorage);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmpStorage);
        $this->app->useStoragePath($this->originalStoragePath);

        parent::tearDown();
    }

    public function test_flip_without_predeclared_rollback_trigger_is_refused(): void
    {
        $protocol = new PromotionProtocol(entries: [[
            'id' => 'acos.test.missing_rollback',
            'family' => 'TEST',
            'slice' => 'ELEV-26s',
            'state' => PromotionProtocol::STATE_OFF,
            'shadow_minimum_window' => '24h',
            'flip_criterion' => 'objective criterion',
            'judge_engine_id' => 'codex-elev26s-judge',
            'receipt' => 'receipt://test',
        ]]);

        $result = $protocol->flip('acos.test.missing_rollback', PromotionProtocol::STATE_SHADOW, [
            'observation_window_id' => 'window-1',
            'receipt' => 'receipt://flip',
            'actor' => 'test',
        ]);

        self::assertFalse($result['ok']);
        self::assertSame('missing_predeclared_rollback_trigger', $result['reason']);
        self::assertSame([], $protocol->ledgerEvents());
    }

    public function test_second_flip_in_same_family_observation_window_is_refused(): void
    {
        $protocol = new PromotionProtocol(entries: [
            $this->managedFlag('acos.test.flag_a', 'FAM'),
            $this->managedFlag('acos.test.flag_b', 'FAM'),
        ]);

        $first = $protocol->flip('acos.test.flag_a', PromotionProtocol::STATE_SHADOW, [
            'observation_window_id' => 'window-1',
            'receipt' => 'receipt://flag-a-shadow',
            'actor' => 'test',
        ]);
        $second = $protocol->flip('acos.test.flag_b', PromotionProtocol::STATE_SHADOW, [
            'observation_window_id' => 'window-1',
            'receipt' => 'receipt://flag-b-shadow',
            'actor' => 'test',
        ]);

        self::assertTrue($first['ok']);
        self::assertFalse($second['ok']);
        self::assertSame('family_window_flip_already_recorded', $second['reason']);
        self::assertCount(1, $protocol->ledgerEvents());
    }

    public function test_promotions_command_lists_managed_max_flags_and_legacy_unmanaged_flags(): void
    {
        Artisan::call('atlas:promotions', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('atlas.acos.promotion_protocol.report.v1', $payload['schema_version']);
        self::assertSame('ok', $payload['status']);
        self::assertContains(PromotionProtocol::STATE_SUSPENDED_PENDING_EVIDENCE, $payload['states']);

        $byId = collect($payload['flags'])->keyBy('id');

        self::assertTrue($byId->has('ATLAS_AUTONOMOS_MASTER_ENABLED'));
        self::assertSame('managed', $byId->get('ATLAS_AUTONOMOS_MASTER_ENABLED')['status']);
        self::assertSame(PromotionProtocol::STATE_OFF, $byId->get('ATLAS_AUTONOMOS_MASTER_ENABLED')['state']);
        self::assertTrue($byId->get('ATLAS_AUTONOMOS_MASTER_ENABLED')['required_fields']['ok']);

        self::assertTrue($byId->has('atlas.memory.feedback_ranking_enabled'));
        self::assertSame('legacy_unmanaged', $byId->get('atlas.memory.feedback_ranking_enabled')['status']);
    }

    /**
     * @return array<string,mixed>
     */
    private function managedFlag(string $id, string $family): array
    {
        return [
            'id' => $id,
            'family' => $family,
            'slice' => 'ELEV-26s',
            'state' => PromotionProtocol::STATE_OFF,
            'shadow_minimum_window' => '24h',
            'flip_criterion' => 'objective criterion',
            'rollback_trigger' => 'rollback on negative evidence',
            'judge_engine_id' => 'codex-elev26s-judge',
            'receipt' => 'receipt://test',
        ];
    }
}
