<?php

declare(strict_types=1);

namespace App\Services\Ai\Obra;

use App\Models\AiJob;
use App\Services\Ai\AiProviderManager;
use Throwable;

/**
 * AOBG N3.F1 — the REAL (provider-backed) decomposer. THE path that spends.
 *
 * Asks a provider (resolved by {@see AiProviderManager}, gated by
 * `atlas.obra.decompose_provider`) to break an intent into an ordered, dependency-
 * aware list of steps, returned as STRICT JSON. It then maps the JSON into
 * {@see ObraNodeDraft}s for the {@see AtlasObraPlanService} to validate + anchor +
 * persist. The actual provider invocation is isolated behind the protected
 * {@see invokeProvider()} seam so a test can override it and exercise the full
 * decode/parse/validate path with ZERO provider spend — the same test seam
 * philosophy as {@see \App\Services\Ai\RealExecution\AtlasLiveCodeDeliveryService}'s
 * sandbox factory.
 *
 * HONEST DEGRADE (anti-over-claim): the decomposer NEVER throws and never returns a
 * fabricated plan. If the provider is unconfigured, errors, returns not-ok, or
 * returns unparseable output, it falls back to the {@see DeterministicObraDecomposer}
 * (cost-free, deterministic) so an obra is always planned — degraded, never broken.
 * The fallback is recorded in the label so a reader knows the plan degraded.
 *
 * PROVIDER-SAFE: the prompt carries ONLY the operator's intent + structural
 * instructions (no Atlas-internal ids/traces). The decomposer reads back step
 * LABELS only (title / request / target_area / depends_on keys) — it never asks for
 * or stores source.
 */
class ProviderObraDecomposer implements ObraDecomposer
{
    public function __construct(
        private readonly AiProviderManager $providers,
        private readonly DeterministicObraDecomposer $fallback = new DeterministicObraDecomposer,
    ) {}

    /** Whether the provider path actually ran on the most recent decompose. */
    private bool $degraded = false;

    public function label(): string
    {
        $key = (string) config('atlas.obra.decompose_provider', '');

        return $this->degraded || $key === ''
            ? 'provider_fallback:deterministic'
            : 'provider:'.$key;
    }

    /**
     * @param  array<string,mixed>  $opts
     * @return list<ObraNodeDraft>
     */
    public function decompose(string $intent, array $opts = []): array
    {
        $this->degraded = false;
        $intent = trim($intent);
        if ($intent === '') {
            return [];
        }

        $providerKey = trim((string) config('atlas.obra.decompose_provider', ''));
        if ($providerKey === '') {
            // No provider configured ⇒ deterministic plan, honestly degraded.
            $this->degraded = true;

            return $this->fallback->decompose($intent, $opts);
        }

        $maxNodes = max(1, (int) ($opts['max_nodes'] ?? config('atlas.obra.max_nodes', 12)));

        try {
            $output = $this->invokeProvider($providerKey, $this->prompt($intent, $maxNodes), $opts);
            $drafts = $this->parse($output);
            if ($drafts === []) {
                $this->degraded = true;

                return $this->fallback->decompose($intent, $opts);
            }

            return $drafts;
        } catch (Throwable) {
            // Honest degrade — a provider outage never breaks planning.
            $this->degraded = true;

            return $this->fallback->decompose($intent, $opts);
        }
    }

    /**
     * The provider call seam — overridable in tests for ZERO-spend coverage of the
     * decode/parse/validate path. Returns the raw provider text (expected: a JSON
     * step list). Throws on provider error / not-ok so {@see decompose()} can degrade.
     *
     * @param  array<string,mixed>  $opts
     */
    protected function invokeProvider(string $providerKey, string $prompt, array $opts): string
    {
        $provider = $this->providers->get($providerKey);

        $job = new AiJob;
        $job->kind = 'obra_decompose';
        $job->provider = $providerKey;
        $job->model = (string) ($opts['model'] ?? '');
        $job->prompt = $prompt;
        $job->input_text = $prompt;
        // READ-ONLY: the decomposer only ever returns text (a plan); it can edit nothing.
        $job->metadata = ['permission_mode' => 'read', 'obra_decompose' => true];
        $job->timeout_seconds = (int) ($opts['timeout_seconds'] ?? 120);

        $result = $provider->run($job, $prompt);
        if (! (bool) ($result->ok ?? false)) {
            throw new \RuntimeException('provider_returned_not_ok:'.(string) ($result->errorCode ?? ''));
        }

        return (string) ($result->output ?? '');
    }

    /**
     * The provider-bound decomposition prompt. Structural + provider-safe: it asks
     * for a STRICT JSON array of steps with explicit `key` handles + `depends_on`
     * references so the service can build + validate the DAG.
     */
    private function prompt(string $intent, int $maxNodes): string
    {
        return <<<PROMPT
        You are decomposing a software-engineering INTENT into an ordered, dependency-aware
        plan of small steps (an "obra"). Output STRICT JSON ONLY — a single array, no prose,
        no markdown fences.

        Each element is an object:
          {
            "key": "kebab-case-handle",          // unique within this plan
            "title": "short label",
            "request": "the natural-language step to deliver",
            "target_area": "file/dir/module hint or null",
            "depends_on": ["key", ...]            // keys of steps that must finish first
          }

        Rules:
          - At most {$maxNodes} steps. Prefer the smallest correct decomposition.
          - depends_on MUST reference only keys present in this array; NO cycles.
          - Steps must form a valid DAG with at least one topological order.
          - Each request is a self-contained, reviewable change.

        INTENT:
        {$intent}
        PROMPT;
    }

    /**
     * Parse the provider's JSON step list into drafts. Tolerant of a leading/trailing
     * markdown fence; strict about the array shape. Returns [] on any parse failure
     * (so {@see decompose()} degrades) — never throws, never fabricates.
     *
     * @return list<ObraNodeDraft>
     */
    private function parse(string $output): array
    {
        $text = trim($output);
        if ($text === '') {
            return [];
        }

        // Strip a ```json ... ``` fence if the provider wrapped the JSON.
        if (preg_match('/```(?:json)?\s*(.+?)```/su', $text, $m) === 1) {
            $text = trim($m[1]);
        }

        // Isolate the first top-level JSON array if there is surrounding prose.
        $start = strpos($text, '[');
        $end = strrpos($text, ']');
        if ($start === false || $end === false || $end <= $start) {
            return [];
        }
        $json = substr($text, $start, $end - $start + 1);

        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            return [];
        }

        $drafts = [];
        foreach ($decoded as $row) {
            if (! is_array($row)) {
                continue;
            }
            $draft = ObraNodeDraft::fromArray($row);
            // A step with no key or no request is unusable for DAG wiring — drop it.
            if ($draft->key === '' || $draft->request === '') {
                continue;
            }
            $drafts[] = $draft;
        }

        return $drafts;
    }
}
