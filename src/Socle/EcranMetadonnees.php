<?php

declare(strict_types=1);

namespace Ged\Socle;

use Ged\Config;

/**
 * Écran Métadonnées de l'administrateur (V2-04) : la nomenclature des types (ajouter, renommer,
 * activer, désactiver — jamais supprimer) et les champs personnalisés typés rattachés à chaque type.
 */
final class EcranMetadonnees
{
    private readonly Config $config;
    private readonly Nomenclature $nomenclature;
    private readonly Champs $champs;

    public function __construct(Config $config)
    {
        $this->config = $config;
        $this->nomenclature = new Nomenclature($config);
        $this->champs = new Champs($config);
    }

    /**
     * Tout ce que `ecrans/metadonnees.twig` attend : les types (y compris désactivés), celui dont on
     * voit les champs (?type=), et les natures proposées à la création d'un champ.
     *
     * @param array<string, mixed> $parametres
     * @return array<string, mixed>
     */
    public function donnees(array $parametres, string $csrf): array
    {
        // Lecture PURE : un GET n'écrit jamais dans le fonds — le contrat HTTP et la sonde parcourent
        // les routes sur des fonds de test en lecture seule. Un fonds sans nomenclature se reconnaît
        // (graine à poser), les six types livrés se posent par l'action d'administration « graine ».
        $types = $this->nomenclature->lister(false);
        $choisi = null;
        $demande = (int) ($parametres['type'] ?? 0);
        foreach ($types as $t) {
            if ($t['id'] === $demande) {
                $choisi = $t;
            }
        }
        $choisi ??= $types[0] ?? null;
        // Étiquettes ACTIVES ET EN VEILLE (un retrait reste lisible et réactivable) avec leur parente.
        $etiquettes = (new Etiquettes($this->config))->lister(false);

        return [
            'ecran' => 'metadonnees',
            'bilan' => \Ged\Ui\Maquette::bilan(),   // badge « à traiter » du rail : encore la maquette (branchement : secteur Ingestion)
            'types' => $types,
            'graineAPoser' => $types === [],
            'typeChoisi' => $choisi === null ? null : $choisi + ['slug' => Fichiers::slug($choisi['nom'])],
            'champsDuType' => $choisi === null ? [] : $this->champs->pourType($choisi['nom']),
            'natures' => Champs::NATURES,
            'etiquettes' => $etiquettes,
            'parentsEtiquettePossibles' => $etiquettes,
            'couleurEtiquetteDefaut' => Etiquettes::COULEUR_DEFAUT,
            'csrf' => $csrf,
        ];
    }
}
