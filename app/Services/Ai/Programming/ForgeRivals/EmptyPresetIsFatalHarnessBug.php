<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\ForgeRivals;

use LogicException;

/**
 * Thrown by AtlasForgeRivalsCasesRegistry when a preset resolves to zero cases.
 *
 * A preset with no cases is never a legitimate operator state — it always
 * indicates the registry itself was misconfigured (empty list, broken adapter,
 * unknown legacy id). It must never silently downgrade to a no-op battery.
 *
 * Per the v2 operator battery canon: "Se preset seleciona 0 casos, isso é bug
 * fatal do harness e deve falhar com mensagem clara."
 */
final class EmptyPresetIsFatalHarnessBug extends LogicException {}
