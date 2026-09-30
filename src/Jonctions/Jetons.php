<?php

declare(strict_types=1);

namespace Ged\Jonctions;

use PDO;

/**
 * Jetons de l'API d'entrée. Un jeton se crée soit pour une application (portée `depot`), soit pour un
 * appareil appairé : dans ce cas il est LIÉ À UN COMPTE (`compte_id` / `compte_identifiant`) et c'est
 * l'identité sous laquelle la GED applique ses droits. La révocation est une trace, jamais une
 * suppression. Seule l'empreinte sha256 est en base : le jeton en clair n'est rendu qu'une fois.
 */
final class Jetons
{
    public const PORTEE_DEPOT = 'depot';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** Crée un jeton d'application et retourne sa valeur en clair, une seule fois. @throws \InvalidArgumentException */
    public function creer(string $application, string $portee = self::PORTEE_DEPOT): string
    {
        $application = trim($application);
        if ($application === '' || mb_strlen($application) > 100) {
            throw new \InvalidArgumentException('application requise (1 à 100 caractères)');
        }
        $portee = trim($portee);
        if ($portee !== '*' && preg_match('/^[a-z][a-z0-9_-]{0,19}$/', $portee) !== 1) {
            throw new \InvalidArgumentException("portée invalide : $portee");
        }

        $jeton = bin2hex(random_bytes(32));
        $this->pdo->prepare('INSERT INTO jonctions_jetons (application, empreinte, portee) VALUES (?, ?, ?)')
            ->execute([$application, hash('sha256', $jeton), $portee]);

        return $jeton;
    }

    /**
     * Crée un jeton d'appairage LIÉ au compte. Portée `depot`, haché en base, révocable.
     *
     * @throws \InvalidArgumentException
     */
    public function creerAppairage(string $application, string $appareil, ?int $compteId, string $compteIdentifiant): string
    {
        $application = trim($application);
        if ($application === '' || mb_strlen($application) > 100) {
            throw new \InvalidArgumentException('application requise (1 à 100 caractères)');
        }
        $compteIdentifiant = trim($compteIdentifiant);
        if ($compteIdentifiant === '' || mb_strlen($compteIdentifiant) > 255) {
            throw new \InvalidArgumentException('compte d’appairage requis');
        }
        $appareil = mb_substr(trim($appareil), 0, 255);

        $jeton = bin2hex(random_bytes(32));
        $this->pdo->prepare('INSERT INTO jonctions_jetons (application, empreinte, portee, compte_id, compte_identifiant, appareil)'
            . ' VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$application, hash('sha256', $jeton), self::PORTEE_DEPOT, $compteId, $compteIdentifiant, $appareil]);

        return $jeton;
    }

    /** Révoque un jeton actif. Retourne true si la révocation vient d'être posée. Idempotent. */
    public function revoquer(int $id): bool
    {
        $requete = $this->pdo->prepare('UPDATE jonctions_jetons SET revoque_le = COALESCE(revoque_le, NOW()) WHERE id = ? AND revoque_le IS NULL');
        $requete->execute([$id]);

        return $requete->rowCount() === 1;
    }

    /**
     * Retrouve un jeton par son empreinte, révoqué ou non, avec son compte : sert à nommer précisément
     * le refus et à résoudre les droits.
     *
     * @return array{id:int, application:string, portee:string, revoque:bool, compte_id:?int,
     *               compte_identifiant:?string, appareil:?string}|null
     */
    public function resoudre(string $jeton): ?array
    {
        if ($jeton === '') {
            return null;
        }
        $requete = $this->pdo->prepare('SELECT id, application, portee, revoque_le, compte_id, compte_identifiant, appareil'
            . ' FROM jonctions_jetons WHERE empreinte = ? LIMIT 1');
        $requete->execute([hash('sha256', $jeton)]);
        $ligne = $requete->fetch(PDO::FETCH_ASSOC);
        if ($ligne === false) {
            return null;
        }

        return [
            'id' => (int) $ligne['id'],
            'application' => (string) $ligne['application'],
            'portee' => (string) $ligne['portee'],
            'revoque' => $ligne['revoque_le'] !== null,
            'compte_id' => $ligne['compte_id'] === null ? null : (int) $ligne['compte_id'],
            'compte_identifiant' => $ligne['compte_identifiant'] === null ? null : (string) $ligne['compte_identifiant'],
            'appareil' => $ligne['appareil'] === null ? null : (string) $ligne['appareil'],
        ];
    }

    /**
     * Vérifie qu'un jeton est actif et couvre la portée demandée ; marque alors sa dernière utilisation.
     *
     * @return array{id:int, application:string, portee:string}|null
     */
    public function verifier(string $jeton, string $portee = self::PORTEE_DEPOT): ?array
    {
        $ligne = $this->resoudre($jeton);
        if ($ligne === null || $ligne['revoque'] || ($ligne['portee'] !== $portee && $ligne['portee'] !== '*')) {
            return null;
        }
        $this->marquerUtilisation($ligne['id']);

        return ['id' => $ligne['id'], 'application' => $ligne['application'], 'portee' => $ligne['portee']];
    }

    /** Note le dernier appel réussi d'un jeton (trace d'usage, jamais une donnée sensible). */
    public function marquerUtilisation(int $id): void
    {
        $this->pdo->prepare('UPDATE jonctions_jetons SET derniere_utilisation = NOW() WHERE id = ?')->execute([$id]);
    }

    /** @return list<array{id:int, application:string, portee:string, cree_le:string, revoque_le:?string}> */
    public function lister(): array
    {
        $lignes = $this->pdo->query('SELECT id, application, portee, cree_le, revoque_le FROM jonctions_jetons ORDER BY id')
            ->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static fn (array $l): array => [
            'id' => (int) $l['id'],
            'application' => (string) $l['application'],
            'portee' => (string) $l['portee'],
            'cree_le' => (string) $l['cree_le'],
            'revoque_le' => $l['revoque_le'] === null ? null : (string) $l['revoque_le'],
        ], $lignes);
    }

