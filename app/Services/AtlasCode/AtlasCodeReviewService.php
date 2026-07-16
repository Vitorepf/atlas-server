<?php

declare(strict_types=1);

namespace App\Services\AtlasCode;

use App\Models\AiJob;
use App\Models\AiTrace;
use App\Services\Ai\AiGatewayService;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Atlas Código · H6 · a 2ª natureza da pílula: revisar VÁRIOS ao mesmo tempo.
 *
 * "revise os 6 commits de hoje" não devolve texto: devolve o GRAFO SE
 * TRANSFORMANDO. Cada commit ganha um agente próprio e um estado próprio na
 * própria linha dele. A interface É a resposta — não a descrição dela.
 *
 * Ancoragem sem tabela nova: `AiJob.client_id` já é chave única com relação
 * para o trace. `atlas-code:review:{repo}:{hash}` dá as duas coisas de graça:
 *
 *   - IDEMPOTÊNCIA: pedir a revisão duas vezes NÃO gasta dois agentes; o
 *     gateway devolve o mesmo trace (`existingTraceForClient`). O operador
 *     pode repetir a pergunta sem queimar motor.
 *   - LEITURA: o estado de cada commit é uma consulta por chave, não um
 *     varredura de traces.
 *
 * Zero mock: se o motor não roda, o estado diz isso. Um cartão "revisando…"
 * eterno seria mentira por silêncio.
 */
final class AtlasCodeReviewService
{
    public const SCHEMA_VERSION = 'atlas.code.review.v1';

    /** Quantos commits um lote aceita. Acima disto, é varredura, não revisão. */
    public const MAX_BATCH = 12;

    public function __construct(
        private readonly ?AiGatewayService $gateway = null,
        private readonly ?AtlasCodeProvenanceService $provenance = null,
    ) {}

    /**
     * A semente legível da âncora agente ↔ commit. Pura, e é o que se lê num
     * log ou numa auditoria — UUID não conta história.
     */
    public function reviewKey(string $repo, string $hash): string
    {
        return 'atlas-code:review:'.trim($repo).':'.mb_substr(strtolower(trim($hash)), 0, 40);
    }

