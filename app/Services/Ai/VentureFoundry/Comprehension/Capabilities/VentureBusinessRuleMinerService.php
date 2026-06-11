<?php

declare(strict_types=1);

namespace App\Services\Ai\VentureFoundry\Comprehension\Capabilities;

use App\Models\AiVentureComprehensionFinding;
use App\Models\AiVentureComprehensionRun;
use App\Services\Ai\VentureFoundry\Comprehension\ComprehensionCapability;
use App\Services\Ai\VentureFoundry\Comprehension\ComprehensionRecorder;
use App\Services\Ai\VentureFoundry\Comprehension\FindingDraft;
use App\Services\Ai\VentureFoundry\Comprehension\WorkspaceReader;

/**
 * Mines the REAL business rules a venture's source code already enforces, each
 * cited to a path + line. This is the deterministic floor of the comprehension
 * subsystem: it never asks an LLM and never invents a rule — every finding is a
 * verbatim observation from the workspace (pricing tables, trial windows,
 * commission constants, validation rules, authorization gates, domain
 * constants). Cite or omit.
 *
 * The orchestrator later promotes the high-confidence catch via {@see
 * candidates()} into the official venture rule canon (this service never writes
 * the canon itself).
 */
final class VentureBusinessRuleMinerService implements ComprehensionCapability
{
    /** Categories mined, in report order. */
    public const CATEGORY_PRICING = 'pricing';

    public const CATEGORY_TRIAL = 'trial';

    public const CATEGORY_COMMISSION = 'commission';

    public const CATEGORY_VALIDATION = 'validation';

    public const CATEGORY_AUTHORIZATION = 'authorization';

    public const CATEGORY_DOMAIN_CONSTANT = 'domain_constant';

    /** Explicit config/const evidence is trusted; grep heuristics are weaker. */
    private const CONFIDENCE_EXPLICIT = 0.9;

    private const CONFIDENCE_HEURISTIC = 0.7;

    /** A rule is promotable to the canon only at/above this confidence. */
    private const CANDIDATE_FLOOR = 0.8;

    /** Filenames that signal a monetization/pricing config worth deep reading. */
    private const PRICING_FILE_HINTS = ['plan', 'plans', 'price', 'pricing', 'subscription', 'subscriptions', 'cashier', 'billing', 'tier', 'tiers'];

    /** A decimal money amount (1+ integer digits, '.' or ',' separator, 2 fraction digits). */
    private const MONEY_REGEX = '/\b\d+[.,]\d{2}\b/';

    /** Words that promote a bare decimal to a real pricing signal. */
    private const PRICE_CONTEXT_REGEX = '/(price|preco|preço|valor|amount|cost|custo|fee|tarifa|brl|usd|eur|r\$|\$|€)/i';

    public function __construct(private readonly ComprehensionRecorder $recorder) {}

    public function capability(): string
    {
        return AiVentureComprehensionFinding::CAPABILITY_BUSINESS_RULE;
    }

    /**
     * Scan the workspace for business rules and persist each as a cited finding.
     *
     * @return array<string,mixed> {capability, total, by_category, high_confidence_count, top}
     */
    public function scan(AiVentureComprehensionRun $run, WorkspaceReader $reader): array
    {
        /** @var list<FindingDraft> $drafts */
        $drafts = [];
        foreach ($this->minePricing($reader) as $draft) {
            $drafts[] = $draft;
        }
        foreach ($this->mineTrial($reader) as $draft) {
            $drafts[] = $draft;
        }
        foreach ($this->mineCommission($reader) as $draft) {
            $drafts[] = $draft;
        }
        foreach ($this->mineValidation($reader) as $draft) {
            $drafts[] = $draft;
        }
        foreach ($this->mineAuthorization($reader) as $draft) {
            $drafts[] = $draft;
        }
        foreach ($this->mineDomainConstants($reader, $drafts) as $draft) {
            $drafts[] = $draft;
        }

        $recorded = $this->recorder->recordMany($run, $drafts);

        $byCategory = [];
        $highConfidence = 0;
        $top = [];
        foreach ($recorded as $finding) {
            $category = (string) ($finding->category ?? 'uncategorized');
            $byCategory[$category] = ($byCategory[$category] ?? 0) + 1;
            if ((float) $finding->confidence >= self::CANDIDATE_FLOOR) {
                $highConfidence++;
            }
            $top[] = [
                'category' => $category,
                'title' => $finding->title,
                'confidence' => (float) $finding->confidence,
                'evidence_path' => $finding->evidence_path,
                'evidence_line' => $finding->evidence_line,
            ];
        }

        usort($top, static fn (array $a, array $b): int => $b['confidence'] <=> $a['confidence']);

        return [
            'capability' => $this->capability(),
            'total' => count($recorded),
            'by_category' => $byCategory,
            'high_confidence_count' => $highConfidence,
            'top' => array_slice($top, 0, 15),
        ];
    }

