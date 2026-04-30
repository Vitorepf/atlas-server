<?php

namespace Tests\Unit;

use App\Support\BehaviorCategories;
use PHPUnit\Framework\TestCase;

class BehaviorCategoriesTest extends TestCase
{
    public function test_canonicalizes_legacy_categories(): void
    {
        $this->assertSame('substancias', BehaviorCategories::canonicalize('bebida'));
        $this->assertSame('substancias', BehaviorCategories::canonicalize('suplemento'));
        $this->assertSame('relacional', BehaviorCategories::canonicalize('conflito'));
        $this->assertSame('sono_ritmo', BehaviorCategories::canonicalize('sono'));
        $this->assertSame('treino_movimento', BehaviorCategories::canonicalize('treino'));
        $this->assertSame('trabalho_cognicao', BehaviorCategories::canonicalize('trabalho'));
        $this->assertSame('saude_sintoma', BehaviorCategories::canonicalize('saude'));
    }

    public function test_canonicalizes_display_labels(): void
    {
        $this->assertSame('substancias', BehaviorCategories::canonicalize('Substâncias'));
        $this->assertSame('treino_movimento', BehaviorCategories::canonicalize('Treino/Movimento'));
        $this->assertSame('saude_sintoma', BehaviorCategories::canonicalize('Saúde/Sintoma'));
    }
}
