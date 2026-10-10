<?php

declare(strict_types=1);

namespace Ged\Archivage;

/**
 * LA GARDE DU SERVICE : c'est ici, et pas route par route, que toute écriture sur un document scellé est
 * refusée. Le Socle l'appelle depuis `DepotFichiers::mettreAJour()` et `miseALaCorbeille()` ; l'indexeur
 * l'appelle pour constater une altération hors application. Aucun identifiant de base n'est nécessaire :
 * le scellement vit dans le dossier, à côté du document.
 *
 * Méthodes statiques volontairement : l'appel depuis un autre secteur tient en une ligne clairement signalée.
 */
final class Garde
{
    /**
     * Le scellement actif d'un document, ou null (non scellé, ou scellement levé par un humain).
     *
     * @return array<string, mixed>|null
     */
    public static function estScelle(string $absoluDocument, ?int $idDocument = null): ?array
    {
        $scellement = Scellement::lire($absoluDocument, $idDocument);

        return Scellement::estActif($scellement) ? $scellement : null;
    }

    /**
     * Refuse une mutation sur un document scellé : l'erreur nomme l'opération, le document et l'échéance.
     *
     * @throws ErreurScellement
     */
    public static function interdireMutation(string $absoluDocument, string $operation, ?int $idDocument = null): void
    {
        $scellement = self::estScelle($absoluDocument, $idDocument);
        if ($scellement === null) {
            return;
        }
        $chemin = (string) ($scellement['chemin_relatif'] ?? basename($absoluDocument));
        throw ErreurScellement::mutation($operation, $chemin, (string) ($scellement['echeance'] ?? ''));
    }

    /**
     * L'indexeur a vu le contenu d'un document scellé changé sur le disque : jamais de nouvelle version
     * silencieuse, une alerte « scellé altéré » est écrite dans le dossier. Rend l'alerte, ou null si le
     * document n'est pas scellé / n'a pas changé.
     *
     * @return array<string, mixed>|null
     */
    public static function constaterAlteration(string $absoluDocument, string $empreinteObservee, ?int $idDocument = null, ?Horloge $horloge = null): ?array
    {
        $scellement = self::estScelle($absoluDocument, $idDocument);
        if ($scellement === null || (string) ($scellement['empreinte_fichier'] ?? '') === $empreinteObservee) {
            return null;
        }

        $horloge ??= new HorlogeSysteme();
        $alerte = [
            'horodatage' => $horloge->maintenant()->format(\DateTimeInterface::ATOM),
            'document_id' => (int) ($scellement['document_id'] ?? 0),
            'chemin_relatif' => (string) ($scellement['chemin_relatif'] ?? basename($absoluDocument)),
            'cause' => 'scellé altéré',
            'empreinte_scellee' => (string) ($scellement['empreinte_scellee'] ?? ''),
            'empreinte_observee' => $empreinteObservee,
        ];
        self::journaliserAlerte($absoluDocument, $alerte);

        return $alerte;
    }

    /**
     * Ajoute l'alerte au journal du dossier (une seule fois tant que le même contenu altéré est observé).
     *
     * @param array<string, mixed> $alerte
     */
    private static function journaliserAlerte(string $absoluDocument, array $alerte): void
    {
        $dossier = Scellement::dossier($absoluDocument);
        if (!is_dir($dossier) && !mkdir($dossier, 0775, true) && !is_dir($dossier)) {
            return;
        }
        $fichier = $dossier . '/alertes.ndjson';
        if (is_file($fichier)) {
            $lignes = file($fichier, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            $derniere = Scellement::decoder((string) end($lignes));
            if ($derniere !== null && ($derniere['empreinte_observee'] ?? null) === $alerte['empreinte_observee']
                && ($derniere['document_id'] ?? null) === $alerte['document_id']) {
                return;
            }
        }
        file_put_contents($fichier, json_encode($alerte, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
