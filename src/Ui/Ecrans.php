<?php

declare(strict_types=1);

namespace Ged\Ui;

use Psr\Http\Message\ResponseInterface as Reponse;
use Psr\Http\Message\ServerRequestInterface as Requete;

/**
 * Les six écrans maîtres. Lot MAQUETTES : données fixes (Maquette). Chaque secteur remplacera sa source
 * de données sans toucher au gabarit — le gabarit est le contrat visuel.
 */
final class Ecrans
{
    public function __construct(private readonly Vue $vue)
    {
    }

    /** @return array<string, mixed> */
    private function commun(string $ecran): array
    {
        return ['ecran' => $ecran, 'bilan' => Maquette::bilan(), 'types' => Maquette::TYPES];
    }

    /**
     * Le jeton CSRF du formulaire. Source AUTORITAIRE : la session posée par la garde centrale (elle
     * vient d'être créée à la connexion, alors que le cookie du navigateur porte encore le jeton de
     * connexion précédent). On retombe sur le cookie si la garde n'a pas posé d'attribut (rendu hors
     * requête HTTP).
     */
    public static function jetonCsrf(Requete $req): string
    {
        $session = $req->getAttribute('ged.session');
        if (is_array($session) && isset($session['csrf']) && (string) $session['csrf'] !== '') {
            return (string) $session['csrf'];
        }

        return (string) ($req->getCookieParams()['gedv2_csrf'] ?? '');
    }

    public function aTraiter(Requete $req, Reponse $rep): Reponse
    {
        // Branchement réel : bilan du fonds + file des documents `a_controler` + proposition du Classeur.
        // Le gabarit reste le contrat visuel (mêmes classes, mêmes data-testid, même disposition) ; le
        // jeton CSRF du formulaire vient de la session courante.
        if (class_exists(ATraiter::class)) {
            $csrf = self::jetonCsrf($req);
            $session = $req->getAttribute('ged.session');
            $compte = is_array($session) && is_int($session['compte'] ?? null) ? $session['compte'] : null;
            $administrateur = is_array($session) && in_array('*', (array) ($session['permissions'] ?? []), true);
            $donnees = ATraiter::depuisRacine(dirname(__DIR__, 2), $compte, $administrateur)->donnees($req->getQueryParams(), $csrf);

            return $this->vue->rendre($rep, 'ecrans/a-traiter.twig', $donnees + $this->commun('a-traiter'));
        }

        $file = Maquette::aTraiter();
        $choisi = (int) ($req->getQueryParams()['doc'] ?? $file[0]['id']);
        $courant = $file[0];
        foreach ($file as $p) {
            if ($p['id'] === $choisi) {
                $courant = $p;
            }
        }

        return $this->vue->rendre($rep, 'ecrans/a-traiter.twig', $this->commun('a-traiter') + ['file' => $file, 'courant' => $courant]);
    }

