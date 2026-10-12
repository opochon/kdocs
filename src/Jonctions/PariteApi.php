<?php

declare(strict_types=1);

namespace Ged\Jonctions;

use Ged\Base;
use Ged\Config;
use Ged\Socle\Champs;
use Ged\Socle\ModificationMasse;
use Ged\Securite\DroitsDossiers;
use PDO;
use Psr\Http\Message\ResponseInterface as Reponse;
use Psr\Http\Message\ServerRequestInterface as Requete;
use Throwable;

/**
 * Porte HTTP des compléments de l'API de GEDv2 apportés par le lot ged-parite-api-2 (parité Paperless-ngx
 * 2.20.5). Même porte que `DocumentsApi` (jeton `Authorization: Bearer …`, hors session, portées
 * `documents_lecture` / `documents_ecriture`) : un seul endroit décide des droits (voir `ApiJeton`).
 *
 *   GET  /api/v1/champs         définitions de champs personnalisés (CustomFieldViewSet, filtres id/nom)
 *   GET  /api/v1/partages       liens de partage externes (ShareLinkFilterSet : créé/expirant)
 *   GET  /api/v1/statistiques   statistiques du fonds (StatisticsView)
 *   GET  /api/v1/selection_data référentiels de sélection (SelectionDataView)
 *   GET  /api/v1/autocomplete   suggestions de recherche (SearchAutoCompleteView)
 *   GET  /api/v1/metadonnees    métadonnées de formulaire (DocumentViewSet.metadata)
 *   GET  /api/v1/next_asn       prochain numéro d'archive (UnifiedSearchViewSet.next_asn)
 *   POST /api/v1/documents/{id}/email  envoi d'un document (DocumentViewSet.email_document)
 *   POST /api/v1/documents/email       envoi de plusieurs documents (email_documents)
 *   POST /api/v1/documents/bulk_edit   modification en masse (BulkEditObjectsView)
 *   POST /api/v1/documents/bulk_download archive ZIP des documents (BulkDownloadView)
 *   POST /api/v1/dossiers/tester       essai d'un dossier (StoragePathViewSet.test)
 *
 * Toute erreur est un JSON `{"erreur":{"etape","cause","reference"}}` : une erreur se nomme, jamais un 500 muet.
 */
final class PariteApi
{
    private readonly PDO $pdo;

    public function __construct(
        private readonly Config $config,
        private readonly ApiJeton $apiJeton,
        private readonly Mailer $mailer,
        private readonly DroitsDossiers $droits,
    ) {
        $this->pdo = Base::pdo($config);
    }

    public function champs(Requete $requete, Reponse $reponse): Reponse
    {
        $etape = 'GET /api/v1/champs';
        try {
            $this->apiJeton->authentifier($requete, $etape, false);

            return $this->json($reponse, 200, $this->filtrerChamps((new Champs($this->config))->tous(), $requete->getQueryParams(), $etape));
        } catch (ErreurApi $e) {
            return $this->erreur($reponse, $e);
        } catch (Throwable $e) {
            return $this->echec($reponse, $etape, $e, 'les champs personnalisés n’ont pas pu être lus');
        }
    }

    public function partages(Requete $requete, Reponse $reponse): Reponse
    {
        $etape = 'GET /api/v1/partages';
        try {
            $session = $this->apiJeton->authentifier($requete, $etape, false);

            return $this->json($reponse, 200, $this->listerPartages($session, $requete->getQueryParams(), $etape));
        } catch (ErreurApi $e) {
            return $this->erreur($reponse, $e);
        } catch (Throwable $e) {
            return $this->echec($reponse, $etape, $e, 'les liens de partage n’ont pas pu être lus');
        }
    }

