<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainAutonomyIncidentPostmortemMiner;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasExternalBrainAutonomyIncidentPostmortemMinerWiringWiredTest extends TestCase
{
    private string $inputPath = '';

    protected function tearDown(): void
    {
        if ($this->inputPath !== '' && is_file($this->inputPath)) {
            @unlink($this->inputPath);
        }
        parent::tearDown();
    }

    public function test_regression_repair_command_includes_autonomy_incident_postmortems(): void
    {
        $payload = $this->runCommand([
            'autonomy_incidents' => [
                ['incident_type' => AtlasExternalBrainAutonomyIncidentPostmortemMiner::TYPE_POISON_RESERVE],
            ],
        ]);

        self::assertArrayHasKey('autonomy_incident_postmortems', $payload);
        self::assertCount(1, $payload['autonomy_incident_postmortems']);
        self::assertSame(
            AtlasExternalBrainAutonomyIncidentPostmortemMiner::TYPE_POISON_RESERVE,
            $payload['autonomy_incident_postmortems'][0]['incident_type'],
        );
        self::assertSame('poisoned_packet_reserved_without_quarantine', $payload['autonomy_incident_postmortems'][0]['root_cause']);
    }

    public function test_empty_autonomy_incidents_produces_empty_postmortems(): void
    {
        $payload = $this->runCommand([]);

        self::assertSame([], $payload['autonomy_incident_postmortems']);
    }

    private function runCommand(array $decoded): array
    {
        $this->inputPath = sys_get_temp_dir().'/autonomy-incident-'.bin2hex(random_bytes(6)).'.json';
        file_put_contents($this->inputPath, json_encode($decoded, JSON_THROW_ON_ERROR));

        Artisan::call('atlas:external-brain:regression-repair', ['--input' => $this->inputPath]);

        return json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);
    }
}