    /**
     * High-confidence (>= 0.8) mined rules as plain arrays for the orchestrator
     * to promote into the official venture rule canon. Reads only what THIS
     * service already persisted for the run — no re-scan, no invention.
     *
     * @return list<array{category:string,statement:string,evidence_path:?string,evidence_line:?int,confidence:float}>
     */
    public function candidates(AiVentureComprehensionRun $run): array
    {
        $findings = AiVentureComprehensionFinding::query()
            ->where('run_id', $run->id)
            ->where('capability', $this->capability())
            ->where('confidence', '>=', self::CANDIDATE_FLOOR)
            ->orderByDesc('confidence')
            ->orderBy('category')
            ->get();

        $candidates = [];
        foreach ($findings as $finding) {
            $candidates[] = [
                'category' => (string) ($finding->category ?? 'uncategorized'),
                'statement' => (string) $finding->title,
                'evidence_path' => $finding->evidence_path,
                'evidence_line' => $finding->evidence_line !== null ? (int) $finding->evidence_line : null,
                'confidence' => (float) $finding->confidence,
            ];
        }

        return $candidates;
    }

    /**
     * PRICING: pricing-named config/code files holding a currency + decimal
     * amount. Explicit pricing config gets 0.9; bare grep heuristics get 0.7.
     *
     * @return list<FindingDraft>
     */
    private function minePricing(WorkspaceReader $reader): array
    {
        $drafts = [];
        foreach ($reader->grep(self::MONEY_REGEX, ['php', 'json', 'yaml', 'yml']) as $match) {
            $path = $match['path'];
            $line = (int) $match['line'];
            $text = $match['text'];

            $fromPricingFile = $this->looksLikePricingFile($path);
            $hasPriceContext = preg_match(self::PRICE_CONTEXT_REGEX, $text) === 1;
            if (! $fromPricingFile && ! $hasPriceContext) {
                continue;
            }

            if (! preg_match(self::MONEY_REGEX, $text, $amountMatch)) {
                continue;
            }
            $amount = $amountMatch[0];

            // A real price is a value, not a threshold/tolerance (`> 0.01`,
            // `abs($diff) > 0.01`) nor a multiplier/divisor (`* 4.33`,
            // `* 0.85`). Those bare decimals match the money regex but are NOT
            // prices and must never reach — let alone be promoted into — the
            // pricing canon.
            if ($this->isNonPriceNumber($text, $amount)) {
                continue;
            }
            $currency = $this->detectCurrency($reader, $path, $line, $text);
            $label = $this->detectPriceLabel($reader, $path, $line, $text);

            $confidence = $fromPricingFile ? self::CONFIDENCE_EXPLICIT : self::CONFIDENCE_HEURISTIC;
            $title = $label !== null
                ? sprintf("Plano '%s' custa %s%s", $label, $amount, $currency !== null ? ' '.$currency : '')
                : sprintf('Preço de %s%s', $amount, $currency !== null ? ' '.$currency : '');

            $drafts[] = new FindingDraft(
                capability: $this->capability(),
                kind: self::CATEGORY_PRICING,
                title: $title,
                category: self::CATEGORY_PRICING,
                detail: sprintf('Pricing rule observed in %s:%d — amount %s%s.', $path, $line, $amount, $currency !== null ? ' '.$currency : ''),
                confidence: $confidence,
                evidenceKind: AiVentureComprehensionFinding::EVIDENCE_OBSERVED,
                evidencePath: $path,
                evidenceLine: $line,
                evidenceSnippet: $reader->snippet($path, $line),
                recommendation: 'Confirm this is the source-of-truth price and promote it into the venture pricing canon.',
                payload: ['amount' => $amount, 'currency' => $currency, 'label' => $label],
            );
        }

        return $drafts;
    }

