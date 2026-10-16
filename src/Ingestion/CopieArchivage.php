<?php

declare(strict_types=1);

namespace Ged\Ingestion;

use Ged\Config;
use Ged\Socle\Fichiers;

/**
 * La copie d'archivage PDF/A-2b d'un document du fonds : conversion, état, persistance.
 *
 *  - L'ORIGINAL n'est jamais modifié ni remplacé : la copie vit à côté, dans un dossier interne
 *    `.pdfa/` (même famille que `.versions/`), nommée comme l'original (`facture.pdf` →
 *    `.pdfa/facture.pdf` ; `photo.jpg` → `.pdfa/photo.jpg.pdf`). Un dossier en point est ignoré par
 *    l'indexeur et le reconstructeur : la copie ne devient jamais un faux document.
 *  - L'état est enregistré dans le `.meta.json` (`pdfa: {…}`) quand le document n'est pas scellé. Pour un
 *    document SCELLÉ, la Garde d'archivage couvre le `.meta.json` : le réécrire casserait le sceau. L'état
 *    identique vit alors dans `.pdfa/<fichier>.pdfa.json`, et la copie reste permise (elle n'altère pas
 *    l'original).
 *  - Un échec rend un bloc `statut=echec` avec sa raison ; le document reste ingéré.
 *  - Idempotent : deux passes sur un document inchangé rendent exactement le même bloc, sans refaire la copie.
 */
final class CopieArchivage
{
    private readonly string $racine;

    public function __construct(private readonly Config $config, private readonly ConversionPdfA $conversion)
    {
        $this->racine = rtrim(str_replace('\\', '/', $config->chemin('STORAGE_ROOT', 'storage/documents')), '/');
    }

    public static function depuis(Config $config): self
    {
        return new self($config, ConversionPdfA::depuis());
    }

    /** Convention de nommage : la copie d'archivage d'un document, à côté de lui. */
    public static function cheminCopie(string $absoluDocument): string
    {
        $nom = basename($absoluDocument);

        return dirname($absoluDocument) . '/.pdfa/' . (str_ends_with(strtolower($nom), '.pdf') ? $nom : $nom . '.pdf');
    }

    /**
     * Un PDF ou une image bitmap : les seuls formats dont on sait produire une copie PDF/A ici.
     * (La bureautique passera un jour par LibreOffice → PDF ; hors périmètre de ce lot.)
     */
    public static function estEligible(string $absoluDocument): bool
    {
        if (!is_file($absoluDocument)) {
            return false;
        }
        $debut = (string) @file_get_contents($absoluDocument, false, null, 0, 5);
        if (str_starts_with($debut, '%PDF')) {
            return true;
        }

        return @getimagesize($absoluDocument) !== false;
    }

    /**
     * Produit (ou retrouve) la copie d'archivage et rend le bloc `pdfa` persisté.
     *
     * `$forcer` est la relance EXPLICITE demandée par un humain : la conversion est refaite même après un
     * échec déjà persisté. Sans elle, cet échec est rendu tel quel (aucune reprise silencieuse). Le champ
     * `reprise` (`auto` | `jamais`) est ce qui autorise le RATTRAPAGE à relancer tout seul les seules causes
     * d'environnement : un échec définitif (chiffré, corrompu) n'est jamais repris automatiquement.
     *
     * @return array<string, mixed>
     */
    public function pour(string $absoluDocument, string $cheminRelatif, ?int $idDocument = null, bool $forcer = false): array
    {
        $empreinteOrigine = is_file($absoluDocument) ? (string) hash_file('sha256', $absoluDocument) : '';
        if ($empreinteOrigine === '') {
            return ['statut' => 'echec', 'niveau' => '2b', 'raison' => 'copie d’archivage impossible : document illisible'];
        }
        if (!self::estEligible($absoluDocument)) {
            return ['statut' => 'non_applicable', 'niveau' => '2b', 'empreinte_origine' => $empreinteOrigine,
                'raison' => 'copie d’archivage sans objet : ni PDF ni image'];
        }

        $existant = $this->blocPersiste($absoluDocument);
        if ($existant !== null && ($existant['empreinte_origine'] ?? null) === $empreinteOrigine) {
            $statut = (string) ($existant['statut'] ?? '');
            if ($statut === 'echec') {
                if (!$forcer) {
                    return $existant;   // pas de relance demandée : l'échec est rendu tel quel
                }
                // Relance explicite : la conversion est refaite. C'est le rattrapage qui décide, lui, de ne
                // relancer D'ELLE-MÊME que les causes d'environnement (`reprise: auto`, via `aReprendre()`).
            } elseif ($statut === 'ok' && $this->copieValide($absoluDocument, $existant)) {
                return $existant;
            }
        }

        $copie = self::cheminCopie($absoluDocument);
        $raison = $this->conversion->convertir($absoluDocument, $copie);
        if ($raison !== null) {
            $bloc = ['statut' => 'echec', 'niveau' => '2b', 'empreinte_origine' => $empreinteOrigine,
                'raison' => $raison, 'reprise' => ConversionPdfA::reprise($raison), 'le' => date(\DateTimeInterface::ATOM)];
        } else {
            $bloc = ['statut' => 'ok', 'niveau' => '2b',
                'chemin' => $this->cheminRelatif($copie), 'empreinte_sha256' => (string) hash_file('sha256', $copie),
                'taille' => (int) filesize($copie), 'empreinte_origine' => $empreinteOrigine, 'le' => date(\DateTimeInterface::ATOM)];
        }
        $this->persister($absoluDocument, $cheminRelatif, $idDocument, $bloc);

        return $bloc;
    }

