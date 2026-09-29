<?php

declare(strict_types=1);

namespace Ged\Securite;

use RuntimeException;

/** Sessions opaques stockées côté serveur : seul un identifiant aléatoire passe dans le cookie. */
final class RegistreSessions
{
    public function __construct(
        private readonly string $dossier,
        private readonly int $dureeInactivite = 1800,
        private readonly int $dureeMaximale = 28800,
    ) {
        if ($this->dureeInactivite < 1 || $this->dureeMaximale < $this->dureeInactivite) {
            throw new RuntimeException('Durées de session invalides.');
        }
        if (!is_dir($this->dossier) && !mkdir($this->dossier, 0700, true) && !is_dir($this->dossier)) {
            throw new RuntimeException('Impossible de créer le registre de sessions.');
        }
    }

    /** @param list<string> $permissions @return array{id:string,csrf:string} */
    public function ouvrir(string $utilisateur, array $permissions, ?int $maintenant = null, ?int $compte = null): array
    {
        $maintenant ??= time();
        $id = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $csrf = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->ecrire($id, [
            'utilisateur' => $utilisateur,
            'compte' => $compte,
            'permissions' => array_values($permissions),
            'csrf' => $csrf,
            'cree_le' => $maintenant,
            'expire_le' => min($maintenant + $this->dureeInactivite, $maintenant + $this->dureeMaximale),
        ]);

        return ['id' => $id, 'csrf' => $csrf];
    }

    /** @return array{utilisateur:string,compte:int|null,permissions:list<string>,csrf:string,cree_le:int,expire_le:int}|null */
    public function lire(string $id, ?int $maintenant = null): ?array
    {
        $maintenant ??= time();
        $session = $this->charger($id);
        if ($session === null || $session['expire_le'] < $maintenant) {
            return null;
        }

        // Chaque requête valide repousse l'échéance sans dépasser la durée totale autorisée.
        $session['expire_le'] = min($maintenant + $this->dureeInactivite, $session['cree_le'] + $this->dureeMaximale);
        $this->ecrire($id, $session);

        return $session;
    }

    public function fermer(string $id): void
    {
        $fichier = $this->fichier($id);
        if (is_file($fichier)) {
            unlink($fichier);
        }
    }

    /**
     * Consomme le CSRF d'une session et le remplace sous verrou.
     * Chaque mutation exige ainsi un jeton inédit : une requête interceptée ne peut pas être rejouée.
     */
    public function consommerCsrf(string $id, string $jeton, ?int $maintenant = null): ?string
    {
        if (!preg_match('/^[A-Za-z0-9_-]{40,}$/', $id) || $jeton === '') {
            return null;
        }
        $maintenant ??= time();
        $fichier = $this->fichier($id);
        $verrou = @fopen($fichier, 'c+');
        if ($verrou === false || !flock($verrou, LOCK_EX)) {
            return null;
        }
        try {
            $contenu = stream_get_contents($verrou);
            if ($contenu === false) {
                return null;
            }
            $session = json_decode($contenu, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($session) || !isset($session['csrf'], $session['expire_le'])
                || (int) $session['expire_le'] < $maintenant || !hash_equals((string) $session['csrf'], $jeton)) {
                return null;
            }
            $nouveau = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $session['csrf'] = $nouveau;
            // Le jeton a une longueur fixe : réécrire le JSON ne laisse aucun reliquat.
            // Une session expirée est ignorée par lire(), jamais purgée en masse.
            rewind($verrou);
            fwrite($verrou, json_encode($session, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            fflush($verrou);

            return $nouveau;
        } catch (\JsonException) {
            return null;
        } finally {
            flock($verrou, LOCK_UN);
            fclose($verrou);
        }
    }

    public function enteteCookie(string $id, bool $securise): string
    {
        return sprintf('gedv2_session=%s; Path=/; HttpOnly; SameSite=Lax%s', $id, $securise ? '; Secure' : '');
    }

    /**
     * Émet un jeton CSRF anonyme à usage unique pour le formulaire de connexion.
     * Un jeton consommé ne peut donc pas reconnecter une session par rejeu d'une requête interceptée.
     */
    public function ouvrirJetonConnexion(?int $maintenant = null): string
    {
        $maintenant ??= time();
        $jeton = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $dossier = $this->dossierJetonsConnexion();
        $fichier = $dossier . '/' . hash('sha256', $jeton) . '.json';
        $contenu = json_encode(['expire_le' => $maintenant + 600], JSON_THROW_ON_ERROR);
        if (file_put_contents($fichier, $contenu, LOCK_EX) === false) {
            throw new RuntimeException('Impossible d’enregistrer le jeton de connexion.');
        }

        return $jeton;
    }

    /** Consomme atomiquement un jeton CSRF de connexion encore valide. */
    public function consommerJetonConnexion(string $jeton, ?int $maintenant = null): bool
    {
        if (!preg_match('/^[A-Za-z0-9_-]{40,}$/', $jeton)) {
            return false;
        }
        $maintenant ??= time();
        $fichier = $this->dossierJetonsConnexion() . '/' . hash('sha256', $jeton) . '.json';
        $marqueur = $fichier . '.utilise';
        // « x » est atomique : un seul processus peut poser le marqueur de consommation.
        $verrou = @fopen($marqueur, 'x');
        if ($verrou === false) {
            return false;
        }
        fclose($verrou);

        $contenu = is_file($fichier) ? file_get_contents($fichier) : false;
        if (is_file($fichier)) {
            unlink($fichier);
        }
        if ($contenu === false) {
            return false;
        }
        try {
            $donnees = json_decode($contenu, true, 512, JSON_THROW_ON_ERROR);

            return is_array($donnees) && isset($donnees['expire_le']) && (int) $donnees['expire_le'] >= $maintenant;
        } catch (\JsonException) {
            return false;
        }
    }

    /** @return array{utilisateur:string,compte:int|null,permissions:list<string>,csrf:string,cree_le:int,expire_le:int}|null */
    private function charger(string $id): ?array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{40,}$/', $id)) {
            return null;
        }
        $fichier = $this->fichier($id);
        if (!is_file($fichier)) {
            return null;
        }
        $contenu = file_get_contents($fichier);
        if ($contenu === false) {
            return null;
        }
        try {
            /** @var array{utilisateur:string,compte:int|null,permissions:list<string>,csrf:string,cree_le:int,expire_le:int} $session */
            $session = json_decode($contenu, true, 512, JSON_THROW_ON_ERROR);
            return $session;
        } catch (\JsonException) {
            return null;
        }
    }

    /** @param array{utilisateur:string,compte:int|null,permissions:list<string>,csrf:string,cree_le:int,expire_le:int} $session */
    private function ecrire(string $id, array $session): void
    {
        $json = json_encode($session, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (file_put_contents($this->fichier($id), $json, LOCK_EX) === false) {
            throw new RuntimeException('Impossible d’enregistrer la session.');
        }
    }

    private function fichier(string $id): string
    {
        return $this->dossier . '/' . hash('sha256', $id) . '.json';
    }

    private function dossierJetonsConnexion(): string
    {
        $dossier = $this->dossier . '/connexion';
        if (!is_dir($dossier) && !mkdir($dossier, 0700, true) && !is_dir($dossier)) {
            throw new RuntimeException('Impossible de créer le registre des jetons de connexion.');
        }

        return $dossier;
    }
}
