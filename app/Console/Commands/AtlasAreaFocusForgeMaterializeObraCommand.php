<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusForgeObraMaterializerService;
use Illuminate\Console\Command;

/**
 * Area Focus Loop · Forge Obra Materializer CLI (S2).
 *
 * Turns an explicit operator ACCEPT decision receipt (AP-724) + a ready forge
 * handoff (AP-729) into a REAL governed Obra (AtlasProject) and prints its
 * obra_id. Never fabricates an Obra: it requires an integrity-verified accept
 * receipt and is idempotent on (handoff_id, decision_id, finding_hash).
 *
 * Creating the Obra is the only mutation — it is NOT forge execution. The result
 * reports forge_executed=false; owner=forge must still be dispatched (with live
 * authority + provider proof) for any code to change.
 */
class AtlasAreaFocusForgeMaterializeObraCommand extends Command
{
    protected $signature = 'atlas:software-company-stewardship:area-focus-materialize-obra
        {--actor= : operator_actor (required)}
        {--decision-receipt= : AP-724 accept receipt as inline JSON or a path to a JSON file (required)}
        {--handoff= : AP-729 forge handoff as inline JSON or a path to a JSON file (required)}
        {--json : Emit JSON}';

    protected $description = 'Atlas Software Company Stewardship · Materialize a real governed Obra from an operator accept receipt + forge handoff (S2). No fabrication, idempotent, never forge execution.';

    public function handle(AreaFocusForgeObraMaterializerService $service): int
    {
        $receipt = $this->readJsonOption('decision-receipt');
        $handoff = $this->readJsonOption('handoff');
        if ($receipt === null || $handoff === null) {
            $this->line(json_encode([
                'schema_version' => AreaFocusForgeObraMaterializerService::RESULT_SCHEMA,
                'status' => 'blocked',
                'blocker' => 'invalid_json_input',
                'detail' => '--decision-receipt and --handoff must be valid JSON (inline or a readable file path).',
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::FAILURE;
        }

        $result = $service->materialize([
            'operator_actor' => (string) $this->option('actor'),
            'decision_receipt' => $receipt,
            'handoff' => $handoff,
        ]);

        $blocked = ($result['status'] ?? '') === AreaFocusForgeObraMaterializerService::STATUS_BLOCKED;

        if ((bool) $this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $blocked ? self::FAILURE : self::SUCCESS;
        }

        $this->components->twoColumnDetail('Area Focus Forge Obra materialization', 'S2');
        $this->components->twoColumnDetail('Status', strtoupper((string) ($result['status'] ?? 'unknown')));
        if ($blocked) {
            $this->components->twoColumnDetail('Blocker', (string) ($result['blocker'] ?? ''));
            $this->components->twoColumnDetail('Detail', (string) ($result['detail'] ?? ''));

            return self::FAILURE;
        }
        $this->components->twoColumnDetail('Obra id', (string) ($result['created_obra_id'] ?? ''));
        $this->components->twoColumnDetail('Idempotent', ($result['idempotent'] ?? false) ? 'yes' : 'no');
        $this->components->twoColumnDetail('Forge executed', $result['forge_executed'] ? 'no (Obra created only)' : 'no (Obra created only)');
        $this->components->twoColumnDetail('Next allowed action', (string) ($result['next_allowed_action'] ?? ''));
        $this->components->twoColumnDetail('Materialization hash', (string) ($result['materialization_hash'] ?? ''));

        return self::SUCCESS;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function readJsonOption(string $option): ?array
    {
        $raw = trim((string) $this->option($option));
        if ($raw === '') {
            return null;
        }
        if (! str_starts_with($raw, '{') && is_file($raw) && is_readable($raw)) {
            $raw = (string) file_get_contents($raw);
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }
}
