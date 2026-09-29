<?php

declare(strict_types=1);

namespace Ged\Socle;

use Ged\Base;
use Ged\Config;
use Ged\Contrats\Depot;
use PDO;

/**
 * Le fonds documentaire : chaque document est un fichier lisible sans l'application, ses métadonnées dans
 * `<fichier>.meta.json` à côté, ses versions dans `.versions/<fichier>/`. La base n'est qu'un index :
 * tout ce qui doit survivre à une reconstruction vit dans le dossier (le dossier fait foi).
 *
 * Le nom du fichier est fixé AU DÉPÔT (issu du titre ou du nom d'origine) : changer un titre ne déplace
 * jamais un document, seul le rangement année/type/émetteur bouge. La trace des déplacements suit.
 */
final class DepotFichiers implements Depot
{
    /** Ordre canonique des clés connues du `.meta.json` ; les clés des autres secteurs passent après, telles quelles. */
    private const CLES_CONNUES = ['titre', 'type', 'emetteur', 'date', 'montant', 'etiquettes', 'statut', 'source'];

    private readonly string $racine;
    private readonly PDO $pdo;

    public function __construct(private readonly Config $config)
    {
        $this->racine = rtrim(str_replace('\\', '/', $config->chemin('STORAGE_ROOT', 'storage/documents')), '/');
        $this->pdo = Base::pdo($config);
    }

    /** Racine documentaire absolue (séparateurs /). */
    public function racine(): string
    {
        return $this->racine;
    }

    /** Chemin absolu d'un chemin relatif du fonds ; refuse toute évasion (`..`). */
    public function cheminAbsolu(string $cheminRelatif): string
    {
        $cheminRelatif = str_replace('\\', '/', $cheminRelatif);
        if (str_starts_with($cheminRelatif, '/') || str_contains($cheminRelatif, '..')) {
            throw new \InvalidArgumentException("chemin hors du fonds : $cheminRelatif");
        }

        return $this->racine . '/' . $cheminRelatif;
    }

    public function deposer(string $cheminFichier, array $metadonnees): int
    {
        if (!is_file($cheminFichier)) {
            throw new \RuntimeException("dépôt : fichier source introuvable ($cheminFichier)");
        }
        $meta = $this->metaDeDepot($metadonnees, $cheminFichier);
        $nom = Fichiers::nomRangé((string) $meta['titre'], pathinfo($cheminFichier, PATHINFO_EXTENSION));
        $relatif = $this->cheminRangement($meta) . '/' . $nom;

        $cible = Fichiers::copierAvecMeta($cheminFichier, $this->cheminAbsolu($relatif), Fichiers::json($meta, true));
        $relatif = substr($cible, strlen($this->racine) + 1);

        $id = $this->enregistrer($relatif, $meta);

        // La version 1 est posée dès le dépôt : toute modification future aura une base de comparaison.
        $this->archiverVersion($id, $cible, Fichiers::empreinte($cible), (int) filesize($cible));

        return $id;
    }

    /**
     * Insère la ligne d'index d'un document DÉJÀ rangé dans le fonds (dépôt, indexeur d'un fichier posé à la
     * main, reconstruction). L'empreinte, la taille et le MIME sont lus du fichier : le dossier fait foi.
     */
    public function enregistrer(string $cheminRelatif, array $meta): int
    {
        $absolu = $this->cheminAbsolu($cheminRelatif);
        [$entetes, $valeurs] = Fichiers::colonnesIndex($meta);
        $this->pdo->prepare('INSERT INTO documents (chemin_relatif, nom, empreinte, taille, mime, meta, '
            . implode(', ', $entetes) . ') VALUES (?, ?, ?, ?, ?, ?, ' . rtrim(str_repeat('?, ', count($valeurs)), ', ') . ')')
            ->execute([$cheminRelatif, basename($cheminRelatif), Fichiers::empreinte($absolu), (int) filesize($absolu),
                Fichiers::mime($absolu), Fichiers::json($meta), ...$valeurs]);

        return (int) $this->pdo->lastInsertId();
    }

