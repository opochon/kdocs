<?php

declare(strict_types=1);

namespace Ged\Ingestion;

use Ged\Contrats\Classeur;
use Ged\Contrats\Depot;

/**
 * Un « tic » d'ingestion : relever les sources, découper les lots reçus, classer et ranger les pièces prêtes.
 * Budget de temps borné (hébergement mutualisé : jamais un appel PHP long) ; ce qui n'est pas fini l'est au tic
 * suivant. Chaque étape est reprise si une tâche est tuée : les verrous sont courts et expirent.
 */
final class Pilote
{
    /** @param list<object> $sources objets exposant relever(): list<Entree> */
    public function __construct(
        private readonly Travaux $travaux,
        private readonly array $sources,
        private readonly Decoupeur $decoupeur,
        private readonly Depot $depot,
        private readonly ?Classeur $classeur,
        private readonly string $zoneTravail,
    ) {
    }

    /** @return array{recus:int, doublons:int, decoupes:int, ranges:int, erreurs:int} */
    public function tic(int $budgetS = 20): array
    {
        $fin = microtime(true) + $budgetS;
        $compte = ['recus' => 0, 'doublons' => 0, 'decoupes' => 0, 'ranges' => 0, 'erreurs' => 0];

        foreach ($this->sources as $source) {
            foreach ($source->relever() as $entree) {
                if ($this->travaux->recevoir($entree) !== null) {
                    $compte['recus']++;
                } else {
                    // Déjà reçu : on garde le fichier de côté (jamais supprimé), on ne le traite pas deux fois.
                    $this->mettreDeCote($entree->chemin, 'doublons');
                    $compte['doublons']++;
                }
            }
        }

        // Ranger d'abord ce qui est prêt (rapide), puis découper un lot (long).
        while (microtime(true) < $fin && ($t = $this->travaux->reserver('pret')) !== null) {
            try {
                $this->ranger($t);
                $compte['ranges']++;
            } catch (\Throwable $e) {
                $this->travaux->echouer((int) $t['id'], 'rangement : ' . $e->getMessage());
                $compte['erreurs']++;
            }
        }
        if (microtime(true) < $fin && ($t = $this->travaux->reserver('recu', 30)) !== null) {
            try {
                $this->decouper($t);
                $compte['decoupes']++;
            } catch (\Throwable $e) {
                $this->travaux->echouer((int) $t['id'], 'découpe : ' . $e->getMessage());
                $compte['erreurs']++;
            }
        }

        return $compte;
    }

    /** @param array<string, mixed> $lot */
    private function decouper(array $lot): void
    {
        $sortie = $this->zoneTravail . '/pieces/' . $lot['id'];
        $pieces = $this->decoupeur->decouper((string) $lot['chemin'], $sortie);
        if ($pieces === []) {
            throw new \RuntimeException('le moteur n’a rendu aucune pièce');
        }
        $base = pathinfo((string) $lot['nom_origine'], PATHINFO_FILENAME);
        foreach ($pieces as $p) {
            $nom = $base . (count($pieces) > 1 ? ' (p. ' . Travaux::plages($p['pages']) . ')' : '') . '.pdf';
            $this->travaux->ajouterPiece((int) $lot['id'], $p['fichier'], $nom, (string) $lot['source'], $p['pages'], $p['texte'], $p['analyse']);
        }
        $this->travaux->passer((int) $lot['id'], 'decoupe');
    }

    /** @param array<string, mixed> $piece */
    private function ranger(array $piece): void
    {
        $analyse = json_decode((string) $piece['analyse'], true) ?: [];
        $texte = (string) ($analyse['texte'] ?? '');
        unset($analyse['texte']);

        $proposition = $this->classeur?->proposer($texte, $analyse, (string) $piece['nom_origine']);
        $meta = [
            'titre' => $proposition['titre'] ?? pathinfo((string) $piece['nom_origine'], PATHINFO_FILENAME),
            'source' => $piece['source'],
            'origine' => ['travail' => (int) $piece['id'], 'lot' => (int) $piece['parent_id'], 'pages' => $piece['pages']],
            'texte' => $texte,
            'statut' => ($proposition['valide'] ?? false) ? 'valide' : 'a_controler',
        ];
        if ($proposition !== null) {
            foreach (['type', 'emetteur', 'date', 'montant', 'etiquettes', 'raison'] as $cle) {
                $meta[$cle] = $proposition[$cle] ?? null;
            }
            $meta['classement'] = ['source' => $proposition['source'], 'confiance' => $proposition['confiance'], 'preuve' => $proposition['preuve']];
        }
        $id = $this->depot->deposer((string) $piece['chemin'], $meta);
        $this->travaux->passer((int) $piece['id'], 'range', $id);
    }

    private function mettreDeCote(string $chemin, string $sousDossier): void
    {
        $cible = $this->zoneTravail . '/' . $sousDossier;
        if (!is_dir($cible)) {
            mkdir($cible, 0775, true);
        }
        @rename($chemin, $cible . '/' . basename($chemin));
    }
}
