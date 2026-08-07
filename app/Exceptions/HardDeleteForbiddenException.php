<?php

namespace KDocs\Exceptions;

/**
 * Invariant de conception de K-Docs : AUCUNE ligne n'est jamais supprimee d'une
 * table par le produit. La suppression se fait par marquage (deleted_at), jamais
 * par DELETE. Toute tentative de suppression dure leve cette exception.
 *
 * Pourquoi cette regle prime sur le confort de purge :
 *  - une donnee effacable ne peut pas servir de preuve. Pour une GED fiduciaire
 *    suisse (GeBuV / Olico, retention 10 ans, piste de revision), perdre la chaine
 *    de tracabilite est pire que garder des lignes inutiles ;
 *  - la base est indexee : filtrer les lignes marquees supprimees ne coute rien
 *    en performance. L'argument de la purge « pour alleger » ne tient pas ;
 *  - ce qui disparait sans trace ne peut pas etre audite, donc ne peut pas etre
 *    defendu.
 *
 * Exception unique et hors application : la reconstruction de base pour les tests.
 * Elle ne passe JAMAIS par le produit — c'est un outil externe, et il doit etre
 * precede d'un dump. Voir tools/backup-db.mjs et governance/budgets.json.
 *
 * @see \Tests\Feature\NoHardDeleteTest  le cliquet qui empeche la regression
 */
class HardDeleteForbiddenException extends KDocsException
{
    protected int $httpStatusCode = 403;

    public function __construct(
        string $message = "Suppression definitive interdite : K-Docs ne supprime jamais de ligne.",
        int $code = 0,
        ?\Throwable $previous = null,
        array $context = []
    ) {
        parent::__construct($message, $code, $previous, $context);
    }

    /**
     * Tentative de suppression dure d'un enregistrement identifie.
     */
    public static function forRecord(string $table, int $id): self
    {
        return new self(
            "Suppression definitive refusee sur {$table}#{$id} : "
            . "K-Docs ne supprime jamais de ligne. Utiliser le marquage (deleted_at).",
            0,
            null,
            ['table' => $table, 'id' => $id]
        );
    }

    /**
     * Tentative de purge en masse (vidage de corbeille, nettoyage planifie).
     */
    public static function forPurge(string $origin): self
    {
        return new self(
            "Purge refusee ({$origin}) : K-Docs ne supprime jamais de ligne. "
            . "Les documents restent en corbeille indefiniment, marques par deleted_at.",
            0,
            null,
            ['origin' => $origin]
        );
    }
}
