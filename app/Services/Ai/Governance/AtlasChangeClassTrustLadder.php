<?php

declare(strict_types=1);

namespace App\Services\Ai\Governance;

use App\Services\Ai\Policy\PolicyCanon;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Facades\File;

/**
 * Self-Construction trust ladder: a per-change-CLASS record of re-checkable evidence
 * (frozen-judge passes, clean promotions) that lets a class EARN lower friction over
 * time — the scaffolding for Atlas to safely earn the right to improve itself.
 *
 * Two pétreo sovereignty properties, structural:
 *   1. DEFAULT = MAX FRICTION. With no operator-configured thresholds (the default),
 *      every class stays at AUTONOMY_SUGGEST — operator approval for everything.
 *      Autonomy rises ONLY past thresholds the OPERATOR sets, from evidence.
 *   2. NEVER above the risk cap. earnedAutonomy() tops out at AUTONOMOUS, and
 *      admission re-binds it under the risk-canon cap (min), so it can only relax
 *      friction WITHIN the cap, never beyond it.
 *
 * Trust is asymmetric: a single revert resets the class's clean streak to zero —
 * friction returns immediately. Trust accrues only from re-checkable evidence,
 * never from a provider self-report.
 */
final class AtlasChangeClassTrustLadder
{
    public const SCHEMA = 'atlas.ai.change_class_trust_ladder.v1';

    public const EVIDENCE_FROZEN_JUDGE_PASS = 'frozen_judge_pass';

    public const EVIDENCE_CLEAN_PROMOTION = 'clean_promotion';

    public const EVIDENCE_REVERT = 'revert';

    private ?string $logOverride = null;

    public function setLogPathForTesting(?string $path): void
    {
        $this->logOverride = $path;
    }

    public function logPath(): string
    {
        if ($this->logOverride !== null) {
            return $this->logOverride;
        }
        $base = function_exists('storage_path')
            ? storage_path('atlas/governance')
            : sys_get_temp_dir().'/atlas/governance';

        return $base.DIRECTORY_SEPARATOR.'change_class_trust.jsonl';
    }

    public function recordEvidence(string $changeClass, string $kind, ?string $ref = null): void
    {
        $class = trim($changeClass);
        if ($class === '') {
            return;
        }
        $this->append([
            'schema_version' => self::SCHEMA,
            'recorded_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
            'change_class' => $class,
            'evidence_kind' => $kind,
            'clean' => $kind !== self::EVIDENCE_REVERT,
            'ref' => $ref,
        ]);
    }

    /** Consecutive clean evidences since the last revert (a revert resets to 0). */
    public function cleanStreak(string $changeClass): int
    {
        $class = trim($changeClass);
        $streak = 0;
        foreach ($this->read() as $e) {
            if ((string) ($e['change_class'] ?? '') !== $class) {
                continue;
            }
            $streak = ($e['clean'] ?? false) === true ? $streak + 1 : 0;
        }

        return $streak;
    }

    /**
     * The autonomy a class has EARNED. Default (no thresholds) = SUGGEST (max
     * friction). Never above AUTONOMOUS; admission re-binds under the risk cap.
     */
    public function earnedAutonomy(string $changeClass): string
    {
        $streak = $this->cleanStreak($changeClass);
        $t = (array) config('atlas.ai.trust_ladder.thresholds', []);

        if ($streak >= $this->threshold($t, 'autonomous')) {
            return PolicyCanon::AUTONOMY_AUTONOMOUS;
        }
        if ($streak >= $this->threshold($t, 'execute_with_approval')) {
            return PolicyCanon::AUTONOMY_EXECUTE_WITH_APPROVAL;
        }
        if ($streak >= $this->threshold($t, 'draft')) {
            return PolicyCanon::AUTONOMY_DRAFT;
        }

        return PolicyCanon::AUTONOMY_SUGGEST;
    }

    /**
     * @return array{schema_version:string,change_class:string,clean_streak:int,earned_autonomy:string}
     */
    public function snapshot(string $changeClass): array
    {
        return [
            'schema_version' => self::SCHEMA,
            'change_class' => trim($changeClass),
            'clean_streak' => $this->cleanStreak($changeClass),
            'earned_autonomy' => $this->earnedAutonomy($changeClass),
        ];
    }

    /**
     * @param  array<string,mixed>  $thresholds
     */
    private function threshold(array $thresholds, string $key): int
    {
        $v = $thresholds[$key] ?? null;

        // Absent / non-positive => unreachable (disabled) => the tier never unlocks.
        return is_numeric($v) && (int) $v > 0 ? (int) $v : PHP_INT_MAX;
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function append(array $row): void
    {
        $path = $this->logPath();
        File::ensureDirectoryExists(dirname($path));
        File::append($path, json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function read(): array
    {
        $path = $this->logPath();
        if (! File::exists($path)) {
            return [];
        }
        $out = [];
        foreach (preg_split('/\r?\n/', (string) File::get($path)) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }
            $row = json_decode($line, true);
            if (is_array($row)) {
                $out[] = $row;
            }
        }

        return $out;
    }
}