    public function bibliotheque(Requete $req, Reponse $rep): Reponse
    {
        // Le secteur Socle branche la bibliothèque sur les VRAIES données (fonds, vues, champs
        // personnalisés — V2-04) ; le gabarit reste le contrat visuel. Branchement minimal, comme
        // pour /recherche et /workflows ; repli de maquette si la base n'est pas encore là.
        if (class_exists(\Ged\Socle\EcranBibliotheque::class)) {
            try {
                $session = $req->getAttribute('ged.session');
                $compte = is_array($session) && is_int($session['compte'] ?? null) ? $session['compte'] : null;
                $permissions = is_array($session) ? array_values(array_map('strval', (array) ($session['permissions'] ?? []))) : [];
                $administrateur = in_array('*', $permissions, true);
                $ecran = new \Ged\Socle\EcranBibliotheque(new \Ged\Config(dirname(__DIR__, 2)), $compte, $administrateur, $permissions);
                $donnees = $ecran->donnees($req->getQueryParams(), self::jetonCsrf($req));

                return $this->vue->rendre($rep, 'ecrans/bibliotheque.twig', $donnees);
            } catch (\PDOException) {
                return $this->vue->rendre($rep, 'erreur.twig', [
                    'code' => 503,
                    'etape' => 'GET /bibliotheque',
                    'cause' => 'Schéma documentaire absent ou indisponible : exécutez tools/installer.php.',
                    'reference' => '',
                ], 503);
            }
        }

        $docs = Maquette::bibliotheque();
        $choisi = (int) ($req->getQueryParams()['doc'] ?? $docs[0]['id']);
        $doc = Maquette::document($choisi);

        // Même forme de données que l'écran réel du Socle : le gabarit ne connaît qu'un contrat.
        return $this->vue->rendre($rep, 'ecrans/bibliotheque.twig', $this->commun('bibliotheque') + [
            'docs' => $docs, 'compte' => count($docs), 'total' => count($docs),
            'totalFiltre' => count($docs), 'page' => 1, 'pages' => 1, 'pageTaille' => count($docs),
            'lienPagePrecedente' => null, 'lienPageSuivante' => null,
            'vuesVolet' => [['id' => null, 'nom' => 'Tous les documents', 'compte' => count($docs)]],
            'vueActive' => null, 'arbre' => Maquette::arbre(), 'tousChamps' => [], 'operateurs' => [],
            'filtres' => ['type' => null, 'emetteur' => null, 'de' => null, 'a' => null, 'etiquettes' => [], 'champ' => null],
            'erreur' => null, 'csrf' => '',
            'apercu' => [
                'id' => $doc['id'], 'titre' => $doc['titre'], 'type' => $doc['type'], 'emetteur' => $doc['emetteur'],
                'date' => $doc['date'], 'chemin' => $doc['dossier'] . '/' . $doc['fichier'], 'champs' => [],
                'ouverture' => null, 'ouvrir' => null,
                'rendu' => ['url' => '', 'categorie' => 'inconnu', 'pages' => 0, 'titre' => $doc['titre'],
                    'message' => 'Aucun aperçu pour ce format.'],
            ],
        ]);
    }

    public function recherche(Requete $req, Reponse $rep): Reponse
    {
        // Le secteur Recherche branche ses vrais résultats (source : le fonds) ; le gabarit et son
        // système visuel restent le contrat. Point de branchement minimal, sans toucher au Kernel.
        if (class_exists(\Ged\Recherche\Ecran::class)) {
            $session = $req->getAttribute('ged.session');
            $compte = is_array($session) && is_int($session['compte'] ?? null) ? $session['compte'] : null;
            $administrateur = is_array($session) && in_array('*', (array) ($session['permissions'] ?? []), true);
            $donnees = \Ged\Recherche\Ecran::depuisRacine(null, $compte, $administrateur)->donnees($req->getQueryParams(), self::jetonCsrf($req));
            // V2-10c / V2-10d / V2-10e : on ne montre pas un extrait OCR illisible. Un extrait qui
            // mélange bruit et texte est rogné (tête ET queue) au passage propre ; la fenêtre retenue
            // contient le premier terme trouvé ; un extrait court majoritairement bruité est écarté.
            // Le titre, le type, l'émetteur et la date restent affichés.
            foreach ($donnees['resultats'] as &$resultat) {
                $resultat['extraits'] = ExtraitsLisibles::retenir((array) ($resultat['extraits'] ?? []));
            }
            unset($resultat);

            return $this->vue->rendre($rep, 'ecrans/recherche.twig',
                $this->commun('recherche') + $donnees);
        }

        // Repli de démonstration (aucune donnée réelle) : même forme de données pour le même gabarit.
        $q = trim((string) ($req->getQueryParams()['q'] ?? ''));
        $terme = $q !== '' ? $q : 'maintenance';
        $resultats = array_map(static fn (array $r): array => [
            'id' => $r['id'], 'titre' => $r['titre'], 'type' => $r['type'], 'emetteur' => null,
            'date' => $r['date'], 'montant' => null,
            'segments' => self::surligner($r['extrait'], $terme),
            'titre_segments' => [['t' => $r['titre'], 'hit' => false]],
            'extraits' => [self::surligner($r['extrait'], $terme)],
            'pertinence' => 0.0,
        ], Maquette::resultats($q));

        return $this->vue->rendre($rep, 'ecrans/recherche.twig', $this->commun('recherche') + [
            'q' => $q, 'resultats' => $resultats, 'total' => count($resultats), 'duree' => '0,08', 'mode' => 'demo',
            'erreur' => null, 'filtres_actifs' => false, 'types_filtre' => [],
            'filtres' => ['type' => null, 'emetteur' => null, 'date_debut' => null, 'date_fin' => null,
                'montant_min' => null, 'montant_max' => null], 'csrf' => self::jetonCsrf($req),
        ]);
    }

