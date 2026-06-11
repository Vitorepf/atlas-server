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
 * Audience & usage comprehension: reconstructs WHO a venture serves and HOW the
 * product is used purely from what the source workspace reveals — pricing
 * tiers, language reach, channel integrations, the domain entities it models
 * and the size of its feature surface — and, crucially, names what the code
 * CANNOT tell us.
 *
 * This capability is the operator's "público e uso" demand answered honestly:
 * every positive finding is cited (path + line) from a real file (cite or
 * omit), and the gaps that code cannot reveal — live usage (DAU/MAU), churn,
 * feature adoption, real revenue — are declared as explicit, low-confidence
 * BLIND_SPOT findings (evidence kind 'inferred') so a reader never mistakes
 * static structure for measured behaviour. Zero provider/LLM spend; fully
 * deterministic and offline.
 */
class VentureAudienceUsageProfileService implements ComprehensionCapability
{
    /** Channel/integration deps worth surfacing as audience-reach signals. */
    private const KNOWN_INTEGRATIONS = [
        // composer (PHP) packages
        'stripe/stripe-php' => ['label' => 'Stripe payments', 'channel' => 'payments'],
        'laravel/cashier' => ['label' => 'Laravel Cashier (subscriptions)', 'channel' => 'subscriptions'],
        'googleads/google-ads-php' => ['label' => 'Google Ads', 'channel' => 'advertising'],
        'facebook/php-business-sdk' => ['label' => 'Meta Ads', 'channel' => 'advertising'],
        // package.json (JS) deps
        'react' => ['label' => 'React web/app UI', 'channel' => 'frontend'],
        'i18next' => ['label' => 'i18next localization', 'channel' => 'localization'],
        'next' => ['label' => 'Next.js', 'channel' => 'frontend'],
        '@stripe/stripe-js' => ['label' => 'Stripe.js', 'channel' => 'payments'],
    ];

    /**
     * In-code channel string references (affiliate networks / checkout) that do
     * not show up as a manifest dependency but reveal a distribution channel.
     *
     * @var array<string,string>
     */
    private const CODE_CHANNEL_REFS = [
        'clickbank' => 'ClickBank affiliate network',
        'buygoods' => 'BuyGoods affiliate network',
        'cartpanda' => 'CartPanda checkout',
        'hotmart' => 'Hotmart marketplace',
    ];

    public function __construct(private readonly ComprehensionRecorder $recorder) {}

    public function capability(): string
    {
        return AiVentureComprehensionFinding::CAPABILITY_AUDIENCE_USAGE;
    }

    /**
     * Scan the workspace and record the audience/usage profile, then return a
     * structured report.
     *
     * @return array<string,mixed>
     */
    public function scan(AiVentureComprehensionRun $run, WorkspaceReader $reader): array
    {
        $drafts = [];

        $planTiers = $this->detectPlanTiers($reader, $drafts);
        $locales = $this->detectLocales($reader, $drafts);
        $integrations = $this->detectIntegrations($reader, $drafts);
        $schemaEntities = $this->detectSchemaEntities($reader, $drafts);
        $surfaceSize = $this->detectSurface($reader, $drafts);
        $blindSpots = $this->declareBlindSpots($planTiers, $locales, $drafts);

        $this->recorder->recordMany($run, $drafts);

        return [
            'capability' => $this->capability(),
            'plan_tiers' => $planTiers,
            'locales' => $locales,
            'integrations' => $integrations,
            'schema_entities' => $schemaEntities,
            'surface_size' => $surfaceSize,
            'blind_spots' => $blindSpots,
        ];
    }

    /**
     * Pricing tiers from config files (each tier = a paying segment with its
     * price point). Cited to the config file + the line declaring the tier.
     *
     * @param  list<FindingDraft>  $drafts
     * @return list<array<string,mixed>>
     */
    private function detectPlanTiers(WorkspaceReader $reader, array &$drafts): array
    {
        $tiers = [];

        // A "tier" line looks like   'raso' => ['price' => 149.90, 'currency' => 'BRL'],
        $matches = $reader->grep(
            "/'([a-z0-9_\\-]+)'\\s*=>\\s*\\[[^\\]]*'price'\\s*=>\\s*([0-9]+(?:\\.[0-9]+)?)/i",
            ['php'],
        );

        foreach ($matches as $m) {
            if (! preg_match(
                "/'([a-z0-9_\\-]+)'\\s*=>\\s*\\[[^\\]]*'price'\\s*=>\\s*([0-9]+(?:\\.[0-9]+)?)(?:[^\\]]*'currency'\\s*=>\\s*'([A-Z]{3})')?/i",
                $m['text'],
                $parts,
            )) {
                continue;
            }

            $name = $parts[1];
            $price = (float) $parts[2];
            $currency = $parts[3] ?? null;

            $tiers[] = [
                'name' => $name,
                'price' => $price,
                'currency' => $currency,
                'path' => $m['path'],
                'line' => $m['line'],
            ];

            $drafts[] = new FindingDraft(
                capability: $this->capability(),
                kind: 'plan_tier',
                title: sprintf('Plano "%s" — %s%s', $name, $this->formatPrice($price), $currency !== null ? ' '.$currency : ''),
                category: 'pricing',
                detail: sprintf(
                    'Segmento pagante "%s" com price point %s%s declarado em configuração. Cada tier mapeia um público disposto a pagar essa faixa.',
                    $name,
                    $this->formatPrice($price),
                    $currency !== null ? ' '.$currency : '',
                ),
                confidence: 0.95,
                evidencePath: $m['path'],
                evidenceLine: $m['line'],
                evidenceSnippet: $reader->snippet($m['path'], $m['line']),
                recommendation: 'Validar contra clientes reais por tier (conversão, retenção e LTV por faixa).',
                payload: ['plan' => $name, 'price' => $price, 'currency' => $currency],
            );
        }

        return $tiers;
    }

