<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming;

/**
 * Atlas Rivals · Operator Runbook Generator v1.
 *
 * Emits a copy-safe runbook for the Atlas Forge Rivals Real Battery operator:
 *   - commands are exactly as they must be pasted (full PHP path, no quotes
 *     that depend on shell context, no JSON glued to the command line);
 *   - no step prompts interactively;
 *   - cost warnings are loud and explicit (⚠);
 *   - the real-provider step requires three opt-in flags and is fenced by a
 *     dedicated `real_run_warning` block so the operator cannot miss it;
 *   - the same JSON document also contains a pre-rendered text and markdown
 *     version, so the operator can `--format=text` it into a terminal or
 *     `--format=markdown` it into a ticket without re-formatting.
 *
 * State-machine snapshot is optional: if a battery state machine is injected
 * and the input declares a run intent, we attach the snapshot so the runbook
 * reflects the current phase. The generator never blocks on snapshot errors
 * — the runbook itself is canonical even without live state.
 *
 * Schema: atlas.rivals.operator_runbook.v1
 */
class AtlasRivalsOperatorRunbookGenerator
{
    public const SCHEMA_VERSION = 'atlas.rivals.operator_runbook.v1';

    public const FORMAT_TEXT = 'text';

    public const FORMAT_MARKDOWN = 'markdown';

    public const FORMAT_JSON = 'json';

    private const PHP_BIN = '/opt/homebrew/bin/php';

    private const ARTISAN_CMD = '/opt/homebrew/bin/php artisan atlas:engineering:benchmark:rivals-harness';

