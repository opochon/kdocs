<?php

declare(strict_types=1);

namespace Ged\Contrats;

/**
 * Contrat entre les secteurs Ingestion / Classement (qui produisent des documents) et Socle (qui les range).
 * Implémenté par le secteur Socle. Modifiable seulement par l'agent de vue d'ensemble, après accord des deux côtés.
 */
interface Depot
{
    /**
     * Range un fichier dans le fonds : copie sous la racine documentaire (chemin année/type/émetteur si le
     * type est connu, sinon « À classer »), écrit `<fichier>.meta.json` à côté, crée la version 1, indexe.
     * Le fichier source n'est jamais supprimé par cet appel (l'appelant décide de son sort).
     *
     * @param array<string, mixed> $metadonnees titre, type, emetteur, date (Y-m-d), montant, etiquettes[],
     *                                          source (dossier|adresse|ecran|mobile), origine (lot + pages)…
     * @return int identifiant du document dans l'index
     */
    public function deposer(string $cheminFichier, array $metadonnees): int;

    /**
     * Met à jour les métadonnées (et le rangement si type/émetteur/date/dossier changent) : `.meta.json`
     * réécrit, fichier déplacé si besoin, déplacement tracé. Jamais de suppression.
     *
     * F10 : tout changement de RANGEMENT exige le niveau `deposer` du compte de l'appelant sur le dossier
     * de droits d'ARRIVÉE (héritage compris, refus par défaut sans règle ; administrateur toujours
     * permis). Le refus est nommé (dossier visé, niveau manquant) et, sur refus, rien ne bouge. Le compte
     * est passé EXPLICITEMENT par l'appelant ; `null` reste le compte synthétique des oracles, sans ACL.
     *
     * @param array<string, mixed> $metadonnees
     */
    public function mettreAJour(int $idDocument, array $metadonnees, string $auteur, ?int $compte = null, bool $administrateur = false): void;

    /** @return array<string, mixed>|null Ligne d'index et métadonnées décodées. */
    public function fiche(int $idDocument): ?array;

    /** @return list<array<string, mixed>> Documents vivants de l'index. */
    public function lister(): array;

    /** Résout un chemin relatif du fonds, en refusant toute sortie de racine. */
    public function cheminAbsolu(string $cheminRelatif): string;
}
