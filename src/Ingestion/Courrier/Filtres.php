<?php

declare(strict_types=1);

namespace Ged\Ingestion\Courrier;

/**
 * Filtres d'une règle de courrier, à la manière de GEDv2 : sous-chaînes d'en-têtes (expéditeur, destinataire,
 * objet, corps) et jokers de nom de pièce (`fnmatch`, insensible à la casse). Les critères « déjà fait » d'une
 * action (déjà lu, déjà signalé, déjà étiqueté) écartent un message : c'est ainsi que Paperless évite de
 * retraiter ce qu'il a déjà marqué.
 */
final class Filtres
{
    /** Extensions de pièces que GEDv2 sait déposer (la catégorie `chaine` accepte ces familles). */
    public const EXTENSIONS = ['pdf', 'png', 'jpg', 'jpeg', 'tif', 'tiff', 'webp', 'bmp', 'gif',
        'docx', 'doc', 'xlsx', 'xls', 'odt', 'ods', 'rtf', 'txt', 'csv', 'eml', 'msg', 'html', 'htm'];

    public static function messageRetenu(Message $message, Regle $regle, \DateTimeImmutable $maintenant): bool
    {
        if ($regle->ageMax > 0 && $message->date < $maintenant->modify(sprintf('-%d days', $regle->ageMax))) {
            return false;
        }
        if ($regle->filtreDe !== '' && !str_contains($message->de, $regle->filtreDe)) {
            return false;
        }
        if ($regle->filtreObjet !== '' && !str_contains($message->sujet, $regle->filtreObjet)) {
            return false;
        }
        if ($regle->filtreCorps !== '' && !str_contains($message->corps, $regle->filtreCorps)) {
            return false;
        }
        if ($regle->filtreA !== '') {
            $trouve = false;
            foreach ($message->destinataires as $destinataire) {
                if (str_contains($destinataire, $regle->filtreA)) {
                    $trouve = true;
                    break;
                }
            }
            if (!$trouve) {
                return false;
            }
        }

        return true;
    }

    /** Le message est-il déjà dans l'état que l'action de la règle pose ? Alors il n'est pas retraité. */
    public static function retenuParAction(Message $message, Regle $regle): bool
    {
        return match ($regle->action) {
            Regle::ACTION_MARQUER_LU => !$message->vu,
            Regle::ACTION_DRAPEAU => !$message->signale,
            Regle::ACTION_ETIQUETER => self::etiquetteRetenue($message, $regle->parametreAction),
            default => true,
        };
    }

    private static function etiquetteRetenue(Message $message, string $parametre): bool
    {
        if (str_starts_with(strtolower($parametre), 'apple:')) {
            return !$message->signale;
        }

        return !in_array($parametre, $message->motsCles, true);
    }

    /**
     * Noms de fichiers retenus par les motifs d'inclusion et d'exclusion (séparés par des virgules, jokers
     * autorisés, comparaison insensible à la casse), dans l'ordre d'entrée.
     *
     * @param list<string> $noms
     * @return list<string>
     */
    public static function fichiersRetenus(array $noms, string $inclure, string $exclure): array
    {
        $retenus = [];
        foreach ($noms as $nom) {
            if (!self::nomInclus($nom, $inclure) || self::nomExclu($nom, $exclure)) {
                continue;
            }
            $retenus[] = $nom;
        }

        return $retenus;
    }

    private static function nomInclus(string $nom, string $motifs): bool
    {
        if (trim($motifs) === '') {
            return true;
        }
        foreach (explode(',', $motifs) as $motif) {
            $motif = trim($motif);
            if ($motif !== '' && fnmatch(strtolower($motif), strtolower($nom))) {
                return true;
            }
        }

        return false;
    }

    private static function nomExclu(string $nom, string $motifs): bool
    {
        if (trim($motifs) === '') {
            return false;
        }
        foreach (explode(',', $motifs) as $motif) {
            $motif = trim($motif);
            if ($motif !== '' && fnmatch(strtolower($motif), strtolower($nom))) {
                return true;
            }
        }

        return false;
    }

    /** La pièce passe-t-elle la disposition, le nom et le type supporté ? */
    public static function pieceRetenue(Piece $piece, Regle $regle): bool
    {
        if ($piece->estInline() && $regle->typePiece === Regle::PIECES_JOINTES) {
            return false;
        }
        if (self::fichiersRetenus([$piece->nom], $regle->filtreFichiersInclure, $regle->filtreFichiersExclure) === []) {
            return false;
        }

        return self::pieceSupportee($piece);
    }

    public static function pieceSupportee(Piece $piece): bool
    {
        $extension = strtolower(pathinfo($piece->nom, PATHINFO_EXTENSION));
        if ($extension !== '' && in_array($extension, self::EXTENSIONS, true)) {
            return true;
        }

        // Nom sans extension exploitable : on se rabat sur le type MIME déclaré.
        $type = strtolower($piece->type);

        return str_starts_with($type, 'application/pdf') || str_starts_with($type, 'image/');
    }
}
