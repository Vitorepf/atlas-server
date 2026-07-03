<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskServing;

use Closure;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * ARCHITECTURE JUDGE — the semantic layer ABOVE the deterministic delta
 * prover: the prover measures what shrank; this judge reads the actual diff
 * against the seam decision and judges what numbers cannot see — clarity,
 * coupling, safety, IO shape, whether the abstraction is the RIGHT one.
 *
 * Runs on the local hermes one-shot (same pattern as the S53 semantic flow
 * arbiter: worker-side, local provider, zero cloud spend, nothing leaves the
 * machine). Provider/model follow the operator's directive: verboo/qwen3.6-35b
 * (config-overridable). ADVISORY by contract: the verdict lands on the receipt
 * and envelope, it NEVER blocks a report — enforce power stays with the
 * deterministic prover; semantics inform the operator and the learning loop.
 *
 * Fail-open everywhere: hermes missing/slow/non-JSON ⇒ status=unavailable.
 */
final class AtlasRefactorArchitectureJudge
{
    public const SCHEMA = 'atlas.task_serving.refactor_architecture_judgment.v1';

    /** @param Closure(string):string|null $runner test seam; production runs hermes one-shot */
    public function __construct(
        private readonly ?Closure $runner = null,
        private readonly ?string $repoRootOverride = null,
    ) {}

    /**
     * @param  array<string,mixed>  $designSpec
     * @param  array<string,mixed>  $proof  the delta prover's output
     * @param  list<string>  $scopeFiles  the seam scope (diff source)
     * @return array<string,mixed>
     */
    public function judge(array $designSpec, array $proof, array $scopeFiles): array
    {
        try {
            $diff = $this->scopeDiff($scopeFiles);
            if ($diff === '') {
                return ['schema' => self::SCHEMA, 'status' => 'no_diff'];
            }

            $prompt = "Você é um juiz de arquitetura de software. Julgue se este refactor melhora a arquitetura DE VERDADE.\n\n"
                .'DECISÃO DE COSTURA (design spec): '.json_encode($designSpec, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n"
                .'DELTAS MEDIDOS: '.json_encode($proof['delta'] ?? [], JSON_UNESCAPED_SLASHES)."\n\n"
                ."DIFF (HEAD → entrega):\n".mb_substr($diff, 0, 12000)."\n\n"
                .'Responda APENAS um JSON válido, sem markdown, no formato: '
                .'{"improves_architecture": true|false, "score_0_10": <número>, '
                .'"dimensions": {"clareza": -1|0|1, "acoplamento": -1|0|1, "seguranca": -1|0|1, "io": -1|0|1}, '
                .'"reasons": ["até 3 razões curtas"], "concerns": ["até 2 riscos, ou vazio"]}';

            $raw = trim(($this->runner ?? $this->hermesRunner())($prompt));
            // Hermes may wrap the JSON in prose — extract the outermost object.
            $start = strpos($raw, '{');
            $end = strrpos($raw, '}');
            $parsed = ($start !== false && $end !== false && $end > $start)
                ? json_decode(substr($raw, $start, $end - $start + 1), true)
                : null;
            if (! is_array($parsed) || ! array_key_exists('improves_architecture', $parsed)) {
                return ['schema' => self::SCHEMA, 'status' => 'unparseable', 'raw_excerpt' => mb_substr($raw, 0, 200)];
            }

            return [
                'schema' => self::SCHEMA,
                'status' => 'judged',
                'improves_architecture' => (bool) $parsed['improves_architecture'],
                'score_0_10' => (float) ($parsed['score_0_10'] ?? 0.0),
                'dimensions' => (array) ($parsed['dimensions'] ?? []),
                'reasons' => array_values((array) ($parsed['reasons'] ?? [])),
                'concerns' => array_values((array) ($parsed['concerns'] ?? [])),
                'judge' => (string) config('atlas_task_governance.refactor_judge_model', 'verboo/qwen3.6-35b'),
            ];
        } catch (Throwable $e) {
            return ['schema' => self::SCHEMA, 'status' => 'unavailable', 'error' => mb_substr($e->getMessage(), 0, 200)];
        }
    }

    private function hermesRunner(): Closure
    {
        return function (string $prompt): string {
            $binary = (string) config('atlas_task_governance.refactor_judge_binary', 'hermes');
            [$provider, $model] = array_pad(explode(
                '/',
                (string) config('atlas_task_governance.refactor_judge_model', 'verboo/qwen3.6-35b'),
                2,
            ), 2, '');
            $timeout = max(10.0, (float) config('atlas_task_governance.refactor_judge_timeout_seconds', 120));

            $args = [$binary, '-z', $prompt];
            if ($provider !== '') {
                $args[] = '--provider';
                $args[] = $provider;
            }
            if ($model !== '') {
                $args[] = '--model';
                $args[] = $model;
            }
            $p = new Process($args, $this->repoRootOverride ?? base_path(), null, null, $timeout);
            $p->run();

            return $p->isSuccessful() ? $p->getOutput() : '';
        };
    }

    /** @param list<string> $scopeFiles */
    private function scopeDiff(array $scopeFiles): string
    {
        $repo = $this->repoRootOverride ?? base_path();
        $args = array_merge(['git', 'diff', 'HEAD', '--'], array_values(array_filter(array_map('strval', $scopeFiles))));
        $p = new Process($args, $repo, null, null, 20.0);
        $p->run();

        return $p->isSuccessful() ? $p->getOutput() : '';
    }
}
