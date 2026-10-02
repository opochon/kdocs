<?php

declare(strict_types=1);

namespace Ged\Ui;

use Ged\Base;
use Ged\Classement\Habitudes;
use Ged\Config;
use Ged\Contrats\Depot;
use Ged\Socle\DepotFichiers;
use Ged\Socle\Nomenclature;
use PDO;
use Throwable;

/**
 * Adaptateur de l'écran /a-traiter : il lit le FONDS réel (dossier + index reconstructible) et les tables
 * d'ingestion, sans changer le gabarit (mêmes classes, mêmes data-testid). Il ne classe pas seul :
 *
 * - « Classés automatiquement / À contrôler » viennent des documents vivants du fonds (leur `statut`) ;
 *   « En cours de lecture » vient des travaux d'ingestion encore ouverts ;
 * - la file est celle des documents au statut `a_controler`, du plus ancien au plus récent ;
 * - « Classement proposé » affiche ce que le Classeur a écrit à l'ingestion (source IA/habitude/règle,
 *   raison, preuve) ;
 * - `valider()` est le SEUL geste qui écrit : la mise à jour du fonds passe par `Ged\Contrats\Depot`, et
 *   le choix humain enseigne l'habitude (`Ged\Classement\Habitudes`) — jamais un tri automatique.
 *
 * L'aperçu réel se construit depuis le chemin indexé (O(1) par pièce) : pas de parcours du fonds entier à
 * chaque rendu, le dossier « fait foi » étant déjà vérifié par la présence du fichier.
 */
final class ATraiter
{
    /** Libellés et classes CSS des sources de proposition (voir `public/assets/css/ged.css`). */
    private const SOURCES = ['ia' => 'IA', 'habitude' => 'Habitude', 'regle' => 'Règle', 'humain' => 'Humain'];

    /** Borne de la file affichée : la requête est paginée (LIMIT) et ne lit jamais plus que la file. */
    private const FILE_MAX = 1000;

    private readonly ?Depot $depot;
    private readonly ?Habitudes $habitudes;

    public function __construct(
        private readonly Config $config,
        private readonly ?PDO $pdo,
        ?Depot $depot = null,
        ?Habitudes $habitudes = null,
    ) {
        $this->depot = $depot ?? ($pdo !== null ? new DepotFichiers($config) : null);
        $this->habitudes = $habitudes ?? ($pdo !== null ? new Habitudes($pdo) : null);
    }

    /** Construit l'écran depuis la racine du projet (la config vient de l'environnement). */
    public static function depuisRacine(?string $racine = null): self
    {
        $config = new Config($racine ?? dirname(__DIR__, 2));

        return new self($config, Base::pdo($config));
    }

    /**
     * Données du gabarit `ecrans/a-traiter.twig` pour la query string de l'écran.
     *
     * @param array<string, mixed> $parametres
     *
     * @return array<string, mixed>
     */
    public function donnees(array $parametres, string $csrf): array
    {
        // Le bilan se compte dans l'index (COUNT borné par statut), jamais en reparcourant le fonds.
        $auto = $this->compterValides();
        $file = $this->file();

        $choisi = (int) ($parametres['doc'] ?? 0);
        $courant = null;
        foreach ($file as $ligne) {
            if ($ligne['id'] === $choisi) {
                $courant = $ligne;
                break;
            }
        }
        // Un lien /a-traiter?doc=… reste une adresse de pièce, pas seulement une position dans la
        // première tranche de la file. C'est important si l'index contient encore des entrées dont
        // le fichier a disparu : elles ne doivent ni masquer le document demandé ni envoyer une
        // retouche vers une autre pièce.
        $courant ??= $choisi > 0 ? $this->ligneDuDocumentAControler($choisi) : null;
        $courant ??= $file[0] ?? $this->courantVide();
        $enCours = $this->enCours();
        $fusion = [];
        $lotCourant = (int) ($courant['lot'] ?? 0);
        if ($courant['id'] > 0 && $lotCourant > 0) {
            foreach ($this->piecesDuLot($lotCourant, $file) as $candidate) {
                if ($candidate['id'] !== $courant['id'] && (int) ($candidate['lot'] ?? 0) === $lotCourant) {
                    $fusion[] = ['id' => $candidate['id'], 'titre' => $candidate['titre']];
                }
            }
        }
        $refusLot = json_decode((string) ($parametres['lot_refus'] ?? '{}'), true);

        return [
            'bilan' => [
                'auto' => $auto,
                'a_controler' => count($file),
                'en_cours' => $enCours['en_cours'],
                'seuil' => $this->seuilPourcent(),
                'lots' => $enCours['lots'],
            ],
            'file' => $file,
            'courant' => $courant,
            'types' => $this->types($courant['type']),
            'csrf' => $csrf,
            'fusion' => $fusion,
            'lotValide' => max(0, (int) ($parametres['lot_valide'] ?? 0)),
            'lotRefus' => is_array($refusLot) ? $refusLot : [],
        ];
    }