    public function __construct(
        private readonly ?RivalsForgeReadinessFingerprintService $fingerprint = null,
        private readonly mixed $stateMachine = null,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function generate(array $input): array
    {
        $format = $this->normalizeFormat($input['format'] ?? null);

        $preamble = [
            '⚠ Este runbook executa uma bateria REAL contra Atlas Forge e Claude Code CLI.',
            "⚠ O passo 'run-quick-real' chama provider externo e GASTA TOKENS.",
            '⚠ Confirme cada etapa antes de avançar. Pare se houver dúvida.',
        ];

        $preconditions = $this->buildPreconditions();
        $steps = $this->buildSteps();
        $realRunWarning = $this->buildRealRunWarning();
        $postRunSteps = $this->buildPostRunSteps();
        $abortStrategies = $this->buildAbortStrategies();

        $fingerprintBlock = $this->maybeBuildFingerprint($input);
        $stateSnapshot = $this->maybeBuildStateSnapshot($input);

        $document = [
            'schema' => self::SCHEMA_VERSION,
            'generated_at' => $this->now(),
            'format' => $format,
            'title' => 'Atlas Rivals Real Battery · Operator Runbook (quick)',
            'preamble' => $preamble,
            'fingerprint' => $fingerprintBlock,
            'state_snapshot' => $stateSnapshot,
            'preconditions' => $preconditions,
            'steps' => $steps,
            'real_run_warning' => $realRunWarning,
            'post_run_steps' => $postRunSteps,
            'abort_strategies' => $abortStrategies,
        ];

        $document['rendered_text'] = $this->renderText($document);
        $document['rendered_markdown'] = $this->renderMarkdown($document);

        return $document;
    }

    /**
     * @return list<array<string,string>>
     */
    private function buildPreconditions(): array
    {
        return [
            [
                'id' => 'p1',
                'label' => 'PHP 8.4+',
                'command' => self::PHP_BIN.' --version',
                'expected' => 'PHP 8.4.x',
            ],
            [
                'id' => 'p2',
                'label' => 'Git index limpo',
                'command' => 'git status --porcelain',
                'expected' => '(vazio)',
            ],
            [
                'id' => 'p3',
                'label' => 'Sem .pyc rastreado',
                'command' => "git ls-files | grep -E '\\.py[co]$'",
                'expected' => '(vazio)',
            ],
            [
                'id' => 'p4',
                'label' => 'Cwd correto · atlas-server',
                'command' => 'pwd',
                'expected' => '/Users/vitorepf/develop/Atlas/atlas-server',
            ],
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function buildSteps(): array
    {
        return [
            [
                'number' => 1,
                'title' => 'Provisionar worktrees isolados',
                'rationale' => 'Workspaces separados garantem que Atlas Forge e Claude Code CLI nunca compartilhem estado nem contaminem o tree principal.',
                'command' => self::ARTISAN_CMD.' setup-worktrees --json',
                'expected_status' => 'worktrees_ready',
                'where_to_look' => 'JSON.atlas_path e JSON.baseline_path. Logs em storage/logs/laravel.log.',
                'how_to_abort' => 'rm -rf <atlas_path> <baseline_path> · depois rode reset-test-worktrees.',
                'next_command' => 'doctor',
            ],
            [
                'number' => 2,
                'title' => 'Doctor · checar saúde do harness',
                'rationale' => 'Doctor agrega preflight + fingerprint + capacidade do provider. Strict aborta no primeiro fail.',
                'command' => self::ARTISAN_CMD.' doctor --json --strict',
                'expected_status' => 'all checks ok',
                'where_to_look' => 'JSON.checks[*].status — todos devem ser "passed".',
                'how_to_abort' => 'Resolva o check vermelho antes de seguir. Não use --no-strict no caminho feliz.',
                'next_command' => 'dry-run',
            ],
            [
                'number' => 3,
                'title' => 'Dry-run · plano sem dispatch',
                'rationale' => 'Dry-run compõe protocolo + manifest + preflight sem chamar provider. Última oportunidade pra revisar intent.',
                'command' => self::ARTISAN_CMD.' dry-run --json',
                'expected_status' => 'dry_run_clean',
                'where_to_look' => 'JSON.blocking_reasons (deve estar vazio) e JSON.replay_manifest_planned.',
                'how_to_abort' => 'Ctrl+C · dry-run não muda estado.',
                'next_command' => 'fingerprint',
            ],
            [
                'number' => 4,
                'title' => 'Fingerprint · capturar intent canônico',
                'rationale' => 'Fingerprint é o contrato entre runbook e run. Diferença no momento do run = mismatch detectado.',
                'command' => self::ARTISAN_CMD.' fingerprint --json',
                'expected_status' => 'fingerprint_computed',
                'where_to_look' => 'JSON.fingerprint.value (sha256). Guarde esse hash · vai ser comparado no run-quick-real.',
                'how_to_abort' => 'Se intent mudou, reinicie do step 3 (dry-run).',
                'next_command' => 'preflight',
            ],
            [
                'number' => 5,
                'title' => 'Preflight · validar gate profile',
                'rationale' => 'Preflight valida que Atlas arm é Forge, que docs canônicos existem e que o gate profile é coerente.',
                'command' => self::ARTISAN_CMD.' preflight --json',
                'expected_status' => 'preflight_passed',
                'where_to_look' => 'JSON.checks[*].status. Especialmente checks.case_manifest.atlas_arm_is_forge=true.',
                'how_to_abort' => 'Não prossiga com preflight vermelho. Volte ao doctor.',
                'next_command' => 'capacity-check',
            ],
            [
                'number' => 6,
                'title' => 'Capacity check · provider tem crédito?',
                'rationale' => 'Provider sem crédito = run morre no meio e marca evidence como invalid. Cheque antes de gastar tokens.',
                'command' => self::ARTISAN_CMD.' capacity-check --json',
                'expected_status' => 'capacity_ok',
                'where_to_look' => 'JSON.capacity.atlas e JSON.capacity.baseline. Ambos devem reportar credit_ok=true.',
                'how_to_abort' => 'Recarregue crédito antes de prosseguir. Não rode run-quick-real sem capacity_ok.',
                'next_command' => 'review-runbook',
            ],
            [
                'number' => 7,
                'title' => 'Review · leia este runbook do começo ao fim',
                'rationale' => 'O próximo passo gasta dinheiro real. A flag --confirm-runbook-reviewed afirma que você fez essa leitura.',
                'command' => self::ARTISAN_CMD.' runbook --format=text',
                'expected_status' => 'runbook_emitted',
                'where_to_look' => 'Releia o real_run_warning abaixo. Confirme que as três flags estão entendidas.',
                'how_to_abort' => 'Se algo no runbook não fizer sentido, NÃO marque --confirm-runbook-reviewed. Pare aqui.',
                'next_command' => 'run-quick-real',
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function buildRealRunWarning(): array
    {
        return [
            'title' => '⚠ ATENÇÃO · PRÓXIMO PASSO GASTA TOKENS',
            'body' => [
                'O comando abaixo invoca Atlas Forge (Anthropic API) e Claude Code CLI (Anthropic API).',
                'Estimativa: 50k-200k tokens dependendo do case e do preset.',
                'Custo aproximado: $0.30-$1.50 (preset=quick).',
                'Requer TRÊS flags explícitas: --confirm-runbook-reviewed --confirm-provider-cost --confirm-real-provider-call',
                'Sem essas três flags, o comando emite plano e NÃO chama provider.',
                'Se em dúvida, rode primeiro sem as flags · vai ver o plano sem custo.',
            ],
            'command' => self::ARTISAN_CMD.' run-quick-real --confirm-runbook-reviewed --confirm-provider-cost --confirm-real-provider-call --json',
            'dry_command' => self::ARTISAN_CMD.' run-quick-real --json',
            'expected_status' => 'real_run_dispatched',
            'where_to_look' => 'JSON.run_id, JSON.evidence_path. Logs em storage/logs/rivals-harness.log.',
            'how_to_abort' => 'Ctrl+C durante o run · evidence será marcado como invalid. Depois rode collect-evidence pra fechar o registro.',
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function buildPostRunSteps(): array
    {
        return [
            [
                'number' => 8,
                'title' => 'Coletar evidence',
                'rationale' => 'Evidence pack consolida diff, logs, prompts e respostas em um pacote auditável.',
                'command' => self::ARTISAN_CMD.' collect-evidence --run-id=<id> --json',
                'expected_status' => 'evidence_collected',
                'where_to_look' => 'JSON.evidence_path · diretório com manifest.json + artefatos.',
                'how_to_abort' => 'Não há aborto seguro · evidence é write-only. Para descartar run inteiro: marque-o invalid no manifest.',
                'next_command' => 'replay',
            ],
            [
                'number' => 9,
                'title' => 'Verificar replay',
                'rationale' => 'Replay confere que o evidence pack reconstrói a mesma execução · prova de auditabilidade.',
                'command' => self::ARTISAN_CMD.' replay --run-id=<id> --json',
                'expected_status' => 'replay_verified',
                'where_to_look' => 'JSON.replay.matches=true. Se false, JSON.replay.diff_fields aponta o drift.',
                'how_to_abort' => 'Replay falho = evidence corrompido. Não confie no run.',
                'next_command' => 'report',
            ],
            [
                'number' => 10,
                'title' => 'Gerar report',
                'rationale' => 'Report é a leitura humana final · scorecard + rubric + observações.',
                'command' => self::ARTISAN_CMD.' report --run-id=<id> --json',
                'expected_status' => 'report_emitted',
                'where_to_look' => 'JSON.report_path · markdown pronto pra revisão.',
                'how_to_abort' => 'Report é idempotente · pode re-rodar.',
                'next_command' => null,
            ],
        ];
    }

    /**
     * @return list<array<string,string>>
     */
    private function buildAbortStrategies(): array
    {
        return [
            [
                'context' => 'Workspace dirty depois do run',
                'action' => 'Marca run como invalid via collect-evidence; investigue diff antes de qualquer commit.',
            ],
            [
                'context' => 'Provider out of credits',
                'action' => 'Aborte com Ctrl+C; o run será marcado como invalid; rode reset-test-worktrees.',
            ],
            [
                'context' => 'Fingerprint mismatch entre runbook e run',
                'action' => 'NÃO confie no run · re-emita runbook com as flags reais do dispatch e re-confirme intent.',
            ],
            [
                'context' => 'Claude Code CLI trava ou retorna timeout',
                'action' => 'Ctrl+C; rode capacity-check; se OK, refaça a partir do step 6.',
            ],
            [
                'context' => 'Doctor falha em strict mode',
                'action' => 'Não rode com --no-strict pra contornar · resolva o check vermelho na raiz.',
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function maybeBuildFingerprint(array $input): ?array
    {
        if ($this->fingerprint === null) {
            return null;
        }

        try {
            return $this->fingerprint->compute([
                'suite_id' => $input['suite_id'] ?? null,
                'preset' => $input['preset'] ?? 'quick',
                'atlas_model' => $input['atlas_model'] ?? null,
                'baseline_model' => $input['baseline_model'] ?? null,
                'atlas_workspace' => $input['atlas_workspace'] ?? null,
                'baseline_workspace' => $input['baseline_workspace'] ?? null,
                'case_ids' => $input['case_ids'] ?? [],
                'gate_profile' => $input['gate_profile'] ?? null,
                'test_command' => $input['test_command'] ?? null,
            ]);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function maybeBuildStateSnapshot(array $input): ?array
    {
        if ($this->stateMachine === null) {
            return null;
        }

        if (! is_object($this->stateMachine) || ! method_exists($this->stateMachine, 'snapshot')) {
            return null;
        }

        try {
            /** @var mixed $snapshot */
            $snapshot = $this->stateMachine->snapshot($input);

            return is_array($snapshot) ? $snapshot : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalizeFormat(mixed $format): string
    {
        if (! is_string($format)) {
            return self::FORMAT_JSON;
        }

        $normalized = strtolower(trim($format));

        return match ($normalized) {
            self::FORMAT_TEXT => self::FORMAT_TEXT,
            self::FORMAT_MARKDOWN => self::FORMAT_MARKDOWN,
            self::FORMAT_JSON => self::FORMAT_JSON,
            default => self::FORMAT_JSON,
        };
    }

    private function now(): string
    {
        if (function_exists('now')) {
            try {
                /** @var object{toJSON: callable():string}|null $n */
                $n = now();
                if (is_object($n) && method_exists($n, 'toJSON')) {
                    $val = $n->toJSON();
                    if (is_string($val) && $val !== '') {
                        return $val;
                    }
                }
            } catch (\Throwable) {
                // fall through
            }
        }

        return gmdate('Y-m-d\TH:i:s\Z');
    }

    /**
     * @param  array<string,mixed>  $doc
     */
    private function renderText(array $doc): string
    {
        $lines = [];
        $lines[] = '================================================================';
        $lines[] = (string) $doc['title'];
        $lines[] = '================================================================';
        $lines[] = 'schema · '.(string) $doc['schema'];
        $lines[] = 'generated_at · '.(string) $doc['generated_at'];
        $lines[] = '';

        $lines[] = '--- PREAMBULO ---';
        foreach ((array) $doc['preamble'] as $line) {
            $lines[] = (string) $line;
        }
        $lines[] = '';

        $lines[] = '--- PRECONDICOES ---';
        foreach ((array) $doc['preconditions'] as $pre) {
            $lines[] = sprintf('[%s] %s', (string) $pre['id'], (string) $pre['label']);
            $lines[] = '  $ '.(string) $pre['command'];
            $lines[] = '  → esperado · '.(string) $pre['expected'];
        }
        $lines[] = '';

        $lines[] = '--- STEPS ---';
        foreach ((array) $doc['steps'] as $step) {
            $lines[] = sprintf('Step %d · %s', (int) $step['number'], (string) $step['title']);
            $lines[] = '  rationale · '.(string) $step['rationale'];
            $lines[] = '  $ '.(string) $step['command'];
            $lines[] = '  → expected_status · '.(string) $step['expected_status'];
            $lines[] = '  → where_to_look · '.(string) $step['where_to_look'];
            $lines[] = '  → how_to_abort · '.(string) $step['how_to_abort'];
            if (! empty($step['next_command'])) {
                $lines[] = '  → next · '.(string) $step['next_command'];
            }
            $lines[] = '';
        }

        $warn = (array) $doc['real_run_warning'];
        $lines[] = '################################################################';
        $lines[] = (string) $warn['title'];
        $lines[] = '################################################################';
        foreach ((array) $warn['body'] as $line) {
            $lines[] = '  '.(string) $line;
        }
        $lines[] = '';
        $lines[] = '  comando · '.(string) $warn['command'];
        $lines[] = '  dry · '.(string) $warn['dry_command'];
        $lines[] = '  → expected_status · '.(string) $warn['expected_status'];
        $lines[] = '  → where_to_look · '.(string) $warn['where_to_look'];
        $lines[] = '  → how_to_abort · '.(string) $warn['how_to_abort'];
        $lines[] = '';

        $lines[] = '--- POST RUN ---';
        foreach ((array) $doc['post_run_steps'] as $step) {
            $lines[] = sprintf('Step %d · %s', (int) $step['number'], (string) $step['title']);
            $lines[] = '  rationale · '.(string) $step['rationale'];
            $lines[] = '  $ '.(string) $step['command'];
            $lines[] = '  → expected_status · '.(string) $step['expected_status'];
            $lines[] = '  → where_to_look · '.(string) $step['where_to_look'];
            $lines[] = '  → how_to_abort · '.(string) $step['how_to_abort'];
            $lines[] = '';
        }

        $lines[] = '--- ABORT STRATEGIES ---';
        foreach ((array) $doc['abort_strategies'] as $strat) {
            $lines[] = '· '.(string) $strat['context'];
            $lines[] = '  → '.(string) $strat['action'];
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  array<string,mixed>  $doc
     */
    private function renderMarkdown(array $doc): string
    {
        $lines = [];
        $lines[] = '# '.(string) $doc['title'];
        $lines[] = '';
        $lines[] = '`schema` '.(string) $doc['schema'];
        $lines[] = '`generated_at` '.(string) $doc['generated_at'];
        $lines[] = '';

        $lines[] = '## Preambulo';
        $lines[] = '';
        foreach ((array) $doc['preamble'] as $line) {
            $lines[] = '> '.(string) $line;
        }
        $lines[] = '';

        $lines[] = '## Precondicoes';
        $lines[] = '';
        foreach ((array) $doc['preconditions'] as $pre) {
            $lines[] = sprintf('### %s · %s', (string) $pre['id'], (string) $pre['label']);
            $lines[] = '';
            $lines[] = '```bash';
            $lines[] = (string) $pre['command'];
            $lines[] = '```';
            $lines[] = '';
            $lines[] = '> esperado · '.(string) $pre['expected'];
            $lines[] = '';
        }

        $lines[] = '## Steps';
        $lines[] = '';
        foreach ((array) $doc['steps'] as $step) {
            $lines[] = sprintf('### Step %d · %s', (int) $step['number'], (string) $step['title']);
            $lines[] = '';
            $lines[] = (string) $step['rationale'];
            $lines[] = '';
            $lines[] = '```bash';
            $lines[] = (string) $step['command'];
            $lines[] = '```';
            $lines[] = '';
            $lines[] = '- expected_status · `'.(string) $step['expected_status'].'`';
            $lines[] = '- where_to_look · '.(string) $step['where_to_look'];
            $lines[] = '- how_to_abort · '.(string) $step['how_to_abort'];
            if (! empty($step['next_command'])) {
                $lines[] = '- next · `'.(string) $step['next_command'].'`';
            }
            $lines[] = '';
        }

        $warn = (array) $doc['real_run_warning'];
        $lines[] = '## '.(string) $warn['title'];
        $lines[] = '';
        foreach ((array) $warn['body'] as $line) {
            $lines[] = '> '.(string) $line;
        }
        $lines[] = '';
        $lines[] = '```bash';
        $lines[] = (string) $warn['command'];
        $lines[] = '```';
        $lines[] = '';
        $lines[] = '_dry · sem as três flags emite plano e NÃO chama provider_';
        $lines[] = '';
        $lines[] = '```bash';
        $lines[] = (string) $warn['dry_command'];
        $lines[] = '```';
        $lines[] = '';
        $lines[] = '- expected_status · `'.(string) $warn['expected_status'].'`';
        $lines[] = '- where_to_look · '.(string) $warn['where_to_look'];
        $lines[] = '- how_to_abort · '.(string) $warn['how_to_abort'];
        $lines[] = '';

        $lines[] = '## Post run';
        $lines[] = '';
        foreach ((array) $doc['post_run_steps'] as $step) {
            $lines[] = sprintf('### Step %d · %s', (int) $step['number'], (string) $step['title']);
            $lines[] = '';
            $lines[] = (string) $step['rationale'];
            $lines[] = '';
            $lines[] = '```bash';
            $lines[] = (string) $step['command'];
            $lines[] = '```';
            $lines[] = '';
            $lines[] = '- expected_status · `'.(string) $step['expected_status'].'`';
            $lines[] = '- where_to_look · '.(string) $step['where_to_look'];
            $lines[] = '- how_to_abort · '.(string) $step['how_to_abort'];
            $lines[] = '';
        }

        $lines[] = '## Abort strategies';
        $lines[] = '';
        foreach ((array) $doc['abort_strategies'] as $strat) {
            $lines[] = '- **'.(string) $strat['context'].'** → '.(string) $strat['action'];
        }
        $lines[] = '';

        return implode("\n", $lines);
    }
}
