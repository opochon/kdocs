<?php

declare(strict_types=1);

namespace Ged\Ingestion\Courrier;

use Ged\Contrats\Depot;
use Ged\Ingestion\SourceDossier;

/**
 * Moteur de courrier : applique les règles d'un compte à une boîte, à la manière de GEDv2.
 *
 * Pour chaque règle active, dans l'ordre : lire le dossier, écarter les messages déjà traités (journal
 * `Traites`), appliquer les filtres puis les critères d'action, déposer les pièces jointes et/ou le courriel
 * entier par le contrat `Depot` (la même chaîne d'ingestion que le dossier surveillé), appliquer l'action au
 * message, puis écrire la trace du courriel traité. Une panne sur un message n'interrompt pas les autres :
 * elle est nommée et journalisée en échec.
 */
final class Moteur
{
    public function __construct(
        private readonly Depot $depot,
        private readonly Traites $traites,
        private readonly string $zoneTravail,
        private readonly \DateTimeImmutable $maintenant,
    ) {
    }

    /**
     * @param list<Regle> $regles
     * @return array{traites:int, pieces:int, journalises:int, deposes:list<string>, erreurs:list<string>}
     */
    public function traiter(Compte $compte, array $regles, PortBoite $boite): array
    {
        $rapport = ['traites' => 0, 'pieces' => 0, 'journalises' => 0, 'deposes' => [], 'erreurs' => []];
        try {
            $boite->tester();
        } catch (\Throwable $e) {
            $rapport['erreurs'][] = 'compte ' . $compte->nom . ' : ' . $e->getMessage();

            return $rapport;
        }

        foreach ($regles as $regle) {
            if (!$regle->actif) {
                continue;
            }
            foreach ($boite->messages($regle->dossier) as $message) {
                try {
                    $resultat = $this->traiterMessage($compte, $regle, $message, $boite);
                    $rapport['traites'] += $resultat['traites'];
                    $rapport['pieces'] += $resultat['pieces'];
                    $rapport['journalises'] += $resultat['journalises'];
                    $rapport['deposes'] = array_merge($rapport['deposes'], $resultat['deposes']);
                } catch (\Throwable $e) {
                    $this->journaliser($compte, $regle, $message, Traites::ECHEC, $e->getMessage());
                    $rapport['erreurs'][] = $regle->nom . ' / message ' . $message->uid . ' : ' . $e->getMessage();
                }
            }
        }

        return $rapport;
    }

    /**
     * @return array{traites:int, pieces:int, journalises:int, deposes:list<string>}
     */
    private function traiterMessage(Compte $compte, Regle $regle, Message $message, PortBoite $boite): array
    {
        $vide = ['traites' => 0, 'pieces' => 0, 'journalises' => 0, 'deposes' => []];
        if ($this->traites->existe($compte->nom, $regle->nom, $regle->dossier, (string) $message->uid)) {
            return $vide;
        }
        if (!Filtres::messageRetenu($message, $regle, $this->maintenant)
            || !Filtres::retenuParAction($message, $regle)) {
            return $vide;
        }
        if ($message->pieces === [] && $regle->portee === Regle::PORTEE_JOINTES) {
            return $vide;
        }

        $deposes = [];
        if ($regle->portee === Regle::PORTEE_JOINTES || $regle->portee === Regle::PORTEE_TOUT) {
            foreach ($message->pieces as $piece) {
                if (!Filtres::pieceRetenue($piece, $regle)) {
                    continue;
                }
                $this->deposerPiece($compte, $regle, $message, $piece);
                $deposes[] = $piece->nom;
            }
        }
        if (($regle->portee === Regle::PORTEE_EML || $regle->portee === Regle::PORTEE_TOUT) && $message->brut !== '') {
            $this->deposerCourriel($compte, $regle, $message);
            $deposes[] = ($message->sujet !== '' ? $message->sujet : 'courriel') . '.eml';
        }

        if ($deposes === []) {
            $this->journaliser($compte, $regle, $message, Traites::SANS_CONSOMMATION, null);

            return ['traites' => 0, 'pieces' => 0, 'journalises' => 1, 'deposes' => []];
        }

        $boite->appliquer($regle->dossier, $message->uid, $regle->action, $regle->parametreAction);
        $this->journaliser($compte, $regle, $message, Traites::SUCCES, null);

        return ['traites' => 1, 'pieces' => count($deposes), 'journalises' => 1, 'deposes' => $deposes];
    }

    private function deposerPiece(Compte $compte, Regle $regle, Message $message, Piece $piece): void
    {
        $chemin = $this->ecrireTemporaire($piece->nom, $piece->contenu);
        $titre = $regle->titre($message, $piece);
        $meta = $this->meta($compte, $regle, $message, $piece->nom);
        if ($titre !== null && $titre !== '') {
            $meta['titre'] = $titre;
        }
        $this->depot->deposer($chemin, $meta);
    }

    private function deposerCourriel(Compte $compte, Regle $regle, Message $message): void
    {
        $nom = ($message->sujet !== '' ? $message->sujet : 'courriel') . '.eml';
        $chemin = $this->ecrireTemporaire($nom, $message->brut);
        $meta = $this->meta($compte, $regle, $message, $nom);
        $meta['titre'] = $message->sujet !== '' ? $message->sujet : 'Courriel';

        // Mise en page PDF du corps : le choix est conservé dans les métadonnées (rendu par la chaîne d'aperçu).
        $meta['courriel_pdf'] = $regle->miseEnPagePdf;
        $this->depot->deposer($chemin, $meta);
    }

    /**
     * @return array<string, mixed>
     */
    private function meta(Compte $compte, Regle $regle, Message $message, string $nomOrigine): array
    {
        $meta = [
            'source' => 'adresse',
            'nom_origine' => $nomOrigine,
            'date' => $message->date->format('Y-m-d'),
            'etiquettes' => $regle->etiquettes,
            'origine' => ['courriel' => [
                'compte' => $compte->nom,
                'regle' => $regle->nom,
                'dossier' => $regle->dossier,
                'uid' => (string) $message->uid,
                'sujet' => $message->sujet,
            ]],
        ];
        $emetteur = $regle->emetteur($message);
        if ($emetteur !== null && $emetteur !== '') {
            $meta['emetteur'] = $emetteur;
        }
        if ($regle->typeDocument !== null && $regle->typeDocument !== '') {
            $meta['type'] = $regle->typeDocument;
        }
        if ($regle->proprietaireDepuisRegle && $compte->proprietaire !== '') {
            $meta['proprietaire'] = $compte->proprietaire;
        }

        return $meta;
    }

    private function ecrireTemporaire(string $nom, string $contenu): string
    {
        if (!is_dir($this->zoneTravail) && !mkdir($this->zoneTravail, 0775, true) && !is_dir($this->zoneTravail)) {
            throw new ErreurCourrier('courrier : zone de travail impossible ' . $this->zoneTravail);
        }
        $sur = $nom !== '' ? SourceDossier::nomSur($nom) : 'piece';
        $chemin = rtrim($this->zoneTravail, '/\\') . '/' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '-' . $sur;
        if (file_put_contents($chemin, $contenu) === false) {
            throw new ErreurCourrier('courrier : écriture impossible dans la zone de travail');
        }

        return $chemin;
    }

    private function journaliser(Compte $compte, Regle $regle, Message $message, string $statut, ?string $erreur): void
    {
        $this->traites->enregistrer(
            $compte->nom,
            $regle->nom,
            $regle->dossier,
            (string) $message->uid,
            $message->sujet,
            $message->date,
            $statut,
            $erreur,
            $this->maintenant,
        );
    }
}
