<?php

declare(strict_types=1);

namespace Ged\Socle;

use Ged\Base;
use Ged\Config;
use Ged\Recherche\DocumentFonds;
use Ged\Recherche\IndexPleinTexte;
use PDO;
use RuntimeException;

/** Reconstruction courte : son état appartient au fonds, sous .ged/, jamais à l'index SQL. */
final class ReconstructionIndex
{
    /** Nombre de chemins absents affichés par l'écran ; le compte, lui, reste exact. */
    private const ABSENTS_MONTRES = 5;

    private readonly Registre $registre;

    public function __construct(private readonly Config $config)
    {
        // L'administrateur lance cette reconstruction sur un schéma déjà posé par l'installateur.
        // Cet objet est construit depuis une route : aucune migration ne peut y être déclenchée.
        $this->registre = new Registre($config);
    }

    /**
     * Refuse sous verrou un second lancement ; le fichier atomique survit à une coupure.
     *
     * Le total annoncé est le PÉRIMÈTRE que `synchroniser()` relira — la même définition que la
     * synchronisation, jamais le nombre de lignes de l'index. Les documents vivants absents du fonds
     * courant sont comptés et nommés à part : l'utilisateur voit un pourcentage juste, sans reste
     * fantôme, et sait ce qui ne sera pas relu.
     *
     * @return array<string, mixed>
     */
    public function lancer(string $auteur): array
    {
        return $this->registre->modifier('reconstruction-index', function (array $etat) use ($auteur): array {
            if (($etat['etat'] ?? null) === 'en_cours') throw new RuntimeException('reconstruction : une tâche est déjà en cours');
            $pdo = Base::pdo($this->config);
            $index = IndexPleinTexte::depuis($this->config, $pdo);
            $perimetre = array_map(static fn (DocumentFonds $document): string => $document->chemin, $index->aRelire());
            $absents = $this->absents($pdo, $perimetre);
            $index->vider();
            return ['id' => (int) ($etat['id'] ?? 0) + 1, 'etat' => 'en_cours', 'total' => count($perimetre),
                'traite' => 0, 'auteur' => $auteur, 'erreur' => null,
                'absents' => count($absents), 'absents_noms' => array_slice($absents, 0, self::ABSENTS_MONTRES)];
        });
    }

    /**
     * Les documents VIVANTS de l'index dont le fichier n'est pas dans le fonds courant : la
     * reconstruction ne peut pas les relire. Ils sont rendus à part pour être comptés et nommés,
     * jamais fondus dans le total ni tus.
     *
     * @param list<string> $perimetre chemins relatifs des documents présents dans le fonds
     * @return list<string> chemins relatifs des documents vivants hors périmètre, triés
     */
    private function absents(PDO $pdo, array $perimetre): array
    {
        $presents = array_fill_keys($perimetre, true);
        $vivants = $pdo->query('SELECT chemin_relatif FROM documents WHERE supprime_le IS NULL ORDER BY chemin_relatif')
            ->fetchAll(PDO::FETCH_COLUMN);
        $absents = [];
        foreach ($vivants as $chemin) {
            if (!isset($presents[(string) $chemin])) {
                $absents[] = (string) $chemin;
            }
        }

        return $absents;
    }

    /**
     * Une tranche reste bornée à 100 ; l'état est relu et publié sous le verrou du Registre.
     *
     * @return array<string, mixed>
     */
    public function avancer(int $id, int $lot = 25): array
    {
        try {
            return $this->registre->modifier('reconstruction-index', function (array $etat) use ($id, $lot): array {
                if (($etat['id'] ?? null) !== $id) throw new RuntimeException('reconstruction : tâche introuvable');
                if (($etat['etat'] ?? null) !== 'en_cours') return $etat;
                $faits = IndexPleinTexte::depuis($this->config, Base::pdo($this->config))->synchroniser(max(1, min(100, $lot)));
                // Le total est exactement le périmètre relu : l'avancement se cumule sans forcer la
                // fin quand plus rien ne reste — aucun reste fantôme, aucune tranche supplémentaire.
                $traite = min((int) $etat['total'], (int) $etat['traite'] + $faits);
                $etat['traite'] = $traite;
                $etat['etat'] = $traite >= (int) $etat['total'] ? 'termine' : 'en_cours';
                return $etat;
            });
        } catch (RuntimeException $e) { throw $e; }
        catch (\Throwable $e) {
            $this->registre->modifier('reconstruction-index', static function (array $etat) use ($id, $e): array {
                if (($etat['id'] ?? null) === $id) { $etat['etat'] = 'echec'; $etat['erreur'] = mb_substr($e->getMessage(), 0, 512); }
                return $etat;
            });
            return $this->etat($id) ?? throw new RuntimeException('reconstruction : tâche perdue');
        }
    }

    /** @return array{id:int,etat:string,total:int,traite:int,auteur:string,erreur:?string,absents:int,absents_noms:list<string>}|null */
    public function etat(?int $id = null): ?array
    {
        $etat = $this->registre->lire('reconstruction-index');
        if (!isset($etat['id']) || ($id !== null && (int) $etat['id'] !== $id)) return null;
        return ['id' => (int) $etat['id'], 'etat' => (string) $etat['etat'], 'total' => (int) $etat['total'],
            'traite' => (int) $etat['traite'], 'auteur' => (string) $etat['auteur'],
            'erreur' => $etat['erreur'] === null ? null : (string) $etat['erreur'],
            'absents' => (int) ($etat['absents'] ?? 0),
            'absents_noms' => array_values(array_map('strval', (array) ($etat['absents_noms'] ?? [])))];
    }
}