    /**
     * Le geste HUMAIN de validation : met à jour le document via le dépôt (statut `valide`, rangement
     * recalculé, trace), puis enseigne le choix à l'habitude de l'émetteur. Seul ce chemin enseigne.
     *
     * @param array<string, mixed> $champs
     */
    public function valider(int $id, array $champs, string $auteur): void
    {
        if ($this->depot === null) {
            throw new \RuntimeException('validation impossible : le dépôt du fonds est indisponible');
        }
        $fiche = $this->depot->fiche($id);
        if ($fiche === null) {
            throw new \RuntimeException("document $id introuvable dans l'index");
        }

        // Sans proposition, le type part vide : la validation au clavier ne doit JAMAIS ranger un faux type.
        $type = $this->champ($champs['type'] ?? null);
        if ($type === null) {
            throw new \RuntimeException('type requis : choisissez un type avant de valider');
        }

        $meta = [
            'titre' => $this->champ($champs['titre'] ?? null) ?? (string) ($fiche['titre'] ?? $fiche['nom'] ?? ''),
            'type' => $type,
            'emetteur' => $this->champ($champs['emetteur'] ?? null),
            'date' => $this->dateIso($champs['date'] ?? null),
            'montant' => is_numeric($champs['montant'] ?? null) ? (float) $champs['montant'] : null,
            'etiquettes' => $this->etiquettes($champs['etiquettes'] ?? null),
            'statut' => 'valide',
            'classement' => ['source' => 'humain', 'confiance' => 1.0, 'preuve' => 'validation humaine', 'auteur' => $auteur, 'le' => date('c')],
        ];
        $this->depot->mettreAJour($id, $meta, $auteur);

        $emetteur = (string) ($meta['emetteur'] ?? '');
        $type = (string) ($meta['type'] ?? '');
        if ($this->habitudes !== null && $emetteur !== '' && $type !== '') {
            $this->habitudes->retenirChoix($emetteur, Habitudes::CHAMP_TYPE, $type, (string) $id);
        }
    }

    /** Validation de lot : les pièces complètes empruntent le même chemin que la validation unitaire. */
    public function validerPropositions(array $ids, string $auteur): array
    {
        $parId = [];
        foreach ($this->file() as $piece) {
            $parId[(int) $piece['id']] = $piece;
        }
        $valides = [];
        $refuses = [];
        foreach (array_values(array_unique(array_map('intval', $ids))) as $id) {
            $piece = $parId[$id] ?? null;
            if ($piece === null) { $refuses[$id] = 'pièce absente de la file'; continue; }
            $manques = [];
            if (trim((string) $piece['type']) === '') $manques[] = 'type';
            if (trim((string) $piece['emetteur']) === '') $manques[] = 'émetteur';
            if ($this->dateIso($piece['date']) === null) $manques[] = 'date';
            if ($manques !== []) { $refuses[$id] = 'proposition incomplète : ' . implode(', ', $manques); continue; }
            $this->valider($id, ['type' => $piece['type'], 'emetteur' => $piece['emetteur'], 'titre' => $piece['titre'],
                'date' => $piece['date'], 'montant' => $piece['montant'], 'etiquettes' => $piece['etiquettes']], $auteur);
            $valides[] = $id;
        }

        return ['valides' => $valides, 'refuses' => $refuses];
    }