    /** @param array<string, mixed> $args */
    public function document(Requete $req, Reponse $rep, array $args): Reponse
    {
        // Fiche branchée sur le FONDS réel quand l'identifiant y existe (le dossier fait foi) : mêmes
        // classes et data-testid, seul l'aperçu passe de la page de papier à la vraie visionneuse. Un
        // identifiant inconnu retombe sur la maquette : jamais un écran muet ni un 500.
        $id = (int) ($args['id'] ?? 0);
        $compte = null;
        $reel = null;
        try {
            $session = $req->getAttribute('ged.session');
            $compte = is_array($session) && is_int($session['compte'] ?? null) ? $session['compte'] : null;
            $administrateur = is_array($session) && in_array('*', array_values(array_map('strval', (array) ($session['permissions'] ?? []))), true);
            $reel = Document::depuisRacine(dirname(__DIR__, 2))->donnees($id, $compte, $administrateur);
        } catch (\Throwable) {
            $reel = null;
        }

        // La fiche fait partie du périmètre mobile (V2-10b) : pas d'avis « écran conçu pour l'ordinateur »,
        // tout en gardant Bibliothèque comme entrée de rail courante.
        $doc = $reel ?? Maquette::document($id);
        $doc += ['statut' => '', 'ouverture' => null, 'secours' => [],
            // Champs Paperless de la fiche (lot ged-parite-final-1) : présents même sur le repli de maquette,
            // l'écran ne casse jamais et dit « — » quand l'information n'existe pas.
            'proprietaire' => '', 'proprietaire_id' => null, 'nom_origine' => '', 'pages' => 0,
            'asn' => '', 'storage_type' => 'unencrypted',
            // Famille M-Files nº 2 : l'onglet Relations est toujours là, même sur le repli de maquette.
            'relations' => [], 'relations_types' => [],
            'pdfa' => ['statut' => 'en_attente', 'niveau' => '2b', 'telechargeable' => false, 'chemin' => '', 'raison' => '', 'empreinte_sha256' => '', 'reprise' => ''],
            'archivage' => ['scelle' => false, 'scelle_le' => '', 'echeance' => '', 'tsa_jeton' => '', 'tsa_url' => ''],
            // Famille M-Files nº 5 (lot ged-mfiles-fiche-1) : repli neutre si le dossier ne répond pas,
            // l'écran ne casse jamais et reste actionnable.
            'nom_fichier' => '', 'verrou' => ['verrouille' => false, 'par' => '', 'depuis' => '', 'expire_le' => '']];
        $session = $req->getAttribute('ged.session');
        $permissions = is_array($session) ? array_values(array_map('strval', (array) ($session['permissions'] ?? []))) : [];
        $peutSceller = in_array('*', $permissions, true) || in_array('archivage:sceller', $permissions, true);
        $config = new \Ged\Config(dirname(__DIR__, 2));

        // Édition depuis le poste (F13) : « Ouvrir dans … » n'apparaît qu'à qui peut MODIFIER le document
        // ET pour un format éditable (liste blanche) — un .exe ou un .docm n'a pas de bouton.
        $peutModifier = $reel !== null
            && \Ged\Edition\Droits::peutModifier($config, $compte, $permissions, (int) ($reel['id'] ?? 0));
        $peutOuvrirPoste = $peutModifier && \Ged\Edition\Formats::editable((string) ($doc['fichier'] ?? ''));
        $libelleOuverture = \Ged\Edition\Pieces::libelleOuverture((string) ($doc['fichier'] ?? ''));

        return $this->vue->rendre($rep, 'ecrans/document.twig', $this->commun('bibliotheque') + [
            'avis_ordinateur' => false, 'doc' => $doc, 'csrf' => self::jetonCsrf($req),
            'peut_modifier' => $peutModifier,
            'peut_ouvrir_poste' => $peutOuvrirPoste,
            'libelle_ouverture' => $libelleOuverture,
            'lien_ouverture' => $peutOuvrirPoste ? \Ged\Edition\Pieces::lien((int) ($doc['id'] ?? 0)) : '',
            'peut_sceller' => $peutSceller, 'tsa_configuree' => trim($config->get('TSA_URL')) !== '' || $config->get('APP_ENV') === 'test',
            'archivage_erreur' => (string) ($req->getQueryParams()['archivage_erreur'] ?? ''),
            'archivage_succes' => (string) ($req->getQueryParams()['archivage_succes'] ?? '') === '1',
            'version_succes' => (int) ($req->getQueryParams()['version_succes'] ?? 0),
            'version_erreur' => (string) ($req->getQueryParams()['version_erreur'] ?? ''),
            'secours_succes' => (int) ($req->getQueryParams()['secours_succes'] ?? 0),
            'secours_erreur' => (string) ($req->getQueryParams()['secours_erreur'] ?? ''),
            'relation_succes' => (string) ($req->getQueryParams()['relation_succes'] ?? '') === '1',
            'relation_erreur' => (string) ($req->getQueryParams()['relation_erreur'] ?? ''),
            // Famille M-Files nº 5 (lot ged-mfiles-fiche-1) : message nommé d'un geste de fiche réussi
            // (titre, nom de fichier, verrou), ramené par le PRG.
            'fiche_succes' => (string) ($req->getQueryParams()['fiche_succes'] ?? ''),
            // Le secret ne transite jamais dans l'URL : le flash serveur est consommé à ce seul rendu.
            'partage_lien' => ($jeton = (new \Ged\Socle\JetonPartageSession($config))->consommer(
                (string) ($req->getCookieParams()['gedv2_session'] ?? '')
            )) !== '' ? '/partages/' . $jeton : '',
            'peut_partager_externe' => in_array('*', $permissions, true)
                || in_array('documents:partager-externe', $permissions, true),
            'auteur_courant' => is_array($session) ? (string) ($session['utilisateur'] ?? '') : '',
        ]);
    }