    public function statistiques(Requete $requete, Reponse $reponse): Reponse
    {
        $etape = 'GET /api/v1/statistiques';
        try {
            $session = $this->apiJeton->authentifier($requete, $etape, false);

            return $this->json($reponse, 200, $this->apiJeton->catalogue($session)->statistiques());
        } catch (ErreurApi $e) {
            return $this->erreur($reponse, $e);
        } catch (Throwable $e) {
            return $this->echec($reponse, $etape, $e, 'les statistiques n’ont pas pu être lues');
        }
    }

    public function nextAsn(Requete $requete, Reponse $reponse): Reponse
    {
        $etape = 'GET /api/v1/next_asn';
        try {
            $session = $this->apiJeton->authentifier($requete, $etape, false);

            return $this->json($reponse, 200, $this->apiJeton->catalogue($session)->prochainAsn());
        } catch (ErreurApi $e) {
            return $this->erreur($reponse, $e);
        } catch (Throwable $e) {
            return $this->echec($reponse, $etape, $e, 'le prochain numéro d’archive n’a pas pu être calculé');
        }
    }

    public function selectionData(Requete $requete, Reponse $reponse): Reponse
    {
        $etape = 'POST /api/v1/documents/selection_data';
        try {
            $session = $this->apiJeton->authentifier($requete, $etape, false);
            $corps = $requete->getParsedBody();

            return $this->json($reponse, 200, $this->apiJeton->catalogue($session)
                ->selectionData($this->identifiantsSelection(is_array($corps) ? $corps : [])));
        } catch (ErreurApi $e) {
            return $this->erreur($reponse, $e);
        } catch (Throwable $e) {
            return $this->echec($reponse, $etape, $e, 'les données de sélection n’ont pas pu être lues');
        }
    }

    public function autocomplete(Requete $requete, Reponse $reponse): Reponse
    {
        $etape = 'GET /api/v1/autocomplete';
        try {
            $session = $this->apiJeton->authentifier($requete, $etape, false);
            $criteres = $requete->getQueryParams();
            $terme = trim((string) ($criteres['terme'] ?? $criteres['term'] ?? ''));
            if ($terme === '') {
                throw new ErreurApi(400, 'terme requis (paramètre « terme »)', $etape);
            }
            $limite = (int) ($criteres['limite'] ?? $criteres['limit'] ?? 10);
            if ($limite < 1) {
                throw new ErreurApi(400, 'limite invalide (entier positif attendu)', $etape);
            }

            return $this->json($reponse, 200, $this->apiJeton->catalogue($session)->autocomplete($terme, $limite));
        } catch (ErreurApi $e) {
            return $this->erreur($reponse, $e);
        } catch (Throwable $e) {
            return $this->echec($reponse, $etape, $e, 'l’autocomplétion n’a pas pu être calculée');
        }
    }

    /** @param array<string, string> $args */
    public function metadonneesDocument(Requete $requete, Reponse $reponse, array $args): Reponse
    {
        $id = (int) ($args['id'] ?? 0);
        $etape = 'GET /api/v1/documents/' . $id . '/metadonnees';
        try {
            $session = $this->apiJeton->authentifier($requete, $etape, false);

            return $this->json($reponse, 200, $this->apiJeton->catalogue($session)->fiche($id, $etape));
        } catch (ErreurApi $e) {
            return $this->erreur($reponse, $e);
        } catch (Throwable $e) {
            return $this->echec($reponse, $etape, $e, "les métadonnées du document $id n’ont pas pu être lues");
        }
    }

    /** @param array<string, string> $args */
    public function emailDocument(Requete $requete, Reponse $reponse, array $args): Reponse
    {
        $id = (int) ($args['id'] ?? 0);

        return $this->envoyerDocuments($requete, $reponse, [$id], 'POST /api/v1/documents/' . $id . '/email');
    }

    public function emailDocuments(Requete $requete, Reponse $reponse): Reponse
    {
        $corps = $requete->getParsedBody();

        return $this->envoyerDocuments($requete, $reponse, [], 'POST /api/v1/documents/email', is_array($corps) ? $corps : []);
    }