    /** Identifiant du document qui suit celui-ci dans la file (recalculée après validation), ou null. */
    public function suivant(int $id): ?int
    {
        $ids = [];
        foreach ($this->file() as $ligne) {
            $ids[] = $ligne['id'];
        }
        $rang = array_search($id, $ids, true);
        if ($rang === false) {
            return $ids[0] ?? null;
        }

        return $ids[$rang + 1] ?? null;
    }

    /** Compteur de la file réelle de CE fonds : la source unique du badge « À traiter » du rail. */
    public function compterAControler(): int
    {
        return count($this->docsAControler());
    }

    /** @return list<array<string, mixed>> */
    private function file(): array
    {
        return array_map(fn (array $doc): array => $this->ligne($doc), $this->docsAControler());
    }

    /**
     * La file des documents `a_controler`, lue par une requête indexée et bornée : le travail est
     * proportionnel à la FILE, jamais à la taille du fonds. Une ligne dont le fichier a disparu est
     * ignorée (le dossier fait foi) ; l'ordre reste celui de l'écran (plus ancien d'abord).
     *
     * @return list<array{id:int, absolu:string, ligne:array<string, mixed>, meta:array<string, mixed>}>
     */
    private function docsAControler(): array
    {
        if ($this->depot === null) {
            return [];
        }
        try {
            $lignes = $this->depot instanceof DepotFichiers
                ? $this->depot->listerParStatut('a_controler', self::FILE_MAX)
                : array_values(array_filter($this->depot->lister(),
                    static fn (array $ligne): bool => ($ligne['statut'] ?? 'a_controler') === 'a_controler'));
        } catch (Throwable) {
            // Base muette (table absente, liaison perdue) : l'écran reste lisible, jamais un 500 muet.
            return [];
        }

        $docs = [];
        foreach ($lignes as $ligne) {
            $document = $this->documentDepuisLigne($ligne);
            if ($document !== null) {
                $docs[] = $document;
            }
        }
        usort($docs, static function (array $a, array $b): int {
            return [self::cleDate($a), $a['id']] <=> [self::cleDate($b), $b['id']];
        });

        return $docs;
    }

    /**
     * Résout une pièce explicitement demandée, avec la même preuve disque que la file. La limite de
     * la liste ne peut donc jamais changer la cible d'un geste d'édition.
     *
     * @return array<string, mixed>|null
     */
    private function ligneDuDocumentAControler(int $id): ?array
    {
        if ($this->depot === null || $id < 1) {
            return null;
        }
        try {
            $ligne = $this->depot->fiche($id);
        } catch (Throwable) {
            return null;
        }
        if ($ligne === null || ($ligne['statut'] ?? '') !== 'a_controler' || ($ligne['supprime_le'] ?? null) !== null) {
            return null;
        }
        $document = $this->documentDepuisLigne($ligne);

        return $document === null ? null : $this->ligne($document);
    }

    /**
     * Les pièces sœurs sont cherchées par leur lot afin que fusionner reste disponible après une
     * scission, même lorsqu'une tranche de file est précédée par des index sans fichier.
     *
     * @param list<array<string, mixed>> $file
     * @return list<array<string, mixed>>
     */
    private function piecesDuLot(int $lot, array $file): array
    {
        $pieces = $file;
        if ($this->pdo === null || $lot < 1) {
            return $pieces;
        }
        try {
            $requete = $this->pdo->prepare("SELECT * FROM documents WHERE supprime_le IS NULL AND statut = 'a_controler'"
                . " AND JSON_UNQUOTE(JSON_EXTRACT(meta, '$.origine.lot')) = ? ORDER BY id ASC");
            $requete->execute([(string) $lot]);
            foreach ($requete->fetchAll() as $ligne) {
                $document = $this->documentDepuisLigne($ligne);
                if ($document === null) {
                    continue;
                }
                $piece = $this->ligne($document);
                $pieces[(int) $piece['id']] = $piece;
            }
        } catch (Throwable) {
            // La file déjà résolue reste utilisable si une base ancienne ne sait pas lire le JSON.
        }

        return array_values($pieces);
    }

