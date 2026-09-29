<?php

declare(strict_types=1);

namespace Ged\Archivage;

use Ged\Config;
use Ged\Socle\DepotFichiers;

/**
 * Le geste de scellement : empreinte du fichier ET de son `.meta.json`, jeton RFC 3161 sur l'empreinte
 * composite, justificatif et jeton écrits DANS LE DOSSIER (`.scellement/<fichier>.json` + `.tsr`), échéance
 * de rétention calculée par le type. Idempotent : re-sceller un document déjà scellé ne réécrit rien.
 *
 * La TSA est injectable (simulée en test, réelle en recette via `TSA_URL`) ; l'horloge aussi.
 */
final class Scelleur
{
    public function __construct(
        private readonly DepotFichiers $depot,
        private readonly Horodateur $horodateur,
        private readonly Horloge $horloge,
        private readonly Retention $retention,
    ) {
    }

    /** Scelleur de production : TSA réelle lue dans `TSA_URL`, horloge système, rétention du fonds. */
    public static function depuisConfig(Config $config, ?Horloge $horloge = null, ?Horodateur $horodateur = null): self
    {
        $depot = new DepotFichiers($config);
        $horloge ??= new HorlogeSysteme();
        $horodateur ??= new TsaReelle($config->get('TSA_URL', ''));

        return new self($depot, $horodateur, $horloge, new Retention($depot->racine(), $horloge));
    }

    /**
     * Scelle un document vivant du fonds. Refuse (en le nommant) un document inconnu, à la corbeille, ou
     * dont la TSA ne répond pas. Un document déjà scellé rend son scellement existant (idempotent).
     *
     * @return array<string, mixed>
     */
    public function sceller(int $idDocument, string $auteur): array
    {
        $fiche = $this->depot->fiche($idDocument);
        if ($fiche === null) {
            throw ErreurScellement::scellement("document $idDocument introuvable dans l’index");
        }
        if ($fiche['supprime_le'] !== null) {
            throw ErreurScellement::scellement("le document $idDocument est à la corbeille, le restaurer d’abord");
        }

        $cheminRelatif = (string) $fiche['chemin_relatif'];
        $absolu = $this->depot->cheminAbsolu($cheminRelatif);
        $existant = Scellement::lire($absolu, $idDocument);
        if (Scellement::estActif($existant)) {
            return [
                'document_id' => $idDocument,
                'chemin_relatif' => $cheminRelatif,
                'empreinte_scellee' => (string) $existant['empreinte_scellee'],
                'echeance' => (string) $existant['echeance'],
                'scelle_le' => (string) $existant['scelle_le'],
                'tsa_url' => (string) ($existant['tsa_url'] ?? ''),
                'already_sealed' => true,
            ];
        }

        /** @var array<string, mixed> $meta */
        $meta = is_array($fiche['meta']) ? $fiche['meta'] : [];
        $empreinteFichier = Empreintes::fichier($absolu);
        $empreinteMeta = Empreintes::meta($absolu);
        $empreinteScellee = Empreintes::scellee($empreinteFichier, $empreinteMeta);
        $jeton = $this->horodateur->horodater(Empreintes::binaire($empreinteScellee));
        $instant = $this->horloge->maintenant();
        $reference = $this->retention->reference($meta, $instant);
        $type = isset($meta['type']) && $meta['type'] !== null ? (string) $meta['type'] : null;

        $donnees = [
            'version' => 1,
            'document_id' => $idDocument,
            'chemin_relatif' => $cheminRelatif,
            'nom' => basename($cheminRelatif),
            'algorithme' => 'sha256',
            'empreinte_fichier' => $empreinteFichier,
            'empreinte_meta' => $empreinteMeta,
            'empreinte_scellee' => $empreinteScellee,
            'scelle_le' => $instant->format(\DateTimeInterface::ATOM),
            'scelle_par' => $auteur,
            'type' => $type,
            'reference' => $reference,
            'retention_annees' => $this->retention->annees($type),
            'echeance' => $this->retention->echeance($reference, $type),
            'tsa_url' => $this->horodateur->etiquette(),
            'tsa_jeton' => basename($absolu) . '.tsr',
            'tsa_sha256' => hash('sha256', $jeton),
        ];

        // Un scellement levé puis re-scellé : le justificatif précédent est COPIÉ dans l'historique, jamais supprimé.
        if ($existant !== null) {
            $this->archiverJustificatif($absolu, $existant);
        }

        Scellement::ecrire($absolu, $donnees, $jeton);

        return $donnees + ['already_sealed' => false];
    }

    /** Le document est-il scellé actuellement ? */
    public function estScelle(int $idDocument): bool
    {
        $fiche = $this->depot->fiche($idDocument);
        if ($fiche === null) {
            return false;
        }

        return Scellement::estActif(Scellement::lire($this->depot->cheminAbsolu((string) $fiche['chemin_relatif']), $idDocument));
    }

    /** Copie le justificatif d'un scellement levé dans `.scellement/historique/` (zéro suppression). */
    private function archiverJustificatif(string $absolu, array $existant): void
    {
        $dossier = Scellement::dossier($absolu) . '/historique';
        if (!is_dir($dossier) && !mkdir($dossier, 0775, true) && !is_dir($dossier)) {
            return;
        }
        $etiquette = preg_replace('/[^A-Za-z0-9]/', '-', (string) ($existant['scelle_le'] ?? 'ancien')) ?? 'ancien';
        @copy(Scellement::cheminJson($absolu), $dossier . '/' . basename($absolu) . '.' . $etiquette . '.json');
    }
}