    /**
     * O `client_id` que o gateway usa para deduplicar.
     *
     * `ai_jobs.client_id` é coluna UUID — a chave legível não cabe lá (o
     * Postgres recusa: `invalid input syntax for type uuid`). UUIDv5 resolve
     * sem migração e sem perder nada: mesma semente → sempre o mesmo UUID,
     * então a IDEMPOTÊNCIA continua de pé. Pedir a revisão do mesmo commit
     * duas vezes não gasta dois agentes.
     */
    public function clientId(string $repo, string $hash): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, $this->reviewKey($repo, $hash))->toString();
    }

    /**
     * O estado de um trace, na língua da tela.
     *
     * `null` = ninguém pediu revisão deste commit. Ausência é dita: não existe
     * "revisão pendente" que nunca foi pedida.
     */
    public function stateOf(?AiTrace $trace): string
    {
        if (! $trace instanceof AiTrace) {
            return 'idle';
        }

        // O guarda bloqueou a saída, mas o job rodou com ok=true → o trace grava
        // status='succeeded' com a NOTA do bloqueio como response_text. Sem
        // isto, a revisão bloqueada aparecia como 'done' com veredito "a saída
        // interna foi bloqueada; reenvie" — falha vestida de fato E vocabulário
        // de máquina como veredito de commit. No chat "reenvie" é resposta; como
        // veredito, é falha. Reconhecer a nota exige saber o texto dela — por
        // isso a constante no sanitizer.
        if (is_string($trace->response_text)
            && str_contains($trace->response_text, 'A saída interna foi bloqueada')) {
            return 'failed';
        }

        return match (strtolower((string) $trace->status)) {
            'succeeded', 'completed' => 'done',
            'failed', 'error' => 'failed',
            'processing', 'running' => 'running',
            // `cancelled` é terminal legal (operador cancelou) e caía no default
            // 'queued' → a pílula girava "na fila" para sempre sobre uma revisão
            // que ninguém vai terminar. Status desconhecido não é progresso: o
            // default erra para o lado de NÃO afirmar fila.
            'cancelled' => 'failed',
            'queued', 'pending', 'created' => 'queued',
            default => 'unknown',
        };
    }

    /**
     * O prompt do revisor — puro, para o teste poder ler o que o agente lê.
     *
     * Ele recebe FATO (mensagem, corpo, arquivos com contagem, E O DIFF),
     * nunca opinião pronta, e é instruído a citar arquivo. Revisor que não
     * pode apontar onde não revisou: opinou.
     *
     * O DIFF é o ponto, e custou a primeira leva de 12 agentes: a versão sem
     * ele mandava revisar um commit dando só a lista de NOMES de arquivo. O
     * agente fez o certo — foi procurar o código — não alcançou o repositório
     * e devolveu "Não encontrei os arquivos citados no workspace". O guarda de
     * saída bloqueou aquilo (era raciocínio, não resposta) e a tela mostrou
     * "saída interna bloqueada".
     *
     * Ninguém revisa código sem ver o código. O Atlas TEM o diff; fazer o
     * agente ir buscá-lo é desperdiçar o ecossistema e apostar que ele tem
     * acesso ao disco — o container é `:ro` e o worker roda fora dele.
     *
     * @param  array<string,mixed>  $provenance
     * @param  string  $diff  O patch real, já limitado.
     */
    public function reviewPrompt(array $provenance, string $diff = ''): string
    {
        $lines = [];
        $lines[] = 'Revise este commit como engenheiro sênior. Seja curto e concreto.';
        $lines[] = '';
        $lines[] = 'Commit: '.(string) ($provenance['commit_message'] ?? '(sem mensagem)');
        if (is_string($provenance['commit_body'] ?? null) && trim($provenance['commit_body']) !== '') {
            $lines[] = 'Descrição: '.trim($provenance['commit_body']);
        }

        $files = array_values(array_filter((array) ($provenance['files'] ?? []), 'is_array'));
        if ($files !== []) {
            $lines[] = '';
            $lines[] = 'Arquivos tocados:';
            foreach ($files as $file) {
                $additions = $file['additions'] ?? null;
                $deletions = $file['deletions'] ?? null;
                $counts = $additions === null || $deletions === null
                    ? 'binário'
                    : '+'.$additions.' -'.$deletions;
                $lines[] = '  - '.(string) ($file['path'] ?? '?').' ('.(string) ($file['status'] ?? '?').', '.$counts.')';
            }
        }

        if (trim($diff) !== '') {
            $lines[] = '';
            $lines[] = 'O DIFF (é isto que você revisa — não procure o repositório, ele não está ao seu alcance):';
            $lines[] = '```diff';
            $lines[] = trim($diff);
            $lines[] = '```';
        } else {
            // Sem diff, o revisor tem de saber que está cego — senão ele sai
            // procurando o repositório, não acha, e devolve raciocínio.
            $lines[] = '';
            $lines[] = 'ATENÇÃO: o diff deste commit não pôde ser lido. Você NÃO tem acesso ao repositório.';
            $lines[] = 'Responda exatamente: "não consegui ver o diff deste commit". Não tente procurar os arquivos.';
        }

        $lines[] = '';
        $lines[] = 'Responda em português, em no máximo 3 frases, SEM raciocinar em voz alta:';
        $lines[] = '1. Há problema real neste commit? Se não houver, diga "sem problema" e pare.';
        $lines[] = '2. Se houver, qual é e em QUAL arquivo. Sem citar arquivo, não afirme.';
        $lines[] = 'Não elogie. Não resuma o que o commit faz — isso a mensagem já diz.';
        $lines[] = 'Escreva SÓ o veredito final. Nada de plano, nada de "vou verificar".';

        return implode("\n", $lines);
    }

    /**
     * O DIFF do commit — o que o revisor precisa ver para revisar.
     *
     * O corte é dito, nunca silencioso: diff gigante avisa quantas linhas
     * ficaram de fora, e o revisor sabe que não viu tudo.
     */
    public function diff(string $path, string $hash, int $maxChars = 20_000): string
    {
        $raw = $this->git($path, ['git', 'show', '--format=', '--unified=3', '-M', $hash]);
        if (trim($raw) === '') {
            return '';
        }

        if (mb_strlen($raw) <= $maxChars) {
            return rtrim($raw);
        }

        $remaining = mb_substr_count(mb_substr($raw, $maxChars), "\n");

        return rtrim(mb_substr($raw, 0, $maxChars))
            ."\n\n[diff cortado — mais ~{$remaining} linhas que você NÃO viu. Se o veredito depender delas, diga isso em vez de afirmar.]";
    }

    /** @param array<int,string> $command */
    private function git(string $path, array $command): string
    {
        try {
            $process = new Process($command, $path, null, null, 20);
            $process->run();

            return $process->isSuccessful() ? $process->getOutput() : '';
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * Enfileira um revisor por commit e devolve o estado de cada um.
     *
     * @param  array<int,string>  $hashes
     * @return array<string,mixed>
     */
    public function start(string $repo, array $hashes): array
    {
        $located = (new AtlasCodeRepoLocator())->locate($repo);
        $hashes = $this->boundedHashes($hashes);

        $reviews = [];
        foreach ($hashes as $hash) {
            $reviews[] = $this->startOne($located['slug'], $located['path'], $hash);
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'repo' => $located['slug'],
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'reviews' => $reviews,
        ];
    }

    /**
     * @param  array<int,string>  $hashes
     * @return array<string,mixed>
     */
    public function status(string $repo, array $hashes): array
    {
        $located = (new AtlasCodeRepoLocator())->locate($repo);

        $reviews = [];
        foreach ($this->boundedHashes($hashes) as $hash) {
            $trace = $this->traceFor($located['slug'], $hash);
            $reviews[] = $this->shape($hash, $this->stateOf($trace), $trace);
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'repo' => $located['slug'],
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'reviews' => $reviews,
        ];
    }

    /**
     * @param  array<int,string>  $hashes
     * @return array<int,string>
     */
    public function boundedHashes(array $hashes): array
    {
        $clean = [];
        foreach ($hashes as $hash) {
            $hash = strtolower(trim((string) $hash));
            if (preg_match('/^[0-9a-f]{7,40}$/', $hash) === 1) {
                $clean[$hash] = true;
            }
        }

        return array_slice(array_keys($clean), 0, self::MAX_BATCH);
    }

    /**
     * @return array<string,mixed>
     */
    private function startOne(string $repo, string $path, string $hash): array
    {
        // Revisão que FALHOU não é revisão feita: a idempotência não pode
        // guardar a falha para sempre. Soltar a chave devolve ao operador o
        // direito de mandar de novo — e o agente que falhou por motivo
        // transitório (ou por bug já corrigido) tem uma segunda chance.
        // Sucesso e trabalho em andamento continuam intocados: pedir duas vezes
        // não gasta dois agentes.
        $existing = $this->traceFor($repo, $hash);
        if ($this->stateOf($existing) === 'failed') {
            $this->releaseKey($repo, $hash);
        }

        try {
            $provenance = ($this->provenance ?? new AtlasCodeProvenanceService())->capture($hash, $repo);
        } catch (Throwable) {
            return $this->shape($hash, 'failed', null, 'não consegui ler este commit.');
        }

        try {
            $trace = ($this->gateway ?? app(AiGatewayService::class))->enqueueInteraction(
                $this->reviewPrompt($provenance, $this->diff($path, $hash)),
                [
                    // A chave é o contrato de idempotência: pedir de novo NÃO
                    // gasta outro agente.
                    'client_id' => $this->clientId($repo, $hash),
                    'source_type' => 'system',
                    'agent' => 'atlas-code-review',
                    'intent' => 'code_review',
                    // O MOTOR É DO ATLAS DECIDE — e continua sendo.
                    //
                    // Tentei fixar `codex_cli` aqui (23 respostas limpas e 0
                    // bloqueadas em 14 dias, contra 9 bloqueadas do hermes) por
                    // `options['provider']` E por `payload.operator_requested_
                    // provider`. O Decide ignorou as duas: ele é dono da
                    // topologia por canon, e está certo. Config que não faz
                    // nada seria pior que ausência — parece que resolve.
                    //
                    // Enquanto o Decide mandar esta rota para o hermes_cli, o
                    // veredito morre no guarda: o hermes abre `┌─ Reasoning ┐`,
                    // escreve o pensamento e NUNCA fecha a moldura (verificado:
                    // zero `└` na saída). Sem o fecho, bloquear é a decisão
                    // certa. O conserto é declarar a política no Atlas Decide
                    // (revisão de commit → motor que responde sem raciocinar em
                    // voz alta) ou impedir o hermes de imprimir a moldura nesta
                    // rota — nenhum dos dois se faz de passagem daqui.
                    'payload' => [
                        'atlas_code' => ['repo' => $repo, 'commit_hash' => $hash],
                        // MODO READ, e isto é o ponto: revisar é LER e opinar —
                        // o agente não toca no repositório. O default do
                        // servidor é `danger`, que exige workspace-cert, e a
                        // governança negou os 12 primeiros agentes com
                        // `permission_denied` — corretamente: eu estava pedindo
                        // autoridade de escrita para um trabalho de leitura.
                        // Privilégio pedido além do necessário é falha de
                        // desenho, não obstáculo a contornar.
                        'permission_mode' => 'read',
                        'tool_permissions' => ['mode' => 'read'],
                    ],
                ],
            );
        } catch (Throwable $exception) {
            // Motor desligado é DITO. Cartão "revisando…" eterno é mentira por
            // silêncio — o pior tipo, porque parece que algo acontece.
            return $this->shape($hash, 'failed', null, $this->engineFailure($exception));
        }

        return $this->shape($hash, $this->stateOf($trace), $trace);
    }

    private function engineFailure(Throwable $exception): string
    {
        $message = strtolower($exception->getMessage());

        if (str_contains($message, 'disabled')) {
            return 'o motor de IA está desligado — nenhum agente foi acionado.';
        }

        return 'não consegui acionar um agente para este commit.';
    }

    /**
     * Solta a chave de idempotência para um novo agente poder nascer.
     *
     * Só o job sai; o trace fica — a falha continua auditável no ledger. O que
     * se apaga é o direito de bloquear uma segunda tentativa, não a memória de
     * que a primeira falhou.
     */
    private function releaseKey(string $repo, string $hash): void
    {
        try {
            AiJob::query()->where('client_id', $this->clientId($repo, $hash))->delete();
        } catch (Throwable) {
            // Não conseguir soltar a chave não pode derrubar o lote: os outros
            // commits seguem.
        }
    }

    private function traceFor(string $repo, string $hash): ?AiTrace
    {
        try {
            return AiJob::query()
                ->with('trace')
                ->where('client_id', $this->clientId($repo, $hash))
                ->first()?->trace;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function shape(string $hash, string $state, ?AiTrace $trace, ?string $note = null): array
    {
        $verdict = $state === 'done' && is_string($trace?->response_text)
            ? trim($trace->response_text)
            : null;

        return array_filter([
            'hash' => $hash,
            'state' => $state,
            // O veredito só existe quando o agente terminou. Nada de "provável".
            'verdict' => $verdict,
            'note' => $note,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
