<?php

declare(strict_types=1);

namespace KDocs\Apps\Erpconnect\Services;

use RuntimeException;

/**
 * Levée quand K-Time est injoignable (réseau, timeout, HTTP 5xx/0).
 *
 * Le contrôleur retourne HTTP 503 et le panneau affiche « K-Time indisponible »
 * sans jamais propager une erreur 500 à l'utilisateur.
 */
class KTimeUnavailableException extends RuntimeException {}
