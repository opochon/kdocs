<?php

declare(strict_types=1);

namespace Ged\DepotFileserver;

/**
 * RÉVERSIBILITÉ : le dépôt reste une arborescence de fichiers ordinaires. Ce service exporte une
 * copie propre, sans aucun élément interne (ni `.depot-fileserver/`, ni dossiers de versions
 * masqués), et vérifie que la copie est identique au dépôt, fichier par fichier, par empreinte.
 *
 * On peut donc revenir à une gestion de fichiers classique sans migration ni renommage :
 * débrancher la GED laisse le partage utilisable tel quel.
 */
final class Reversibilite
{
    public function __construct(private readonly string $racine)
    {
    }

    /**
     * Copie les documents visibles du dépôt vers $destination (arborescence conservée).
     *
     * @return array{exportes:int, chemin:string}
     */
    public function exporter(string $destination): array
    {
        $documents = Arborescence::parcourir($this->racine);
        $exportes = 0;
        foreach ($documents as $relatif => $info) {
            $cible = rtrim($destination, '/\\') . '/' . $relatif;
            Arborescence::assurerDossier(dirname($cible));
            if (!@copy($info['absolu'], $cible)) {
                throw new \RuntimeException('réversibilité : copie impossible vers ' . $cible);
            }
            ++$exportes;
        }

        return ['exportes' => $exportes, 'chemin' => $destination];
    }

    /**
     * Vérifie que l'export est identique au dépôt (même arborescence, mêmes empreintes).
     *
     * @return array{identiques:int, ecarts:list<string>}
     */
    public function verifier(string $destination): array
    {
        $ecarts = [];
        $identiques = 0;
        foreach (Arborescence::parcourir($this->racine) as $relatif => $info) {
            $cible = rtrim($destination, '/\\') . '/' . $relatif;
            if (!is_file($cible)) {
                $ecarts[] = $relatif . ' : absent de l’export';
                continue;
            }
            if (Arborescence::empreinte($cible) !== Arborescence::empreinte($info['absolu'])) {
                $ecarts[] = $relatif . ' : empreinte différente';
                continue;
            }
            ++$identiques;
        }

        return ['identiques' => $identiques, 'ecarts' => $ecarts];
    }
}