    /**
     * True when the matched decimal is used as a comparison threshold/tolerance
     * or an arithmetic multiplier/divisor rather than a monetary value — e.g.
     * `abs($diff) > 0.01`, `$price * 12 * 0.85`, `$amount * 4.33 / $n`. Such
     * numbers match the money regex but are not prices.
     */
    private function isNonPriceNumber(string $text, string $amount): bool
    {
        $num = preg_quote($amount, '/');

        // Comparison threshold with the amount as the right operand: `> 0.01`,
        // `>= 3`. The comparator must NOT be part of a `=>`/`->` arrow or a
        // `<=`/`>=`/`!=` token's other half, so a PHP array price assignment
        // (`'price' => 149.90`) is never mistaken for a comparison.
        if (preg_match('/(?<![=\-<>!])[<>]=?\s*[\$R€]?\s*'.$num.'\b/', $text) === 1) {
            return true;
        }
        // abs()/diff/tolerance/epsilon expressions around the amount.
        if (preg_match('/(?:abs\s*\(|diff|tolerance|epsilon|delta)[^;{]*'.$num.'/i', $text) === 1) {
            return true;
        }

        // Arithmetic factor: the amount is immediately multiplied or divided.
        if (preg_match('/[*\/]\s*'.$num.'\b/', $text) === 1) {
            return true;
        }
        if (preg_match('/\b'.$num.'\s*[*\/]/', $text) === 1) {
            return true;
        }

        return false;
    }

    /**
     * TRIAL: trial_days / trial_period / free_trial keys with a numeric value.
     *
     * @return list<FindingDraft>
     */
    private function mineTrial(WorkspaceReader $reader): array
    {
        $drafts = [];
        $pattern = '/[\'"]?(trial_days|trial_period(?:_days)?|free_trial(?:_days)?|trialdays)[\'"]?/i';
        foreach ($reader->grep($pattern, ['php', 'json', 'yaml', 'yml', 'env']) as $match) {
            $path = $match['path'];
            $line = (int) $match['line'];
            $text = $match['text'];

            $days = null;
            if (preg_match('/\b(\d{1,4})\b/', $text, $numMatch)) {
                $days = (int) $numMatch[1];
            }
            preg_match($pattern, $text, $keyMatch);
            $key = strtolower($keyMatch[1] ?? 'trial');

            $title = $days !== null
                ? sprintf('Período de trial de %d dia%s (%s)', $days, $days === 1 ? '' : 's', $key)
                : sprintf('Período de trial configurado (%s)', $key);

            $drafts[] = new FindingDraft(
                capability: $this->capability(),
                kind: self::CATEGORY_TRIAL,
                title: $title,
                category: self::CATEGORY_TRIAL,
                detail: sprintf('Trial window declared in %s:%d.', $path, $line),
                confidence: self::CONFIDENCE_EXPLICIT,
                evidenceKind: AiVentureComprehensionFinding::EVIDENCE_OBSERVED,
                evidencePath: $path,
                evidenceLine: $line,
                evidenceSnippet: $reader->snippet($path, $line),
                recommendation: 'Promote the trial length into the venture rule canon and verify it matches GTM.',
                payload: ['trial_days' => $days, 'key' => $key],
            );
        }

        return $drafts;
    }

    /**
     * COMMISSION / PAYOUT: const RATE, commission/payout/transfer signals with
     * a rate-like numeric value.
     *
     * @return list<FindingDraft>
     */
    private function mineCommission(WorkspaceReader $reader): array
    {
        $drafts = [];
        $pattern = '/\b(?:const\s+RATE|commission|payout|transfer_fee|comissao|comissão|repasse)\b/i';
        $seen = [];
        foreach ($reader->grep($pattern, ['php', 'json', 'yaml', 'yml']) as $match) {
            $path = $match['path'];
            $line = (int) $match['line'];
            $text = $match['text'];

            $rate = null;
            if (preg_match('/\b(\d+(?:[.,]\d+)?)\b/', $text, $numMatch)) {
                $rate = $numMatch[1];
            }

            // Only emit a rule when there's an actual numeric rate to cite,
            // otherwise this is just a method reference, not a business rule.
            if ($rate === null) {
                continue;
            }

            $dedup = $path.':'.$line;
            if (isset($seen[$dedup])) {
                continue;
            }
            $seen[$dedup] = true;

            $isConst = preg_match('/\bconst\s+RATE\b/i', $text) === 1;
            $percent = $this->rateToPercent($rate);
            $title = $percent !== null
                ? sprintf('Taxa de comissão/repasse de %s', $percent)
                : sprintf('Taxa de comissão/repasse = %s', $rate);

            $drafts[] = new FindingDraft(
                capability: $this->capability(),
                kind: self::CATEGORY_COMMISSION,
                title: $title,
                category: self::CATEGORY_COMMISSION,
                detail: sprintf('Commission/payout rate observed in %s:%d (value %s).', $path, $line, $rate),
                confidence: $isConst ? self::CONFIDENCE_EXPLICIT : self::CONFIDENCE_HEURISTIC,
                evidenceKind: AiVentureComprehensionFinding::EVIDENCE_OBSERVED,
                evidencePath: $path,
                evidenceLine: $line,
                evidenceSnippet: $reader->snippet($path, $line),
                recommendation: 'Confirm the commission rate against contracts and promote it into the venture rule canon.',
                payload: ['rate' => $rate, 'is_const' => $isConst],
            );
        }

        return $drafts;
    }