    /**
     * Locale/i18n files = the markets (languages) the product reaches today.
     * Cited to each locale file.
     *
     * @param  list<FindingDraft>  $drafts
     * @return list<array<string,mixed>>
     */
    private function detectLocales(WorkspaceReader $reader, array &$drafts): array
    {
        $locales = [];

        foreach ($reader->files(['json']) as $rel) {
            if (! preg_match('#(?:^|/)(?:i18n/)?locales?/([a-z]{2}(?:-[A-Za-z]{2,4})?)\.json$#', $rel, $parts)
                && ! preg_match('#(?:^|/)(?:lang|translations?)/([a-z]{2}(?:-[A-Za-z]{2,4})?)\.json$#', $rel, $parts)) {
                continue;
            }

            $code = $parts[1];
            $market = $this->localeMarket($code);

            $locales[] = [
                'code' => $code,
                'market' => $market,
                'path' => $rel,
            ];

            $drafts[] = new FindingDraft(
                capability: $this->capability(),
                kind: 'locale',
                title: sprintf('Mercado/idioma %s (%s)', $code, $market),
                category: 'reach',
                detail: sprintf('Arquivo de tradução para %s indica alcance no mercado %s.', $code, $market),
                confidence: 0.9,
                evidencePath: $rel,
                evidenceLine: 1,
                evidenceSnippet: $reader->snippet($rel, 1),
                recommendation: 'Confirmar tração real por mercado (não toda língua suportada está ativa comercialmente).',
                payload: ['locale' => $code, 'market' => $market],
            );
        }

        return $locales;
    }

    /**
     * Channel/integration dependencies from manifests + in-code channel refs.
     * Each cited to the manifest line (or code line) that declares it.
     *
     * @param  list<FindingDraft>  $drafts
     * @return list<array<string,mixed>>
     */
    private function detectIntegrations(WorkspaceReader $reader, array &$drafts): array
    {
        $integrations = [];

        foreach (['composer.json', 'package.json'] as $manifest) {
            $decoded = $reader->json($manifest);
            if ($decoded === []) {
                continue;
            }

            /** @var array<string,mixed> $deps */
            $deps = array_merge(
                is_array($decoded['require'] ?? null) ? $decoded['require'] : [],
                is_array($decoded['require-dev'] ?? null) ? $decoded['require-dev'] : [],
                is_array($decoded['dependencies'] ?? null) ? $decoded['dependencies'] : [],
                is_array($decoded['devDependencies'] ?? null) ? $decoded['devDependencies'] : [],
            );

            foreach ($deps as $package => $version) {
                $package = (string) $package;
                if (! isset(self::KNOWN_INTEGRATIONS[$package])) {
                    continue;
                }
                $meta = self::KNOWN_INTEGRATIONS[$package];

                // Cite the exact manifest line declaring the dependency.
                $hit = $this->locateManifestLine($reader, $manifest, $package);

                $integrations[] = [
                    'package' => $package,
                    'label' => $meta['label'],
                    'channel' => $meta['channel'],
                    'path' => $manifest,
                    'line' => $hit,
                ];

                $drafts[] = new FindingDraft(
                    capability: $this->capability(),
                    kind: 'integration',
                    title: sprintf('Integração: %s', $meta['label']),
                    category: $meta['channel'],
                    detail: sprintf('Dependência "%s" revela o canal "%s" — %s.', $package, $meta['channel'], $meta['label']),
                    confidence: 0.9,
                    evidencePath: $manifest,
                    evidenceLine: $hit,
                    evidenceSnippet: $hit !== null ? $reader->snippet($manifest, $hit) : null,
                    recommendation: 'Mapear volume e dependência operacional desse canal (lock-in, custo, SLA).',
                    payload: ['package' => $package, 'channel' => $meta['channel']],
                );
            }
        }

        // In-code channel references (affiliate networks / checkout) — string refs only.
        foreach (self::CODE_CHANNEL_REFS as $needle => $label) {
            $codeHits = $reader->grep('/'.preg_quote($needle, '/').'/i', ['php', 'js', 'jsx', 'ts', 'tsx'], 20);
            foreach ($codeHits as $hit) {
                $integrations[] = [
                    'package' => $needle,
                    'label' => $label,
                    'channel' => 'distribution',
                    'path' => $hit['path'],
                    'line' => $hit['line'],
                ];

                $drafts[] = new FindingDraft(
                    capability: $this->capability(),
                    kind: 'integration',
                    title: sprintf('Canal em código: %s', $label),
                    category: 'distribution',
                    detail: sprintf('Referência a "%s" no código revela o canal de distribuição "%s".', $needle, $label),
                    confidence: 0.7,
                    evidencePath: $hit['path'],
                    evidenceLine: $hit['line'],
                    evidenceSnippet: $reader->snippet($hit['path'], $hit['line']),
                    recommendation: 'Confirmar se o canal está ativo em produção ou é resíduo de protótipo.',
                    payload: ['ref' => $needle, 'channel' => 'distribution'],
                );

                break; // one citation per channel is enough as evidence.
            }
        }

        return $integrations;
    }

