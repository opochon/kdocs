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
            if ($session !== null) {
                $this->fermer($id);
            }
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

    public function enteteCookie(string $id, bool $securise): string
    {
        return sprintf('gedv2_session=%s; Path=/; HttpOnly; SameSite=Lax%s', $id, $securise ? '; Secure' : '');
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
}