    /**
     * VALIDATION: FormRequest rules() arrays and Validator::make rule strings.
     *
     * @return list<FindingDraft>
     */
    private function mineValidation(WorkspaceReader $reader): array
    {
        $drafts = [];
        $pattern = '/(public\s+function\s+rules\s*\(|Validator::make|->validate\s*\()/i';
        $seen = [];
        foreach ($reader->grep($pattern, ['php']) as $match) {
            $path = $match['path'];
            $line = (int) $match['line'];

            $dedup = $path.':'.$line;
            if (isset($seen[$dedup])) {
                continue;
            }
            $seen[$dedup] = true;

            $drafts[] = new FindingDraft(
                capability: $this->capability(),
                kind: self::CATEGORY_VALIDATION,
                title: sprintf('Regra de validação declarada em %s', $path),
                category: self::CATEGORY_VALIDATION,
                detail: sprintf('Input validation rules defined in %s:%d.', $path, $line),
                confidence: self::CONFIDENCE_HEURISTIC,
                evidenceKind: AiVentureComprehensionFinding::EVIDENCE_OBSERVED,
                evidencePath: $path,
                evidenceLine: $line,
                evidenceSnippet: $reader->snippet($path, $line, 2),
                recommendation: 'Extract the concrete field constraints into the venture rule canon.',
            );
        }

        return $drafts;
    }

    /**
     * AUTHORIZATION: Policies/, Gate::, middleware, can()/authorize().
     *
     * @return list<FindingDraft>
     */
    private function mineAuthorization(WorkspaceReader $reader): array
    {
        $drafts = [];
        $pattern = '/(Gate::(?:allows|denies|define|authorize|check)|->authorize\s*\(|->can\s*\(|->cannot\s*\(|->middleware\s*\()/';
        $seen = [];

        // Policy files are authorization rules by definition.
        foreach ($reader->files(['php']) as $rel) {
            if (! preg_match('#(^|/)Policies/#', $rel)) {
                continue;
            }
            $drafts[] = new FindingDraft(
                capability: $this->capability(),
                kind: self::CATEGORY_AUTHORIZATION,
                title: sprintf('Política de autorização: %s', basename($rel)),
                category: self::CATEGORY_AUTHORIZATION,
                detail: sprintf('Authorization policy defined in %s.', $rel),
                confidence: self::CONFIDENCE_HEURISTIC,
                evidenceKind: AiVentureComprehensionFinding::EVIDENCE_OBSERVED,
                evidencePath: $rel,
                evidenceLine: 1,
                evidenceSnippet: $reader->snippet($rel, 1),
                recommendation: 'Map the policy gates into the venture rule canon as access constraints.',
            );
        }

        foreach ($reader->grep($pattern, ['php']) as $match) {
            $path = $match['path'];
            $line = (int) $match['line'];

            $dedup = $path.':'.$line;
            if (isset($seen[$dedup])) {
                continue;
            }
            $seen[$dedup] = true;

            $drafts[] = new FindingDraft(
                capability: $this->capability(),
                kind: self::CATEGORY_AUTHORIZATION,
                title: sprintf('Regra de autorização em %s', $path),
                category: self::CATEGORY_AUTHORIZATION,
                detail: sprintf('Authorization check observed in %s:%d.', $path, $line),
                confidence: self::CONFIDENCE_HEURISTIC,
                evidenceKind: AiVentureComprehensionFinding::EVIDENCE_OBSERVED,
                evidencePath: $path,
                evidenceLine: $line,
                evidenceSnippet: $reader->snippet($path, $line),
                recommendation: 'Capture who-can-do-what into the venture rule canon.',
            );
        }

        return $drafts;
    }

