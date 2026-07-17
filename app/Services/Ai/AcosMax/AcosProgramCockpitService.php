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
    public const SCHEMA_VERSION = 'atlas.acos.cockpit.v1';

    public const STATUS_UNAVAILABLE = 'unavailable';

    public const REASON_SOURCE_NOT_LANDED_YET = 'source_not_landed_yet';


    public function report(?string $scoreboardPath = null): array
    {
        $scoreboardPath ??= base_path('docs/engineering-knowledge-base/atlas-acos-max-execution-scoreboard-v1.md');

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'mutates_state' => false,
            'sections' => [
                'm' => $this->commandSection('atlas:acos:m-series --json', 'atlas:acos:m-series', ['--json' => true]),
                'r' => $this->commandSection('atlas:atlas-decide:live-feedback --regret --json', 'atlas:atlas-decide:live-feedback', ['--regret' => true, '--json' => true]),
                'loops_funnel' => $this->loopsFunnelSection(),
                'windows' => $this->commandSection('atlas:windows --json', 'atlas:windows', ['--json' => true]),
                'pending_flips' => $this->commandSection('atlas:promotions --json', 'atlas:promotions', ['--json' => true]),
                'review_debt' => $this->callbackSection(
                    'AtlasOperatorReviewDebtMeter::report(7)',
                    fn (): array => (new AtlasOperatorReviewDebtMeter)->report(7),
                ),
                'brakes' => $this->brakesSection(),
                'current_lote' => $this->scoreboardSection($scoreboardPath),
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
                return $this->unavailable($source, 'source_did_not_emit_json', ['exit_code' => $exitCode]);
            }

            return [
                'status' => 'ok',
                'source' => $source,
                'source_exit_code' => $exitCode,
                'payload' => $decoded,
            ];
        } catch (Throwable $e) {
            return $this->unavailable($source, 'source_unavailable', ['error' => mb_substr($e->getMessage(), 0, 200)]);
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
                'status' => 'ok',
                'source' => $source,
                'payload' => $callback(),
            ];
        } catch (Throwable $e) {
            return $this->unavailable($source, 'source_unavailable', ['error' => mb_substr($e->getMessage(), 0, 200)]);
        }
    }

    /** @return array<string,mixed> */
    private function loopsFunnelSection(): array
    {
        return [
            'status' => 'ok',
            'source' => [
                'loops' => 'atlas:flywheel:loops --json',
                'funnel' => 'MULTX-02 future source',
            ],
            'payload' => [
                'loops' => $this->commandSection('atlas:flywheel:loops --json', 'atlas:flywheel:loops', ['--json' => true]),
                'funnel' => [
                    'status' => self::STATUS_UNAVAILABLE,
                    'source' => 'MULTX-02',
                    'reason' => self::REASON_SOURCE_NOT_LANDED_YET,
                ],
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function brakesSection(): array
    {
        return [
            'status' => 'ok',
            'source' => [
                'rollback_triggers' => 'atlas:acos:rollback-triggers --json',
                'operational_volume' => 'atlas:acos:operational-volume --json',
            ],
            'payload' => [
                'rollback_triggers' => $this->commandSection('atlas:acos:rollback-triggers --json', 'atlas:acos:rollback-triggers', ['--json' => true]),
                'operational_volume' => $this->commandSection('atlas:acos:operational-volume --json', 'atlas:acos:operational-volume', ['--json' => true]),
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function scoreboardSection(string $path): array
    {
        if (! is_file($path)) {
            return $this->unavailable($path, 'scoreboard_missing');
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
                $current = ['heading' => $line, 'lines' => []];

                continue;
            }
            if (is_array($current)) {
                if (str_starts_with($line, '## ')) {
                    $blocks[] = $current;
                    $current = null;

                    continue;
                }
                if ((AiValueNormalizer::trimmedStringOrNull($line) ?? '') !== '') {
                    $current['lines'][] = $line;
                }
            }
        }
        if (is_array($current)) {
            $blocks[] = $current;
        }

        $selected = null;
        foreach ($blocks as $block) {
            $text = implode("\n", AiValueNormalizer::arrayOrEmpty($block['lines'] ?? null));
            if (str_contains($text, '[ ]')) {
                $selected = $block;
                break;
            }
        }
        $selected ??= $blocks[0] ?? null;
        if (! is_array($selected)) {
            return $this->unavailable($path, 'current_lote_not_found');
        }

        return [
            'status' => 'ok',
            'source' => $path,
            'payload' => [
                'heading' => AiValueNormalizer::trimmedStringOrNull($selected['heading'] ?? null) ?? '',
                'lines' => array_values(AiValueNormalizer::arrayOrEmpty($selected['lines'] ?? null)),
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
            'status' => self::STATUS_UNAVAILABLE,
            'source' => $source,
            'reason' => $reason,
            ...$extra,
        ];
    }
}
