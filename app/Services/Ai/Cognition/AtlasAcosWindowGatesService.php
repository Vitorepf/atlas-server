<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognition;

use App\Services\Ai\Memory\AtlasMemoryQualityService;
use App\Services\Ai\Support\AiValueNormalizer;
use Throwable;
use App\Support\UtcIsoTimestamp;

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
    public const FIELD_RECEIPT_STATUS = 'receipt_status';
    public const FIELD_SCHEMA_VERSION = 'schema_version';
    public const FIELD_VALUE = 'value';
    public const FIELD_WINDOW_RECEIPTS = 'window_receipts';
    public const FIELD_FEEDBACK = 'feedback';
    public const FIELD_MEMORY_QUALITY = 'memory_quality';
    public const FIELD_RATIONALE = 'rationale';
    public const FIELD_RELATION_DENSITY = 'relation_density';
    public const FIELD_STORAGE_PATH = 'storage_path';
    public const FIELD_STRUCTURAL_HONESTY = 'structural_honesty';
    public const FIELD_REPORTED = 'reported';
    public const FIELD_D3_RELATION_DENSITY = 'D3_relation_density';
    public const FIELD_D4_D5_FEEDBACK = 'D4_D5_feedback';
    public const FIELD_D5_RATIONALE = 'D5_rationale';
    public const FIELD_D5_STRUCTURAL_HONESTY = 'D5_structural_honesty';
    public const FIELD_APP_ATLAS_EVIDENCE = 'app/atlas/evidence';
    public const FIELD__JSON = '.json';
    public const FIELD_RECEIPT_ILEG_VEL = 'receipt ilegível';
    public const FIELD_RECEIPT_N_O_OBJETO = 'receipt não-objeto';
    public const FIELD_VER_DOC__JANELA_ = 'ver doc (janela)';
    public const INT_70 = 70;

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
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_GENERATED_AT => UtcIsoTimestamp::now(),
            self::FIELD_LIVE_DIMENSIONS => $this->liveDimensions(),
            self::FIELD_WINDOW_RECEIPTS => $this->windowReceipts(),
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
                self::FIELD_GATE => self::FIELD_MEMORY_QUALITY,
                self::FIELD_STATUS => self::STATUS_SEM_DADOS,
                self::FIELD_EVIDENCE => 'AtlasMemoryQualityService::scorecard indisponível (tabelas ausentes)',
            ]];
        }

        $dims = AiValueNormalizer::arrayOrEmpty($card[self::FIELD_COMPONENTS] ?? null);

        return [
            $this->dimension(self::FIELD_D3_RELATION_DENSITY, $dims, self::FIELD_RELATION_DENSITY, '>=self::INT_70', 70, true),
            $this->dimension(self::FIELD_D5_STRUCTURAL_HONESTY, $dims, self::FIELD_STRUCTURAL_HONESTY, '>=self::INT_70', 70, true),
            $this->dimension(self::FIELD_D5_RATIONALE, $dims, self::FIELD_RATIONALE, '>=self::INT_70', 70, true),
            // D5 feedback / composite: the target direction is contested in the
            // docs (quality composite vs "<=50" marker) → report, never assert.
            $this->dimension(self::FIELD_D4_D5_FEEDBACK, $dims, self::FIELD_FEEDBACK, self::FIELD_VER_DOC__JANELA_, null, false),
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

        $out = [self::FIELD_GATE => $gate, self::FIELD_TARGET => $target, self::FIELD_VALUE => $value];
        $numeric = AiValueNormalizer::finiteFloatOrNull($value);
        if ($assert && $threshold !== null && $numeric !== null) {
            $out[self::FIELD_STATUS] = $numeric >= $threshold ? self::STATUS_MET : self::STATUS_AGUARDANDO_JANELA;
        } else {
            $out[self::FIELD_STATUS] = self::FIELD_REPORTED;
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
        $dir = function_exists(self::FIELD_STORAGE_PATH)
            ? storage_path(self::FIELD_APP_ATLAS_EVIDENCE)
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
        $gate = basename($file, self::FIELD__JSON);
        try {
            $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [self::FIELD_GATE => $gate, self::FIELD_STATUS => self::STATUS_SEM_DADOS, self::FIELD_EVIDENCE => self::FIELD_RECEIPT_ILEG_VEL];
        }
        if (! is_array($data)) {
            return [self::FIELD_GATE => $gate, self::FIELD_STATUS => self::STATUS_SEM_DADOS, self::FIELD_EVIDENCE => self::FIELD_RECEIPT_N_O_OBJETO];
        }

        $certified = ($data[self::FIELD_CERTIFIED] ?? false) === true;
        $fresh = (time() - (int) @filemtime($file)) <= self::RECEIPT_FRESH_SECONDS;

        return [
            self::FIELD_GATE => $gate,
            self::FIELD_STATUS => $certified && $fresh ? self::STATUS_CERTIFIED : self::STATUS_AGUARDANDO_JANELA,
            self::FIELD_CERTIFIED => $certified,
            self::FIELD_FRESH => $fresh,
            self::FIELD_RECEIPT_STATUS => (AiValueNormalizer::trimmedStringOrNull($data[self::FIELD_STATUS] ?? null) ?? self::STATUS_UNKNOWN),
            self::FIELD_GENERATED_AT => (AiValueNormalizer::trimmedStringOrNull($data[self::FIELD_GENERATED_AT] ?? null) ?? ''),
        ];
    }
}