    /** @return array{id:int, absolu:string, ligne:array<string, mixed>, meta:array<string, mixed>}|null */
    private function documentDepuisLigne(array $ligne): ?array
    {
        if ($this->depot === null) {
            return null;
        }
        $relatif = str_replace('\\', '/', (string) ($ligne['chemin_relatif'] ?? ''));
        if ($relatif === '' || str_contains($relatif, '..')) {
            return null;
        }
        try {
            $absolu = $this->depot->cheminAbsolu($relatif);
        } catch (Throwable) {
            return null;
        }
        if (!is_file($absolu)) {
            return null;
        }
        $meta = is_string($ligne['meta'] ?? null) ? (json_decode((string) $ligne['meta'], true) ?: []) : (array) ($ligne['meta'] ?? []);

        return ['id' => (int) $ligne['id'], 'absolu' => $absolu, 'ligne' => $ligne, 'meta' => $meta];
    }

    /** Nombre de documents vivants déjà classés (`valide`) : un COUNT indexé, pas un parcours du fonds. */
    private function compterValides(): int
    {
        if ($this->depot instanceof DepotFichiers) {
            try {
                return $this->depot->compterParStatut('valide');
            } catch (Throwable) {
                return 0;
            }
        }
        try {
            $auto = 0;
            foreach ($this->depot?->lister() ?? [] as $ligne) {
                if (($ligne['statut'] ?? '') === 'valide') {
                    $auto++;
                }
            }

            return $auto;
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * @param array{id:int, absolu:string, ligne:array<string, mixed>, meta:array<string, mixed>} $doc
     *
     * @return array<string, mixed>
     */
    private function ligne(array $doc): array
    {
        $meta = $doc['meta'];
        // « Rien d'inventé » : sans `classement` écrit à l'ingestion, il n'y a AUCUNE proposition — pas de
        // source, pas de badge de confiance, et le type reste vide (l'humain doit le choisir).
        $classement = is_array($meta['classement'] ?? null) ? $meta['classement'] : null;
        $sourceCle = is_string($classement['source'] ?? null) ? trim((string) $classement['source']) : '';
        $source = $sourceCle !== '' ? (self::SOURCES[$sourceCle] ?? ucfirst($sourceCle)) : '';
        $confiance = $sourceCle !== '' && isset($classement['confiance']) && is_numeric($classement['confiance'])
            ? (int) round(max(0.0, min(1.0, (float) $classement['confiance'])) * 100) : null;
        $raison = isset($meta['raison']) && is_string($meta['raison']) && trim($meta['raison']) !== ''
            ? trim($meta['raison'])
            : ($sourceCle === '' ? 'aucune proposition automatique' : 'à contrôler');
        $rendu = Rendu::pour($doc['id'], $doc['absolu'], $meta);
        $pages = Rendu::compterPages($meta);

        return [
            'id' => $doc['id'],
            'titre' => (string) ($meta['titre'] ?? $doc['ligne']['titre'] ?? ''),
            'emetteur' => (string) ($meta['emetteur'] ?? ''),
            'type' => isset($meta['type']) && is_string($meta['type']) ? $meta['type'] : '',
            'source' => $source,
            'source_cle' => $sourceCle,
            'confiance' => $confiance,
            'raison' => $raison,
            'preuve' => (string) ($classement['preuve'] ?? ''),
            'date' => $this->dateIso($meta['date'] ?? null) ?? '',
            'montant' => isset($meta['montant']) && is_numeric($meta['montant']) ? (float) $meta['montant'] : null,
            'etiquettes' => $this->etiquettes($meta['etiquettes'] ?? null),
            'pages' => $pages,
            'texte' => $this->lignesTexte($meta['texte'] ?? null),
            'rendu' => $rendu,
            'lot' => (int) ($meta['origine']['lot'] ?? 0),
        ];
    }

    /** @param array{meta:array<string, mixed>, ligne:array<string, mixed>} $doc */
    private static function cleDate(array $doc): string
    {
        $date = $doc['meta']['date'] ?? null;
        if (is_string($date) && preg_match('#^\d{4}-\d{2}-\d{2}$#', $date) === 1) {
            return $date;
        }
        $cree = (string) ($doc['ligne']['cree_le'] ?? '');

        return strlen($cree) >= 10 ? substr($cree, 0, 10) : '9999-12-31';
    }

    /** @return array{en_cours:int, lots:int} */
    private function enCours(): array
    {
        if ($this->pdo === null) {
            return ['en_cours' => 0, 'lots' => 0];
        }
        try {
            $enCours = (int) $this->pdo->query("SELECT COUNT(*) FROM ingestion_travaux WHERE etat IN ('recu','decoupe','pret')")->fetchColumn();
            $lots = (int) $this->pdo->query("SELECT COUNT(DISTINCT CASE WHEN parent_id = 0 THEN id ELSE parent_id END) FROM ingestion_travaux WHERE etat IN ('recu','decoupe','pret')")->fetchColumn();
        } catch (Throwable) {
            return ['en_cours' => 0, 'lots' => 0];
        }

        return ['en_cours' => $enCours, 'lots' => $lots > 0 ? $lots : $enCours];
    }

    /** @return array<string, mixed> */
    private function courantVide(): array
    {
        return [
            'id' => 0, 'titre' => '', 'emetteur' => '', 'type' => '', 'source' => '', 'source_cle' => '',
            'confiance' => null, 'raison' => 'aucun document à contrôler', 'preuve' => '', 'date' => '',
            'montant' => null, 'etiquettes' => [], 'pages' => 0, 'texte' => [], 'rendu' => null, 'lot' => 0,
        ];
    }

    /** @return list<string> */
    private function types(string $courant): array
    {
        $types = [];
        if ($this->pdo !== null) {
            try {
                $types = (new Nomenclature($this->pdo))->types();
            } catch (Throwable) {
                $types = [];
            }
        }
        if ($types === []) {
            $types = Maquette::TYPES;
        }
        if ($courant !== '' && !in_array($courant, $types, true)) {
            array_unshift($types, $courant);
        }

        return array_values(array_unique($types));
    }

    private function seuilPourcent(): int
    {
        return (int) round(max(0.0, min(1.0, (float) $this->config->get('CLASSEMENT_SEUIL', '0.8'))) * 100);
    }

    private function champ(mixed $valeur): ?string
    {
        if (!is_string($valeur)) {
            return null;
        }
        $valeur = trim($valeur);

        return $valeur !== '' ? $valeur : null;
    }

    private function dateIso(mixed $valeur): ?string
    {
        return is_string($valeur) && preg_match('#^(\d{4})-(\d{2})-(\d{2})$#', $valeur, $m) === 1
            && checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $valeur : null;
    }

    /** @return list<string> */
    private function etiquettes(mixed $valeur): array
    {
        $liste = is_array($valeur) ? $valeur : (is_string($valeur) ? explode(',', $valeur) : []);

        return array_values(array_filter(array_map(static fn ($e): string => trim((string) $e), $liste), static fn (string $e): bool => $e !== ''));
    }

    /** @return list<string> */
    private function lignesTexte(mixed $texte): array
    {
        if (is_array($texte)) {
            $lignes = array_map('strval', $texte);
        } elseif (is_string($texte) && trim($texte) !== '') {
            $lignes = preg_split('/\R/u', $texte) ?: [];
        } else {
            return [];
        }

        return array_values(array_filter(array_map('trim', $lignes), static fn (string $l): bool => $l !== ''));
    }
}
