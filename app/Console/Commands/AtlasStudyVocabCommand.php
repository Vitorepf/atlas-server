<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * O vocabulario controlado de um dominio, e a busca que o torna recuperavel.
 *
 * Sem isto o principio do operador flutua: ele escreve "contra range polarizada eu
 * defendo menos" e nada no sistema sabe o que "polarizada" significa, nem consegue
 * devolver todo principio que toca esse conceito. Vocabulario sem busca e um PDF; o que
 * ensina e poder ir do termo para as decisoes e da decisao de volta para o termo.
 *
 * TRES DEFEITOS DE ORIGEM QUE ESTE COMANDO REPARA, cada um medido sobre os 305 verbetes
 * reais do `Dicionario_do_Poker.pdf` antes de uma linha ser escrita. O reparo mora aqui,
 * em codigo versionado, e nao no JSON de rascunho, porque extracao se refaz e conserto
 * em arquivo temporario se perde.
 *
 * 1. RODAPE DE PAGINA VAZANDO NO CORPO — 62 verbetes (20%). A extracao intercala o
 *    rodape "Comunidade Reg Life - Todos os direitos reservados / POKER E... falar outro
 *    IDIOMA" no meio do texto, e o que vem DEPOIS dele e a pagina seguinte, que pertence
 *    a OUTRO verbete. Medido: a definicao de "Average" terminava com "Quando voce precisa
 *    de mais 2 cartas para formar um jogo completo" — que nao e a definicao de Average.
 *    Sem este corte, 1 em cada 5 verbetes ensinaria a coisa errada. O corte e seguro
 *    porque a definicao verdadeira SEMPRE termina antes do rodape: verificado que as 62
 *    fecham em ponto final apos o corte. 13.950 caracteres estranhos removidos.
 *
 * 2. ROTULO DE SECAO LIDO COMO VERBETE — 18 entradas. "Variacao" (x12), "Curiosidade"
 *    (x3), "Variacoes" (x2) e "Exemplos de uso" nao sao termos; sao blocos que pertencem
 *    ao verbete ANTERIOR. Como a chave e unica por (dominio, termo), importar sem tratar
 *    faria 14 entradas se sobrescreverem EM SILENCIO — o vocabulario perderia conteudo e
 *    ainda relataria sucesso. Aqui elas sao anexadas ao verbete anterior, com recibo.
 *
 * 3. ESPACO COMIDO NA QUEBRA DE LINHA — "modalidadeOmahaonde", "continuationBet". O
 *    reparo e deliberadamente estreito: so insere espaco entre uma corrida de minusculas
 *    e uma Maiuscula seguida de minusculas. Sigla ("GTO", "MDF", "EV", "ICM") nunca casa
 *    esse padrao, entao "3bet" e "cbet" saem intactos. Ele NAO conserta juncao de mesma
 *    caixa ("Minimade" continua "Minimade"), porque desfazer isso exigiria lexico e
 *    chutar separacao em palavra real e pior que deixar visivel.
 */
class AtlasStudyVocabCommand extends Command
{
    protected $signature = 'atlas:study:vocab
        {--import= : Caminho de um JSON [{termo,definicao}] para importar}
        {--domain=poker : Dominio de estudo — o mesmo eixo do scope_id do sinal}
        {--source= : De onde veio o corpus (ex.: Dicionario_do_Poker.pdf)}
        {--search= : Busca por termo ou definicao}
        {--term= : Mostra um verbete exato}
        {--limit=20 : Maximo de resultados}
        {--dry-run : Mostra o que seria importado, sem gravar}
        {--json : Saida canonica}';

    protected $description = 'Importa e consulta o vocabulario controlado de um dominio de estudo.';

    public function handle(): int
    {
        if (! DatabaseTableAvailability::has('atlas_study_vocabulary')) {
            return $this->responde(['ok' => false, 'reason' => 'missing_vocabulary_table', 'hint' => 'rode as migrations']);
        }

        $domain = (string) $this->option('domain');

        if ($this->option('import')) {
            return $this->importar((string) $this->option('import'), $domain);
        }
        if ($this->option('term')) {
            return $this->mostrar((string) $this->option('term'), $domain);
        }

        return $this->buscar((string) ($this->option('search') ?: ''), $domain);
    }

