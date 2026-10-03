<?php

declare(strict_types=1);

namespace Ged\Securite;

use PDO;

/** Résolution des droits de dossier : groupe local, parent, puis refus explicite par défaut. */
final class DroitsDossiers
{
    /** @var list<string> */
    private const NIVEAUX = ['lire', 'deposer', 'modifier', 'gerer'];

    private const PERMISSIONS_ACTIONS = [
        'lire' => 'documents:lire', 'deposer' => 'documents:deposer', 'modifier' => 'documents:modifier',
        'gerer' => 'dossiers:gerer',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function creerGroupe(string $nom, string $role): int
    {
        $role = $this->roleCanonique($role);
        $this->exigerRole($role);
        $this->pdo->prepare('INSERT INTO securite_groupes (nom, role_code) VALUES (?, ?)')->execute([$nom, $role]);

        return (int) $this->pdo->lastInsertId();
    }

    public function ajouterCompteAuGroupe(int $compte, int $groupe): void
    {
        $this->pdo->prepare('INSERT IGNORE INTO securite_groupes_comptes (groupe_id, compte_id) VALUES (?, ?)')
            ->execute([$groupe, $compte]);
    }

    public function poserDossier(int $id, ?int $parent, string $chemin): void
    {
        $chemin = trim(str_replace('\\', '/', $chemin), '/');
        if ($id < 1 || $chemin === '' || str_contains($chemin, '../')) {
            throw new \InvalidArgumentException('Dossier de sécurité invalide.');
        }
        $this->pdo->prepare('INSERT INTO securite_dossiers (id, parent_id, chemin_relatif) VALUES (?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE parent_id = VALUES(parent_id), chemin_relatif = VALUES(chemin_relatif)')
            ->execute([$id, $parent, $chemin]);
    }

    public function poserRegle(int $dossier, int $groupe, string $action, bool $autorise): void
    {
        $action = $this->actionCanonique($action);
        $this->permissionAction($action);
        $this->pdo->prepare('INSERT INTO securite_dossiers_droits (dossier_id, groupe_id, action_code, autorise) VALUES (?, ?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE autorise = VALUES(autorise)')
            ->execute([$dossier, $groupe, $action, (int) $autorise]);
    }

    /** @return list<string> */
    public function permissionsPourCompte(int $compte): array
    {
        $sql = 'SELECT DISTINCT rp.permission_code FROM securite_roles_permissions rp WHERE rp.role_code IN ('
            . "SELECT CASE role WHEN 'admin' THEN 'administrateur' WHEN 'lecteur' THEN 'utilisateur' "
            . "WHEN 'contributeur' THEN 'manager' WHEN 'archiveur' THEN 'manager' ELSE role END FROM comptes WHERE id = ? "
            . 'UNION SELECT g.role_code FROM securite_groupes g '
            . 'INNER JOIN securite_groupes_comptes gc ON gc.groupe_id = g.id WHERE gc.compte_id = ?)';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$compte, $compte]);

        return array_values(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
    }

    public function autorise(int $compte, int $dossier, string $action): bool
    {
        $action = $this->actionCanonique($action);
        $permission = $this->permissionAction($action);
        $permissions = $this->permissionsPourCompte($compte);
        if (in_array('*', $permissions, true)) {
            return true;
        }
        if (!in_array($permission, $permissions, true)) {
            return false;
        }

        $groupes = $this->groupesDuCompte($compte);
        if ($groupes === []) {
            return false;
        }
        foreach (array_slice(self::NIVEAUX, 0, array_search($action, self::NIVEAUX, true) + 1) as $niveau) {
            if ($this->decisionHeritee($dossier, $groupes, $niveau) !== true) {
                return false;
            }
        }

        return true;
    }

