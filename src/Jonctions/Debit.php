<?php

declare(strict_types=1);

namespace Ged\Jonctions;

use Ged\Config;

/**
 * Limiteur de débit de l'API REST des documents, par jeton : une fenêtre glissante simple, tenue dans un
 * fichier d'état hors requête (aucune table, aucun démon — compatible hébergement mutualisé).
 *
 * La décision est prise SOUS VERROU exclusif (un fichier `.lock` dédié) : deux requêtes simultanées du
 * même jeton ne peuvent pas dépasser le plafond. Le compteur n'est jamais une donnée utilisateur : un
 * fichier d'état par empreinte de clé, remis à zéro à l'expiration de la fenêtre. Un état INUTILISABLE
 * lève une exception (jamais un faux 429 « débit dépassé » qui masquerait une panne de disque) :
 * l'appelant la nomme et la trace.
 */
final class Debit
{
    private readonly string $dossier;
    private readonly int $maximum;
    private readonly int $fenetre;

    public function __construct(Config $config)
    {
        $this->dossier = rtrim(str_replace('\\', '/', $config->chemin('API_DEBIT_DOSSIER', 'var/api-debit')), '/');
        $this->maximum = max(1, (int) $config->get('API_DEBIT_MAX', '300'));
        $this->fenetre = max(1, (int) $config->get('API_DEBIT_FENETRE', '60'));
    }

    /**
     * Une requête de plus est-elle permise pour cette clé dans la fenêtre courante ?
     *
     * @throws \RuntimeException si le compteur ne peut pas être tenu (disque, verrou) — jamais confondu
     *                           avec un dépassement de débit.
     */
    public function autoriser(string $cle): bool
    {
        if (!is_dir($this->dossier) && !mkdir($this->dossier, 0775, true) && !is_dir($this->dossier)) {
            throw new \RuntimeException('compteur de débit inutilisable : dossier ' . $this->dossier);
        }
        $empreinte = hash('sha256', $cle);
        $verrou = fopen($this->dossier . '/' . $empreinte . '.lock', 'c');
        if ($verrou === false) {
            throw new \RuntimeException('compteur de débit inutilisable : verrou non ouvrable');
        }

        try {
            if (!flock($verrou, LOCK_EX)) {
                throw new \RuntimeException('compteur de débit inutilisable : verrou impossible');
            }
            $etat = json_decode((string) @file_get_contents($this->dossier . '/' . $empreinte . '.json'), true);
            $maintenant = time();
            if (!is_array($etat) || $maintenant - (int) ($etat['debut'] ?? 0) >= $this->fenetre) {
                $etat = ['debut' => $maintenant, 'requetes' => 0];
            }
            if ((int) $etat['requetes'] >= $this->maximum) {
                return false;
            }
            $etat['requetes'] = (int) $etat['requetes'] + 1;
            if (file_put_contents($this->dossier . '/' . $empreinte . '.json', (string) json_encode($etat)) === false) {
                throw new \RuntimeException('compteur de débit inutilisable : état non écrit');
            }

            return true;
        } finally {
            flock($verrou, LOCK_UN);
            fclose($verrou);
        }
    }
}