    /**
     * L'état courant de la copie, sans convertir : `ok`, `echec`, `en_attente` (éligible mais pas encore
     * produite) ou `non_applicable`. `telechargeable` dit si l'écran peut proposer le téléchargement ;
     * `reprise` (`auto` | `jamais`) dit si un échec est rejouable sans rien changer au document.
     *
     * @return array{statut:string, niveau:string, telechargeable:bool, chemin:string, raison:string, empreinte_sha256:string, reprise:string}
     */
    public function etat(string $absoluDocument, ?int $idDocument = null): array
    {
        $vierge = ['statut' => 'en_attente', 'niveau' => '2b', 'telechargeable' => false, 'chemin' => '',
            'raison' => '', 'empreinte_sha256' => '', 'reprise' => ''];
        if (!is_file($absoluDocument)) {
            return array_merge($vierge, ['statut' => 'absent', 'raison' => 'document introuvable']);
        }
        if (!self::estEligible($absoluDocument)) {
            return array_merge($vierge, ['statut' => 'non_applicable', 'raison' => 'ni PDF ni image']);
        }
        $bloc = $this->blocPersiste($absoluDocument);
        if ($bloc === null) {
            return $vierge;
        }
        $statut = (string) ($bloc['statut'] ?? 'en_attente');
        if ($statut === 'ok' && !$this->copieValide($absoluDocument, $bloc)) {
            return array_merge($vierge, ['raison' => 'copie absente ou altérée']);
        }
        if ($statut === 'ok' && ($bloc['chemin'] ?? '') !== $this->cheminRelatif(self::cheminCopie($absoluDocument))) {
            // Le document a été déplacé : la copie doit être refaite à sa nouvelle place (le rattrapage s'en charge).
            return array_merge($vierge, ['raison' => 'copie à refaire après déplacement']);
        }

        return ['statut' => $statut, 'niveau' => (string) ($bloc['niveau'] ?? '2b'),
            'telechargeable' => $statut === 'ok', 'chemin' => (string) ($bloc['chemin'] ?? ''),
            'raison' => (string) ($bloc['raison'] ?? ''), 'empreinte_sha256' => (string) ($bloc['empreinte_sha256'] ?? ''),
            'reprise' => $statut === 'echec' ? $this->reprise($bloc) : ''];
    }

    /** Vrai si la copie a échoué pour une cause d'environnement : le rattrapage peut la refaire. */
    public function aReprendre(string $absoluDocument, ?int $idDocument = null): bool
    {
        $etat = $this->etat($absoluDocument, $idDocument);

        return $etat['statut'] === 'echec' && $etat['reprise'] === ConversionPdfA::REPRISE_AUTO;
    }

    /** Chemin absolu de la copie si elle est téléchargeable, sinon null (jamais un chemin inventé). */
    public function telechargeable(string $absoluDocument, ?int $idDocument = null): ?string
    {
        $etat = $this->etat($absoluDocument, $idDocument);

        return $etat['telechargeable'] ? self::cheminCopie($absoluDocument) : null;
    }

