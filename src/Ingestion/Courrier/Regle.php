<?php

declare(strict_types=1);

namespace Ged\Ingestion\Courrier;

/**
 * Règle de courrier (modele Paperless `MailRule`) : quels messages retenir, quoi en faire, avec quels
 * attributs. Les entiers sont ceux du modele Paperless, pour que les vecteurs commis se rejouent tels quels.
 *
 * Les filtres sont des sous-chaînes (expéditeur, destinataire, objet, corps) ou des jokers de nom de pièce
 * (`fnmatch`, insensible à la casse), exactement comme `make_criterias` / `filename_inclusion_matches`.
 */
final class Regle
{
    public const ACTION_SUPPRIMER = 1;
    public const ACTION_DEPLACER = 2;
    public const ACTION_MARQUER_LU = 3;
    public const ACTION_DRAPEAU = 4;
    public const ACTION_ETIQUETER = 5;

    public const TITRE_OBJET = 1;
    public const TITRE_FICHIER = 2;
    public const TITRE_AUCUN = 3;

    public const EMETTEUR_RIEN = 1;
    public const EMETTEUR_COURRIEL = 2;
    public const EMETTEUR_NOM = 3;
    public const EMETTEUR_CHOISI = 4;

    public const PIECES_JOINTES = 1;
    public const PIECES_TOUT = 2;

    public const PORTEE_JOINTES = 1;
    public const PORTEE_EML = 2;
    public const PORTEE_TOUT = 3;

    public const PDF_DEFAUT = 0;
    public const PDF_TEXTE_HTML = 1;
    public const PDF_HTML_TEXTE = 2;
    public const PDF_HTML = 3;
    public const PDF_TEXTE = 4;

    /** Dossier de corbeille où « supprimer » range le message : GEDv2 n'efface jamais. */
    public const CORBEILLE = 'GED-corbeille';

    /** @var array<int, string> */
    public const TYPES_ACTION = [
        self::ACTION_SUPPRIMER => 'Supprimer (ranger en corbeille)',
        self::ACTION_DEPLACER => 'Déplacer vers un dossier',
        self::ACTION_MARQUER_LU => 'Marquer comme lu',
        self::ACTION_DRAPEAU => 'Mettre un drapeau',
        self::ACTION_ETIQUETER => 'Étiqueter',
    ];

    /** @var array<int, string> */
    public const TYPES_TITRE = [
        self::TITRE_OBJET => "Utiliser l'objet",
        self::TITRE_FICHIER => 'Utiliser le nom de la pièce',
        self::TITRE_AUCUN => 'Ne pas attribuer de titre',
    ];

    /** @var array<int, string> */
    public const TYPES_EMETTEUR = [
        self::EMETTEUR_RIEN => 'Aucun émetteur',
        self::EMETTEUR_COURRIEL => "Adresse d'expédition",
        self::EMETTEUR_NOM => "Nom de l'expéditeur (sinon adresse)",
        self::EMETTEUR_CHOISI => 'Émetteur choisi',
    ];

    /** @var array<int, string> */
    public const PORTEES = [
        self::PORTEE_JOINTES => 'Pièces jointes seulement',
        self::PORTEE_EML => 'Courriel entier en .eml',
        self::PORTEE_TOUT => 'Courriel entier + pièces jointes',
    ];

    /** @var array<int, string> */
    public const TYPES_PIECES = [
        self::PIECES_JOINTES => 'Pièces jointes déclarées seulement',
        self::PIECES_TOUT => "Tous les fichiers, y compris 'inline'",
    ];

    /** @var array<int, string> */
    public const MISES_EN_PAGE = [
        self::PDF_DEFAUT => 'Défaut du système',
        self::PDF_TEXTE_HTML => "Texte puis HTML",
        self::PDF_HTML_TEXTE => 'HTML puis texte',
        self::PDF_HTML => 'HTML seulement',
        self::PDF_TEXTE => 'Texte seulement',
    ];

