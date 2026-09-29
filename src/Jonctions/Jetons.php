<?php

declare(strict_types=1);

namespace Ged\Jonctions;

use PDO;

/**
 * Jetons d'application de l'API d'entrée. Un jeton se crée pour une application donnée, porte une portée
 * (`depot`) et se révoque ; la révocation est une trace, jamais une suppression. Seule l'empreinte sha256
 * est en base : le jeton en clair n'est rendu qu'une fois, à la création, et n'est jamais relu.
 */
final class Jetons
{
    public const PORTEE_DEPOT = 'depot';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** Crée un jeton et retourne sa valeur en clair, une seule fois. @throws \InvalidArgumentException */
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

    /** Révoque un jeton actif. Retourne true si la révocation vient d'être posée. Idempotent. */
    public function revoquer(int $id): bool
    {
        $requete = $this->pdo->prepare('UPDATE jonctions_jetons SET revoque_le = COALESCE(revoque_le, NOW()) WHERE id = ? AND revoque_le IS NULL');
        $requete->execute([$id]);

        return $requete->rowCount() === 1;
    }

    /**
     * Retrouve un jeton par son empreinte, révoqué ou non : sert à nommer précisément le refus.
     *
     * @return array{id:int, application:string, portee:string, revoque:bool}|null
     */
    public function resoudre(string $jeton): ?array
    {
        if ($jeton === '') {
            return null;
        }
        $requete = $this->pdo->prepare('SELECT id, application, portee, revoque_le FROM jonctions_jetons WHERE empreinte = ? LIMIT 1');
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
}
