<?php

declare(strict_types=1);

namespace App\Services\Ai\AcosMax;

use App\Services\Ai\Autonomy\AtlasOperatorReviewDebtMeter;
use App\Services\Ai\Support\AiValueNormalizer;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

final class AcosProgramCockpitService
{
    public const FIELD_BRAKES = 'brakes';
    public const FIELD_CURRENT_LOTE = 'current_lote';
    public const SCHEMA_VERSION = 'atlas.acos.cockpit.v1';

    public const STATUS_UNAVAILABLE = 'unavailable';

    public const STATUS_OK = 'ok';

    public const REASON_SOURCE_NOT_LANDED_YET = 'source_not_landed_yet';

    public const REASON_SOURCE_UNAVAILABLE = 'source_unavailable';

    public const FIELD_ERROR = 'error';
    public const FIELD_STATUS = 'status';
    public const FIELD_SOURCE = 'source';
    public const FIELD_PAYLOAD = 'payload';
    public const FIELD_LINES = 'lines';
    public const FIELD_OK = 'ok';
    public const FIELD_REASON = 'reason';
    public const FIELD_SECTIONS = 'sections';
    public const FIELD_LOOPS = 'loops';
    public const FIELD_FUNNEL = 'funnel';
    public const FIELD_ROLLBACK_TRIGGERS = 'rollback_triggers';
    public const FIELD_OPERATIONAL_VOLUME = 'operational_volume';
    public const FIELD_HEADING = 'heading';
    public const FIELD_EXTERNAL_PROVIDER_CALL = 'external_provider_call';
    public const FIELD_PROVIDER_TOKENS_SPENT = 'provider_tokens_spent';
    public const FIELD_EXIT_CODE = 'exit_code';
    public const FIELD_GENERATED_AT = 'generated_at';
    public const FIELD_LOOPS_FUNNEL = 'loops_funnel';
    public const FIELD_MUTATES_STATE = 'mutates_state';
    public const FIELD_PENDING_FLIPS = 'pending_flips';
    public const FIELD_REVIEW_DEBT = 'review_debt';
    public const FIELD_SCHEMA_VERSION = 'schema_version';
    public const FIELD_SOURCE_EXIT_CODE = 'source_exit_code';
    public const FIELD_WINDOWS = 'windows';
    public const FIELD_CURRENT_LOTE_NOT_FOUND = 'current_lote_not_found';
    public const FIELD_NOW = 'now';
    public const FIELD_SCOREBOARD_MISSING = 'scoreboard_missing';
    public const FIELD_SOURCE_DID_NOT_EMIT_JSON = 'source_did_not_emit_json';
    public const FIELD_UTC = 'UTC';
    public const FIELD_MULTX_02 = 'MULTX-02';
    public const FIELD_ATLAS_PROMOTIONS = 'atlas:promotions';
    public const FIELD_ATLAS_WINDOWS = 'atlas:windows';
    public const FIELD___JSON = '--json';
    public const FIELD___REGRET = '--regret';
    public const FIELD_ATLAS_ACOS_M_SERIES = 'atlas:acos:m-series';
    public const FIELD_ATLAS_ACOS_OPERATIONAL_VOLUME = 'atlas:acos:operational-volume';
    public const FIELD_ATLAS_ACOS_ROLLBACK_TRIGGERS = 'atlas:acos:rollback-triggers';
    public const FIELD_ATLAS_ATLAS_DECIDE_LIVE_FEEDBACK = 'atlas:atlas-decide:live-feedback';


