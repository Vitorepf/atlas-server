<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognition;

use App\Services\Ai\AtlasMemoryQualityService;
use App\Services\Ai\Support\AiValueNormalizer;
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
    public const FIELD_COMPONENTS = 'components';
    public const FIELD_FRESH = 'fresh';
    public const SCHEMA_VERSION = 'atlas.cognition.window_gates.v1';

    public const STATUS_UNKNOWN = 'unknown';

    public const STATUS_SEM_DADOS = 'sem_dados';

    public const STATUS_AGUARDANDO_JANELA = 'aguardando_janela';

    public const STATUS_CERTIFIED = 'certified';

    public const STATUS_MET = 'met';

    public const FIELD_CERTIFIED = 'certified';
    public const FIELD_STATUS = 'status';
    public const FIELD_GATE = 'gate';
    public const FIELD_REASON = 'reason';
    public const FIELD_OK = 'ok';
    public const FIELD_WINDOWS = 'windows';
    public const FIELD_DAYS = 'days';
    public const FIELD_EVIDENCE = 'evidence';
    public const FIELD_GENERATED_AT = 'generated_at';
    public const FIELD_TARGET = 'target';
    public const FIELD_LIVE_DIMENSIONS = 'live_dimensions';
    public const FIELD_NOTE = 'note';

    /** Receipt freshness before a certified gate is treated as stale (7 days). */
    public const RECEIPT_FRESH_SECONDS = 604800;

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
            self::FIELD_GENERATED_AT => gmdate('c'),
            self::FIELD_LIVE_DIMENSIONS => $this->liveDimensions(),
            'window_receipts' => $this->windowReceipts(),
            self::FIELD_NOTE => 'Gates de valor/janela são código-completo, prova pendente: a certificação enche na cadência de dados reais. Nada aqui é fabricado — valores medidos + veredito da própria fonte.',
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
                self::FIELD_GATE => 'memory_quality',
                self::FIELD_STATUS => self::STATUS_SEM_DADOS,
                self::FIELD_EVIDENCE => 'AtlasMemoryQualityService::scorecard indisponível (tabelas ausentes)',
            ]];
        }

        $dims = AiValueNormalizer::arrayOrEmpty($card[self::FIELD_COMPONENTS] ?? null);

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
            return [self::FIELD_GATE => $gate, self::FIELD_STATUS => self::STATUS_SEM_DADOS, self::FIELD_TARGET => $target, self::FIELD_EVIDENCE => "dimensão '{$key}' ausente no scorecard"];
        }
        $value = $dims[$key];

        $out = [self::FIELD_GATE => $gate, self::FIELD_TARGET => $target, 'value' => $value];
        $numeric = AiValueNormalizer::finiteFloatOrNull($value);
        if ($assert && $threshold !== null && $numeric !== null) {
            $out[self::FIELD_STATUS] = $numeric >= $threshold ? self::STATUS_MET : self::STATUS_AGUARDANDO_JANELA;
        } else {
            $out[self::FIELD_STATUS] = 'reported';
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
        usort($out, static fn ($a, $b) => strcmp(AiValueNormalizer::trimmedScalarStringOrNull($a[self::FIELD_GATE] ?? null) ?? '', AiValueNormalizer::trimmedScalarStringOrNull($b[self::FIELD_GATE] ?? null) ?? ''));

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
            return [self::FIELD_GATE => $gate, self::FIELD_STATUS => self::STATUS_SEM_DADOS, self::FIELD_EVIDENCE => 'receipt ilegível'];
        }
        if (! is_array($data)) {
            return [self::FIELD_GATE => $gate, self::FIELD_STATUS => self::STATUS_SEM_DADOS, self::FIELD_EVIDENCE => 'receipt não-objeto'];
        }

        $certified = ($data[self::FIELD_CERTIFIED] ?? false) === true;
        $fresh = (time() - (int) @filemtime($file)) <= self::RECEIPT_FRESH_SECONDS;

        return [
            self::FIELD_GATE => $gate,
            self::FIELD_STATUS => $certified && $fresh ? self::STATUS_CERTIFIED : self::STATUS_AGUARDANDO_JANELA,
            self::FIELD_CERTIFIED => $certified,
            self::FIELD_FRESH => $fresh,
            'receipt_status' => (AiValueNormalizer::trimmedStringOrNull($data[self::FIELD_STATUS] ?? null) ?? self::STATUS_UNKNOWN),
            self::FIELD_GENERATED_AT => (AiValueNormalizer::trimmedStringOrNull($data[self::FIELD_GENERATED_AT] ?? null) ?? ''),
        ];
    }
}