    /**
     * Domain entities from migrations / table declarations — what the product
     * tracks and models. Cited to the migration file + line.
     *
     * @param  list<FindingDraft>  $drafts
     * @return list<array<string,mixed>>
     */
    private function detectSchemaEntities(WorkspaceReader $reader, array &$drafts): array
    {
        $entities = [];
        $seen = [];

        $matches = $reader->grep(
            "/(?:Schema::create|create table)\\s*\\(?\\s*'([a-z][a-z0-9_]+)'/i",
            ['php', 'sql'],
            500,
            'database',
        );

        // Fallback: comment-declared entities like "// Schema::create('campaigns', ...)"
        // are matched by the same pattern above (it does not require executable code).
        // Widen scope to all migrations if the under-relative scoping found nothing.
        if ($matches === []) {
            $matches = $reader->grep(
                "/(?:Schema::create|create table)\\s*\\(?\\s*'([a-z][a-z0-9_]+)'/i",
                ['php', 'sql'],
            );
        }

        foreach ($matches as $m) {
            if (! preg_match("/(?:Schema::create|create table)\\s*\\(?\\s*'([a-z][a-z0-9_]+)'/i", $m['text'], $parts)) {
                continue;
            }
            $entity = strtolower($parts[1]);
            if (isset($seen[$entity])) {
                continue;
            }
            $seen[$entity] = true;

            $entities[] = [
                'entity' => $entity,
                'path' => $m['path'],
                'line' => $m['line'],
            ];

            $drafts[] = new FindingDraft(
                capability: $this->capability(),
                kind: 'schema_entity',
                title: sprintf('Entidade de domínio: %s', $entity),
                category: 'model',
                detail: sprintf('A tabela "%s" mostra que o produto modela/rastreia esse conceito de domínio.', $entity),
                confidence: 0.85,
                evidencePath: $m['path'],
                evidenceLine: $m['line'],
                evidenceSnippet: $reader->snippet($m['path'], $m['line']),
                recommendation: 'Confirmar quais entidades têm dados vivos vs. esquema sem uso.',
                payload: ['entity' => $entity],
            );
        }

        return $entities;
    }

    /**
     * Feature surface size = count of routes/controllers/screens. Cited to a
     * representative surface file when one exists.
     *
     * @param  list<FindingDraft>  $drafts
     */
    private function detectSurface(WorkspaceReader $reader, array &$drafts): int
    {
        $controllers = $reader->files(['php'], 'app/Http/Controllers');
        $screens = array_values(array_filter(
            $reader->files(['jsx', 'tsx', 'vue']),
            static fn (string $rel): bool => str_contains(strtolower($rel), 'page')
                || str_contains(strtolower($rel), 'screen')
                || str_contains(strtolower($rel), 'view'),
        ));

        $surfaceFiles = array_merge($controllers, $screens);
        $size = count($surfaceFiles);

        if ($size === 0) {
            return 0;
        }

        $citePath = $surfaceFiles[0];

        $drafts[] = new FindingDraft(
            capability: $this->capability(),
            kind: 'surface',
            title: sprintf('Tamanho da superfície de produto: %d arquivo(s) de superfície', $size),
            category: 'surface',
            detail: sprintf(
                'Contagem estrutural de controllers/telas (%d) aproxima o tamanho da superfície de funcionalidades. Não mede uso real de cada superfície.',
                $size,
            ),
            confidence: 0.7,
            evidencePath: $citePath,
            evidenceLine: 1,
            evidenceSnippet: $reader->snippet($citePath, 1),
            recommendation: 'Cruzar com analytics para saber quais superfícies são realmente usadas.',
            payload: [
                'surface_size' => $size,
                'controllers' => count($controllers),
                'screens' => count($screens),
            ],
        );

        return $size;
    }