    public function mettreAJour(int $idDocument, array $metadonnees, string $auteur): void
    {
        $ligne = $this->fiche($idDocument);
        if ($ligne === null) {
            throw new \RuntimeException("mise à jour : document $idDocument inconnu");
        }
        if ($ligne['supprime_le'] !== null) {
            throw new \RuntimeException("mise à jour : le document $idDocument est à la corbeille, le restaurer d’abord");
        }

        $meta = $this->fusionner($ligne['meta'], $metadonnees);   // fiche() livre déjà le meta décodé
        $ancienRel = (string) $ligne['chemin_relatif'];
        // Le nom du fichier ne bouge pas (fixé au dépôt) : seul le rangement peut changer.
        $nouveauRel = $this->cheminRangement($meta) . '/' . basename($ancienRel);

        if ($nouveauRel !== $ancienRel) {
            $absolu = Fichiers::deplacerAvecAnnexes($this->cheminAbsolu($ancienRel), $this->cheminAbsolu($nouveauRel));
            $nouveauRel = substr($absolu, strlen($this->racine) + 1);
            $this->tracer($idDocument, $ancienRel, $nouveauRel, 'mise_a_jour', $auteur);
        }
        file_put_contents($this->cheminAbsolu($nouveauRel) . '.meta.json', Fichiers::json($meta, true));

        [$entetes, $valeurs] = Fichiers::colonnesIndex($meta);
        $this->pdo->prepare('UPDATE documents SET chemin_relatif = ?, nom = ?, meta = ?, '
            . implode(' = ?, ', $entetes) . ' = ? WHERE id = ?')
            ->execute([$nouveauRel, basename($nouveauRel), Fichiers::json($meta), ...$valeurs, $idDocument]);
    }

    /**
     * Corbeille : le document (fichier, `.meta.json`, `.versions/`) part sous `.corbeille/` en gardant son
     * arborescence, la ligne est simplement drapeautée. Rien n'est jamais effacé. La ligne garde son
     * `chemin_relatif` dans le fonds : c'est SA place, où la restauration la remettra.
     */
    public function miseALaCorbeille(int $idDocument, string $auteur): void
    {
        $ligne = $this->fiche($idDocument);
        if ($ligne === null) {
            throw new \RuntimeException("corbeille : document $idDocument inconnu");
        }
        if ($ligne['supprime_le'] !== null) {
            return;   // déjà à la corbeille : l'opération est idempotente
        }
        $relatif = (string) $ligne['chemin_relatif'];
        Fichiers::deplacerAvecAnnexes($this->cheminAbsolu($relatif), $this->cheminCorbeille($relatif));
        Fichiers::cacher($this->racine . '/.corbeille');
        $this->pdo->prepare('UPDATE documents SET supprime_le = NOW(), supprime_par = ? WHERE id = ?')
            ->execute([$auteur, $idDocument]);
    }

    /** Sort le document de la corbeille : retour à sa place dans le fonds, drapeau levé, contenu intact. */
    public function restaurer(int $idDocument): void
    {
        $ligne = $this->fiche($idDocument);
        if ($ligne === null) {
            throw new \RuntimeException("restauration : document $idDocument inconnu");
        }
        if ($ligne['supprime_le'] === null) {
            throw new \RuntimeException("restauration : le document $idDocument n’est pas à la corbeille");
        }
        $relatif = (string) $ligne['chemin_relatif'];
        $absoluCorbeille = $this->cheminCorbeille($relatif);
        if (!is_file($absoluCorbeille)) {
            throw new \RuntimeException("restauration : fichier introuvable dans la corbeille ($relatif)");
        }
        $absolu = Fichiers::deplacerAvecAnnexes($absoluCorbeille, $this->cheminAbsolu($relatif));
        $this->pdo->prepare('UPDATE documents SET chemin_relatif = ?, nom = ?, supprime_le = NULL, supprime_par = NULL'
            . ' WHERE id = ?')->execute([substr($absolu, strlen($this->racine) + 1), basename($absolu), $idDocument]);
    }

    /** Emplacement d'un chemin du fonds une fois à la corbeille (même arborescence, sous `.corbeille/`). */
    private function cheminCorbeille(string $cheminRelatif): string
    {
        return $this->racine . '/.corbeille/' . $cheminRelatif;
    }

