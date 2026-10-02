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
            $donnees = ATraiter::depuisRacine(dirname(__DIR__, 2))->donnees($req->getQueryParams(), $csrf);

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
                $ecran = new \Ged\Socle\EcranBibliotheque(new \Ged\Config(dirname(__DIR__, 2)));
                $donnees = $ecran->donnees($req->getQueryParams(), (string) ($req->getCookieParams()['gedv2_csrf'] ?? ''));

                return $this->vue->rendre($rep, 'ecrans/bibliotheque.twig', $donnees);
            } catch (\PDOException) {
                // base absente : repli de démonstration ci-dessous, l'écran n'est jamais muet
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
            $donnees = \Ged\Recherche\Ecran::depuisRacine()->donnees($req->getQueryParams());
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
                'montant_min' => null, 'montant_max' => null],
        ]);
    }

    public function document(Requete $req, Reponse $rep, array $args): Reponse
    {
        // Fiche branchée sur le FONDS réel quand l'identifiant y existe (le dossier fait foi) : mêmes
        // classes et data-testid, seul l'aperçu passe de la page de papier à la vraie visionneuse. Un
        // identifiant inconnu retombe sur la maquette : jamais un écran muet ni un 500.
        $id = (int) ($args['id'] ?? 0);
        try {
            $reel = Document::depuisRacine(dirname(__DIR__, 2))->donnees($id);
        } catch (\Throwable) {
            $reel = null;
        }

        // La fiche fait partie du périmètre mobile (V2-10b) : pas d'avis « écran conçu pour l'ordinateur »,
        // tout en gardant Bibliothèque comme entrée de rail courante.
        $doc = $reel ?? Maquette::document($id);
        $doc += ['statut' => '', 'archivage' => ['scelle' => false, 'scelle_le' => '', 'echeance' => '', 'tsa_jeton' => '', 'tsa_url' => '']];
        $session = $req->getAttribute('ged.session');
        $permissions = is_array($session) ? (array) ($session['permissions'] ?? []) : [];
        $peutSceller = in_array('*', $permissions, true) || in_array('archivage:sceller', $permissions, true);
        $config = new \Ged\Config(dirname(__DIR__, 2));

        return $this->vue->rendre($rep, 'ecrans/document.twig', $this->commun('bibliotheque') + [
            'avis_ordinateur' => false, 'doc' => $doc, 'csrf' => self::jetonCsrf($req),
            'peut_sceller' => $peutSceller, 'tsa_configuree' => trim($config->get('TSA_URL')) !== '' || $config->get('APP_ENV') === 'test',
            'archivage_erreur' => (string) ($req->getQueryParams()['archivage_erreur'] ?? ''),
            'archivage_succes' => (string) ($req->getQueryParams()['archivage_succes'] ?? '') === '1',
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

        return $this->vue->rendre($rep, 'ecrans/application.twig', $this->commun('application') + $donnees);
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
