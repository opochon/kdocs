<?php

declare(strict_types=1);

namespace Ged\Archivage;

/**
 * Rejoue un scellement depuis le dossier seul (aucune base) : empreinte du fichier, empreinte des
 * métadonnées, empreinte composite scellée, et jeton TSA (structure DER, statut granted, empreinte
 * couverte, sha256 du jeton). Un fonds sain rend 0 écart ; un octet changé est trouvé et nommé.
 *
 * Limite assumée : la signature PKI de la TSA n'est pas vérifiée cryptographiquement (openssl ts le ferait) ;
 * on vérifie ici que le jeton existe, est bien formé, couvre l'empreinte scellée et n'a pas bougé.
 */
final class Verificateur
{
    public function __construct(private readonly string $racineFonds)
    {
    }

    /**
     * @return array{scellements:int, ok:bool, ecarts:list<string>, details:list<array<string, mixed>>}
     */
    public function verifierFonds(): array
    {
        $ecarts = [];
        $details = [];
        $fichiers = Scellement::parcourir($this->racineFonds);
        foreach ($fichiers as $fichier) {
            $bilan = $this->verifier($fichier);
            $details[] = $bilan;
            foreach ($bilan['ecarts'] as $ecart) {
                $ecarts[] = $bilan['scellement'] . ' : ' . $ecart;
            }
        }

        return ['scellements' => count($fichiers), 'ok' => $ecarts === [], 'ecarts' => $ecarts, 'details' => $details];
    }

    /**
     * @return array{document:string, scellement:string, ok:bool, ecarts:list<string>}
     */
    public function verifier(string $cheminJson): array
    {
        $nom = basename($cheminJson);
        $ecarts = [];
        $contenu = is_file($cheminJson) ? (string) file_get_contents($cheminJson) : null;
        $scellement = $contenu === null ? null : Scellement::decoder($contenu);
        if ($scellement === null) {
            return ['document' => '', 'scellement' => $nom, 'ok' => false, 'ecarts' => ['scellement illisible (JSON absent ou invalide)']];
        }

        $absolu = $this->localiserDocument($cheminJson, $scellement);
        if ($absolu === null) {
            return ['document' => '', 'scellement' => $nom, 'ok' => false, 'ecarts' => ['document introuvable pour ce scellement']];
        }

        $empreinteFichier = Empreintes::fichier($absolu);
        if ($empreinteFichier !== ($scellement['empreinte_fichier'] ?? '')) {
            $ecarts[] = 'empreinte du fichier différente (fichier modifié hors application)';
        }
        $empreinteMeta = Empreintes::meta($absolu);
        if ($empreinteMeta !== ($scellement['empreinte_meta'] ?? '')) {
            $ecarts[] = 'empreinte des métadonnées (meta) différente (métadonnées modifiées hors application)';
        }

        $scellee = Empreintes::scellee($empreinteFichier, $empreinteMeta);
        if ($scellee !== ($scellement['empreinte_scellee'] ?? '')) {
            $ecarts[] = 'empreinte scellée incohérente';
        }

        $jeton = dirname($cheminJson) . '/' . (string) ($scellement['tsa_jeton'] ?? (basename($absolu) . '.tsr'));
        if (!is_file($jeton)) {
            $ecarts[] = 'jeton TSA introuvable';
        } else {
            $octets = (string) file_get_contents($jeton);
            if (($scellement['tsa_sha256'] ?? '') !== hash('sha256', $octets)) {
                $ecarts[] = 'empreinte du jeton TSA différente (jeton altéré)';
            }
            if (!Der::estSequence($octets) || !Der::statutAccorde($octets)) {
                $ecarts[] = 'jeton TSA invalide (structure DER ou statut non granted)';
            } elseif (preg_match('/^[0-9a-f]{64}$/', (string) ($scellement['empreinte_scellee'] ?? '')) === 1
                && !Der::couvre($octets, Empreintes::binaire((string) $scellement['empreinte_scellee']))) {
                $ecarts[] = 'jeton TSA valide mais ne couvre pas l’empreinte scellée';
            }
        }

        return ['document' => $absolu, 'scellement' => $nom, 'ok' => $ecarts === [], 'ecarts' => $ecarts];
    }

    /**
     * Le document d'un scellement : le fichier frère de même nom (cas nominal), sinon le chemin enregistré
     * dans le justificatif (le dossier fait foi).
     *
     * @param array<string, mixed> $scellement
     */
    private function localiserDocument(string $cheminJson, array $scellement): ?string
    {
        // Le document vit juste au-dessus de son dossier `.scellement/` : le justificatif le nomme.
        $dossierDocuments = dirname(dirname($cheminJson));
        $nomDocument = (string) ($scellement['nom'] ?? '');
        if ($nomDocument !== '' && is_file($dossierDocuments . '/' . $nomDocument)) {
            return $dossierDocuments . '/' . $nomDocument;
        }
        // Repli : le chemin enregistré dans le justificatif (le dossier fait foi).
        $cheminRelatif = (string) ($scellement['chemin_relatif'] ?? '');
        $candidat = rtrim($this->racineFonds, '/') . '/' . $cheminRelatif;
        if ($cheminRelatif !== '' && is_file($candidat)) {
            return $candidat;
        }

        return null;
    }
}