    /**
     * Envoi d'un ou plusieurs documents par courriel (DocumentViewSet.email_document / email_documents) :
     * l'expéditeur est la GED (mécanisme d'envoi existant), chaque document est joint, et un document hors
     * dossiers fait refuser tout l'envoi. Aucun envoi réel en test (transport « fichier »).
     *
     * @param list<int> $idsForces identifiants imposés (route de détail)
     * @param array<string, mixed> $corps
     */
    private function envoyerDocuments(Requete $requete, Reponse $reponse, array $idsForces, string $etape, array $corps = []): Reponse
    {
        try {
            $session = $this->apiJeton->authentifier($requete, $etape, false);
            $body = $corps === [] && is_array($requete->getParsedBody()) ? $requete->getParsedBody() : $corps;
            $ids = $idsForces !== [] ? $idsForces : $this->identifiantsSelection($body);
            $adresses = $this->adresses($body['addresses'] ?? $body['adresses'] ?? '', $etape);
            $objet = trim((string) ($body['subject'] ?? $body['objet'] ?? 'Documents GED'));
            $message = trim((string) ($body['message'] ?? $body['texte'] ?? 'Veuillez trouver les documents en pièce jointe.'));
            if ($objet === '' || $message === '') {
                throw new ErreurApi(400, 'objet et message requis', $etape);
            }

            $catalogue = $this->apiJeton->catalogue($session);
            $pieces = [];
            foreach ($ids as $id) {
                $fichier = $catalogue->fichier($id, null, $etape);
                $contenu = file_get_contents($fichier['chemin']);
                if ($contenu === false) {
                    throw new ErreurApi(404, "le fichier du document $id a disparu du fonds", $etape);
                }
                $pieces[] = ['nom' => $fichier['nom'], 'type' => $fichier['mime'], 'contenu' => $contenu];
            }
            foreach ($adresses as $adresse) {
                $this->mailer->envoyerAvecPieces($adresse, $objet, $message, $pieces);
            }

            return $this->json($reponse, 200, ['envoye' => true, 'documents' => count($ids), 'destinataires' => $adresses]);
        } catch (ErreurApi $e) {
            return $this->erreur($reponse, $e);
        } catch (Throwable $e) {
            return $this->echec($reponse, $etape, $e, 'l’envoi du courriel n’a pas abouti');
        }
    }

    public function bulkEdit(Requete $requete, Reponse $reponse): Reponse
    {
        $etape = 'POST /api/v1/documents/bulk_edit';
        try {
            $session = $this->apiJeton->authentifier($requete, $etape, true);
            $corps = is_array($requete->getParsedBody()) ? $requete->getParsedBody() : [];
            $ids = $this->identifiantsSelection($corps);
            $action = trim((string) ($corps['action'] ?? ''));
            if ($action === '') {
                throw new ErreurApi(400, 'action requise (type, emetteur, etiquettes_ajouter, etiquettes_retirer, dossier, proprietaire, corbeille)', $etape);
            }

            try {
                $masse = new ModificationMasse($this->config, $session['compte'], $session['administrateur']);
                $tache = $masse->lancer($ids, ['action' => $action, 'valeur' => $corps['valeur'] ?? null], $session['identifiant']);
                $etat = $masse->avancer($tache['id']);
                for ($i = 0; $i < 20 && ($etat['etat'] ?? '') === 'en_cours'; ++$i) {
                    $etat = $masse->avancer($tache['id']);
                }
            } catch (\RuntimeException $e) {
                throw new ErreurApi(400, $e->getMessage(), $etape, $e);
            }

            return $this->json($reponse, 200, $etat);
        } catch (ErreurApi $e) {
            return $this->erreur($reponse, $e);
        } catch (Throwable $e) {
            return $this->echec($reponse, $etape, $e, 'la modification en masse n’a pas abouti');
        }
    }

