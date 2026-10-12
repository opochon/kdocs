<?php

declare(strict_types=1);

namespace Ged\Ingestion\Courrier;

use Ged\Config;

/**
 * Données de l'écran d'ADMINISTRATION du courrier (réservé aux détenteurs de `application:administrer`) :
 * comptes, règles, journal des courriels traités, et le test de connexion. L'écran ne voit JAMAIS un mot de
 * passe en clair : les comptes passent par `Compte::pourEcran()`, donc par le masque.
 */
final class Admin
{
    public function __construct(private readonly Config $config)
    {
    }

    public static function depuisRacine(?string $racine = null): self
    {
        return new self(new Config($racine ?? dirname(__DIR__, 3)));
    }

    /** @return array<string, mixed> */
    public function liste(): array
    {
        $comptes = [];
        $regles = [];
        $journal = [];
        $installe = true;
        try {
            foreach (Fabrique::comptes($this->config)->tous() as $compte) {
                $comptes[] = $compte->pourEcran();
            }
            foreach (Fabrique::regles($this->config)->tous() as $regle) {
                $regles[] = $this->resumeRegle($regle);
            }
            $journal = $this->journal(100);
        } catch (\PDOException) {
            $installe = false;
        }

        return [
            'installe' => $installe,
            'comptes' => $comptes,
            'regles' => $regles,
            'journal' => $journal,
            'constantes' => $this->constantes(),
            'faux' => $this->config->get('APP_ENV') === 'test' || $this->config->get('COURRIER_IMAP_FAUX') === '1',
            'csrf' => '',
        ];
    }

    /** @return array<string, mixed>|null */
    public function compte(int $id): ?array
    {
        $compte = Fabrique::comptes($this->config)->parId($id);
        if ($compte === null) {
            return null;
        }

        return ['compte' => $compte->pourEcran(), 'constantes' => $this->constantes()];
    }

    /** @return array<string, mixed>|null */
    public function regle(int $id): ?array
    {
        $regle = Fabrique::regles($this->config)->parId($id);
        if ($regle === null) {
            return null;
        }

        return ['regle' => $this->resumeRegle($regle), 'dossier' => $regle->pourTableau(), 'constantes' => $this->constantes()];
    }

    /** @return list<array<string, mixed>> */
    private function journal(int $limite): array
    {
        return (new Traites(\Ged\Base::pdo($this->config)))->journal($limite);
    }

    /** @return array<string, mixed> */
    private function resumeRegle(Regle $regle): array
    {
        return [
            'id' => $regle->id,
            'nom' => $regle->nom,
            'ordre' => $regle->ordre,
            'compte' => $regle->compte,
            'actif' => $regle->actif,
            'dossier' => $regle->dossier,
            'action_libelle' => Regle::TYPES_ACTION[$regle->action] ?? '?',
            'parametre_action' => $regle->parametreAction,
            'filtres' => $this->libelleFiltres($regle),
            'portee_libelle' => Regle::PORTEES[$regle->portee] ?? '?',
            'titre_libelle' => Regle::TYPES_TITRE[$regle->titreDepuis] ?? '?',
            'emetteur_libelle' => Regle::TYPES_EMETTEUR[$regle->emetteurDepuis] ?? '?',
            'complet' => $regle->pourTableau(),
        ];
    }

    /** @return list<string> */
    private function libelleFiltres(Regle $regle): array
    {
        $filtres = [];
        foreach ([
            'expéditeur' => $regle->filtreDe,
            'destinataire' => $regle->filtreA,
            'objet' => $regle->filtreObjet,
            'corps' => $regle->filtreCorps,
            'pièces (inclure)' => $regle->filtreFichiersInclure,
            'pièces (exclure)' => $regle->filtreFichiersExclure,
        ] as $libelle => $valeur) {
            if ($valeur !== '') {
                $filtres[] = $libelle . ' : ' . $valeur;
            }
        }

        return $filtres;
    }

    /** @return array<string, mixed> */
    private function constantes(): array
    {
        return [
            'actions' => Regle::TYPES_ACTION,
            'titres' => Regle::TYPES_TITRE,
            'emetteurs' => Regle::TYPES_EMETTEUR,
            'portees' => Regle::PORTEES,
            'pieces' => Regle::TYPES_PIECES,
            'mises_en_page' => Regle::MISES_EN_PAGE,
            'securites' => [
                Compte::SECURITE_NON => Compte::libelleSecurite(Compte::SECURITE_NON),
                Compte::SECURITE_SSL => Compte::libelleSecurite(Compte::SECURITE_SSL),
                Compte::SECURITE_STARTTLS => Compte::libelleSecurite(Compte::SECURITE_STARTTLS),
            ],
            'types_compte' => [
                Compte::TYPE_IMAP => Compte::libelleType(Compte::TYPE_IMAP),
                Compte::TYPE_GMAIL_OAUTH => Compte::libelleType(Compte::TYPE_GMAIL_OAUTH),
                Compte::TYPE_OUTLOOK_OAUTH => Compte::libelleType(Compte::TYPE_OUTLOOK_OAUTH),
            ],
        ];
    }
}
