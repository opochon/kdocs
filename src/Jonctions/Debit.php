<?php

declare(strict_types=1);

namespace Ged\Jonctions;

use Ged\Config;

/**
 * Limiteur de débit de l'API REST des documents, par jeton : une fenêtre glissante simple, tenue dans un
 * fichier d'état hors requête (aucune table, aucun démon — compatible hébergement mutualisé).
 *
 * La décision est prise SOUS VERROU exclusif : deux requêtes simultanées du même jeton ne peuvent pas
 * dépasser le plafond. Le compteur n'est jamais une donnée utilisateur : un fichier par empreinte de clé,
 * remis à zéro à l'expiration de la fenêtre. Si l'état est illisible, on REFUSE (fail-closed) : la cause
 * est nommée côté appelant, jamais un 500 muet.
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

    /** Une requête de plus est-elle permise pour cette clé dans la fenêtre courante ? */
    public function autoriser(string $cle): bool
    {
        if (!is_dir($this->dossier) && !mkdir($this->dossier, 0775, true) && !is_dir($this->dossier)) {
            return false;   // état indisponible : on n'ouvre pas la porte
        }
        $poignee = fopen($this->dossier . '/' . hash('sha256', $cle) . '.json', 'c+');
        if ($poignee === false) {
            return false;
        }

        try {
            if (!flock($poignee, LOCK_EX)) {
                return false;
            }
            $etat = json_decode((string) stream_get_contents($poignee), true);
            $maintenant = time();
            if (!is_array($etat) || $maintenant - (int) ($etat['debut'] ?? 0) >= $this->fenetre) {
                $etat = ['debut' => $maintenant, 'requetes' => 0];
            }
            if ((int) $etat['requetes'] >= $this->maximum) {
                return false;
            }
            $etat['requetes'] = (int) $etat['requetes'] + 1;
            ftruncate($poignee, 0);
            rewind($poignee);
            fwrite($poignee, (string) json_encode($etat));
            fflush($poignee);

            return true;
        } finally {
            flock($poignee, LOCK_UN);
            fclose($poignee);
        }
    }
}
