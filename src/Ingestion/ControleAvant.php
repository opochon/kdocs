<?php

declare(strict_types=1);

namespace Ged\Ingestion;

/**
 * Contrôles AVANT consommation d'un fichier : existence, doublon, dossiers, numéro d'archive.
 *
 * Équivalent GEDv2 de `ConsumerPreflightPlugin` : la passe qui refuse tôt un fichier qui ne peut pas
 * entrer (fichier disparu, doublon déjà indexé, numéro d'archive hors bornes ou déjà pris) et qui
 * s'assure que les dossiers de travail existent. GEDv2 ne « consomme » pas un fichier par Celery : la
 * même passe vit dans `Ingestion\Pilote` ; ce service en isole les règles déterministes pour qu'elles
 * soient testables sans base, et `executer()` les enchaîne dans l'ordre de Paperless.
 *
 * Aucun échec n'est silencieux : chaque refus lève une `ErreurConsommation` qui nomme le fichier et la
 * cause, avec le statut machine correspondant (`MessageStatut`).
 */
final class ControleAvant extends EtapesConsommation
{
    /** Bornes du numéro d'archive : uint32_t, comme l'index de Paperless. */
    public const ASN_MIN = 0;
    public const ASN_MAX = 4294967295;

    /** Le fichier doit encore être là où on l'attend. */
    public function verifierFichier(string $chemin): void
    {
        if (!is_file($chemin)) {
            $this->echouer(MessageStatut::FILE_NOT_FOUND, 'fichier introuvable : ' . $chemin);
        }
    }

    /**
     * Un même contenu (empreinte) déjà ingéré n'entre pas deux fois ; s'il est dans la corbeille, le
     * statut le dit, pour ne pas confondre un doublon vivant et un doublon à restaurer.
     */
    public function verifierDoublon(
        ?string $empreinte,
        ?string $empreinteExistante,
        string $titreExistant,
        bool $existantDansCorbeille,
    ): void {
        if ($empreinte === null || $empreinte === '' || $empreinteExistante === null) {
            return;
        }
        $statut = $existantDansCorbeille
            ? MessageStatut::DOCUMENT_ALREADY_EXISTS_IN_TRASH
            : MessageStatut::DOCUMENT_ALREADY_EXISTS;
        $this->echouer(
            $statut,
            'doublon de « ' . $titreExistant . ' »' . ($existantDansCorbeille ? ' (document dans la corbeille)' : ''),
        );
    }

    /**
     * Le numéro d'archive doit tenir dans les bornes et être libre. Un numéro déjà pris est refusé ;
     * s'il appartient à un document de la corbeille, le statut le nomme.
     */
    public function verifierAsn(?int $asn, ?int $asnExistant = null, bool $existantDansCorbeille = false): void
    {
        if ($asn === null) {
            return;
        }
        if ($asn < self::ASN_MIN || $asn > self::ASN_MAX) {
            $this->echouer(
                MessageStatut::ASN_RANGE,
                sprintf('numéro d’archive %d hors bornes [%d, %d]', $asn, self::ASN_MIN, self::ASN_MAX),
            );
        }
        if ($asnExistant !== null) {
            $statut = $existantDansCorbeille
                ? MessageStatut::ASN_ALREADY_EXISTS_IN_TRASH
                : MessageStatut::ASN_ALREADY_EXISTS;
            $this->echouer(
                $statut,
                sprintf('numéro d’archive %d déjà pris', $asn)
                    . ($existantDansCorbeille ? ' (document dans la corbeille)' : ''),
            );
        }
    }

    /**
     * Crée les dossiers de travail manquants ; rend la liste de ceux qui ont été créés. Un dossier
     * impossible à créer est une erreur nommée, jamais un échec plus loin.
     *
     * @param list<string> $dossiers
     * @return list<string>
     */
    public function verifierDossiers(array $dossiers): array
    {
        $crees = [];
        foreach ($dossiers as $dossier) {
            if (is_dir($dossier)) {
                continue;
            }
            if (!mkdir($dossier, 0775, true) && !is_dir($dossier)) {
                $this->echouer(MessageStatut::FAILED, 'dossier impossible à créer : ' . $dossier);
            }
            $crees[] = $dossier;
        }

        return $crees;
    }

    /**
     * Enchaîne les contrôles dans l'ordre de Paperless : fichier, doublon, dossiers, numéro d'archive.
     * Rend les événements de progression (début, puis fin) ; lève à la première cause de refus.
     *
     * @param array{fichier:string, empreinte?:?string, empreinte_existante?:?string,
     *              titre_existant?:string, dans_corbeille?:bool, asn?:?int, asn_existant?:?int,
     *              asn_dans_corbeille?:bool, dossiers?:list<string>} $contexte
     * @return list<array{statut:string, message:string, courant:int, total:int}>
     */
    public function executer(array $contexte): array
    {
        $evenements = [$this->progression(0, 100, MessageStatut::NEW_FILE)];
        $this->verifierFichier((string) ($contexte['fichier'] ?? ''));
        $this->verifierDoublon(
            $contexte['empreinte'] ?? null,
            $contexte['empreinte_existante'] ?? null,
            (string) ($contexte['titre_existant'] ?? ''),
            (bool) ($contexte['dans_corbeille'] ?? false),
        );
        $this->verifierDossiers((array) ($contexte['dossiers'] ?? []));
        $this->verifierAsn(
            $contexte['asn'] ?? null,
            $contexte['asn_existant'] ?? null,
            (bool) ($contexte['asn_dans_corbeille'] ?? false),
        );
        $evenements[] = $this->progression(100, 100, MessageStatut::FINISHED);

        return $evenements;
    }
}
