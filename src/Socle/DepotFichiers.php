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
     * Le fichier peut vivre ailleurs que sous son chemin du fonds (corbeille, reconstruction) : passer $absolu.
     *
     * @param array<string, mixed> $meta
     */
    public function enregistrer(string $cheminRelatif, array $meta, ?string $absolu = null): int
    {
        $absolu ??= $this->cheminAbsolu($cheminRelatif);
        [$entetes, $valeurs] = Fichiers::colonnesIndex($meta);
        $this->pdo->prepare('INSERT INTO documents (chemin_relatif, nom, empreinte, taille, mime, meta, '
            . implode(', ', $entetes) . ') VALUES (?, ?, ?, ?, ?, ?, ' . rtrim(str_repeat('?, ', count($valeurs)), ', ') . ')')
            ->execute([$cheminRelatif, basename($cheminRelatif), Fichiers::empreinte($absolu), (int) filesize($absolu),
                Fichiers::mime($absolu), Fichiers::json($meta), ...$valeurs]);

        $id = (int) $this->pdo->lastInsertId();
        // Rattachement au dossier de droits (secteur Droits/Sécurité, lot R1) : sans lui, la garde
        // refuse l'aperçu de TOUT document rangé. Le dossier est créé à la volée depuis le chemin.
        $this->rattacherDossierDeDroits($cheminRelatif);

        return $id;
    }

    /** Insère la ligne d'une archive de version DÉJÀ posée sur le disque (reconstruction). */
    public function poserVersion(int $idDocument, int $numero, string $nomArchive, string $absoluArchive): void
    {
        $this->pdo->prepare('INSERT INTO document_versions (document_id, numero, nom_archive, empreinte, taille)'
            . ' VALUES (?, ?, ?, ?, ?)')
            ->execute([$idDocument, $numero, $nomArchive, Fichiers::empreinte($absoluArchive), (int) filesize($absoluArchive)]);
    }

    public function mettreAJour(int $idDocument, array $metadonnees, string $auteur, ?int $compte = null, bool $administrateur = false): void
    {
        $ligne = $this->fiche($idDocument);
        if ($ligne === null) {
            throw new \RuntimeException("mise à jour : document $idDocument inconnu");
        }
        if ($ligne['supprime_le'] !== null) {
            throw new \RuntimeException("mise à jour : le document $idDocument est à la corbeille, le restaurer d’abord");
        }

        // Archivage légal (V2-09) : la garde vit dans le service, pas route par route. Un document scellé
        // refuse toute écriture, déplacement de rangement compris. Appel d'une ligne, signalé dans
        // docs/secteurs/archivage.md et docs/secteurs/socle.md (Demandes).
        if (class_exists(\Ged\Archivage\Garde::class)) {
            \Ged\Archivage\Garde::interdireMutation($this->cheminAbsolu((string) $ligne['chemin_relatif']), 'mise à jour', $idDocument);
        }

        $meta = $this->fusionner($ligne['meta'], $metadonnees);   // fiche() livre déjà le meta décodé
        $ancienRel = (string) $ligne['chemin_relatif'];
        // Le nom du fichier ne bouge pas (fixé au dépôt) : seul le rangement peut changer.
        $nouveauRel = $this->cheminRangement($meta) . '/' . basename($ancienRel);

        if ($nouveauRel !== $ancienRel) {
            // F10 : le contrôle vit ICI, dans le service, AVANT le premier octet déplacé. Un changement de
            // chemin exige `deposer` sur le dossier de droits d'ARRIVÉE, avec le compte de l'appelant.
            $this->exigerDroitDeDepot($nouveauRel, $compte, $administrateur);
            $absolu = Fichiers::deplacerAvecAnnexes($this->cheminAbsolu($ancienRel), $this->cheminAbsolu($nouveauRel));
            $nouveauRel = substr($absolu, strlen($this->racine) + 1);
            $this->tracer($idDocument, $ancienRel, $nouveauRel, 'mise_a_jour', $auteur);
        }
        file_put_contents($this->cheminAbsolu($nouveauRel) . '.meta.json', Fichiers::json($meta, true));

        [$entetes, $valeurs] = Fichiers::colonnesIndex($meta);
        $this->pdo->prepare('UPDATE documents SET chemin_relatif = ?, nom = ?, meta = ?, '
            . implode(' = ?, ', $entetes) . ' = ? WHERE id = ?')
            ->execute([$nouveauRel, basename($nouveauRel), Fichiers::json($meta), ...$valeurs, $idDocument]);
        // Le rangement a pu changer : le nouveau chemin doit avoir son dossier de droits.
        $this->rattacherDossierDeDroits($nouveauRel);
    }

    /**
     * F10 — toute écriture qui change le CHEMIN du document exige le niveau `deposer` sur le dossier de
     * droits d'ARRIVÉE, héritage du parent compris, refus par défaut sans règle ; administrateur
     * (permission `*`) toujours permis. Le compte vient de l'appelant, explicitement ; `null`/`< 1` reste
     * le compte synthétique des oracles, sans ACL à opposer.
     *
     * Le refus est nommé (dossier visé, niveau manquant) : `Securite\RefusDepot`. Il est levé AVANT le
     * déplacement, donc le fichier, son `.meta.json` et l'index restent intacts.
     */
    private function exigerDroitDeDepot(string $cheminArrivee, ?int $compte, bool $administrateur): void
    {
        if ($administrateur || $compte === null || $compte < 1) {
            return;
        }
        if (!class_exists(\Ged\Securite\DroitsDossiers::class)) {
            return;
        }
        $dossierVise = dirname(str_replace('\\', '/', $cheminArrivee));
        try {
            $droits = new \Ged\Securite\DroitsDossiers($this->pdo);
            $dossier = $droits->dossierPourChemin($cheminArrivee);
            if ($dossier !== null && $droits->autorise($compte, $dossier, 'deposer')) {
                return;
            }
        } catch (\PDOException $e) {
            // Schéma d'ACL absent ou illisible : on NE laisse PAS passer (la garde refuse déjà tout dans
            // ce cas). Le refus est nommé et tracé, jamais un 500 muet ni un déplacement silencieux.
            throw new \Ged\Securite\RefusDepot(sprintf(
                'droits insuffisants : le dossier « %s » exige le niveau « deposer » à l’arrivée (contrôle des droits indisponible : %s)',
                $dossierVise, $e->getMessage()
            ), 0, $e);
        }

        throw new \Ged\Securite\RefusDepot(sprintf(
            'droits insuffisants : le dépôt dans le dossier « %s » exige le niveau « deposer » à l’arrivée',
            $dossierVise
        ));
    }

    /**
     * Reporte dans l'index un `.meta.json` déjà écrit à côté du fichier (champs personnalisés, V2-04) :
     * la colonne `meta` recopie le dossier — sans déplacer la pièce ni toucher au rangement. Ligne
     * vivante seulement : un document à la corbeille ou disparu ne change pas.
     *
     * @param array<string, mixed> $meta
     */
    public function rapporterMeta(string $cheminRelatif, array $meta): void
    {
        $this->pdo->prepare('UPDATE documents SET meta = ? WHERE chemin_relatif = ? AND supprime_le IS NULL')
            ->execute([Fichiers::json($meta), $cheminRelatif]);
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
        // Archivage légal (V2-09) : un document scellé ne part JAMAIS à la corbeille. Appel d'une ligne, signalé.
        if (class_exists(\Ged\Archivage\Garde::class)) {
            \Ged\Archivage\Garde::interdireMutation($this->cheminAbsolu((string) $ligne['chemin_relatif']), 'corbeille', $idDocument);
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
        $this->rattacherDossierDeDroits($relatif);
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

    /**
     * Remplace le contenu courant par un fichier déjà reçu, sans jamais perdre un octet : le contenu
     * sortant est présent dans `.versions/`, le nouveau devient immédiatement la version suivante.
     * Le nom rangé et ses métadonnées restent les mêmes ; changer de format demanderait une décision de
     * rangement distincte et est donc refusé plutôt que de servir un PNG sous une extension PDF.
     */
    public function remplacerContenu(int $idDocument, string $nouveauFichier, string $auteur): int
    {
        $ligne = $this->fiche($idDocument);
        if ($ligne === null || $ligne['supprime_le'] !== null) {
            throw new \RuntimeException("nouvelle version : document $idDocument introuvable ou à la corbeille");
        }
        if (!is_file($nouveauFichier)) {
            throw new \RuntimeException('nouvelle version : fichier téléversé introuvable');
        }
        $absolu = $this->cheminAbsolu((string) $ligne['chemin_relatif']);
        if (class_exists(\Ged\Archivage\Garde::class)) {
            \Ged\Archivage\Garde::interdireMutation($absolu, 'nouvelle version', $idDocument);
        }
        $mimeActuel = Fichiers::mime($absolu);
        if (Fichiers::mime($nouveauFichier) !== $mimeActuel) {
            throw new \RuntimeException("nouvelle version : le type du fichier doit rester $mimeActuel");
        }

        $empreinteActuelle = Fichiers::empreinte($absolu);
        $existe = $this->pdo->prepare('SELECT 1 FROM document_versions WHERE document_id = ? AND empreinte = ? LIMIT 1');
        $existe->execute([$idDocument, $empreinteActuelle]);
        if ($existe->fetchColumn() === false) {
            $this->archiverVersion($idDocument, $absolu, $empreinteActuelle, (int) filesize($absolu));
        }

        // La copie temporaire reste dans le même dossier : aucune version partiellement écrite n'est exposée.
        $temporaire = dirname($absolu) . '/.' . basename($absolu) . '.v-' . bin2hex(random_bytes(6)) . '.tmp';
        if (!copy($nouveauFichier, $temporaire)) {
            throw new \RuntimeException('nouvelle version : copie temporaire impossible');
        }
        if (!rename($temporaire, $absolu)) {
            @unlink($temporaire);
            throw new \RuntimeException('nouvelle version : remplacement du fichier impossible');
        }
        $empreinte = Fichiers::empreinte($absolu);
        $taille = (int) filesize($absolu);
        $this->pdo->prepare('UPDATE documents SET empreinte = ?, taille = ?, mime = ? WHERE id = ?')
            ->execute([$empreinte, $taille, Fichiers::mime($absolu), $idDocument]);

        return $this->archiverVersion($idDocument, $absolu, $empreinte, $taille);
    }

    /**
     * Rétablit une archive comme NOUVELLE version du document. Le contenu courant n'est jamais
     * écrasé sans archive : `remplacerContenu()` le conserve, puis archive le contenu rétabli sous
     * le numéro suivant. La trace de restauration rend l'acte lisible dans l'historique.
     */
    public function restaurerVersion(int $idDocument, int $numero, string $auteur): int
    {
        if ($numero < 1) {
            throw new \RuntimeException('restauration : numéro de version invalide');
        }
        $ligne = $this->fiche($idDocument);
        if ($ligne === null || $ligne['supprime_le'] !== null) {
            throw new \RuntimeException("restauration : document $idDocument introuvable ou à la corbeille");
        }
        $version = $this->pdo->prepare('SELECT nom_archive FROM document_versions WHERE document_id = ? AND numero = ?');
        $version->execute([$idDocument, $numero]);
        $nomArchive = $version->fetchColumn();
        $absolu = $this->cheminAbsolu((string) $ligne['chemin_relatif']);
        $archive = Fichiers::dossierVersions($absolu) . '/' . (string) $nomArchive;
        if ($nomArchive === false || !is_file($archive)) {
            throw new \RuntimeException("restauration : archive v$numero introuvable");
        }
        if (class_exists(\Ged\Archivage\Garde::class)) {
            \Ged\Archivage\Garde::interdireMutation($absolu, 'restaurer une version', $idDocument);
        }

        $nouvelle = $this->remplacerContenu($idDocument, $archive, $auteur);
        $this->tracer($idDocument, (string) $ligne['chemin_relatif'], (string) $ligne['chemin_relatif'], 'restauration', $auteur);

        return $nouvelle;
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

    /**
     * Lignes vivantes d'un statut, bornées : la requête écarte les suppressions ET les absences avant
     * sa limite. Le décalage permet à l'écran de compléter sa file si une ancienne ligne sans drapeau
     * pointe encore vers un fichier disparu. Le tri reprend l'ordre de l'écran (plus ancien d'abord).
     *
     * @return list<array<string, mixed>>
     */
    public function listerParStatut(string $statut, int $limite = 1000, int $decalage = 0): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM documents WHERE statut = ? AND supprime_le IS NULL'
            . ' AND absent_depuis IS NULL ORDER BY date_document ASC, id ASC LIMIT '
            . max(1, $limite) . ' OFFSET ' . max(0, $decalage));
        $stmt->execute([$statut]);

        return $stmt->fetchAll();
    }

    /** Nombre de lignes vivantes d'un statut (COUNT indexé, sans charger le fonds en mémoire). */
    public function compterParStatut(string $statut): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM documents WHERE statut = ? AND supprime_le IS NULL'
            . ' AND absent_depuis IS NULL');
        $stmt->execute([$statut]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Chemin de rangement `année/type[/émetteur]` ; sans type connu : `année/À classer`.
     * Un `dossier` explicite (dépôt direct par l'API dans un dossier autorisé) l'emporte, tel quel.
     * @param array<string, mixed> $meta
     */
    private function cheminRangement(array $meta): string
    {
        if (isset($meta['dossier']) && is_string($meta['dossier'])) {
            $dossier = trim(str_replace('\\', '/', $meta['dossier']), '/');
            if ($dossier !== '' && !str_contains($dossier, '..')) {
                return $dossier;
            }
        }
        // Pas de date crédible, pas d'année inventée : une pièce sans date attend sous Sans_date.
        $annee = isset($meta['date']) && preg_match('#^\d{4}-\d{2}-\d{2}$#', (string) $meta['date'])
            ? substr((string) $meta['date'], 0, 4) : 'Sans_date';
        $segments = [$annee, isset($meta['type']) && $meta['type'] !== ''
            ? Fichiers::assainirSegment((string) $meta['type']) : Fichiers::assainirSegment('A classer')];
        if (isset($meta['emetteur']) && $meta['emetteur'] !== ''
            && self::emetteurPeutCreerDossier($meta)) {
            $segments[] = Fichiers::assainirSegment((string) $meta['emetteur']);
        }

        return implode('/', $segments);
    }

    /**
     * Défense en profondeur au dépôt : une proposition automatique ne peut pas transformer une phrase
     * OCR en dossier. Un émetteur enregistré par la validation humaine reste, lui, une source crédible.
     * Le filtre détaillé vit dans Classement ; ce garde-fou protège aussi les pièces à contrôler.
     * @param array<string, mixed> $meta
     */
    private static function emetteurPeutCreerDossier(array $meta): bool
    {
        $source = (string) (($meta['classement']['source'] ?? ''));
        if ($source === '' || $source === 'humain') {
            return true;
        }
        $nom = trim((string) $meta['emetteur']);
        $normalise = mb_strtolower($nom, 'UTF-8');
        if (mb_strlen($nom) < 3 || mb_strlen($nom) > 100 || preg_match('/\d|@|https?:/u', $nom) === 1
            || preg_match('/^(si|la|le|les|nous|vous|nom du destinataire|adresse|page|de|du|des|en|pour|avec|a|au|aux|chez|par|sur)\b/iu', $normalise) === 1) {
            return false;
        }
        $mots = preg_split('/\s+/', $normalise) ?: [];
        if (count($mots) < 2 || preg_match('/^(service\s+(?:relation\s+)?client(?:ele|s)?|contact\s+cent(?:er|re)|contact\s+cent(?:er|re)\s+service)\b/iu', $normalise) === 1) {
            return false;
        }

        return preg_match('/\b(sa|sarl|sagl|ag|gmbh|association|fondation|commune|canton|service|office|banque|assurance|energie|energies|poste)\b/iu', $normalise) === 1;
    }

    /**
     * Métadonnées canoniques du dépôt : clés connues dans l'ordre (titre par défaut = nom d'origine),
     * puis les clés des secteurs appelants (origine, classement, texte…), telles quelles.
     *
     * @param array<string, mixed> $metadonnees
     * @return array<string, mixed>
     */
    private function metaDeDepot(array $metadonnees, string $source): array
    {
        $metadonnees['titre'] ??= pathinfo($source, PATHINFO_FILENAME);

        return $this->fusionner([], $metadonnees);
    }

    /**
     * Fusion : chaque clé connue présente remplace (null l'efface) ; les autres clés passent après.
     *
     * @param array<string, mixed> $actuel
     * @param array<string, mixed> $nouvelles
     * @return array<string, mixed>
     */
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

    /**
     * Rattache le document rangé à son dossier de droits (secteur Droits/Sécurité, lot R1) : chaque
     * niveau de son chemin devient un dossier ACL, enfant du précédent. Le Socle ne dépend pas du
     * secteur Droits (garde `class_exists`) ; une base sans le schéma de sécurité ne fait jamais
     * échouer un dépôt — le dossier de droits sera posé par l'indexeur ou la reconstruction F12.
     */
    private function rattacherDossierDeDroits(string $cheminRelatif): void
    {
        if (!class_exists(\Ged\Securite\DroitsDossiers::class)) {
            return;
        }
        try {
            (new \Ged\Securite\DroitsDossiers($this->pdo))->assurerDossierPourChemin($cheminRelatif);
        } catch (\PDOException) {
            // Schéma d'ACL absent (base en cours d'installation, test d'un autre secteur) : le dossier
            // fait foi, l'indexeur rattachera plus tard. On ne masque pas les autres erreurs.
        }
    }
}