    /**
     * EXPLICITLY declare what the code cannot reveal. These are required,
     * low-confidence, 'inferred' findings — the honest "say what you don't
     * know" the operator demands.
     *
     * @param  list<array<string,mixed>>  $planTiers
     * @param  list<array<string,mixed>>  $locales
     * @param  list<FindingDraft>  $drafts
     * @return list<array<string,mixed>>
     */
    private function declareBlindSpots(array $planTiers, array $locales, array &$drafts): array
    {
        $blindSpots = [
            [
                'gap' => 'live_usage',
                'title' => 'Uso real (DAU/MAU) não é observável no código',
                'detail' => 'O código revela superfícies e tiers, mas não quantos usuários ativos existem por dia/mês. Sem fonte de uso, o engajamento é desconhecido.',
                'recommendation' => 'Conectar uma fonte de dados de uso (product analytics / event stream) para medir DAU/MAU.',
            ],
            [
                'gap' => 'churn',
                'title' => 'Churn e retenção não são observáveis no código',
                'detail' => 'Os tiers de preço existem no repositório, mas a taxa de cancelamento e a retenção por coorte só vivem nos dados operacionais.',
                'recommendation' => 'Instrumentar eventos de assinatura/cancelamento e ligar a um data source de retenção.',
            ],
            [
                'gap' => 'feature_adoption',
                'title' => 'Adoção de funcionalidades não é observável no código',
                'detail' => 'A superfície de features é contável estaticamente, mas quais funcionalidades são de fato adotadas exige telemetria de uso.',
                'recommendation' => 'Adicionar tracking de feature usage para distinguir superfície morta de superfície usada.',
            ],
            [
                'gap' => 'real_revenue',
                'title' => 'Receita real por segmento não é observável no código',
                'detail' => sprintf(
                    'Há %d tier(s) de preço declarado(s), mas o faturamento real por segmento e o ARR não estão no repositório — apenas o preço de tabela.',
                    count($planTiers),
                ),
                'recommendation' => 'Ligar o sistema de billing (ex.: Stripe) como data source de receita observada por tier.',
            ],
        ];

        foreach ($blindSpots as $spot) {
            $drafts[] = new FindingDraft(
                capability: $this->capability(),
                kind: 'blind_spot',
                title: $spot['title'],
                category: 'blind_spot',
                detail: $spot['detail'],
                confidence: 0.2,
                evidenceKind: AiVentureComprehensionFinding::EVIDENCE_INFERRED,
                recommendation: $spot['recommendation'],
                payload: [
                    'gap' => $spot['gap'],
                    'requires' => 'usage_data_source',
                    'known_tiers' => count($planTiers),
                    'known_markets' => count($locales),
                ],
            );
        }

        return $blindSpots;
    }

    /**
     * Best-effort locate the manifest line declaring a package, for citation.
     */
    private function locateManifestLine(WorkspaceReader $reader, string $manifest, string $package): ?int
    {
        // json_encode escapes forward slashes ("stripe\/stripe-php"), so allow an
        // optional backslash before each '/' when matching the manifest key. Use
        // a '#' delimiter so the literal '/' in the key never collides with it.
        $quoted = preg_quote($package, '#');
        $key = str_replace('/', '\\\\?/', $quoted);
        $hits = $reader->grep('#"'.$key.'"\s*:#', ['json'], 5, null);
        foreach ($hits as $hit) {
            if ($hit['path'] === $manifest) {
                return $hit['line'];
            }
        }

        return null;
    }

    private function localeMarket(string $code): string
    {
        $normalized = strtolower($code);

        return match (true) {
            str_starts_with($normalized, 'pt') => 'Brasil/Portugal (pt)',
            str_starts_with($normalized, 'en') => 'Anglófono (en)',
            str_starts_with($normalized, 'es') => 'Hispanófono (es)',
            str_starts_with($normalized, 'fr') => 'Francófono (fr)',
            str_starts_with($normalized, 'de') => 'Alemão (de)',
            str_starts_with($normalized, 'it') => 'Italiano (it)',
            default => $code,
        };
    }

    private function formatPrice(float $price): string
    {
        return rtrim(rtrim(number_format($price, 2, '.', ''), '0'), '.') === ''
            ? '0'
            : number_format($price, 2, '.', '');
    }
}
