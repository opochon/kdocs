<?php

declare(strict_types=1);

namespace Ged\Edition;

use Ged\Base;
use Ged\Config;
use Ged\Socle\DepotFichiers;
use Ged\Socle\Fichiers;
use PDO;
use RuntimeException;

/**
 * Service d'édition depuis le poste (F13) : le fichier courant et sa version, le dépôt d'une nouvelle
 * version avec la version de base. Le Socle garde la main sur les fichiers (`.versions/`, garde
 * d'archivage) ; ce service ne fait que décider de l'IDEMPOTENCE et du CONFLIT.
 *
 * - contenu identique au contenu courant (reprise après coupure réseau) : la version courante est
 *   confirmée sans créer de doublon ;
 * - version de base différente de la dernière : le contenu est TOUJOURS conservé (nouvelle version) et
 *   le conflit est signalé (`conflit: true`) — jamais un écrasement.
 */
final class Editeur
{
    private readonly PDO $pdo;
    private readonly DepotFichiers $depot;

    public function __construct(private readonly Config $config)
    {
        $this->pdo = Base::pdo($config);
        $this->depot = new DepotFichiers($config);
    }

    /**
     * L'état d'édition d'un document vivant : nom, chemin, type, taille, empreinte et numéro de version.
     *
     * @return array{id:int, nom:string, chemin:string, mime:string, taille:int, empreinte:string, version:int}|null
     */
    public function document(int $document): ?array
    {
        $ligne = $this->depot->fiche($document);
        if ($ligne === null || $ligne['supprime_le'] !== null || $ligne['absent_depuis'] !== null) {
            return null;
        }

        return [
            'id' => (int) $ligne['id'],
            'nom' => (string) $ligne['nom'],
            'chemin' => (string) $ligne['chemin_relatif'],
            'mime' => (string) $ligne['mime'],
            'taille' => (int) $ligne['taille'],
            'empreinte' => (string) $ligne['empreinte'],
            'version' => $this->versionCourante($document),
        ];
    }

    /** Chemin absolu du fichier courant, ou null si le document n'est pas vivant. */
    public function chemin(int $document): ?string
    {
        $etat = $this->document($document);

        return $etat === null ? null : $this->depot->cheminAbsolu($etat['chemin']);
    }

    /** Numéro de la dernière version archivée (0 si aucune : ne devrait pas arriver, la v1 est au dépôt). */
    public function versionCourante(int $document): int
    {
        $requete = $this->pdo->prepare('SELECT COALESCE(MAX(numero), 0) FROM document_versions WHERE document_id = ?');
        $requete->execute([$document]);

        return (int) $requete->fetchColumn();
    }

    /** Empreinte du contenu courant, relue de la ligne d'index (miroir du fichier). */
    public function empreinte(int $document): ?string
    {
        $etat = $this->document($document);

        return $etat['empreinte'] ?? null;
    }

    /**
     * Dépose une nouvelle version produite par le poste.
     *
     * @return array{version:int, empreinte:string, conflit:bool, version_base:int, version_avant:int, deja:bool}
     * @throws RuntimeException document inconnu, fichier absent, ou contenu refusé par le Socle
     */
    public function deposerVersion(int $document, string $cheminFichier, string $auteur, int $versionBase): array
    {
        if ($versionBase < 1) {
            throw new RuntimeException('nouvelle version : version de base invalide');
        }
        if (!is_file($cheminFichier)) {
            throw new RuntimeException('nouvelle version : fichier reçu introuvable');
        }
        $etat = $this->document($document);
        if ($etat === null) {
            throw new RuntimeException("nouvelle version : document $document introuvable");
        }

        $versionAvant = $etat['version'];
        $empreinteAvant = $etat['empreinte'];
        $empreinteNouvelle = Fichiers::empreinte($cheminFichier);

        // Reprise après coupure : le serveur connaît déjà ce contenu, on confirme sans rien dupliquer.
        if (hash_equals($empreinteAvant, $empreinteNouvelle)) {
            return ['version' => $versionAvant, 'empreinte' => $empreinteNouvelle, 'conflit' => false,
                'version_base' => $versionBase, 'version_avant' => $versionAvant, 'deja' => true];
        }

        // Le Socle archive le contenu courant puis installe le nouveau : jamais un octet perdu.
        $numero = $this->depot->remplacerContenu($document, $cheminFichier, $auteur);
        $conflit = $versionBase !== $versionAvant;
        if ($conflit) {
            $this->tracerVersionParallele($document, $auteur, $versionBase, $versionAvant, $numero);
        }

        return ['version' => $numero, 'empreinte' => $empreinteNouvelle, 'conflit' => $conflit,
            'version_base' => $versionBase, 'version_avant' => $versionAvant, 'deja' => false];
    }

    /** Trace une version parallèle dans la collaboration : l'acte reste lisible dans l'historique. */
    private function tracerVersionParallele(int $document, string $auteur, int $versionBase, int $versionAvant, int $numero): void
    {
        $this->pdo->prepare("INSERT INTO document_collaboration (document_id, type, action, auteur, objet_id, texte)
            VALUES (?, 'evenement', 'version_parallele', ?, ?, ?)")
            ->execute([$document, mb_substr($auteur, 0, 128), $numero,
                sprintf('Version parallèle déposée depuis le poste : base v%d, dernière v%d, nouvelle v%d',
                    $versionBase, $versionAvant, $numero)]);
    }
}