    /**
     * Dobra caixa e acento para que "Mão", "mao" e "MAO" sejam o mesmo verbete. Sem isso a
     * ancora do principio passa a depender de como o operador digitou naquele dia.
     */
    public static function normalizar(string $termo): string
    {
        $termo = trim(preg_replace('/\s+/u', ' ', $termo) ?? '');

        return Str::lower(Str::ascii($termo));
    }

    /** Devolve o espaco que a quebra de linha do PDF comeu. */
    public static function repararEspacos(string $texto): string
    {
        $texto = preg_replace('/([a-zà-ÿ])([A-ZÀ-Þ][a-zà-ÿ])/u', '$1 $2', $texto) ?? $texto;

        return trim(preg_replace('/\s+/u', ' ', $texto) ?? $texto);
    }

    /**
     * Corta no rodape de pagina. Tudo depois dele e a pagina seguinte — texto de OUTRO
     * verbete. Cortar encurta; nao cortar ensina errado, e so um dos dois e recuperavel.
     */
    public static function cortarRodape(string $texto): string
    {
        $marca = '/Comunidade\s*Reg\s*Life\s*-\s*Todos\s+os\s+direitos\s+reservados/iu';
        $partes = preg_split($marca, $texto, 2);

        return trim(is_array($partes) ? $partes[0] : $texto);
    }

    /**
     * Rotulos que o PDF usa como sub-bloco e o parser leu como termo. Nao sao vocabulario:
     * pertencem ao verbete anterior.
     */
    public static function ehRotuloDeSecao(string $termo): bool
    {
        $chave = rtrim(self::normalizar($termo), ':');

        return in_array($chave, [
            'variacao', 'variacoes', 'curiosidade', 'curiosidades',
            'exemplo', 'exemplos', 'exemplos de uso', 'observacao', 'nota',
        ], true);
    }

