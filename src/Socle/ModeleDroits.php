<?php

declare(strict_types=1);

namespace Ged\Socle;

use RuntimeException;

/**
 * Droits d'objet à la manière GEDv2 (modèle Paperless `PaperlessObjectPermissions`).
 *
 * Paperless attache des permissions Django directement à chaque objet (`view_document`,
 * `change_document`, …). GEDv2 attache les droits au DOSSIER de droits (`Securite\DroitsDossiers`),
 * hérités par les documents qu'il contient : un droit se pose alors une fois pour un ensemble, et le
 * dossier fait foi. Cette classe traduit les noms Paperless en niveaux GEDv2 — rien de plus, elle ne
 * duplique pas le contrôle, qui reste dans `DroitsDossiers`.
 */
final class ModeleDroits
{
    /** Correspondance nom de permission Paperless → niveau GEDv2. */
    private const ACTIONS = [
        'view_document' => 'lire',
        'view_correspondent' => 'lire',
        'view_documenttype' => 'lire',
        'view_tag' => 'lire',
        'view_storagepath' => 'lire',
        'view_savedview' => 'lire',
        'add_document' => 'deposer',
        'change_document' => 'modifier',
        'change_correspondent' => 'modifier',
        'change_documenttype' => 'modifier',
        'change_tag' => 'modifier',
        'change_storagepath' => 'modifier',
        'change_savedview' => 'modifier',
        'delete_document' => 'gerer',
        'delete_correspondent' => 'gerer',
        'delete_documenttype' => 'gerer',
        'delete_tag' => 'gerer',
        'delete_storagepath' => 'gerer',
        'delete_savedview' => 'gerer',
    ];

    /** Niveau GEDv2 correspondant à une permission Paperless ; refuse un nom inconnu. */
    public static function action(string $codename): string
    {
        if (!isset(self::ACTIONS[$codename])) {
            throw new RuntimeException("droit : permission inconnue « $codename »");
        }

        return self::ACTIONS[$codename];
    }

    /**
     * Les permissions Paperless qui ne donnent que ce niveau GEDv2 (l'équivalent de
     * `get_groups_with_only_permission`, à la granularité du dossier).
     *
     * @return list<string>
     */
    public static function permissionsDuNiveau(string $niveau): array
    {
        if (!in_array($niveau, ['lire', 'deposer', 'modifier', 'gerer'], true)) {
            throw new RuntimeException("droit : niveau inconnu « $niveau » (lire, deposer, modifier, gerer)");
        }

        return array_keys(self::ACTIONS, $niveau, true);
    }

    /** Le compte a-t-il ce droit Paperless sur le dossier de droits du document ? */
    public static function autoriseObjet(\Ged\Securite\DroitsDossiers $droits, int $compte, int $document, string $codename): bool
    {
        $dossier = $droits->dossierPourDocument($document);
        if ($dossier === null) {
            return false;   // hors de tout dossier de droits : refus par défaut
        }

        return $droits->autorise($compte, $dossier, self::action($codename));
    }
}
