<?php

declare(strict_types=1);

namespace Ged\EspacePartage;

use Ged\Base;
use Ged\Config;
use Ged\Securite\DroitsDossiers;
use Ged\Securite\JournalAudit;
use Ged\Socle\Collaboration;
use PDO;
use PDOException;
use RuntimeException;

/**
 * Liens de partage de fichier (lot ged-mfiles-espace-1) : lister, créer, ouvrir (télécharger via le lien) et
 * révoquer. La création et la validation sont portées par `Ged\Socle\Collaboration` (droits, expiration,
 * condensat) : ce service ne les réimplémente pas, il les COMPOSE et ajoute la lecture d'ensemble et la
 * révocation par la clé publique.
 *
 * Deux garanties :
 * - aucun secret en clair : seul le SHA-256 du jeton est conservé (base et journal) ; le jeton n'est rendu
 *   qu'à la création ;
 * - une liste ne révèle jamais un document qu'un compte ne peut pas lire (filtre ACL dans la requête).
 */
final class Partages
{
    private readonly PDO $pdo;
    private readonly Collaboration $collaboration;
    private readonly JournalAudit $audit;

    public function __construct(Config $config)
    {
        $this->pdo = Base::pdo($config);
        $this->collaboration = new Collaboration($config);
        $this->audit = new JournalAudit($config->chemin('SECURITE_DOSSIER', 'var/securite') . '/audit.ndjson');
    }

    /**
     * Crée un lien de partage externe (modele Paperless `ShareLink`) : délègue au service de collaboration
     * qui vérifie que le document existe et fixe l'expiration.
     *
     * @return array{id:int, jeton:string} le jeton ne revient qu'à sa création.
     */
    public function creer(int $document, string $auteur, string $expiration, ?int $version = null): array
    {
        return $this->collaboration->partagerExterne($document, $auteur, $expiration, $version);
    }

    /**
     * Résout un lien par son jeton public ; rend la pièce visée, ou null si le lien est inconnu, expiré,
     * révoqué. Aucune donnée n'est révélée pour un jeton invalide.
     *
     * @return array{document:int, partage:int, version:?int}|null
     */
    public function ouvrir(string $jeton): ?array
    {
        return $this->collaboration->lienExterneValide($jeton);
    }

    /**
     * Les liens de partage externes actifs (non révoqués), du plus récent au plus ancien. Avec un compte,
     * la requête ne retient que les documents que ce compte peut lire : une liste ne fuit jamais.
     *
     * @return list<array{id:int, document:int, titre:string, auteur:string, cree_le:string, expire_le:?string, version:?int}>
     */
    public function liens(?int $compte = null): array
    {
        $sql = "SELECT p.id, p.document_id document, d.titre, p.auteur, p.cree_le, p.expire_le, p.version\n"
            . "FROM document_collaboration p\n"
            . "INNER JOIN documents d ON d.id = p.document_id\n"
            . "WHERE p.type = 'partage' AND p.type_partage = 'externe' AND p.revoque_le IS NULL\n"
            . '  AND d.supprime_le IS NULL AND d.absent_depuis IS NULL';
        $parametres = [];
        if ($compte !== null) {
            [$predicat, $valeurs] = DroitsDossiers::predicatLecture('d', $compte);
            $sql .= ' AND ' . $predicat;
            $parametres = $valeurs;
        }
        $sql .= ' ORDER BY p.cree_le DESC, p.id DESC';
        $requete = $this->pdo->prepare($sql);
        $requete->execute($parametres);

        $liens = [];
        foreach ($requete->fetchAll(PDO::FETCH_ASSOC) as $ligne) {
            $liens[] = [
                'id' => (int) $ligne['id'],
                'document' => (int) $ligne['document'],
                'titre' => (string) $ligne['titre'],
                'auteur' => (string) $ligne['auteur'],
                'cree_le' => (string) $ligne['cree_le'],
                'expire_le' => $ligne['expire_le'] === null ? null : (string) $ligne['expire_le'],
                'version' => $ligne['version'] === null ? null : (int) $ligne['version'],
            ];
        }

        return $liens;
    }

    /**
     * Révoque un lien par sa clé publique (le jeton) : l'empreinte est recalculée, le jeton n'est jamais
     * stocké en clair. Un lien introuvable ou déjà révoqué se refuse en se nommant.
     */
    public function revoquerParCle(string $jeton, string $auteur): void
    {
        if (preg_match('/^[A-Za-z0-9_-]{40,128}$/', $jeton) !== 1) {
            throw new RuntimeException('lien de partage : clé invalide (40 à 128 caractères)');
        }
        $empreinte = hash('sha256', $jeton);
        $requete = $this->pdo->prepare("UPDATE document_collaboration SET revoque_le = NOW()"
            . " WHERE type = 'partage' AND type_partage = 'externe' AND jeton_empreinte = ? AND revoque_le IS NULL");
        $requete->execute([$empreinte]);
        if ($requete->rowCount() !== 1) {
            throw new RuntimeException('lien de partage : introuvable ou déjà révoqué');
        }
        $this->audit->ajouter('partage_externe_revoque_cle', $auteur, ['empreinte' => substr($empreinte, 0, 12)]);
    }

    /**
     * Révoque un lien par son identifiant interne, après avoir vérifié que le compte lit le document visé :
     * un compte qui n'a pas le droit ne peut pas révoquer le lien d'autrui en devinant son identifiant
     * (fail-closed si le schéma de droits est indisponible).
     */
    public function revoquer(int $partage, ?int $compte, string $auteur): void
    {
        if ($compte !== null) {
            $requete = $this->pdo->prepare("SELECT document_id FROM document_collaboration"
                . " WHERE id = ? AND type = 'partage' AND type_partage = 'externe' AND revoque_le IS NULL");
            $requete->execute([$partage]);
            $document = (int) $requete->fetchColumn();
            try {
                $droits = new DroitsDossiers($this->pdo);
                $dossier = $document > 0 ? $droits->dossierPourDocument($document) : null;
                $autorise = $dossier !== null && $droits->autorise($compte, $dossier, 'lire');
            } catch (PDOException $e) {
                error_log('[GEDv2 espace-partage] droits indisponibles à la révocation du lien ' . $partage . ' : ' . $e->getMessage());
                $autorise = false;
            }
            if (!$autorise) {
                throw new RuntimeException('lien de partage : introuvable ou hors de vos droits');
            }
        }
        $this->collaboration->revoquerPartageExterne($partage, $auteur);
    }
}
