<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\Signal\AtlasIdentifierLimitAudit;
use Tests\TestCase;

/**
 * O limite de 63 caracteres do Postgres não avisa: corta e segue. Uma tabela já
 * foi criada assim neste repo e ficou meses invisível.
 *
 * Índices cortados são mais brandos — o Postgres criou e usa. O que não é
 * brando: dois nomes DECLARADOS que cortem na mesma string de 63 colidem no
 * CREATE, e a migration falha de vez.
 *
 * Medido em 28/07/2026: 145 cortados, ZERO colisões, e o par mais próximo
 * compartilha 59 caracteres — 4 de folga. Uma tabela com nome quatro caracteres
 * maior acaba com ela, e é por isso que este número vira teste e não nota.
 */
final class AtlasIdentifierLimitAuditTest extends TestCase
{
    private function audit(array $names): array
    {
        return app(AtlasIdentifierLimitAudit::class)->audit($names);
    }

    public function test_it_finds_the_pair_that_would_collide(): void
    {
        $base = str_repeat('a', 60);
        $out = $this->audit([$base.'_workspace_id_index', $base.'_workspace_id_unique']);

        self::assertCount(1, $out['collisions']);
        self::assertSame(63, strlen($out['collisions'][0]['prefix']));
        self::assertCount(2, $out['collisions'][0]['names']);
    }

    public function test_names_within_the_limit_never_collide_however_similar(): void
    {
        // Cortar um nome curto devolve ele mesmo: só nome LONGO colide.
        $out = $this->audit(['pedido_status_index', 'pedido_status_unique']);

        self::assertSame([], $out['collisions']);
        self::assertSame([], $out['truncated']);
    }

    public function test_the_margin_is_the_distance_to_the_first_collision(): void
    {
        // Dois nomes que compartilham 59 caracteres: faltam 4 para o corte os
        // igualar. É o número que diz quanto tempo ainda resta.
        $shared = str_repeat('b', 58); // 58 + '_' do sufixo = 59 compartilhados
        $out = $this->audit([$shared.'_alpha_index', $shared.'_beta_index']);

        self::assertSame(59, $out['shared_prefix_len']);
        self::assertSame(4, $out['margin']);
        self::assertSame([], $out['collisions'], '59 compartilhados ainda não é colisão');
    }

    public function test_the_live_schema_has_no_collision_and_reports_its_margin(): void
    {
        $source = app(AtlasIdentifierLimitAudit::class)->fromDatabase();
        $out = $this->audit($source['declared']);

        // Em :memory: o driver não é Postgres, e o auditor diz que NÃO SABE em
        // vez de devolver lista vazia com cara de "está tudo certo" — que é
        // exatamente como o corte silencioso passou meses despercebido.
        self::assertFalse($source['available']);
        self::assertSame([], $out['collisions']);
        self::assertGreaterThanOrEqual(0, $out['margin']);
        self::assertLessThanOrEqual(AtlasIdentifierLimitAudit::PG_LIMIT, $out['margin']);
    }
}
