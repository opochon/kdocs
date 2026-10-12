<?php

declare(strict_types=1);

namespace Ged\Ui;

use Ged\Base;
use Ged\Config;
use PDO;
use Throwable;

/**
 * Écran « Corbeille » (modèle Paperless `TrashComponent`, lot ged-parite-ecrans-1).
 *
 * Un document mis à la corbeille n'est PAS supprimé : son fichier, son `.meta.json` et ses versions sont
 * déplacés sous `.corbeille/` (arborescence conservée) et la ligne d'index porte `supprime_le`. GEDv2 ne
 * supprime jamais de donnée utilisateur : l'écran RESTAURE, il n'efface pas.
 *
 * Lecture seule ici : la restauration passe par `Ged\Socle\DepotFichiers::restaurer` (écriture du Socle),
 * appelée par la route. Une base muette rend une liste vide NOMMÉE, jamais un 500.
 */
final class Corbeille
{
    private const LIMITE = 200;

    public function __construct(private readonly ?PDO $pdo)
    {
    }

    public static function depuisRacine(?string $racine = null): self
    {
        $pdo = null;
        try {
            $pdo = Base::pdo(new Config($racine ?? dirname(__DIR__, 2)));
        } catch (Throwable) {
            // Base muette : l'écran nomme l'indisponibilité, jamais un 500.
        }

        return new self($pdo);
    }

    /**
     * @return array{
     *   ecran:string,
     *   disponible:bool,
     *   documents:list<array{id:int, titre:string, chemin:string, supprime_le:string, supprime_par:string}>
     * }
     */
    public function donnees(): array
    {
        return [
            'ecran' => 'corbeille',
            'disponible' => $this->pdo !== null,
            'documents' => $this->documents(),
        ];
    }

    /** @return list<array{id:int, titre:string, chemin:string, supprime_le:string, supprime_par:string}> */
    private function documents(): array
    {
        if ($this->pdo === null) {
            return [];
        }
        try {
            $lignes = $this->pdo->query('SELECT id, titre, chemin_relatif, supprime_le, supprime_par FROM documents'
                . ' WHERE supprime_le IS NOT NULL ORDER BY supprime_le DESC, id DESC LIMIT ' . self::LIMITE)->fetchAll();
        } catch (Throwable) {
            return [];
        }

        $documents = [];
        foreach ($lignes as $ligne) {
            $le = (string) ($ligne['supprime_le'] ?? '');
            $documents[] = [
                'id' => (int) ($ligne['id'] ?? 0),
                'titre' => (string) ($ligne['titre'] ?? ''),
                'chemin' => (string) ($ligne['chemin_relatif'] ?? ''),
                'supprime_le' => $le !== '' ? date('d.m.Y H:i', (int) strtotime($le)) : '',
                'supprime_par' => (string) ($ligne['supprime_par'] ?? ''),
            ];
        }

        return $documents;
    }
}