    /**
     * Appareils appairés d'un compte (écran Application › Appareils reliés et `GET /api/v1/appairage/moi`).
     * Le compte synthétique de test n'a pas d'identifiant en base : on retombe alors sur l'identifiant.
     *
     * @return list<array{id:int, application:string, appareil:string, compte_identifiant:string,
     *               cree_le:string, derniere_utilisation:?string, revoque_le:?string, actif:bool}>
     */
    public function listerAppareils(?int $compteId, string $compteIdentifiant): array
    {
        if ($compteId !== null) {
            $requete = $this->pdo->prepare('SELECT id, application, appareil, compte_identifiant, cree_le, derniere_utilisation, revoque_le'
                . ' FROM jonctions_jetons WHERE appareil IS NOT NULL AND compte_id = ? ORDER BY revoque_le IS NOT NULL, id DESC');
            $requete->execute([$compteId]);
        } else {
            $requete = $this->pdo->prepare('SELECT id, application, appareil, compte_identifiant, cree_le, derniere_utilisation, revoque_le'
                . ' FROM jonctions_jetons WHERE appareil IS NOT NULL AND compte_identifiant = ? ORDER BY revoque_le IS NOT NULL, id DESC');
            $requete->execute([trim($compteIdentifiant)]);
        }

        return array_map(static fn (array $l): array => [
            'id' => (int) $l['id'],
            'application' => (string) $l['application'],
            'appareil' => (string) $l['appareil'],
            'compte_identifiant' => (string) $l['compte_identifiant'],
            'cree_le' => (string) $l['cree_le'],
            'derniere_utilisation' => $l['derniere_utilisation'] === null ? null : (string) $l['derniere_utilisation'],
            'revoque_le' => $l['revoque_le'] === null ? null : (string) $l['revoque_le'],
            'actif' => $l['revoque_le'] === null,
        ], $requete->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Révoque un appareil seulement s'il appartient au compte donné. Idempotent. */
    public function revoquerAppareil(int $id, ?int $compteId, string $compteIdentifiant): bool
    {
        $requete = $this->pdo->prepare('SELECT compte_id, compte_identifiant FROM jonctions_jetons WHERE id = ? AND appareil IS NOT NULL LIMIT 1');
        $requete->execute([$id]);
        $ligne = $requete->fetch(PDO::FETCH_ASSOC);
        if ($ligne === false) {
            return false;
        }
        $appartient = $compteId !== null
            ? (int) $ligne['compte_id'] === $compteId
            : $ligne['compte_id'] === null && (string) $ligne['compte_identifiant'] === trim($compteIdentifiant);
        if (!$appartient) {
            return false;
        }

        return $this->revoquer($id);
    }
}