    public function workflows(Requete $req, Reponse $rep): Reponse
    {
        // Le secteur Workflows branche ses vraies règles et ses circuits ; le gabarit reste le contrat visuel.
        // Branchement minimal, comme pour /recherche : aucune donnée réelle, repli de maquette conservé.
        if (class_exists(\Ged\Workflows\Ecran::class)) {
            $donnees = \Ged\Workflows\Ecran::depuisRacine()->donnees($req->getQueryParams());
            $donnees['csrf'] = self::jetonCsrf($req);

            return $this->vue->rendre($rep, 'ecrans/workflows.twig', $this->commun('workflows') + $donnees);
        }

        return $this->vue->rendre($rep, 'ecrans/workflows.twig', $this->commun('workflows') + [
            'installe' => false, 'regles' => [], 'circuits' => [], 'gabarits' => [],
            'documents' => [], 'journal' => [], 'declencheurs' => [], 'a_porter' => [], 'csrf' => '',
        ]);
    }

    public function application(Requete $req, Reponse $rep): Reponse
    {
        // L'écran ne montre que la configuration et l'état RÉELS (jamais une valeur de maquette) : les
        // données viennent du provider dédié, le gabarit reste le contrat visuel.
        $donnees = Application::depuisRacine(dirname(__DIR__, 2))->donnees();

        return $this->vue->rendre($rep, 'ecrans/application.twig', $this->commun('application') + $donnees + [
            'csrf' => self::jetonCsrf($req),
        ]);
    }

    /**
     * Découpe un extrait en segments [texte, trouvé] : le surlignage se fait dans le gabarit, sans |raw.
     *
     * @return list<array{t:string, hit:bool}>
     */
    public static function surligner(string $texte, string $terme): array
    {
        if ($terme === '') {
            return [['t' => $texte, 'hit' => false]];
        }
        $parts = preg_split('/(' . preg_quote($terme, '/') . ')/iu', $texte, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [$texte];

        return array_map(static fn (string $p) => ['t' => $p, 'hit' => mb_strtolower($p) === mb_strtolower($terme)], $parts);
    }
}