    /**
     * DOMAIN_CONSTANT: other business const/enum values not already captured as
     * pricing/commission. These are weaker (0.7) heuristics.
     *
     * @param  list<FindingDraft>  $already  already-mined drafts (to avoid double-citing a line)
     * @return list<FindingDraft>
     */
    private function mineDomainConstants(WorkspaceReader $reader, array $already): array
    {
        $claimed = [];
        foreach ($already as $draft) {
            if ($draft->evidencePath !== null && $draft->evidenceLine !== null) {
                $claimed[$draft->evidencePath.':'.$draft->evidenceLine] = true;
            }
        }

        $drafts = [];
        // const NAME = value  (numeric or quoted scalar) — a business constant.
        $pattern = '/\bconst\s+([A-Z][A-Z0-9_]{2,})\s*=\s*([\'"][^\'"]+[\'"]|-?\d+(?:\.\d+)?)/';
        $seen = [];
        foreach ($reader->grep($pattern, ['php']) as $match) {
            $path = $match['path'];
            $line = (int) $match['line'];
            $text = $match['text'];

            if (isset($claimed[$path.':'.$line])) {
                continue; // already a pricing/commission finding
            }
            $dedup = $path.':'.$line;
            if (isset($seen[$dedup])) {
                continue;
            }
            $seen[$dedup] = true;

            if (! preg_match($pattern, $text, $constMatch)) {
                continue;
            }
            $name = $constMatch[1];
            $value = trim($constMatch[2], '\'"');

            $drafts[] = new FindingDraft(
                capability: $this->capability(),
                kind: self::CATEGORY_DOMAIN_CONSTANT,
                title: sprintf('Constante de domínio %s = %s', $name, $value),
                category: self::CATEGORY_DOMAIN_CONSTANT,
                detail: sprintf('Domain constant %s defined in %s:%d.', $name, $path, $line),
                confidence: self::CONFIDENCE_HEURISTIC,
                evidenceKind: AiVentureComprehensionFinding::EVIDENCE_OBSERVED,
                evidencePath: $path,
                evidenceLine: $line,
                evidenceSnippet: $reader->snippet($path, $line),
                recommendation: 'Decide whether this constant encodes a business rule worth promoting.',
                payload: ['name' => $name, 'value' => $value],
            );
        }

        return $drafts;
    }

    private function looksLikePricingFile(string $relativePath): bool
    {
        $base = strtolower(basename($relativePath));
        foreach (self::PRICING_FILE_HINTS as $hint) {
            if (str_contains($base, $hint)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Detect a currency token on the cited line, or nearby in the same file.
     */
    private function detectCurrency(WorkspaceReader $reader, string $path, int $line, string $text): ?string
    {
        $window = $text;
        $context = $reader->snippet($path, $line, 1);
        if ($context !== '') {
            $window .= "\n".$context;
        }
        if (preg_match('/\b(BRL|USD|EUR|GBP|JPY|CHF|CAD|AUD)\b/i', $window, $m)) {
            return strtoupper($m[1]);
        }
        if (str_contains($window, 'R$')) {
            return 'BRL';
        }
        if (str_contains($window, '€')) {
            return 'EUR';
        }
        if (str_contains($window, '$')) {
            return 'USD';
        }

        return null;
    }

    /**
     * Detect a plan/price label adjacent to the amount (e.g. the array key
     * 'raso' => ['price' => 149.90, ...]).
     */
    private function detectPriceLabel(WorkspaceReader $reader, string $path, int $line, string $text): ?string
    {
        // Same-line: 'label' => ... price ...
        if (preg_match('/[\'"]([a-zA-Z][a-zA-Z0-9_\- ]{1,40})[\'"]\s*=>/', $text, $m)) {
            $candidate = trim($m[1]);
            if (! $this->isNoiseLabel($candidate)) {
                return $candidate;
            }
        }
        // Preceding line: 'label' => [\n  'price' => ...
        $prev = $reader->snippet($path, $line - 1);
        if ($prev !== '' && preg_match('/[\'"]([a-zA-Z][a-zA-Z0-9_\- ]{1,40})[\'"]\s*=>\s*\[?\s*$/', $prev, $m2)) {
            $candidate = trim($m2[1]);
            if (! $this->isNoiseLabel($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function isNoiseLabel(string $label): bool
    {
        return in_array(strtolower($label), ['price', 'preco', 'preço', 'value', 'valor', 'amount', 'currency', 'moeda', 'cost', 'custo'], true);
    }

    /**
     * Render a fractional rate as a human percent (0.30 -> "30%"), leaving
     * already-percent values (>= 1) as-is when they look like percents.
     */
    private function rateToPercent(string $rate): ?string
    {
        $normalized = (float) str_replace(',', '.', $rate);
        if ($normalized <= 0.0) {
            return null;
        }
        if ($normalized < 1.0) {
            $percent = $normalized * 100.0;

            return rtrim(rtrim(sprintf('%.2f', $percent), '0'), '.').'%';
        }
        if ($normalized <= 100.0 && floor($normalized) === $normalized) {
            return ((int) $normalized).'%';
        }

        return null;
    }
}