    public function report(?string $scoreboardPath = null): array
    {
        $scoreboardPath ??= base_path('docs/engineering-knowledge-base/atlas-acos-max-execution-scoreboard-v1.md');

        return [
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_GENERATED_AT => (new DateTimeImmutable(self::FIELD_NOW, new DateTimeZone(self::FIELD_UTC)))->format(DateTimeInterface::ATOM),
            self::FIELD_EXTERNAL_PROVIDER_CALL => false,
            self::FIELD_PROVIDER_TOKENS_SPENT => false,
            self::FIELD_MUTATES_STATE => false,
            self::FIELD_SECTIONS => [
                'm' => $this->commandSection('atlas:acos:m-series --json', self::FIELD_ATLAS_ACOS_M_SERIES, [self::FIELD___JSON => true]),
                'r' => $this->commandSection('atlas:atlas-decide:live-feedback --regret --json', self::FIELD_ATLAS_ATLAS_DECIDE_LIVE_FEEDBACK, [self::FIELD___REGRET => true, self::FIELD___JSON => true]),
                self::FIELD_LOOPS_FUNNEL => $this->loopsFunnelSection(),
                self::FIELD_WINDOWS => $this->commandSection('atlas:windows --json', self::FIELD_ATLAS_WINDOWS, [self::FIELD___JSON => true]),
                self::FIELD_PENDING_FLIPS => $this->commandSection('atlas:promotions --json', self::FIELD_ATLAS_PROMOTIONS, [self::FIELD___JSON => true]),
                self::FIELD_REVIEW_DEBT => $this->callbackSection(
                    'AtlasOperatorReviewDebtMeter::report(7)',
                    fn (): array => (new AtlasOperatorReviewDebtMeter)->report(7),
                ),
                self::FIELD_BRAKES => $this->brakesSection(),
                self::FIELD_CURRENT_LOTE => $this->scoreboardSection($scoreboardPath),
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    private function commandSection(string $source, string $command, array $arguments): array
    {
        try {
            $buffer = new BufferedOutput;
            $exitCode = Artisan::call($command, $arguments, $buffer);
            $decoded = json_decode(AiValueNormalizer::trimmedStringOrNull($buffer->fetch()) ?? '', true);
            if (! is_array($decoded)) {
                return $this->unavailable($source, self::FIELD_SOURCE_DID_NOT_EMIT_JSON, [self::FIELD_EXIT_CODE => $exitCode]);
            }

            return [
                self::FIELD_STATUS => self::STATUS_OK,
                self::FIELD_SOURCE => $source,
                self::FIELD_SOURCE_EXIT_CODE => $exitCode,
                self::FIELD_PAYLOAD => $decoded,
            ];
        } catch (Throwable $e) {
            return $this->unavailable($source, self::REASON_SOURCE_UNAVAILABLE, [self::FIELD_ERROR => mb_substr($e->getMessage(), 0, 200)]);
        }
    }

    /**
     * @param  callable(): array<string,mixed>  $callback
     * @return array<string,mixed>
     */
    private function callbackSection(string $source, callable $callback): array
    {
        try {
            return [
                self::FIELD_STATUS => self::STATUS_OK,
                self::FIELD_SOURCE => $source,
                self::FIELD_PAYLOAD => $callback(),
            ];
        } catch (Throwable $e) {
            return $this->unavailable($source, self::REASON_SOURCE_UNAVAILABLE, [self::FIELD_ERROR => mb_substr($e->getMessage(), 0, 200)]);
        }
    }

    /** @return array<string,mixed> */
    private function loopsFunnelSection(): array
    {
        return [
            self::FIELD_STATUS => self::STATUS_OK,
            self::FIELD_SOURCE => [
                self::FIELD_LOOPS => 'atlas:flywheel:loops --json',
                self::FIELD_FUNNEL => 'MULTX-02 future source',
            ],
            self::FIELD_PAYLOAD => [
                self::FIELD_LOOPS => $this->commandSection('atlas:flywheel:loops --json', 'atlas:flywheel:loops', [self::FIELD___JSON => true]),
                self::FIELD_FUNNEL => [
                    self::FIELD_STATUS => self::STATUS_UNAVAILABLE,
                    self::FIELD_SOURCE => self::FIELD_MULTX_02,
                    self::FIELD_REASON => self::REASON_SOURCE_NOT_LANDED_YET,
                ],
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function brakesSection(): array
    {
        return [
            self::FIELD_STATUS => self::STATUS_OK,
            self::FIELD_SOURCE => [
                self::FIELD_ROLLBACK_TRIGGERS => 'atlas:acos:rollback-triggers --json',
                self::FIELD_OPERATIONAL_VOLUME => 'atlas:acos:operational-volume --json',
            ],
            self::FIELD_PAYLOAD => [
                self::FIELD_ROLLBACK_TRIGGERS => $this->commandSection('atlas:acos:rollback-triggers --json', self::FIELD_ATLAS_ACOS_ROLLBACK_TRIGGERS, [self::FIELD___JSON => true]),
                self::FIELD_OPERATIONAL_VOLUME => $this->commandSection('atlas:acos:operational-volume --json', self::FIELD_ATLAS_ACOS_OPERATIONAL_VOLUME, [self::FIELD___JSON => true]),
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function scoreboardSection(string $path): array
    {
        if (! is_file($path)) {
            return $this->unavailable($path, self::FIELD_SCOREBOARD_MISSING);
        }

        $content = (string) file_get_contents($path);
        $lines = preg_split('/\R/', $content) ?: [];
        $blocks = [];
        $current = null;
        foreach ($lines as $line) {
            if (str_starts_with($line, '## LOTE ')) {
                if (is_array($current)) {
                    $blocks[] = $current;
                }
                $current = [self::FIELD_HEADING => $line, self::FIELD_LINES => []];

                continue;
            }
            if (is_array($current)) {
                if (str_starts_with($line, '## ')) {
                    $blocks[] = $current;
                    $current = null;

                    continue;
                }
                if ((AiValueNormalizer::trimmedStringOrNull($line) ?? '') !== '') {
                    $current[self::FIELD_LINES][] = $line;
                }
            }
        }
        if (is_array($current)) {
            $blocks[] = $current;
        }

        $selected = null;
        foreach ($blocks as $block) {
            $text = implode("\n", AiValueNormalizer::arrayOrEmpty($block[self::FIELD_LINES] ?? null));
            if (str_contains($text, '[ ]')) {
                $selected = $block;
                break;
            }
        }
        $selected ??= $blocks[0] ?? null;
        if (! is_array($selected)) {
            return $this->unavailable($path, self::FIELD_CURRENT_LOTE_NOT_FOUND);
        }

        return [
            self::FIELD_STATUS => self::STATUS_OK,
            self::FIELD_SOURCE => $path,
            self::FIELD_PAYLOAD => [
                self::FIELD_HEADING => AiValueNormalizer::trimmedStringOrNull($selected[self::FIELD_HEADING] ?? null) ?? '',
                self::FIELD_LINES => array_values(AiValueNormalizer::arrayOrEmpty($selected[self::FIELD_LINES] ?? null)),
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    private function unavailable(string $source, string $reason, array $extra = []): array
    {
        return [
            self::FIELD_STATUS => self::STATUS_UNAVAILABLE,
            self::FIELD_SOURCE => $source,
            self::FIELD_REASON => $reason,
            ...$extra,
        ];
    }
}