    /**
     * @param list<string> $etiquettes
     */
    public function __construct(
        public readonly int $id,
        public readonly string $nom,
        public readonly int $ordre,
        public readonly string $compte,
        public readonly bool $actif,
        public readonly string $dossier,
        public readonly string $filtreDe,
        public readonly string $filtreA,
        public readonly string $filtreObjet,
        public readonly string $filtreCorps,
        public readonly string $filtreFichiersInclure,
        public readonly string $filtreFichiersExclure,
        public readonly int $ageMax,
        public readonly int $typePiece,
        public readonly int $portee,
        public readonly int $miseEnPagePdf,
        public readonly int $action,
        public readonly string $parametreAction,
        public readonly int $titreDepuis,
        public readonly array $etiquettes,
        public readonly ?string $typeDocument,
        public readonly int $emetteurDepuis,
        public readonly string $emetteurChoisi,
        public readonly bool $proprietaireDepuisRegle,
    ) {
    }

    /** @param array<string, mixed> $donnees */
    public static function depuisTableau(array $donnees): self
    {
        $etiquettes = [];
        foreach ((array) ($donnees['etiquettes'] ?? []) as $etiquette) {
            if (is_string($etiquette)) {
                $etiquettes[] = $etiquette;
            }
        }

        return new self(
            (int) ($donnees['id'] ?? 0),
            (string) ($donnees['nom'] ?? ''),
            (int) ($donnees['ordre'] ?? 0),
            (string) ($donnees['compte'] ?? ''),
            (bool) ($donnees['actif'] ?? true),
            (string) ($donnees['dossier'] ?? 'INBOX'),
            (string) ($donnees['filtre_de'] ?? ''),
            (string) ($donnees['filtre_a'] ?? ''),
            (string) ($donnees['filtre_objet'] ?? ''),
            (string) ($donnees['filtre_corps'] ?? ''),
            (string) ($donnees['filtre_fichiers_inclure'] ?? ''),
            (string) ($donnees['filtre_fichiers_exclure'] ?? ''),
            (int) ($donnees['age_max'] ?? 30),
            (int) ($donnees['type_piece'] ?? self::PIECES_JOINTES),
            (int) ($donnees['portee'] ?? self::PORTEE_JOINTES),
            (int) ($donnees['mise_en_page_pdf'] ?? self::PDF_DEFAUT),
            (int) ($donnees['action'] ?? self::ACTION_MARQUER_LU),
            (string) ($donnees['parametre_action'] ?? ''),
            (int) ($donnees['titre_depuis'] ?? self::TITRE_OBJET),
            $etiquettes,
            isset($donnees['type_document']) && $donnees['type_document'] !== '' ? (string) $donnees['type_document'] : null,
            (int) ($donnees['emetteur_depuis'] ?? self::EMETTEUR_RIEN),
            (string) ($donnees['emetteur_choisi'] ?? ''),
            (bool) ($donnees['proprietaire_depuis_regle'] ?? true),
        );
    }