    /** Retourne le dossier ACL le plus précis couvrant le chemin d’un document, ou null. */
    public function dossierPourChemin(string $chemin): ?int
    {
        $chemin = trim(str_replace('\\', '/', $chemin), '/');
        $stmt = $this->pdo->prepare('SELECT id FROM securite_dossiers WHERE ? = chemin_relatif OR ? LIKE CONCAT(chemin_relatif, \'/%\') '
            . 'ORDER BY CHAR_LENGTH(chemin_relatif) DESC LIMIT 1');
        $stmt->execute([$chemin, $chemin]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /** Le document indexé est rattaché au dossier ACL par son chemin, jamais par une donnée du navigateur. */
    public function dossierPourDocument(int $document): ?int
    {
        $stmt = $this->pdo->prepare('SELECT chemin_relatif FROM documents WHERE id = ?');
        $stmt->execute([$document]);
        $chemin = $stmt->fetchColumn();

        return $chemin === false ? null : $this->dossierPourChemin((string) $chemin);
    }

    /**
     * Garantit le dossier de droits d'un document à partir de son chemin dans le fonds, et rend
     * l'identifiant du dossier le plus précis — ou null si le chemin n'a pas de dossier (racine).
     *
     * Chaque niveau du chemin devient un dossier ACL, enfant du précédent : l'héritage du parent
     * décide donc du droit le plus proche, comme pour les dossiers posés à la main. Idempotent :
     * un dossier déjà présent n'est jamais dupliqué ; le parent d'un dossier existant n'est pas
     * écrasé (un administrateur peut avoir posé une règle avant l'arrivée des documents).
     */
    public function assurerDossierPourChemin(string $chemin): ?int
    {
        $chemin = trim(str_replace('\\', '/', $chemin), '/');
        if ($chemin === '' || str_contains($chemin, '..')) {
            return null;
        }
        $segments = explode('/', $chemin);
        array_pop($segments);   // le nom du fichier n'est pas un dossier
        $parent = null;
        $prefixe = '';
        foreach ($segments as $segment) {
            $segment = trim($segment);
            if ($segment === '' || $segment === '.') {
                continue;
            }
            $prefixe = $prefixe === '' ? $segment : "$prefixe/$segment";
            $parent = $this->assurerDossier($parent, $prefixe);
        }

        return $parent;
    }

    /** Crée le dossier ACL s'il manque (parent posé) puis rend son identifiant, sans jamais le dupliquer. */
    private function assurerDossier(?int $parent, string $chemin): int
    {
        $this->pdo->prepare('INSERT INTO securite_dossiers (parent_id, chemin_relatif) VALUES (?, ?)'
            . ' ON DUPLICATE KEY UPDATE id = id')->execute([$parent, $chemin]);
        $stmt = $this->pdo->prepare('SELECT id FROM securite_dossiers WHERE chemin_relatif = ? LIMIT 1');
        $stmt->execute([$chemin]);

        return (int) $stmt->fetchColumn();
    }

    /** @return list<int> */
    private function groupesDuCompte(int $compte): array
    {
        $stmt = $this->pdo->prepare('SELECT groupe_id FROM securite_groupes_comptes WHERE compte_id = ?');
        $stmt->execute([$compte]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @param list<int> $groupes null signifie héritage ; une permission haute inclut les niveaux précédents. */
    private function decisionHeritee(int $dossier, array $groupes, string $action): ?bool
    {
        $vus = [];
        $courant = $dossier;
        while ($courant > 0 && !isset($vus[$courant])) {
            $vus[$courant] = true;
            $regle = $this->regleDuDossier($courant, $groupes, $action);
            if ($regle !== null) {
                return $regle;
            }
            $parent = $this->parentDuDossier($courant);
            if ($parent === null) {
                break;
            }
            $courant = $parent;
        }

        return null;
    }

    /** null signifie héritage ; un refus explicite du niveau demandé gagne au dossier courant. */
    private function regleDuDossier(int $dossier, array $groupes, string $action): ?bool
    {
        $marqueurs = implode(',', array_fill(0, count($groupes), '?'));
        $stmt = $this->pdo->prepare('SELECT action_code, autorise FROM securite_dossiers_droits WHERE dossier_id = ? '
            . "AND groupe_id IN ($marqueurs)");
        $stmt->execute([$dossier, ...$groupes]);
        $regles = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($regles as $regle) {
            if ((string) $regle['action_code'] === $action && (int) $regle['autorise'] === 0) {
                return false;
            }
        }
        foreach ($regles as $regle) {
            $niveau = array_search((string) $regle['action_code'], self::NIVEAUX, true);
            if ($niveau !== false && $niveau >= array_search($action, self::NIVEAUX, true) && (int) $regle['autorise'] === 1) {
                return true;
            }
        }

        return null;
    }

    private function actionCanonique(string $action): string
    {
        return $action === 'archiver' ? 'gerer' : $action;
    }

    private function parentDuDossier(int $dossier): ?int
    {
        $stmt = $this->pdo->prepare('SELECT parent_id FROM securite_dossiers WHERE id = ?');
        $stmt->execute([$dossier]);
        $parent = $stmt->fetchColumn();

        return $parent === false || $parent === null ? null : (int) $parent;
    }

    private function permissionAction(string $action): string
    {
        return self::PERMISSIONS_ACTIONS[$action] ?? throw new \InvalidArgumentException('Action de droit inconnue.');
    }

    private function roleCanonique(string $role): string
    {
        return match ($role) {
            'admin' => 'administrateur', 'lecteur' => 'utilisateur', 'contributeur', 'archiveur' => 'manager',
            default => $role,
        };
    }

    private function exigerRole(string $role): void
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM securite_roles WHERE code = ?');
        $stmt->execute([$role]);
        if ($stmt->fetchColumn() === false) {
            throw new \InvalidArgumentException('Rôle de sécurité inconnu.');
        }
    }
}