    /**
     * Archive le contenu actuel d'un document comme version N (copie, jamais un déplacement : le fichier
     * courant reste à sa place). Rend le numéro posé. Utilisé au dépôt (v1) et par l'indexeur (modifications
     * hors application).
     */
    public function archiverVersion(int $idDocument, string $cheminAbsolu, string $empreinte, int $taille): int
    {
        $numero = (int) $this->pdo->query('SELECT COALESCE(MAX(numero), 0) + 1 FROM document_versions WHERE document_id = '
            . $idDocument)->fetchColumn();
        $archive = Fichiers::archiver($cheminAbsolu, $numero, $empreinte);
        $this->pdo->prepare('INSERT INTO document_versions (document_id, numero, nom_archive, empreinte, taille) VALUES (?, ?, ?, ?, ?)')
            ->execute([$idDocument, $numero, basename($archive), $empreinte, $taille]);

        return $numero;
    }

    /** La ligne d'index d'un document (meta et etiquettes décodées), ou null. */
    public function fiche(int $idDocument): ?array
    {
        $ligne = $this->pdo->query('SELECT * FROM documents WHERE id = ' . $idDocument)->fetch();
        if ($ligne === false) {
            return null;
        }
        $ligne['meta'] = json_decode((string) $ligne['meta'], true) ?: [];
        $ligne['etiquettes'] = json_decode((string) ($ligne['etiquettes'] ?? 'null'), true);

        return $ligne;
    }

    /** Documents vivants (hors corbeille), par chemin : la liste de référence du fonds. */
    public function lister(): array
    {
        return $this->pdo->query('SELECT * FROM documents WHERE supprime_le IS NULL ORDER BY chemin_relatif')->fetchAll();
    }

    /** Chemin de rangement `année/type[/émetteur]` ; sans type connu : `année/À classer`. */
    private function cheminRangement(array $meta): string
    {
        $annee = isset($meta['date']) && preg_match('#^\d{4}-\d{2}-\d{2}$#', (string) $meta['date'])
            ? substr((string) $meta['date'], 0, 4) : date('Y');
        $segments = [$annee, isset($meta['type']) && $meta['type'] !== null && $meta['type'] !== ''
            ? Fichiers::assainirSegment((string) $meta['type']) : Fichiers::assainirSegment('A classer')];
        if (isset($meta['emetteur']) && $meta['emetteur'] !== null && $meta['emetteur'] !== '') {
            $segments[] = Fichiers::assainirSegment((string) $meta['emetteur']);
        }

        return implode('/', $segments);
    }

    /**
     * Métadonnées canoniques du dépôt : clés connues dans l'ordre (titre par défaut = nom d'origine),
     * puis les clés des secteurs appelants (origine, classement, texte…), telles quelles.
     */
    private function metaDeDepot(array $metadonnees, string $source): array
    {
        $metadonnees['titre'] ??= pathinfo($source, PATHINFO_FILENAME);

        return $this->fusionner([], $metadonnees);
    }

    /** Fusion : chaque clé connue présente remplace (null l'efface) ; les autres clés passent après. */
    private function fusionner(array $actuel, array $nouvelles): array
    {
        $meta = $actuel;
        foreach (self::CLES_CONNUES as $cle) {
            if (array_key_exists($cle, $nouvelles)) {
                if ($nouvelles[$cle] === null) {
                    unset($meta[$cle]);
                } else {
                    $meta[$cle] = $cle === 'montant' && is_numeric($nouvelles[$cle])
                        ? (float) $nouvelles[$cle] : $nouvelles[$cle];
                }
            }
        }
        foreach ($nouvelles as $cle => $valeur) {
            if (!in_array($cle, self::CLES_CONNUES, true)) {
                $meta[$cle] = $valeur;
            }
        }

        return $meta;
    }

    private function tracer(int $idDocument, string $avant, string $apres, string $cause, string $auteur): void
    {
        $this->pdo->prepare('INSERT INTO document_deplacements (document_id, chemin_avant, chemin_apres, cause, auteur)'
            . ' VALUES (?, ?, ?, ?, ?)')->execute([$idDocument, $avant, $apres, $cause, $auteur]);
    }
}