    public function bulkDownload(Requete $requete, Reponse $reponse): Reponse
    {
        $etape = 'POST /api/v1/documents/bulk_download';
        try {
            $session = $this->apiJeton->authentifier($requete, $etape, false);
            $corps = is_array($requete->getParsedBody()) ? $requete->getParsedBody() : [];
            $ids = $this->identifiantsSelection($corps);
            $catalogue = $this->apiJeton->catalogue($session);

            $archive = new \ZipArchive();
            $fichierZip = tempnam(sys_get_temp_dir(), 'gedv2-zip-');
            if ($fichierZip === false || $archive->open($fichierZip, \ZipArchive::OVERWRITE) !== true) {
                throw new ErreurApi(500, 'archive ZIP impossible à préparer', $etape);
            }
            $noms = [];
            foreach ($ids as $id) {
                $fichier = $catalogue->fichier($id, null, $etape);
                $contenu = file_get_contents($fichier['chemin']);
                if ($contenu === false) {
                    throw new ErreurApi(404, "le fichier du document $id a disparu du fonds", $etape);
                }
                $nom = $fichier['nom'];
                if (isset($noms[$nom])) {
                    $nom = $id . '-' . $nom;
                }
                $noms[$nom] = true;
                $archive->addFromString($nom, $contenu);
            }
            $archive->close();
            $octets = file_get_contents($fichierZip);
            unlink($fichierZip);
            if ($octets === false) {
                throw new ErreurApi(500, 'archive ZIP illisible après préparation', $etape);
            }
            $reponse->getBody()->write($octets);

            return $reponse->withHeader('Content-Type', 'application/zip')
                ->withHeader('Content-Disposition', 'attachment; filename="documents.zip"')
                ->withStatus(200);
        } catch (ErreurApi $e) {
            return $this->erreur($reponse, $e);
        } catch (Throwable $e) {
            return $this->echec($reponse, $etape, $e, 'l’archive des documents n’a pas pu être préparée');
        }
    }

    public function dossierTester(Requete $requete, Reponse $reponse): Reponse
    {
        $etape = 'POST /api/v1/dossiers/tester';
        try {
            $session = $this->apiJeton->authentifier($requete, $etape, true);
            $corps = is_array($requete->getParsedBody()) ? $requete->getParsedBody() : [];
            $dossier = trim(str_replace('\\', '/', (string) ($corps['dossier'] ?? '')), '/');
            if ($dossier === '' || str_contains($dossier, '..')) {
                throw new ErreurApi(400, 'dossier requis (chemin relatif, sans « .. »)', $etape);
            }
            $idDossier = $this->droits->dossierPourChemin($dossier);
            $deposable = $idDossier !== null && $this->droits->autorise($session['compte'], $idDossier, 'deposer');

            return $this->json($reponse, 200, ['dossier' => $dossier, 'deposable' => $deposable]);
        } catch (ErreurApi $e) {
            return $this->erreur($reponse, $e);
        } catch (Throwable $e) {
            return $this->echec($reponse, $etape, $e, 'l’essai du dossier n’a pas abouti');
        }
    }

    /**
     * Adresses du courriel : liste ou chaîne « a@x, b@y » ; chacune est validée, une entrée invalide
     * refuse l'envoi (400 nommé), jamais un envoi partiel silencieux.
     *
     * @return list<string>
     * @throws ErreurApi
     */
    private function adresses(mixed $brut, string $etape): array
    {
        $adresses = [];
        foreach (is_array($brut) ? $brut : explode(',', (string) $brut) as $adresse) {
            $adresse = trim((string) $adresse);
            if ($adresse === '') {
                continue;
            }
            if (filter_var($adresse, FILTER_VALIDATE_EMAIL) === false) {
                throw new ErreurApi(400, "adresse invalide : « $adresse »", $etape);
            }
            $adresses[] = $adresse;
        }
        if ($adresses === []) {
            throw new ErreurApi(400, 'au moins une adresse destinataire est requise', $etape);
        }

        return $adresses;
    }

