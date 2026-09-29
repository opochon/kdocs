<?php

declare(strict_types=1);

namespace Ged\Workflows;

/**
 * Erreur nommée du secteur Workflows : elle décrit l'étape et la cause (jamais un 500 muet).
 * Une règle qui échoue ne doit jamais remonter jusqu'à l'ingestion : seul l'écouteur la rattrape.
 */
final class ErreurWorkflow extends \RuntimeException
{
}
