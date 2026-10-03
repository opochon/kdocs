<?php

declare(strict_types=1);

namespace Ged\Socle;

use Ged\Base;
use Ged\Config;
use Ged\Recherche\IndexPleinTexte;
use Ged\Recherche\Schema as SchemaRecherche;
use PDO;
use RuntimeException;

/** Job court et reprenable de reconstruction du cache de recherche, sans arrêter les consultations. */
final class ReconstructionIndex
{
    private readonly PDO $pdo;
    public function __construct(private readonly Config $config) { $this->pdo=Base::pdo($config); Schema::installer($this->pdo); SchemaRecherche::installer($this->pdo); }

    /** Refuse explicitement le second lancement ; la ligne en cours est aussi le point de reprise après coupure. */
    public function lancer(string $auteur): array
    {
        $this->pdo->beginTransaction();
        try {
            $active=(int)$this->pdo->query("SELECT COUNT(*) FROM reconstruction_index_jobs WHERE etat='en_cours' FOR UPDATE")->fetchColumn();
            if($active>0) throw new RuntimeException('reconstruction : une tâche est déjà en cours');
            $total=(int)$this->pdo->query('SELECT COUNT(*) FROM documents WHERE supprime_le IS NULL AND absent_depuis IS NULL')->fetchColumn();
            // Le cache reste lisible pendant les tranches suivantes, mais chaque ligne doit être
            // repassée : une reconstruction ne peut pas se contenter de constater des signatures.
            IndexPleinTexte::depuis($this->config, $this->pdo)->vider();
            $s=$this->pdo->prepare("INSERT INTO reconstruction_index_jobs (etat,total,auteur) VALUES ('en_cours',?,?)");$s->execute([$total,$auteur]);$id=(int)$this->pdo->lastInsertId();$this->pdo->commit();
            return $this->etat($id) ?? throw new RuntimeException('reconstruction : tâche introuvable');
        } catch(\Throwable $e) { if($this->pdo->inTransaction())$this->pdo->rollBack(); throw $e; }
    }

    /** Une tranche courte : l'index reste consultable entre deux appels et la même passe peut être rejouée. */
    public function avancer(int $id, int $lot=25): array
    {
        $etat=$this->etat($id); if($etat===null) throw new RuntimeException('reconstruction : tâche introuvable'); if($etat['etat']!=='en_cours') return $etat;
        try {
            $faits=IndexPleinTexte::depuis($this->config,$this->pdo)->synchroniser(max(1,min(100,$lot)));
            $traite=min((int)$etat['total'],(int)$etat['traite']+max($faits, $faits===0?(int)$etat['total']:0));
            $fini=$traite >= (int)$etat['total'];
            $s=$this->pdo->prepare("UPDATE reconstruction_index_jobs SET traite=?, etat=?, termine_le=IF(?,NOW(),NULL) WHERE id=? AND etat='en_cours'");$s->execute([$traite,$fini?'termine':'en_cours',(int)$fini,$id]);
        } catch(\Throwable $e) { $this->pdo->prepare("UPDATE reconstruction_index_jobs SET etat='echec', erreur=? WHERE id=?")->execute([mb_substr($e->getMessage(),0,512),$id]); }
        return $this->etat($id) ?? throw new RuntimeException('reconstruction : tâche perdue');
    }

    /** @return array{id:int,etat:string,total:int,traite:int,auteur:string,erreur:?string}|null */
    public function etat(?int $id=null): ?array { $sql='SELECT id,etat,total,traite,auteur,erreur FROM reconstruction_index_jobs '.($id===null?'ORDER BY id DESC LIMIT 1':'WHERE id=?');$s=$this->pdo->prepare($sql);$s->execute($id===null?[]:[$id]);$r=$s->fetch(PDO::FETCH_ASSOC);return is_array($r)?['id'=>(int)$r['id'],'etat'=>(string)$r['etat'],'total'=>(int)$r['total'],'traite'=>(int)$r['traite'],'auteur'=>(string)$r['auteur'],'erreur'=>$r['erreur']===null?null:(string)$r['erreur']]:null; }
}
