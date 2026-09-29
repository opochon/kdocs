<?php

declare(strict_types=1);

namespace Ged\Securite;

use PDO;

/** Résolution des droits de dossier : groupe local, parent, puis refus explicite par défaut. */
final class DroitsDossiers
{
    /** @var array<string, string> */
    private const PERMISSIONS_ACTIONS = [
        'lire' => 'documents:lire', 'modifier' => 'documents:modifier', 'archiver' => 'documents:archiver',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function creerGroupe(string $nom, string $role): int
    {
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
        $this->permissionAction($action);
        $this->pdo->prepare('INSERT INTO securite_dossiers_droits (dossier_id, groupe_id, action_code, autorise) VALUES (?, ?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE autorise = VALUES(autorise)')
            ->execute([$dossier, $groupe, $action, (int) $autorise]);
    }

    /** @return list<string> */
    public function permissionsPourCompte(int $compte): array
    {
        $sql = 'SELECT DISTINCT rp.permission_code FROM securite_roles_permissions rp WHERE rp.role_code IN ('
            . 'SELECT role FROM comptes WHERE id = ? UNION SELECT g.role_code FROM securite_groupes g '
            . 'INNER JOIN securite_groupes_comptes gc ON gc.groupe_id = g.id WHERE gc.compte_id = ?)';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$compte, $compte]);

        return array_values(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
    }

    public function autorise(int $compte, int $dossier, string $action): bool
    {
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

        return false;
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

    /** @return list<int> */
    private function groupesDuCompte(int $compte): array
    {
        $stmt = $this->pdo->prepare('SELECT groupe_id FROM securite_groupes_comptes WHERE compte_id = ?');
        $stmt->execute([$compte]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** null signifie héritage ; un refus de groupe l’emporte toujours sur un accord concurrent. */
    private function regleDuDossier(int $dossier, array $groupes, string $action): ?bool
    {
        $marqueurs = implode(',', array_fill(0, count($groupes), '?'));
        $stmt = $this->pdo->prepare('SELECT autorise FROM securite_dossiers_droits WHERE dossier_id = ? AND action_code = ? '
            . "AND groupe_id IN ($marqueurs)");
        $stmt->execute([$dossier, $action, ...$groupes]);
        $regles = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if ($regles === []) {
            return null;
        }

        return !in_array('0', array_map('strval', $regles), true);
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

    private function exigerRole(string $role): void
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM securite_roles WHERE code = ?');
        $stmt->execute([$role]);
        if ($stmt->fetchColumn() === false) {
            throw new \InvalidArgumentException('Rôle de sécurité inconnu.');
        }
    }
}