    /**
     * Identifiants de la sélection : `documents` (liste ou « 1,2 ») ou `ids`. Un identifiant invalide est
     * refusé 400, jamais lâché.
     *
     * @param array<string, mixed> $corps
     * @return list<int>
     * @throws ErreurApi
     */
    private function identifiantsSelection(array $corps): array
    {
        $brut = $corps['documents'] ?? $corps['ids'] ?? [];
        $ids = [];
        foreach (is_array($brut) ? $brut : explode(',', (string) $brut) as $element) {
            $element = trim((string) $element);
            if ($element === '') {
                continue;
            }
            $ids[] = (int) $this->identifiant($element, 'documents', 'POST /api/v1/documents/selection_data');
        }
        if ($ids === []) {
            throw new ErreurApi(400, 'sélection vide : « documents » (liste d’identifiants) requis',
                'POST /api/v1/documents/selection_data');
        }

        return $ids;
    }

    /**
     * Filtre des définitions de champs (CustomFieldFilterSet.id / .name) : `id`, `ids`, `nom` (égal, casse
     * ignorée) et `nom_contient` (sous-chaîne). Un identifiant invalide est refusé 400, jamais lâché.
     *
     * @param list<array{id:int, cle:string, nom:string, nature:string, options:list<string>}> $tous
     * @param array<string, mixed> $criteres
     * @return list<array{id:int, cle:string, nom:string, nature:string, options:list<string>}>
     * @throws ErreurApi
     */
    private function filtrerChamps(array $tous, array $criteres, string $etape): array
    {
        $id = $this->identifiant($criteres['id'] ?? null, 'id', $etape);
        $ids = [];
        foreach (explode(',', (string) ($criteres['ids'] ?? '')) as $brut) {
            $brut = trim($brut);
            if ($brut !== '') {
                $ids[] = (int) $this->identifiant($brut, 'ids', $etape);
            }
        }
        $nom = $this->chaine($criteres['nom'] ?? null);
        $contient = $this->chaine($criteres['nom_contient'] ?? null);

        return array_values(array_filter($tous, static fn (array $c): bool =>
            ($id === null || $c['id'] === $id)
            && ($ids === [] || in_array($c['id'], $ids, true))
            && ($nom === null || mb_strtolower($c['nom']) === mb_strtolower($nom))
            && ($contient === null || mb_stripos($c['nom'], $contient) !== false)));
    }

