<?php

declare(strict_types=1);

namespace Ged\Socle;

use Ged\Base;
use Ged\Config;
use Ged\Recherche\IndexPleinTexte;
use RuntimeException;

/** Reconstruction courte : son état appartient au fonds, sous .ged/, jamais à l'index SQL. */
final class ReconstructionIndex
{
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
     * @return array<string, mixed>
     */
    public function lancer(string $auteur): array
    {
        return $this->registre->modifier('reconstruction-index', function (array $etat) use ($auteur): array {
            if (($etat['etat'] ?? null) === 'en_cours') throw new RuntimeException('reconstruction : une tâche est déjà en cours');
            $pdo = Base::pdo($this->config);
            $total = (int) $pdo->query('SELECT COUNT(*) FROM documents WHERE supprime_le IS NULL AND absent_depuis IS NULL')->fetchColumn();
            IndexPleinTexte::depuis($this->config, $pdo)->vider();
            return ['id' => (int) ($etat['id'] ?? 0) + 1, 'etat' => 'en_cours', 'total' => $total, 'traite' => 0, 'auteur' => $auteur, 'erreur' => null];
        });
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
                $traite = min((int) $etat['total'], (int) $etat['traite'] + max($faits, $faits === 0 ? (int) $etat['total'] : 0));
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

    /** @return array{id:int,etat:string,total:int,traite:int,auteur:string,erreur:?string}|null */
    public function etat(?int $id = null): ?array
    {
        $etat = $this->registre->lire('reconstruction-index');
        if (!isset($etat['id']) || ($id !== null && (int) $etat['id'] !== $id)) return null;
        return ['id' => (int) $etat['id'], 'etat' => (string) $etat['etat'], 'total' => (int) $etat['total'], 'traite' => (int) $etat['traite'], 'auteur' => (string) $etat['auteur'], 'erreur' => $etat['erreur'] === null ? null : (string) $etat['erreur']];
    }
}
