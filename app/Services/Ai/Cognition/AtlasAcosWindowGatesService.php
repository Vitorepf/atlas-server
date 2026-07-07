<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognition;

use App\Services\Ai\AtlasMemoryQualityService;
use Throwable;

/**
 * D (Obra #18/#19) — the LIVE-WINDOW gate panel (instrumentation, not a new gate).
 *
 * The value gates D3 (relation density ≥70), D4 (feedback fill), D5 (quality)
 * and the long-horizon / full-suite gates are "code complete, proof pending":
 * their proof needs a WINDOW of real data (days / sustained runs), which cannot
 * be forced. This service surfaces, in one honest place:
 *   - the LIVE measured dimensions (from AtlasMemoryQualityService::scorecard),
 *   - the window gate RECEIPTS' OWN verdicts (from storage/app/atlas/evidence),
 * and labels each `certified` / `aguardando_janela` / `sem_dados`.
 *
 * Anti-Goodhart by construction: it NEVER fabricates a number and NEVER invents
 * a pass verdict — it reports the measured value and echoes each gate's own
 * `certified` flag. A gate whose receipt says `insufficient_*_evidence` shows
 * as `aguardando_janela`, honestly, until the data window fills on cadence.
 */
final class AtlasAcosWindowGatesService
{
    public const SCHEMA_VERSION = 'atlas.cognition.window_gates.v1';

    /** Receipt freshness before a certified gate is treated as stale (7 days). */
    private const RECEIPT_FRESH_SECONDS = 604800;

    public function __construct(
        private readonly ?AtlasMemoryQualityService $quality = null,
    ) {}

    private function quality(): AtlasMemoryQualityService
    {
        return $this->quality ?? app(AtlasMemoryQualityService::class);
    }

    /**
     * @return array<string,mixed>
     */
    public function status(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => gmdate('c'),
            'live_dimensions' => $this->liveDimensions(),
            'window_receipts' => $this->windowReceipts(),
            'note' => 'Gates de valor/janela são código-completo, prova pendente: a certificação enche na cadência de dados reais. Nada aqui é fabricado — valores medidos + veredito da própria fonte.',
        ];
    }

    /**
     * D3/D4/D5 live dimensions from the memory quality scorecard. Value measured;
     * `meets_target` set ONLY where the target direction is unambiguous (D3);
     * otherwise `reported` with the documented target string, never a guessed pass.
     *
     * @return array<int,array<string,mixed>>
     */
    private function liveDimensions(): array
    {
        try {
            $card = $this->quality()->scorecard();
        } catch (Throwable) {
            return [[
                'gate' => 'memory_quality',
                'status' => 'sem_dados',
                'evidence' => 'AtlasMemoryQualityService::scorecard indisponível (tabelas ausentes)',
            ]];
        }

        $dims = (array) ($card['components'] ?? []);

        return [
            $this->dimension('D3_relation_density', $dims, 'relation_density', '>=70', 70, true),
            $this->dimension('D5_structural_honesty', $dims, 'structural_honesty', '>=70', 70, true),
            $this->dimension('D5_rationale', $dims, 'rationale', '>=70', 70, true),
            // D5 feedback / composite: the target direction is contested in the
            // docs (quality composite vs "<=50" marker) → report, never assert.
            $this->dimension('D4_D5_feedback', $dims, 'feedback', 'ver doc (janela)', null, false),
        ];
    }

    /**
     * @param  array<string,mixed>  $dims
     * @return array<string,mixed>
     */
    private function dimension(string $gate, array $dims, string $key, string $target, ?int $threshold, bool $assert): array
    {
        if (! array_key_exists($key, $dims)) {
            return ['gate' => $gate, 'status' => 'sem_dados', 'target' => $target, 'evidence' => "dimensão '{$key}' ausente no scorecard"];
        }
        $value = $dims[$key];

        $out = ['gate' => $gate, 'target' => $target, 'value' => $value];
        if ($assert && $threshold !== null && is_numeric($value)) {
            $out['status'] = (float) $value >= $threshold ? 'met' : 'aguardando_janela';
        } else {
            $out['status'] = 'reported';
        }

        return $out;
    }

    /**
     * The window/long-horizon gate receipts echo their OWN certified verdict.
     *
     * @return array<int,array<string,mixed>>
     */
    private function windowReceipts(): array
    {
        $dir = function_exists('storage_path')
            ? storage_path('app/atlas/evidence')
            : sys_get_temp_dir().'/atlas/evidence';

        if (! is_dir($dir)) {
            return [];
        }

        $out = [];
        foreach (glob($dir.DIRECTORY_SEPARATOR.'*-gate.json') ?: [] as $file) {
            $out[] = $this->receiptStatus($file);
        }
        usort($out, static fn ($a, $b) => strcmp((string) $a['gate'], (string) $b['gate']));

        return $out;
    }

    /**
     * @return array<string,mixed>
     */
    private function receiptStatus(string $file): array
    {
        $gate = basename($file, '.json');
        try {
            $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return ['gate' => $gate, 'status' => 'sem_dados', 'evidence' => 'receipt ilegível'];
        }
        if (! is_array($data)) {
            return ['gate' => $gate, 'status' => 'sem_dados', 'evidence' => 'receipt não-objeto'];
        }

        $certified = ($data['certified'] ?? false) === true;
        $fresh = (time() - (int) @filemtime($file)) <= self::RECEIPT_FRESH_SECONDS;

        return [
            'gate' => $gate,
            'status' => $certified && $fresh ? 'certified' : 'aguardando_janela',
            'certified' => $certified,
            'fresh' => $fresh,
            'receipt_status' => (string) ($data['status'] ?? 'unknown'),
            'generated_at' => (string) ($data['generated_at'] ?? ''),
        ];
    }
}
