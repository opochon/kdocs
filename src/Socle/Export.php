<?php

declare(strict_types=1);

namespace Ged\Socle;

use PDO;

/**
 * Export canonique de l'index : une structure déterministe (mêmes clés, même ordre), sérialisée et
 * hachée toujours de la même façon. C'est la mesure de la porte F12 : la base reconstruite depuis le
 * dossier doit rendre le MÊME hash. N'y entrent ni identifiants ni horodatages (ils dépendent du
 * moment, pas du fonds) ni les lignes sans fichier (absentes) : l'export décrit ce que le dossier montre.
 */
final class Export
{
    /** @return array{documents:list<array<string,mixed>>, versions:list<array<string,mixed>>} */
    public static function generer(PDO $pdo): array
    {
        $documents = [];
        foreach ($pdo->query('SELECT chemin_relatif, nom, titre, type, emetteur, date_document, montant, etiquettes,'
            . ' source, statut, empreinte, taille, mime, meta, supprime_le FROM documents WHERE absent_depuis IS NULL'
            . ' ORDER BY chemin_relatif')->fetchAll() as $l) {
            $documents[] = [
                'chemin' => (string) $l['chemin_relatif'],
                'nom' => (string) $l['nom'],
                'titre' => (string) $l['titre'],
                'type' => $l['type'],
                'emetteur' => $l['emetteur'],
                'date' => $l['date_document'],
                'montant' => $l['montant'],
                'etiquettes' => json_decode((string) ($l['etiquettes'] ?? 'null'), true),
                'source' => $l['source'],
                'statut' => (string) $l['statut'],
                'empreinte' => (string) $l['empreinte'],
                'taille' => (int) $l['taille'],
                'mime' => (string) $l['mime'],
                'meta' => json_decode((string) $l['meta'], true),
                'corbeille' => $l['supprime_le'] !== null,
            ];
        }

        $versions = [];
        foreach ($pdo->query('SELECT d.chemin_relatif, v.numero, v.nom_archive, v.empreinte, v.taille'
            . ' FROM document_versions v JOIN documents d ON d.id = v.document_id'
            . ' ORDER BY d.chemin_relatif, v.numero')->fetchAll() as $v) {
            $versions[] = [
                'chemin' => (string) $v['chemin_relatif'],
                'numero' => (int) $v['numero'],
                'archive' => (string) $v['nom_archive'],
                'empreinte' => (string) $v['empreinte'],
                'taille' => (int) $v['taille'],
            ];
        }

        return ['documents' => $documents, 'versions' => $versions];
    }

    /** sha256 de l'export canonique : la valeur que deux index identiques partagent, et eux seuls. */
    public static function hacher(PDO $pdo): string
    {
        return hash('sha256', Fichiers::json(self::generer($pdo)));
    }
}