    /**
     * Liens de partage externes des documents LISIBLES par le compte (le partage ne révèle jamais un
     * document hors dossiers). `cree_min`/`cree_max` et `expire_min`/`expire_max` filtrent les
     * échéances (correspondance fonctionnelle de ShareLinkFilterSet.created / .expiration).
     *
     * @param array{compte:int, identifiant:string, application:string, administrateur:bool} $session
     * @param array<string, mixed> $criteres
     * @return list<array<string, mixed>>
     * @throws ErreurApi
     */
    private function listerPartages(array $session, array $criteres, string $etape): array
    {
        $where = ["p.type = 'partage'", "p.type_partage = 'externe'", "p.cible_type = 'lien'",
            'd.supprime_le IS NULL', 'd.absent_depuis IS NULL'];
        $params = [];
        foreach (['cree' => 'p.cree_le', 'expire' => 'p.expire_le'] as $prefixe => $colonne) {
            foreach (['min' => '>=', 'max' => '<='] as $borne => $operateur) {
                $valeur = $this->horodatage($criteres[$prefixe . '_' . $borne] ?? null, $prefixe . '_' . $borne, $etape);
                if ($valeur !== null) {
                    $where[] = "$colonne $operateur ?";
                    $params[] = $valeur;
                }
            }
        }
        if (!$session['administrateur']) {
            [$acl, $aclParams] = DroitsDossiers::predicatLecture('d', $session['compte']);
            $where[] = $acl;
            $params = [...$params, ...$aclParams];
        }

        $requete = $this->pdo->prepare('SELECT p.id, p.document_id document, d.titre, p.version, p.cree_le, p.expire_le, p.revoque_le'
            . ' FROM document_collaboration p INNER JOIN documents d ON d.id = p.document_id'
            . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY p.cree_le DESC, p.id DESC LIMIT 1000');
        $requete->execute($params);

        return array_map(static fn (array $l): array => [
            'id' => (int) $l['id'], 'document' => (int) $l['document'], 'titre' => (string) $l['titre'],
            'version' => $l['version'] === null ? null : (int) $l['version'],
            'cree_le' => $l['cree_le'] === null ? null : (string) $l['cree_le'],
            'expire_le' => $l['expire_le'] === null ? null : (string) $l['expire_le'],
            'revoque' => $l['revoque_le'] !== null,
        ], $requete->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @throws ErreurApi */
    private function identifiant(mixed $valeur, string $nom, string $etape): ?int
    {
        $valeur = trim((string) $valeur);
        if ($valeur === '') {
            return null;
        }
        if (preg_match('/^\d+$/', $valeur) !== 1 || (int) $valeur < 1) {
            throw new ErreurApi(400, "filtre $nom : « $valeur » n’est pas un identifiant positif", $etape);
        }

        return (int) $valeur;
    }

    private function chaine(mixed $valeur): ?string
    {
        $valeur = trim((string) $valeur);

        return $valeur === '' ? null : $valeur;
    }

    /** Horodatage AAAA-MM-JJ (minuit) ou AAAA-MM-JJ HH:MM:SS ; une valeur invalide est refusée 400. @throws ErreurApi */
    private function horodatage(mixed $valeur, string $nom, string $etape): ?string
    {
        $valeur = trim((string) $valeur);
        if ($valeur === '') {
            return null;
        }
        if (preg_match('#^(\d{4})-(\d{2})-(\d{2})$#', $valeur, $m) === 1) {
            if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                throw new ErreurApi(400, "filtre $nom : « $valeur » n’existe pas", $etape);
            }

            return $valeur . ' 00:00:00';
        }
        if (preg_match('#^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?$#', $valeur, $m) === 1
            && checkdate((int) $m[2], (int) $m[3], (int) $m[1])
            && (int) $m[4] <= 23 && (int) $m[5] <= 59 && (int) ($m[6] ?? '00') <= 59) {
            return sprintf('%s-%s-%s %s:%s:%s', $m[1], $m[2], $m[3], $m[4], $m[5], $m[6] ?? '00');
        }

        throw new ErreurApi(400, "filtre $nom : « $valeur » n’est pas un horodatage AAAA-MM-JJ[ HH:MM:SS]", $etape);
    }

    private function echec(Reponse $reponse, string $etape, Throwable $e, string $cause): Reponse
    {
        error_log(sprintf('[GEDv2 parite-api] %s : %s (%s:%d)', $etape, $e->getMessage(), $e->getFile(), $e->getLine()));

        return $this->erreur($reponse, new ErreurApi(500, $cause, $etape, $e));
    }

    /** @param mixed $donnees */
    private function json(Reponse $reponse, int $code, mixed $donnees): Reponse
    {
        $reponse->getBody()->write((string) json_encode($donnees, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $reponse->withHeader('Content-Type', 'application/json; charset=utf-8')->withStatus($code);
    }

    private function erreur(Reponse $reponse, ErreurApi $erreur): Reponse
    {
        $reference = substr(hash('sha256', $erreur->etape . $erreur->cause . microtime()), 0, 10);

        return $this->json($reponse, $erreur->statut, [
            'erreur' => ['etape' => $erreur->etape, 'cause' => $erreur->cause, 'reference' => $reference],
        ]);
    }
}