    /**
     * Le champ `reprise` du bloc, ou son classement à la lecture pour un échec posé avant ce lot
     * (aucun champ dans le `.meta.json`) : les documents déjà bloqués redeviennent réparables.
     *
     * @param array<string, mixed> $bloc
     */
    private function reprise(array $bloc): string
    {
        $reprise = (string) ($bloc['reprise'] ?? '');
        if ($reprise !== '') {
            return $reprise;
        }

        return ConversionPdfA::reprise((string) ($bloc['raison'] ?? ''));
    }

    /** @return array<string, mixed>|null */
    private function blocPersiste(string $absoluDocument): ?array
    {
        $meta = $this->meta($absoluDocument);
        if (is_array($meta['pdfa'] ?? null)) {
            return $meta['pdfa'];
        }
        $flanc = $this->cheminFlanc($absoluDocument);
        if (is_file($flanc)) {
            $decode = json_decode((string) file_get_contents($flanc), true);

            return is_array($decode) ? $decode : null;
        }

        return null;
    }

    /** @param array<string, mixed> $bloc */
    private function copieValide(string $absoluDocument, array $bloc): bool
    {
        $copie = self::cheminCopie($absoluDocument);
        if (!is_file($copie)) {
            return false;
        }
        $empreinte = (string) ($bloc['empreinte_sha256'] ?? '');

        return $empreinte !== '' && hash_file('sha256', $copie) === $empreinte;
    }

    /**
     * Écrit le bloc au bon endroit : `.meta.json` pour un document non scellé (et l'index suit), flanc
     * `.pdfa/<fichier>.pdfa.json` pour un document scellé (le `.meta.json` fait partie du sceau).
     *
     * @param array<string, mixed> $bloc
     */
    private function persister(string $absoluDocument, string $cheminRelatif, ?int $idDocument, array $bloc): void
    {
        if ($this->estScelle($absoluDocument, $idDocument) || !is_file($absoluDocument . '.meta.json')) {
            $this->ecrireFlanc($absoluDocument, $bloc);

            return;
        }
        // Sous verrou du dossier, relu frais depuis le disque, écrit atomiquement.
        $meta = Fichiers::modifierMeta($absoluDocument, static function (array $meta) use ($bloc): array {
            $meta['pdfa'] = $bloc;

            return $meta;
        });
        if ($cheminRelatif !== '' && class_exists(\Ged\Socle\DepotFichiers::class)) {
            try {
                (new \Ged\Socle\DepotFichiers($this->config))->rapporterMeta($cheminRelatif, $meta);
            } catch (\Throwable $e) {
                // L'index n'est qu'une copie : le dossier fait foi et l'indexeur repassera. La cause est tracée.
                error_log('pdfa : index non rafraîchi pour ' . $cheminRelatif . ' — ' . $e->getMessage());
            }
        }
    }

    /** @param array<string, mixed> $bloc */
    private function ecrireFlanc(string $absoluDocument, array $bloc): void
    {
        $dossier = dirname($absoluDocument) . '/.pdfa';
        if (!is_dir($dossier) && !mkdir($dossier, 0775, true) && !is_dir($dossier)) {
            return;
        }
        Fichiers::cacher($dossier);
        file_put_contents($this->cheminFlanc($absoluDocument), Fichiers::json($bloc, true));
    }

    private function cheminFlanc(string $absoluDocument): string
    {
        return dirname($absoluDocument) . '/.pdfa/' . basename($absoluDocument) . '.pdfa.json';
    }

    /** @return array<string, mixed> */
    private function meta(string $absoluDocument): array
    {
        $fichier = $absoluDocument . '.meta.json';
        if (!is_file($fichier)) {
            return [];
        }
        $decode = json_decode((string) file_get_contents($fichier), true);

        return is_array($decode) ? $decode : [];
    }

    private function estScelle(string $absoluDocument, ?int $idDocument): bool
    {
        if (!class_exists(\Ged\Archivage\Garde::class)) {
            return false;
        }

        return \Ged\Archivage\Garde::estScelle($absoluDocument, $idDocument) !== null;
    }

    private function cheminRelatif(string $absolu): string
    {
        $absolu = str_replace('\\', '/', $absolu);
        if ($this->racine !== '' && str_starts_with($absolu, $this->racine . '/')) {
            return substr($absolu, strlen($this->racine) + 1);
        }

        return $absolu;
    }
}
