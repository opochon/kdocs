<?php

declare(strict_types=1);

namespace Ged\Recherche;

/**
 * Erreur de base traduite pour l'écran : une étape, une cause lisible, une référence transmissible.
 * Jamais un message technique brut, jamais une liste vide muette.
 */
final readonly class ErreurSql
{
    public function __construct(
        public string $etape,
        public string $cause,
        public string $reference,
    ) {
    }

    public static function nommer(\PDOException $erreur, string $etape): self
    {
        $reference = substr(hash('sha256', $etape . $erreur->getMessage() . microtime()), 0, 10);
        error_log(sprintf('[GEDv2 recherche %s] %s (%s:%d)', $reference, $erreur->getMessage(),
            $erreur->getFile(), $erreur->getLine()));

        return new self($etape, self::cause($erreur), $reference);
    }

    /** Traduit les SQLSTATE courants ; à défaut, un résumé borné du message du pilote. */
    private static function cause(\PDOException $erreur): string
    {
        $message = $erreur->getMessage();
        $connues = [
            '42S02' => 'Schéma de recherche absent ou indisponible : exécutez tools/installer.php.',
            '42S22' => 'colonne de recherche inconnue — schéma à réinstaller',
            '42000' => 'requête de recherche refusée par la base',
            '1045' => 'accès à la base refusé',
            '2002' => 'base injoignable',
            '2006' => 'connexion à la base perdue',
        ];
        foreach ($connues as $code => $cause) {
            if (str_contains($message, (string) $code)) {
                return $cause;
            }
        }

        return mb_substr(preg_replace('/\s+/', ' ', $message) ?: 'erreur de base', 0, 160);
    }
}
