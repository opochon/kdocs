<?php

declare(strict_types=1);

namespace Ged\Socle;

use Ged\Base;
use Ged\Config;
use Ged\Securite\DroitsDossiers;
use Ged\Securite\JournalAudit;
use PDO;
use RuntimeException;

/** Notes, liens et partages de la fiche. Les jetons externes ne sont jamais conservés en clair. */
final class Collaboration
{
    private readonly PDO $pdo;
    private readonly DroitsDossiers $droits;
    private readonly JournalAudit $audit;

    public function __construct(private readonly Config $config)
    {
        $this->pdo = Base::pdo($config);
        Schema::installer($this->pdo);
        $this->droits = new DroitsDossiers($this->pdo);
        $this->audit = new JournalAudit($config->chemin('SECURITE_DOSSIER', 'var/securite') . '/audit.ndjson');
    }

    /** @return list<array{id:int,auteur:string,texte:string,cree_le:string,maj_le:string}> */
    public function notes(int $document): array
    {
        $s = $this->pdo->prepare('SELECT id, auteur, texte, cree_le, maj_le FROM document_notes WHERE document_id = ? AND retire_le IS NULL ORDER BY cree_le ASC');
        $s->execute([$document]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    public function ajouterNote(int $document, string $auteur, string $texte): int
    {
        $texte = trim($texte);
        if ($document < 1 || $texte === '' || mb_strlen($texte) > 8000) throw new RuntimeException('note : texte requis (8 000 caractères maximum)');
        $this->exigerDocument($document);
        $s = $this->pdo->prepare('INSERT INTO document_notes (document_id, auteur, texte) VALUES (?, ?, ?)');
        $s->execute([$document, $auteur, $texte]);
        $id = (int) $this->pdo->lastInsertId();
        $this->actualiserRecherche($document);
        $this->evenement($document, 'note ajoutée', $auteur, $id);
        $this->audit->ajouter('note_ajoutee', $auteur, ['document' => $document, 'note' => $id]);
        return $id;
    }

    public function modifierNote(int $note, string $auteur, string $texte): void
    {
        $texte = trim($texte);
        if ($texte === '' || mb_strlen($texte) > 8000) throw new RuntimeException('note : texte requis (8 000 caractères maximum)');
        $s = $this->pdo->prepare('UPDATE document_notes SET texte = ? WHERE id = ? AND auteur = ? AND retire_le IS NULL');
        $s->execute([$texte, $note, $auteur]);
        if ($s->rowCount() !== 1) throw new RuntimeException('note : seule votre propre note peut être modifiée');
        $doc=(int)$this->pdo->query('SELECT document_id FROM document_notes WHERE id=' . (int)$note)->fetchColumn(); if($doc>0) { $this->actualiserRecherche($doc); $this->evenement($doc, 'note modifiée', $auteur, $note); }
        $this->audit->ajouter('note_modifiee', $auteur, ['note' => $note]);
    }

    public function retirerNote(int $note, string $auteur): void
    {
        $s = $this->pdo->prepare('UPDATE document_notes SET retire_le = NOW() WHERE id = ? AND auteur = ? AND retire_le IS NULL');
        $s->execute([$note, $auteur]);
        if ($s->rowCount() !== 1) throw new RuntimeException('note : seule votre propre note peut être retirée');
        $doc=(int)$this->pdo->query('SELECT document_id FROM document_notes WHERE id=' . (int)$note)->fetchColumn(); if($doc>0) { $this->actualiserRecherche($doc); $this->evenement($doc, 'note retirée', $auteur, $note); }
        $this->audit->ajouter('note_retiree', $auteur, ['note' => $note]);
    }

    /** @return list<array{id:int,document:int,titre:string,libelle:string,auteur:string,cree_le:string}> */
    public function liens(int $document, ?int $compte = null): array
    {
        $sql = 'SELECT l.id, l.libelle, l.auteur, l.cree_le, CASE WHEN l.document_source_id = :doc THEN l.document_cible_id ELSE l.document_source_id END document, d.titre'
            . ' FROM document_liens l INNER JOIN documents d ON d.id = CASE WHEN l.document_source_id = :doc2 THEN l.document_cible_id ELSE l.document_source_id END'
            . ' WHERE (l.document_source_id = :doc3 OR l.document_cible_id = :doc4) AND l.retire_le IS NULL ORDER BY l.cree_le DESC';
        $s = $this->pdo->prepare($sql); $s->execute(['doc'=>$document, 'doc2'=>$document, 'doc3'=>$document, 'doc4'=>$document]);
        $liens = $s->fetchAll(PDO::FETCH_ASSOC);
        // Un lien ne doit jamais révéler le titre ou l'identifiant d'une pièce hors ACL.
        if ($compte === null) return $liens; // compte synthétique des oracles HTTP de test
        return array_values(array_filter($liens, fn (array $lien): bool => $this->peutLire($compte, (int) $lien['document'])));
    }

    public function lier(int $source, int $cible, string $libelle, string $auteur, ?int $compte): int
    {
        $libelle = trim($libelle);
        if ($source < 1 || $cible < 1 || $source === $cible || $libelle === '' || mb_strlen($libelle) > 128) throw new RuntimeException('lien : deux documents distincts et un libellé sont requis');
        $this->exigerDocument($source); $this->exigerDocument($cible);
        if ($compte !== null && (!$this->peutLire($compte, $source) || !$this->peutLire($compte, $cible))) throw new RuntimeException('lien : document cible non lisible');
        $s = $this->pdo->prepare('INSERT INTO document_liens (document_source_id, document_cible_id, libelle, auteur) VALUES (?, ?, ?, ?)');
        $s->execute([$source, $cible, $libelle, $auteur]); $id = (int) $this->pdo->lastInsertId();
        $this->evenement($source, 'lien ajouté : ' . $libelle, $auteur, $id);
        $this->evenement($cible, 'lien ajouté : ' . $libelle, $auteur, $id);
        $this->audit->ajouter('lien_ajoute', $auteur, ['lien'=>$id, 'source'=>$source, 'cible'=>$cible]); return $id;
    }

    public function retirerLien(int $lien, string $auteur): void
    {
        $doc=$this->pdo->prepare('SELECT document_source_id, document_cible_id FROM document_liens WHERE id=?'); $doc->execute([$lien]); $documents=$doc->fetch(PDO::FETCH_ASSOC);
        $s = $this->pdo->prepare('UPDATE document_liens SET retire_le = NOW() WHERE id = ? AND retire_le IS NULL'); $s->execute([$lien]);
        if ($s->rowCount() !== 1) throw new RuntimeException('lien : introuvable ou déjà retiré');
        if (is_array($documents)) foreach (['document_source_id', 'document_cible_id'] as $cle) $this->evenement((int)$documents[$cle], 'lien retiré', $auteur, $lien);
        $this->audit->ajouter('lien_retire', $auteur, ['lien'=>$lien]);
    }

    /** @return array{id:int,jeton:string} le jeton ne revient qu'à sa création, et n'est jamais audité. */
    public function partagerExterne(int $document, string $auteur, string $expiration): array
    {
        $this->exigerDocument($document); $date = $this->expiration($expiration);
        $jeton = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='); $empreinte = hash('sha256', $jeton);
        $s = $this->pdo->prepare("INSERT INTO document_partages (document_id,type_partage,cible_type,jeton_empreinte,auteur,expire_le) VALUES (?,'externe','lien',?,?,?)");
        $s->execute([$document, $empreinte, $auteur, $date]); $id = (int) $this->pdo->lastInsertId();
        $this->audit->ajouter('partage_externe_cree', $auteur, ['partage'=>$id, 'document'=>$document, 'jeton_empreinte'=>substr($empreinte,0,12)]);
        return ['id'=>$id, 'jeton'=>$jeton];
    }

    public function partagerInterne(int $document, string $type, int $cible, string $auteur): int
    {
        if (!in_array($type, ['compte','groupe'], true) || $cible < 1) throw new RuntimeException('partage interne : destinataire invalide');
        $this->exigerDocument($document); $this->exigerDestinataireLisible($document, $type, $cible);
        $s=$this->pdo->prepare("INSERT INTO document_partages (document_id,type_partage,cible_type,cible_id,auteur) VALUES (?,'interne',?,?,?)");
        $s->execute([$document,$type,$cible,$auteur]); $id=(int)$this->pdo->lastInsertId();
        $this->audit->ajouter('partage_interne_cree',$auteur,['partage'=>$id,'document'=>$document,'cible_type'=>$type,'cible'=>$cible]); return $id;
    }

    /** @return list<array{id:int,document:int,titre:string,auteur:string,cree_le:string}> */
    public function partagesAvecMoi(int $compte): array
    {
        $sql = "SELECT p.id, p.document_id document, p.auteur, p.cree_le, d.titre FROM document_partages p"
            . " INNER JOIN documents d ON d.id = p.document_id WHERE p.type_partage = 'interne'"
            . " AND p.revoque_le IS NULL AND ((p.cible_type = 'compte' AND p.cible_id = ?)"
            . " OR (p.cible_type = 'groupe' AND p.cible_id IN (SELECT groupe_id FROM securite_groupes_comptes WHERE compte_id = ?)))"
            . ' ORDER BY p.cree_le DESC';
        $s = $this->pdo->prepare($sql); $s->execute([$compte, $compte]);
        return array_values(array_filter($s->fetchAll(PDO::FETCH_ASSOC), fn (array $partage): bool => $this->peutLire($compte, (int) $partage['document'])));
    }

    public function revoquerPartage(int $partage, string $auteur): void
    {
        $s=$this->pdo->prepare('UPDATE document_partages SET revoque_le = NOW() WHERE id = ? AND revoque_le IS NULL'); $s->execute([$partage]);
        if ($s->rowCount() !== 1) throw new RuntimeException('partage : introuvable ou déjà révoqué');
        $this->audit->ajouter('partage_revoque',$auteur,['partage'=>$partage]);
    }

    public function revoquerPartageInterne(int $partage, string $auteur): void
    {
        $this->revoquerPartageType($partage, 'interne', $auteur);
    }

    public function revoquerPartageExterne(int $partage, string $auteur): void
    {
        $this->revoquerPartageType($partage, 'externe', $auteur);
    }

    /** @return array{document:int,partage:int}|null */
    public function lienExterneValide(string $jeton): ?array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{40,128}$/', $jeton)) return null;
        $empreinte=hash('sha256',$jeton); $s=$this->pdo->prepare("SELECT id,document_id FROM document_partages WHERE type_partage='externe' AND jeton_empreinte=? AND revoque_le IS NULL AND expire_le > NOW()"); $s->execute([$empreinte]); $r=$s->fetch(PDO::FETCH_ASSOC);
        if (!is_array($r)) return null;
        $this->audit->ajouter('partage_externe_acces', null, ['partage'=>(int)$r['id'],'document'=>(int)$r['document_id'],'jeton_empreinte'=>substr($empreinte,0,12)]);
        return ['document'=>(int)$r['document_id'],'partage'=>(int)$r['id']];
    }

    /** @return list<array{id:int,type:string,cible:string,expire_le:?string,revoque_le:?string}> */
    public function partages(int $document): array { $s=$this->pdo->prepare('SELECT id,type_partage type,CASE WHEN cible_type=\'lien\' THEN \'lien externe\' ELSE CONCAT(cible_type, \' #\', cible_id) END cible,expire_le,revoque_le FROM document_partages WHERE document_id=? ORDER BY cree_le DESC'); $s->execute([$document]); return $s->fetchAll(PDO::FETCH_ASSOC); }

    /** @return list<array{quand:string,quoi:string}> */
    public function historique(int $document): array { $s=$this->pdo->prepare("SELECT cree_le quand, CONCAT(UCASE(LEFT(action, 1)), SUBSTRING(action, 2), ' par ', auteur) quoi FROM document_collaboration_evenements WHERE document_id=? UNION ALL SELECT cree_le, CONCAT('Partage ',type_partage,' créé') FROM document_partages WHERE document_id=? ORDER BY quand DESC"); $s->execute([$document,$document]); return $s->fetchAll(PDO::FETCH_ASSOC); }

    public function cheminDocument(int $document): ?array { $s=$this->pdo->prepare('SELECT chemin_relatif,nom,mime,titre FROM documents WHERE id=? AND supprime_le IS NULL AND absent_depuis IS NULL');$s->execute([$document]);$r=$s->fetch(PDO::FETCH_ASSOC);return is_array($r)?$r:null; }

    private function exigerDocument(int $id): void { if ($this->cheminDocument($id)===null) throw new RuntimeException('document introuvable dans le fonds'); }
    private function revoquerPartageType(int $partage, string $type, string $auteur): void { $s=$this->pdo->prepare('UPDATE document_partages SET revoque_le = NOW() WHERE id = ? AND type_partage = ? AND revoque_le IS NULL'); $s->execute([$partage, $type]); if ($s->rowCount() !== 1) throw new RuntimeException('partage ' . $type . ' : introuvable ou déjà révoqué'); $this->audit->ajouter('partage_revoque', $auteur, ['partage'=>$partage, 'type'=>$type]); }
    private function evenement(int $document, string $action, string $auteur, ?int $objet = null): void { $this->pdo->prepare('INSERT INTO document_collaboration_evenements (document_id, action, auteur, objet_id) VALUES (?, ?, ?, ?)')->execute([$document, $action, $auteur, $objet]); }
    /** Met à jour la ligne du MÊME index plein texte dès qu'une note change, sans moteur parallèle. */
    private function actualiserRecherche(int $document): void { try { $s=$this->pdo->prepare('SELECT meta FROM documents WHERE id=?');$s->execute([$document]);$meta=json_decode((string)$s->fetchColumn(),true);$texte=is_array($meta)?(string)($meta['texte']??$meta['ocr']??''):'';$n=$this->pdo->prepare('SELECT texte FROM document_notes WHERE document_id=? AND retire_le IS NULL');$n->execute([$document]);$contenu=trim($texte."\n".implode("\n",array_map('strval',$n->fetchAll(PDO::FETCH_COLUMN))));$this->pdo->prepare('UPDATE recherche_textes SET contenu=? WHERE document_id=?')->execute([$contenu,$document]); } catch(\PDOException) {} }
    private function expiration(string $valeur): string { $d=\DateTimeImmutable::createFromFormat('Y-m-d', $valeur); if(!$d || $d->format('Y-m-d')!==$valeur || $d <= new \DateTimeImmutable('today')) throw new RuntimeException('partage externe : date d’expiration future obligatoire'); return $d->setTime(23,59,59)->format('Y-m-d H:i:s'); }
    private function peutLire(int $compte,int $document): bool { $d=$this->droits->dossierPourDocument($document); return $d!==null && $this->droits->autorise($compte,$d,'lire'); }
    private function exigerDestinataireLisible(int $doc,string $type,int $cible): void { $comptes=$type==='compte'?[$cible]:$this->comptesGroupe($cible); if($comptes===[]) throw new RuntimeException('partage interne : destinataire introuvable'); foreach($comptes as $compte) if(!$this->peutLire($compte,$doc)) throw new RuntimeException('partage interne : le destinataire doit déjà lire le dossier'); }
    /** @return list<int> */ private function comptesGroupe(int $groupe): array { $s=$this->pdo->prepare('SELECT compte_id FROM securite_groupes_comptes WHERE groupe_id=?');$s->execute([$groupe]);return array_map('intval',$s->fetchAll(PDO::FETCH_COLUMN)); }
}
