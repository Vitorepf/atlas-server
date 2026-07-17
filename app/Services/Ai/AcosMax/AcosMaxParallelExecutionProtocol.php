<?php

declare(strict_types=1);

namespace App\Services\Ai\AcosMax;

use App\Services\Ai\AtlasAobgBlackboardService;
use App\Services\Ai\Support\AiValueNormalizer;

/**
 * TETO-06 — multi-engine parallel execution protocol for ACOS Max.
 *
 * Claims are per FAMÍLIA×lote (never per slice). Hot files stay under ELEV-22.
 * Advisory/fail-open: a foreign family claim never hard-blocks; the second engine
 * records a skip and picks another eligible family.
 */
final class AcosMaxParallelExecutionProtocol
{
    public const SCHEMA = 'acos_max.parallel_execution.v1';

    public const CLAIM_KIND = 'task';

    public const DEFAULT_TTL_SECONDS = 3600;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_RENEWED = 'renewed';

    public const STATUS_CONFLICT = 'conflict';

    public const STATUS_ERROR = 'error';

    public const ACTION_PROCEED = 'proceed';

    public const ACTION_SKIP = 'skip';

    public const ENGINE_UNKNOWN = 'unknown';

    public const FIELD_OK = 'ok';


    public function __construct(
        private readonly AtlasAobgBlackboardService $blackboard,
    ) {}

    public static function familyClaimTarget(int $lote, string $family): string
    {
        $lote = max(0, $lote);
        $family = AiValueNormalizer::upperTrimmedString($family);

        return sprintf('acos-max:lote-%d:family:%s', $lote, $family);
    }

    /**
     * @param  array<string,mixed>  $opts
     * @return array{
     *   schema:string,
     *   ok:bool,
     *   status:string,
     *   action:string,
     *   engine:string,
     *   lote:int,
     *   family:string,
     *   target:string,
     *   claimed_by:?string,
     *   scoreboard_annotation:string,
     *   claim:?array<string,mixed>,
     *   conflict:?array<string,mixed>,
     *   skip_reason:?string
     * }
     */
    public function claimFamily(string $engine, int $lote, string $family, array $opts = []): array
    {
        $engine = AiValueNormalizer::lowerTrimmedString($engine);
        $family = AiValueNormalizer::upperTrimmedString($family);
        $target = self::familyClaimTarget($lote, $family);
        $ttl = (int) ($opts['ttl'] ?? self::DEFAULT_TTL_SECONDS);

        $result = $this->blackboard->claim($engine, self::CLAIM_KIND, $target, [
            'ttl' => $ttl,
            'workspace' => $opts['workspace'] ?? null,
            'cwd' => $opts['cwd'] ?? null,
            'meta' => [
                'protocol' => self::SCHEMA,
                'lote' => $lote,
                'family' => $family,
            ],
        ]);

        $status = (AiValueNormalizer::trimmedStringOrNull($result['status'] ?? null) ?? self::STATUS_ERROR);
        if (($result[self::FIELD_OK] ?? false) === true && in_array($status, [self::STATUS_ACTIVE, self::STATUS_RENEWED], true)) {
            return [
                'schema' => self::SCHEMA,
                self::FIELD_OK => true,
                'status' => $status,
                'action' => self::ACTION_PROCEED,
                'engine' => $engine,
                'lote' => $lote,
                'family' => $family,
                'target' => $target,
                'claimed_by' => $engine,
                'scoreboard_annotation' => 'claimed_by:'.$engine,
                'claim' => is_array($result['claim'] ?? null) ? $result['claim'] : null,
                self::STATUS_CONFLICT => null,
                'skip_reason' => null,
            ];
        }

        $conflictEngine = is_array($result[self::STATUS_CONFLICT] ?? null)
            ? AiValueNormalizer::lowerTrimmedString($result[self::STATUS_CONFLICT]['engine'] ?? '')
            : '';

        return [
            'schema' => self::SCHEMA,
            self::FIELD_OK => true,
            'status' => $status === self::STATUS_CONFLICT ? self::STATUS_CONFLICT : $status,
            'action' => self::ACTION_SKIP,
            'engine' => $engine,
            'lote' => $lote,
            'family' => $family,
            'target' => $target,
            'claimed_by' => $conflictEngine !== '' ? $conflictEngine : null,
            'scoreboard_annotation' => $conflictEngine !== '' ? 'claimed_by:'.$conflictEngine : '',
            'claim' => null,
            self::STATUS_CONFLICT => is_array($result[self::STATUS_CONFLICT] ?? null) ? $result[self::STATUS_CONFLICT] : null,
            'skip_reason' => sprintf(
                'family_claimed_by_other_engine:%s',
                $conflictEngine !== '' ? $conflictEngine : self::ENGINE_UNKNOWN,
            ),
        ];
    }

    /**
     * @param  array<string,mixed>  $opts
     * @return array{schema:string,ok:bool,released:bool,target:string}
     */
    public function releaseFamily(string $engine, int $lote, string $family, array $opts = []): array
    {
        $target = self::familyClaimTarget($lote, $family);
        $released = $this->blackboard->releaseActiveForTargets(
            AiValueNormalizer::lowerTrimmedString($engine),
            self::CLAIM_KIND,
            [$target],
            $opts,
        );

        return [
            'schema' => self::SCHEMA,
            self::FIELD_OK => true,
            'released' => ((int) (AiValueNormalizer::finiteFloatOrNull($released['released_count'] ?? null) ?? 0)) > 0,
            'target' => $target,
        ];
    }
}