    /**
     * Traduction FONCTIONNELLE d'une entrée de vecteur Paperless (noms `filter_*`, `assign_*`, `amount_*`)
     * vers la règle GEDv2. Un champ Paperless sans équivalent ici reste hors du rejeu et le test le dit.
     *
     * @param array<string, mixed> $vecteur
     */
    public static function depuisVecteur(array $vecteur): self
    {
        $etiquettes = [];
        foreach ((array) ($vecteur['assign_tags'] ?? []) as $etiquette) {
            if (is_string($etiquette)) {
                $etiquettes[] = $etiquette;
            }
        }

        return new self(
            0,
            (string) ($vecteur['nom'] ?? 'vecteur'),
            0,
            (string) ($vecteur['compte'] ?? ''),
            (bool) ($vecteur['enabled'] ?? true),
            (string) ($vecteur['folder'] ?? 'INBOX'),
            (string) ($vecteur['filter_from'] ?? ''),
            (string) ($vecteur['filter_to'] ?? ''),
            (string) ($vecteur['filter_subject'] ?? ''),
            (string) ($vecteur['filter_body'] ?? ''),
            (string) ($vecteur['filter_attachment_filename_include'] ?? ''),
            (string) ($vecteur['filter_attachment_filename_exclude'] ?? ''),
            (int) ($vecteur['maximum_age'] ?? 30),
            (int) ($vecteur['attachment_type'] ?? self::PIECES_JOINTES),
            (int) ($vecteur['consumption_scope'] ?? self::PORTEE_JOINTES),
            (int) ($vecteur['pdf_layout'] ?? self::PDF_DEFAUT),
            (int) ($vecteur['action'] ?? self::ACTION_MARQUER_LU),
            (string) ($vecteur['action_parameter'] ?? ''),
            (int) ($vecteur['assign_title_from'] ?? self::TITRE_OBJET),
            $etiquettes,
            isset($vecteur['assign_document_type']) ? (string) $vecteur['assign_document_type'] : null,
            (int) ($vecteur['assign_correspondent_from'] ?? self::EMETTEUR_RIEN),
            (string) ($vecteur['emetteur_choisi'] ?? ''),
            (bool) ($vecteur['assign_owner_from_rule'] ?? true),
        );
    }

    /** @return array<string, mixed> */
    public function pourTableau(): array
    {
        return [
            'id' => $this->id,
            'nom' => $this->nom,
            'ordre' => $this->ordre,
            'compte' => $this->compte,
            'actif' => $this->actif,
            'dossier' => $this->dossier,
            'filtre_de' => $this->filtreDe,
            'filtre_a' => $this->filtreA,
            'filtre_objet' => $this->filtreObjet,
            'filtre_corps' => $this->filtreCorps,
            'filtre_fichiers_inclure' => $this->filtreFichiersInclure,
            'filtre_fichiers_exclure' => $this->filtreFichiersExclure,
            'age_max' => $this->ageMax,
            'type_piece' => $this->typePiece,
            'portee' => $this->portee,
            'mise_en_page_pdf' => $this->miseEnPagePdf,
            'action' => $this->action,
            'parametre_action' => $this->parametreAction,
            'titre_depuis' => $this->titreDepuis,
            'etiquettes' => $this->etiquettes,
            'type_document' => $this->typeDocument,
            'emetteur_depuis' => $this->emetteurDepuis,
            'emetteur_choisi' => $this->emetteurChoisi,
            'proprietaire_depuis_regle' => $this->proprietaireDepuisRegle,
        ];
    }

    /**
     * Le parametre d'action est exigé pour étiqueter ou déplacer (modele Paperless), et l'âge reste borné.
     *
     * @throws ErreurCourrier
     */
    public function valider(): void
    {
        if (in_array($this->action, [self::ACTION_ETIQUETER, self::ACTION_DEPLACER], true) && trim($this->parametreAction) === '') {
            throw new ErreurCourrier("une règle qui étiquette ou déplace exige un paramètre d'action");
        }
        if ($this->ageMax > 36500) {
            throw new ErreurCourrier("l'âge maximum d'un courriel est déraisonnable (> 36500 jours)");
        }
        if (trim($this->nom) === '') {
            throw new ErreurCourrier('le nom de la règle est requis');
        }
    }

    /** Titre attribué au document déposé (objet, nom de la pièce sans extension, ou rien). */
    public function titre(Message $message, Piece $piece): ?string
    {
        return match ($this->titreDepuis) {
            self::TITRE_OBJET => $message->sujet,
            self::TITRE_FICHIER => pathinfo($piece->nom, PATHINFO_FILENAME),
            default => null,
        };
    }

    /** Émetteur attribué (rien, adresse, nom — à défaut l'adresse — ou émetteur choisi). */
    public function emetteur(Message $message): ?string
    {
        return match ($this->emetteurDepuis) {
            self::EMETTEUR_COURRIEL => $message->de,
            self::EMETTEUR_NOM => $message->deNom !== '' ? $message->deNom : $message->de,
            self::EMETTEUR_CHOISI => $this->emetteurChoisi,
            default => null,
        };
    }
}