    private function importar(string $caminho, string $domain): int
    {
        if (! is_file($caminho)) {
            return $this->responde(['ok' => false, 'reason' => 'file_not_found', 'path' => $caminho]);
        }

        $bruto = json_decode((string) file_get_contents($caminho), true);
        if (! is_array($bruto)) {
            return $this->responde(['ok' => false, 'reason' => 'invalid_json', 'path' => $caminho]);
        }

        $source = (string) ($this->option('source') ?: basename($caminho));
        $dryRun = (bool) $this->option('dry-run');

        [$limpos, $recibo] = $this->limpar($bruto);

        $criados = 0;
        $atualizados = 0;

        foreach ($limpos as $chave => $verbete) {
            if ($dryRun) {
                $criados++;

                continue;
            }

            $existente = DB::table('atlas_study_vocabulary')
                ->where('domain', $domain)->where('term_normalized', $chave)->first();

            if ($existente !== null) {
                DB::table('atlas_study_vocabulary')->where('id', $existente->id)->update([
                    'term' => $verbete['termo'],
                    'definition' => $verbete['definicao'],
                    'source_ref' => $source,
                    'updated_at' => now(),
                ]);
                $atualizados++;
            } else {
                DB::table('atlas_study_vocabulary')->insert([
                    'id' => (string) Str::uuid(),
                    'domain' => $domain,
                    'term' => $verbete['termo'],
                    'term_normalized' => $chave,
                    'definition' => $verbete['definicao'],
                    'source_ref' => $source,
                    'metadata' => json_encode(['schema_version' => 'atlas.study.vocab.v1'], JSON_UNESCAPED_UNICODE),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $criados++;
            }
        }

        return $this->responde(array_merge([
            'ok' => true,
            'dry_run' => $dryRun,
            'domain' => $domain,
            'source' => $source,
            'lidos' => count($bruto),
            'criados' => $criados,
            'atualizados' => $atualizados,
        ], $recibo, [
            'total_no_dominio' => DB::table('atlas_study_vocabulary')->where('domain', $domain)->count(),
        ]));
    }

    /**
     * Aplica os tres reparos e devolve [verbetes indexados por chave, recibo].
     *
     * O recibo existe para que a contagem final BATA com o corpus. Um importador que
     * engole 14 entradas e responde "ok" mente sobre a propria cobertura, e o operador
     * so descobre meses depois, procurando um termo que ele jurava ter importado.
     *
     * @param  array<int,mixed>  $bruto
     * @return array{0:array<string,array{termo:string,definicao:string}>,1:array<string,mixed>}
     */
    private function limpar(array $bruto): array
    {
        $verbetes = [];
        $ultimaChave = null;
        $recibo = ['rodape_cortado' => 0, 'secoes_anexadas' => 0, 'espacos_reparados' => 0, 'colisoes' => 0, 'recusados' => 0, 'recusados_amostra' => []];

        foreach ($bruto as $linha) {
            if (! is_array($linha)) {
                continue;
            }
            $termo = trim((string) ($linha['termo'] ?? $linha['term'] ?? ''));
            $original = trim((string) ($linha['definicao'] ?? $linha['definition'] ?? ''));

            $semRodape = self::cortarRodape($original);
            if ($semRodape !== $original) {
                $recibo['rodape_cortado']++;
            }
            $definicao = self::repararEspacos($semRodape);
            if ($definicao !== trim(preg_replace('/\s+/u', ' ', $semRodape) ?? $semRodape)) {
                $recibo['espacos_reparados']++;
            }

            if ($termo === '' || $definicao === '') {
                $recibo['recusados']++;
                if (count($recibo['recusados_amostra']) < 5) {
                    $recibo['recusados_amostra'][] = ['termo' => $termo, 'reason' => 'empty_term_or_definition'];
                }

                continue;
            }

            // Rotulo de secao pertence ao verbete anterior. Sem isto a chave unica faria
            // as 12 "Variacao" se sobrescreverem, e 11 blocos de conteudo sumiriam.
            if (self::ehRotuloDeSecao($termo) && $ultimaChave !== null) {
                $verbetes[$ultimaChave]['definicao'] .= ' '.rtrim($termo, ':').': '.$definicao;
                $recibo['secoes_anexadas']++;

                continue;
            }

            $chave = self::normalizar($termo);
            // Dois termos reais que normalizam para a mesma chave: o segundo sobrescreve.
            // Isso pode estar certo (reimportacao) ou ser perda; de qualquer forma nao
            // pode acontecer sem numero.
            if (isset($verbetes[$chave])) {
                $recibo['colisoes']++;
            }
            $verbetes[$chave] = ['termo' => $termo, 'definicao' => $definicao];
            $ultimaChave = $chave;
        }

        return [$verbetes, $recibo];
    }

    private function mostrar(string $termo, string $domain): int
    {
        $row = DB::table('atlas_study_vocabulary')
            ->where('domain', $domain)
            ->where('term_normalized', self::normalizar($termo))
            ->first();

        if ($row === null) {
            return $this->responde([
                'ok' => false,
                'reason' => 'term_not_found',
                'term' => $termo,
                'domain' => $domain,
            ]);
        }

        return $this->responde(['ok' => true, 'term' => $row->term, 'definition' => $row->definition, 'source_ref' => $row->source_ref]);
    }

    private function buscar(string $query, string $domain): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $q = DB::table('atlas_study_vocabulary')->where('domain', $domain);

        if ($query !== '') {
            // Busca pelo normalizado no termo: quem procura "mao" acha "Mão". A definicao
            // entra na busca porque o operador nem sempre sabe o NOME do conceito que quer.
            $alvo = '%'.self::normalizar($query).'%';
            $q->where(function ($w) use ($alvo, $query): void {
                $w->where('term_normalized', 'like', $alvo)
                    ->orWhere('definition', 'like', '%'.$query.'%');
            });
        }

        $rows = $q->orderBy('term')->limit($limit)->get(['term', 'definition', 'source_ref']);

        return $this->responde([
            'ok' => true,
            'domain' => $domain,
            'query' => $query,
            'encontrados' => $rows->count(),
            'total_no_dominio' => DB::table('atlas_study_vocabulary')->where('domain', $domain)->count(),
            'verbetes' => $rows->map(fn ($r): array => [
                'term' => $r->term,
                'definition' => Str::limit($r->definition, 220),
            ])->all(),
        ]);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function responde(array $payload): int
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | ($this->option('json') ? 0 : JSON_PRETTY_PRINT);
        $this->line((string) json_encode($payload, $flags));

        return ($payload['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }
}
