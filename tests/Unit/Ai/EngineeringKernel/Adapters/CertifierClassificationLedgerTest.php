<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\EngineeringKernel\Adapters;

use App\Services\Ai\EngineeringKernel\Adapters\CertifierClassificationLedger;
use PHPUnit\Framework\TestCase;

/**
 * OBRA #5 S0 (AC-0.1 / AC-0.2) — todo *CertificationService do app/ nasce classificado.
 * Um certifier novo sem entrada no ledger quebra este teste com mensagem acionável:
 * ou é juiz de ENTREGA (A_DELIVERY → precisa do adapter do gate soberano, S1) ou é
 * auditor de ESTADO (B_STATE → motor único, S2). Entrada apontando para classe
 * inexistente também quebra (ledger não acumula fantasmas).
 */
final class CertifierClassificationLedgerTest extends TestCase
{
    /** @return list<string> FQCNs reais no disco */
    private function certifiersOnDisk(): array
    {
        $out = [];
        $root = dirname(__DIR__, 5); // tests/Unit/Ai/EngineeringKernel/Adapters -> raiz do repo
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root.'/app', \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($it as $f) {
            if (str_ends_with($f->getFilename(), 'CertificationService.php')) {
                $rel = str_replace($root.'/', '', $f->getPathname());
                $out[] = str_replace(['app/', '/', '.php'], ['App/', '\\', ''], $rel);
            }
        }
        sort($out);

        return $out;
    }

    public function test_every_certifier_on_disk_is_classified(): void
    {
        $ledger = CertifierClassificationLedger::all();
        $missing = array_values(array_filter(
            $this->certifiersOnDisk(),
            static fn (string $fqcn): bool => ! isset($ledger[$fqcn]),
        ));

        $this->assertSame([], $missing,
            'Certifier(s) novo(s) sem classificação no CertifierClassificationLedger: '
            .implode(', ', $missing)
            .' — classifique como A_DELIVERY (juiz de entrega → exige adapter do gate soberano) ou B_STATE (auditor de estado → motor único).');
    }

    public function test_ledger_has_no_ghost_entries(): void
    {
        $ghosts = array_values(array_filter(
            array_keys(CertifierClassificationLedger::all()),
            static fn (string $fqcn): bool => ! class_exists($fqcn),
        ));

        $this->assertSame([], $ghosts, 'Entradas do ledger sem classe no disco: '.implode(', ', $ghosts));
    }

    public function test_categories_are_disjoint_and_delivery_judges_are_the_known_three(): void
    {
        $all = CertifierClassificationLedger::all();
        $this->assertCount(
            count(CertifierClassificationLedger::A_DELIVERY)
            + count(CertifierClassificationLedger::B_STATE)
            + count(CertifierClassificationLedger::C_PARKED)
            + count(CertifierClassificationLedger::D_ISOLATED),
            $all,
            'FQCN repetido entre categorias do ledger.',
        );

        $this->assertCount(3, CertifierClassificationLedger::A_DELIVERY,
            'Juiz de entrega novo? Ele PRECISA de adapter do gate soberano (padrão S1) antes de entrar aqui.');
    }
}
