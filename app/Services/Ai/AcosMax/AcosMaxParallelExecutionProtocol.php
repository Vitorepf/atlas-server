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
    public const FIELD_META = 'meta';
    public const FIELD_PROTOCOL = 'protocol';
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
    public const FIELD_LOTE = 'lote';
    public const FIELD_FAMILY = 'family';
    public const FIELD_SCHEMA = 'schema';
    public const FIELD_TARGET = 'target';
    public const FIELD_STATUS = 'status';
    public const FIELD_ACTION = 'action';
    public const FIELD_ENGINE = 'engine';
    public const FIELD_CLAIMED_BY = 'claimed_by';
    public const FIELD_SCOREBOARD_ANNOTATION = 'scoreboard_annotation';
    public const FIELD_CLAIM = 'claim';
    public const FIELD_SKIP_REASON = 'skip_reason';
    public const FIELD_TTL = 'ttl';
    public const FIELD_CWD = 'cwd';
    public const FIELD_WORKSPACE = 'workspace';


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
        $ttl = (int) ($opts[self::FIELD_TTL] ?? self::DEFAULT_TTL_SECONDS);

        $result = $this->blackboard->claim($engine, self::CLAIM_KIND, $target, [
            self::FIELD_TTL => $ttl,
            self::FIELD_WORKSPACE => $opts[self::FIELD_WORKSPACE] ?? null,
            self::FIELD_CWD => $opts[self::FIELD_CWD] ?? null,
            self::FIELD_META => [
                self::FIELD_PROTOCOL => self::SCHEMA,
                self::FIELD_LOTE => $lote,
                self::FIELD_FAMILY => $family,
            ],
        ]);

        $status = (AiValueNormalizer::trimmedStringOrNull($result[self::FIELD_STATUS] ?? null) ?? self::STATUS_ERROR);
        if (($result[self::FIELD_OK] ?? false) === true && in_array($status, [self::STATUS_ACTIVE, self::STATUS_RENEWED], true)) {
            return [
                self::FIELD_SCHEMA => self::SCHEMA,
                self::FIELD_OK => true,
                self::FIELD_STATUS => $status,
                self::FIELD_ACTION => self::ACTION_PROCEED,
                self::FIELD_ENGINE => $engine,
                self::FIELD_LOTE => $lote,
                self::FIELD_FAMILY => $family,
                self::FIELD_TARGET => $target,
                self::FIELD_CLAIMED_BY => $engine,
                self::FIELD_SCOREBOARD_ANNOTATION => 'claimed_by:'.$engine,
                self::FIELD_CLAIM => is_array($result[self::FIELD_CLAIM] ?? null) ? $result[self::FIELD_CLAIM] : null,
                self::STATUS_CONFLICT => null,
                self::FIELD_SKIP_REASON => null,
            ];
        }

        $conflictEngine = is_array($result[self::STATUS_CONFLICT] ?? null)
            ? AiValueNormalizer::lowerTrimmedString($result[self::STATUS_CONFLICT][self::FIELD_ENGINE] ?? '')
            : '';

        return [
            self::FIELD_SCHEMA => self::SCHEMA,
            self::FIELD_OK => true,
            self::FIELD_STATUS => $status === self::STATUS_CONFLICT ? self::STATUS_CONFLICT : $status,
            self::FIELD_ACTION => self::ACTION_SKIP,
            self::FIELD_ENGINE => $engine,
            self::FIELD_LOTE => $lote,
            self::FIELD_FAMILY => $family,
            self::FIELD_TARGET => $target,
            self::FIELD_CLAIMED_BY => $conflictEngine !== '' ? $conflictEngine : null,
            self::FIELD_SCOREBOARD_ANNOTATION => $conflictEngine !== '' ? 'claimed_by:'.$conflictEngine : '',
            self::FIELD_CLAIM => null,
            self::STATUS_CONFLICT => is_array($result[self::STATUS_CONFLICT] ?? null) ? $result[self::STATUS_CONFLICT] : null,
            self::FIELD_SKIP_REASON => sprintf(
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
            self::FIELD_SCHEMA => self::SCHEMA,
            self::FIELD_OK => true,
            'released' => ((int) (AiValueNormalizer::finiteFloatOrNull($released['released_count'] ?? null) ?? 0)) > 0,
            self::FIELD_TARGET => $target,
        ];
    }
}
