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

    public function aTraiter(Requete $req, Reponse $rep): Reponse
    {
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
        $docs = Maquette::bibliotheque();
        $choisi = (int) ($req->getQueryParams()['doc'] ?? $docs[0]['id']);

        return $this->vue->rendre($rep, 'ecrans/bibliotheque.twig', $this->commun('bibliotheque') + [
            'docs' => $docs, 'vues' => Maquette::vues(), 'arbre' => Maquette::arbre(), 'apercu' => Maquette::document($choisi),
        ]);
    }

    public function recherche(Requete $req, Reponse $rep): Reponse
    {
        // Le secteur Recherche branche ses vrais résultats (source : le fonds) ; le gabarit et son
        // système visuel restent le contrat. Point de branchement minimal, sans toucher au Kernel.
        if (class_exists(\Ged\Recherche\Ecran::class)) {
            return $this->vue->rendre($rep, 'ecrans/recherche.twig',
                $this->commun('recherche') + \Ged\Recherche\Ecran::depuisRacine()->donnees($req->getQueryParams()));
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
        return $this->vue->rendre($rep, 'ecrans/document.twig', $this->commun('bibliotheque') + ['doc' => Maquette::document((int) $args['id'])]);
    }

    public function workflows(Requete $req, Reponse $rep): Reponse
    {
        // Le secteur Workflows branche ses vraies règles et ses circuits ; le gabarit reste le contrat visuel.
        // Branchement minimal, comme pour /recherche : aucune donnée réelle, repli de maquette conservé.
        if (class_exists(\Ged\Workflows\Ecran::class)) {
            $donnees = \Ged\Workflows\Ecran::depuisRacine()->donnees($req->getQueryParams());
            $donnees['csrf'] = (string) ($req->getCookieParams()['gedv2_csrf'] ?? '');

            return $this->vue->rendre($rep, 'ecrans/workflows.twig', $this->commun('workflows') + $donnees);
        }

        return $this->vue->rendre($rep, 'ecrans/workflows.twig', $this->commun('workflows') + [
            'workflows' => Maquette::workflows(), 'installe' => false, 'regles' => [], 'circuits' => [], 'gabarits' => [],
            'documents' => [], 'journal' => [], 'essai' => null, 'declencheurs' => [], 'champs_condition' => [],
            'operateurs' => [], 'etats_cibles' => [], 'csrf' => '',
        ]);
    }

    public function application(Requete $req, Reponse $rep): Reponse
    {
        return $this->vue->rendre($rep, 'ecrans/application.twig', $this->commun('application'));
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
