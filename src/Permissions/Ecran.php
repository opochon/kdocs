<?php

declare(strict_types=1);

namespace Ged\Permissions;

use Ged\Base;
use Ged\Config;
use Ged\Socle\Nomenclature;
use PDO;
use PDOException;
use RuntimeException;

/**
 * Adaptateur de l'écran `/permissions` (famille M-Files « permissions dynamiques ») : il lit les règles
 * nommées du fonds (portables, `.ged/permissions.json`), la liste des groupes et des rôles actifs pour
 * la cible, et les classes de la nomenclature pour la condition. La création et la bascule passent par
 * `Ged\Permissions\Regles` — jamais une écriture directe, jamais une suppression.
 */
final class Ecran
{
    public function __construct(
        private readonly Config $config,
        private readonly ?PDO $pdo = null,
    ) {
    }

    public static function depuisRacine(?string $racine = null): self
    {
        $config = new Config($racine ?? dirname(__DIR__, 2));

        return new self($config, Base::pdo($config));
    }

    /**
     * @return array<string, mixed>
     */
    public function donnees(): array
    {
        $groupes = [];
        $roles = [];
        try {
            if ($this->pdo !== null) {
                $groupes = $this->pdo->query('SELECT id, nom, role_code FROM securite_groupes ORDER BY nom')->fetchAll();
                $roles = $this->pdo->query('SELECT code, libelle FROM securite_roles WHERE actif = 1 ORDER BY code')->fetchAll();
            }
        } catch (PDOException) {
            throw new RuntimeException('Schéma de sécurité absent ou indisponible : exécutez tools/installer.php.');
        }

        return [
            'ecran' => 'permissions',
            'regles' => (new Regles($this->config))->toutes(),
            'groupes' => $groupes,
            'roles' => $roles,
            'classes' => array_values(array_map(static fn (array $t): string => (string) $t['nom'], (new Nomenclature($this->config))->lister())),
        ];
    }

    /**
     * Crée une règle nommée depuis le formulaire de l'écran. Une incohérence est refusée nommément.
     *
     * @param array<string, mixed> $corps
     */
    public function creer(array $corps, string $auteur): int
    {
        $cibles = [];
        $groupe = trim((string) ($corps['groupe'] ?? ''));
        if ($groupe !== '' && ctype_digit($groupe)) {
            $cibles[] = ['type' => 'groupe', 'valeur' => $groupe];
        }
        $role = trim((string) ($corps['role'] ?? ''));
        if ($role !== '') {
            $cibles[] = ['type' => 'role', 'valeur' => $role];
        }

        $champ = trim((string) ($corps['champ'] ?? ''));
        $valeur = trim((string) ($corps['valeur'] ?? ''));

        return (new Regles($this->config))->enregistrer([
            'nom' => (string) ($corps['nom'] ?? ''),
            'conditions' => [
                'classe' => trim((string) ($corps['classe'] ?? '')),
                'champ' => $champ,
                'valeur' => $champ !== '' ? $valeur : null,
                'etat' => trim((string) ($corps['etat'] ?? '')),
            ],
            'voir' => (string) ($corps['voir'] ?? '') === '1',
            'modifier' => (string) ($corps['modifier'] ?? '') === '1',
            'cibles' => $cibles,
        ], $auteur);
    }

    /** Active ou désactive une règle existante (jamais de suppression). */
    public function basculer(int $id, bool $actif): void
    {
        if ($actif) {
            (new Regles($this->config))->activer($id);

            return;
        }
        (new Regles($this->config))->desactiver($id);
    }
}
