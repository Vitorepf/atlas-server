<?php

declare(strict_types=1);

namespace App\Services\AtlasCode;

use Symfony\Component\Process\Process;

/**
 * Atlas Code · M5 Espelho (read-model).
 *
 * O espelho é ADAPTADOR, não fundação: GitHub hoje, origin da Cursor amanhã,
 * GitLab ou self-host. A verdade mora no Mac; lá fora é cópia. Este service
 * só LÊ: diz o que sairia, e o que a varredura encontrou. Nada é enviado
 * aqui — enviar é ato governado (C25), com recibo e undo.
 *
 * Princípios (docs/atlas-codigo-evolucao.md §1, §6):
 *   - Zero dado inventado: sem remote configurado, `mirror` é null — nunca
 *     "0 commits a espelhar".
 *   - Nada sai da máquina sem varredura: os achados vêm antes do envio.
 */
final class AtlasCodeMirrorService
{
    public const SCHEMA_VERSION = 'atlas.code.mirror.v1';

    /**
     * Padrões de segredo de alta confiança (baixo falso-positivo). Cada um é
     * um bloqueio real de envio, não um aviso decorativo.
     *
     * @var array<string,string>
     */
    private const SECRET_PATTERNS = [
        'openai_key' => '/\bsk-[A-Za-z0-9_-]{20,}/',
        'anthropic_key' => '/\bsk-ant-[A-Za-z0-9_-]{20,}/',
        'github_token' => '/\bgh[pousr]_[A-Za-z0-9]{30,}/',
        'aws_access_key' => '/\bAKIA[0-9A-Z]{16}\b/',
        'private_key_block' => '/-----BEGIN (?:RSA |EC |OPENSSH |PGP )?PRIVATE KEY-----/',
        'google_api_key' => '/\bAIza[0-9A-Za-z_-]{35}\b/',
        'slack_token' => '/\bxox[baprs]-[0-9A-Za-z-]{10,}/',
    ];

    public function __construct(
        private readonly ?AtlasCodeWorkspaceProfileService $profiles = null,
        private readonly int $timeoutSeconds = 20,
    ) {}

    /**
     * Função PURA: dado um diff/patch, devolve os achados. Testável sem git.
     * Reporta o padrão pelo NOME — nunca o segredo em si (provider-safe).
     *
     * @return array<int, array{rule:string, line:int}>
     */
    public function scanForSecrets(string $patch): array
    {
        $findings = [];
        $lines = preg_split('/\r?\n/', $patch) ?: [];
        foreach ($lines as $index => $line) {
            // Só linhas adicionadas podem vazar algo novo para o espelho.
            if (! str_starts_with($line, '+')) {
                continue;
            }
            foreach (self::SECRET_PATTERNS as $rule => $pattern) {
                if (preg_match($pattern, $line) === 1) {
                    $findings[] = ['rule' => $rule, 'line' => $index + 1];
                }
            }
        }

        return $findings;
    }

    /**
     * @return array<string,mixed>
     */
    public function capture(string $repo): array
    {
        // O locator da frota: perfil registrado vence, disco responde pelo
        // resto. Espelho e semana ficaram na localização antiga (só perfis)
        // quando ask/graph migraram — medido: nivor-back-end e
        // blackink-website tinham grafo (200) e espelho 404 na MESMA tela.
        $located = (new AtlasCodeRepoLocator())->locate($repo);
        $path = $located['path'];

        $branch = trim($this->run($path, ['git', 'branch', '--show-current']));

        // `git remote` que FALHA não é "sem espelho configurado": .git
        // corrompido e timeout viravam a mesma frase de um repo genuinamente
        // sem remote — falha vestida de fato, na tela que vigia backup.
        $remoteProcess = new Process(['git', 'remote'], $path, null, null, $this->timeoutSeconds);
        try {
            $remoteProcess->run();
        } catch (\Throwable) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'repo' => $located['slug'],
                'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
                'branch' => $branch !== '' ? $branch : null,
                'mirror' => null,
                'reason' => 'remote_unreadable',
            ];
        }
        if (! $remoteProcess->isSuccessful()) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'repo' => $located['slug'],
                'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
                'branch' => $branch !== '' ? $branch : null,
                'mirror' => null,
                'reason' => 'remote_unreadable',
            ];
        }
        $remote = trim($remoteProcess->getOutput());

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'repo' => $located['slug'],
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'branch' => $branch !== '' ? $branch : null,
        ];

        if ($remote === '') {
            // Sem espelho configurado a tela não inventa contagem: diz o fato.
            $payload['mirror'] = null;
            $payload['reason'] = 'no_remote_configured';

            return $payload;
        }

        $remoteName = trim(explode("\n", $remote)[0]);
        $upstream = $branch !== '' ? $remoteName.'/'.$branch : '';
        $ahead = $this->countAhead($path, $upstream);

        $payload['mirror'] = [
            'name' => $remoteName,
            'host' => $this->hostOf($this->run($path, ['git', 'remote', 'get-url', $remoteName])),
            'upstream' => $upstream !== '' ? $upstream : null,
        ];

        if ($ahead === null) {
            // Upstream ausente (branch nova, remote sem fetch): estado honesto.
            $payload['pending'] = null;
            $payload['reason'] = 'upstream_unknown';

            return $payload;
        }

        $payload['pending'] = ['commits' => $ahead];

        if ($ahead > 0) {
            // `git log -p`, NÃO `git diff`. O diff agregado só vê o estado
            // FINAL: um segredo commitado num commit e removido no seguinte
            // some do diff — mas o push envia os dois commits, e o blob com o
            // segredo fica recuperável para sempre no espelho. É o cenário real
            // de vazamento (commitou .env por engano, "consertou" removendo
            // depois). `log -p` vê cada commit, que é o que o remoto recebe.
            $patch = $this->run($path, ['git', 'log', '--format=', '-p', $upstream.'..HEAD']);
            $findings = $this->scanForSecrets($patch);
            $payload['scan'] = [
                'ran' => true,
                'findings' => $findings,
                'blocked' => $findings !== [],
            ];
        }

        return $payload;
    }

    private function countAhead(string $path, string $upstream): ?int
    {
        if ($upstream === '') {
            return null;
        }
        $process = new Process(['git', 'rev-list', '--count', $upstream.'..HEAD'], $path, null, null, $this->timeoutSeconds);
        $process->run();
        if (! $process->isSuccessful()) {
            return null;
        }
        $value = filter_var(trim($process->getOutput()), FILTER_VALIDATE_INT);

        return $value === false ? null : (int) $value;
    }

    /**
     * O host é detalhe do adaptador — só o nome, nunca a URL (que pode
     * carregar credencial embutida).
     */
    public function hostOf(string $remoteUrl): ?string
    {
        $url = trim($remoteUrl);
        if ($url === '') {
            return null;
        }
        if (preg_match('#^[\w.+-]+@([^:]+):#', $url, $matches) === 1) {
            return $matches[1];
        }
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : null;
    }

    /**
     * @param  array<int,string>  $command
     */
    private function run(string $path, array $command): string
    {
        $process = new Process($command, $path, null, null, $this->timeoutSeconds);
        $process->run();
        if (! $process->isSuccessful()) {
            return '';
        }

        return $process->getOutput();
    }
}
