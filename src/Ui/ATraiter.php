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

    private readonly ?Depot $depot;
    private readonly ?Habitudes $habitudes;

    /** @var list<array{id:int, absolu:string, ligne:array<string, mixed>, meta:array<string, mixed>}>|null */
    private ?array $memoireDocuments = null;

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
        $auto = 0;
        foreach ($this->documents() as $doc) {
            if ($this->statut($doc) === 'valide') {
                $auto++;
            }
        }
        $file = $this->file();

        $choisi = (int) ($parametres['doc'] ?? 0);
        $courant = null;
        foreach ($file as $ligne) {
            if ($ligne['id'] === $choisi) {
                $courant = $ligne;
                break;
            }
        }
        $courant ??= $file[0] ?? $this->courantVide();
        $enCours = $this->enCours();

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

        $meta = [
            'titre' => $this->champ($champs['titre'] ?? null) ?? (string) ($fiche['titre'] ?? $fiche['nom'] ?? ''),
            'type' => $this->champ($champs['type'] ?? null),
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

    /** @return list<array<string, mixed>> */
    private function file(): array
    {
        return array_map(fn (array $doc): array => $this->ligne($doc), $this->docsAControler());
    }

    /**
     * @return list<array{id:int, absolu:string, ligne:array<string, mixed>, meta:array<string, mixed>}>
     */
    private function docsAControler(): array
    {
        $docs = [];
        foreach ($this->documents() as $doc) {
            if ($this->statut($doc) === 'a_controler') {
                $docs[] = $doc;
            }
        }
        usort($docs, static function (array $a, array $b): int {
            return [self::cleDate($a), $a['id']] <=> [self::cleDate($b), $b['id']];
        });

        return $docs;
    }

    /**
     * Documents vivants du fonds, lus par le dépôt : une ligne d'index dont le fichier a disparu est
     * ignorée (le dossier fait foi, jamais un index périmé). Mémoïsé pour un rendu en une lecture.
     *
     * @return list<array{id:int, absolu:string, ligne:array<string, mixed>, meta:array<string, mixed>}>
     */
    private function documents(): array
    {
        if ($this->memoireDocuments !== null) {
            return $this->memoireDocuments;
        }
        if ($this->depot === null) {
            return $this->memoireDocuments = [];
        }
        try {
            $documents = [];
            foreach ($this->depot->lister() as $ligne) {
                $relatif = str_replace('\\', '/', (string) ($ligne['chemin_relatif'] ?? ''));
                if ($relatif === '' || str_contains($relatif, '..')) {
                    continue;
                }
                try {
                    $absolu = $this->depot->cheminAbsolu($relatif);
                } catch (Throwable) {
                    continue;
                }
                if (!is_file($absolu)) {
                    continue;
                }
                $meta = is_string($ligne['meta'] ?? null) ? (json_decode((string) $ligne['meta'], true) ?: []) : (array) ($ligne['meta'] ?? []);
                $documents[] = ['id' => (int) $ligne['id'], 'absolu' => $absolu, 'ligne' => $ligne, 'meta' => $meta];
            }

            return $this->memoireDocuments = $documents;
        } catch (Throwable) {
            // Base muette (table absente, liaison perdue) : l'écran reste lisible, jamais un 500 muet.
            return $this->memoireDocuments = [];
        }
    }

    /** @param array{meta:array<string, mixed>, ligne:array<string, mixed>} $doc */
    private function statut(array $doc): string
    {
        $statut = $doc['meta']['statut'] ?? $doc['ligne']['statut'] ?? 'a_controler';

        return is_string($statut) && $statut !== '' ? $statut : 'a_controler';
    }

    /**
     * @param array{id:int, absolu:string, ligne:array<string, mixed>, meta:array<string, mixed>} $doc
     *
     * @return array<string, mixed>
     */
    private function ligne(array $doc): array
    {
        $meta = $doc['meta'];
        $classement = is_array($meta['classement'] ?? null) ? $meta['classement'] : [];
        $source = (string) ($classement['source'] ?? 'regle');
        $confiance = (float) ($classement['confiance'] ?? 0.0);
        $rendu = $this->rendu($doc);
        $pages = $this->pages($meta, $rendu);

        return [
            'id' => $doc['id'],
            'titre' => (string) ($meta['titre'] ?? $doc['ligne']['titre'] ?? ''),
            'emetteur' => (string) ($meta['emetteur'] ?? ''),
            'type' => isset($meta['type']) && is_string($meta['type']) ? $meta['type'] : '',
            'source' => self::SOURCES[$source] ?? ucfirst($source),
            'source_cle' => $source,
            'confiance' => (int) round(max(0.0, min(1.0, $confiance)) * 100),
            'raison' => (string) ($meta['raison'] ?? 'à contrôler'),
            'preuve' => (string) ($classement['preuve'] ?? ''),
            'date' => $this->dateIso($meta['date'] ?? null) ?? '',
            'montant' => isset($meta['montant']) && is_numeric($meta['montant']) ? (float) $meta['montant'] : null,
            'etiquettes' => $this->etiquettes($meta['etiquettes'] ?? null),
            'pages' => $pages,
            'texte' => $this->lignesTexte($meta['texte'] ?? null),
            'rendu' => $rendu,
        ];
    }

    /**
     * Ce que la visionneuse du secteur Aperçu doit charger (macro `visionneuse.moteur`), construit depuis
     * le chemin indexé. Le fichier est présent (vérifié plus haut) ; un format non rendable donne un
     * message nommé, jamais un cadre vide.
     *
     * @param array{id:int, absolu:string, meta:array<string, mixed>, ligne:array<string, mixed>} $doc
     *
     * @return array{url:string, categorie:string, pages:int, titre:string, message:string}
     */
    private function rendu(array $doc): array
    {
        $extension = strtolower(pathinfo($doc['absolu'], PATHINFO_EXTENSION));
        $categorie = \Ged\Apercu\Source::categorie($extension);
        $pages = $this->pages($doc['meta'], null);
        $titre = (string) ($doc['meta']['titre'] ?? $doc['ligne']['titre'] ?? '');
        $url = '/documents/' . $doc['id'] . '/apercu/fichier';

        if ($categorie === 'pdf') {
            return ['url' => $url, 'categorie' => 'pdf', 'pages' => $pages, 'titre' => $titre, 'message' => ''];
        }
        if ($categorie === 'image') {
            return ['url' => $url, 'categorie' => 'image', 'pages' => 1, 'titre' => $titre, 'message' => ''];
        }
        if ($categorie === 'bureautique') {
            if (\Ged\Apercu\Outils::libreOffice() === null) {
                return ['url' => '', 'categorie' => 'bureautique', 'pages' => $pages, 'titre' => $titre, 'message' => \Ged\Apercu\Outils::MSG_LIBREOFFICE];
            }

            return ['url' => $url, 'categorie' => 'pdf', 'pages' => $pages, 'titre' => $titre, 'message' => ''];
        }

        return ['url' => '', 'categorie' => 'inconnu', 'pages' => 0, 'titre' => $titre, 'message' => \Ged\Apercu\Outils::MSG_FORMAT];
    }

    /** @param array<string, mixed> $meta @param array<string, mixed>|null $rendu */
    private function pages(array $meta, ?array $rendu): int
    {
        if ($rendu !== null && ($rendu['pages'] ?? 0) > 0) {
            return (int) $rendu['pages'];
        }
        if (isset($meta['pages']) && is_numeric($meta['pages'])) {
            return max(1, (int) $meta['pages']);
        }
        $origine = is_array($meta['origine'] ?? null) ? $meta['origine'] : [];
        if (isset($origine['pages']) && is_numeric($origine['pages'])) {
            return max(1, (int) $origine['pages']);
        }

        return 1;
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
            'id' => 0, 'titre' => '', 'emetteur' => '', 'type' => '', 'source' => 'Règle', 'source_cle' => 'regle',
            'confiance' => 0, 'raison' => 'aucun document à contrôler', 'preuve' => '', 'date' => '',
            'montant' => null, 'etiquettes' => [], 'pages' => 0, 'texte' => [], 'rendu' => null,
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
