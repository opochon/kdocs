<?php

declare(strict_types=1);

namespace Ged\Workflows;

/**
 * Action de workflow, à la manière de Paperless-ngx 2.20 (`WorkflowAction` et ses variantes courriel
 * `WorkflowActionEmail` et webhook `WorkflowActionWebhook`) : elle affecte ou retire des attributs du
 * document, envoie un courriel ou appelle un webhook.
 *
 * Fonction PURE sur la fiche : `appliquer()` rend la fiche modifiée et la liste des effets ; il n'écrit
 * jamais. L'écriture (index et `.meta.json`) appartient à `Applicateur`, via le contrat `Depot`.
 *
 * Les noms de clés restent ceux de Paperless (`assign_*`, `remove_*`, `email`, `webhook`) : les vecteurs
 * extraits des tests Paperless se relisent sans traduction, et la correspondance est explicite.
 */
final class Action
{
    public const AFFECTATION = 1;
    public const RETRAIT = 2;
    public const COURRIEL = 3;
    public const WEBHOOK = 4;

    /** Extension GEDv2 : mise à la corbeille (jamais une destruction — le document reste restaurable). */
    public const SUPPRESSION = 5;

    /** @var array<int, string> */
    public const TYPES = [
        self::AFFECTATION => 'affectation',
        self::RETRAIT => 'retrait',
        self::COURRIEL => 'courriel',
        self::WEBHOOK => 'webhook',
        self::SUPPRESSION => 'suppression',
    ];

    /**
     * Applique une action à une fiche document. Rend la fiche modifiée et les effets observables
     * (courriel envoyé, webhook appelé), sans écrire hors des ports injectés.
     *
     * @param array<string, mixed> $action
     * @param array<string, mixed> $document
     * @return array{document: array<string, mixed>, effets: list<string>, courriel: array<string,mixed>|null, webhook: array<string,mixed>|null}
     */
    public static function appliquer(
        array $action,
        array $document,
        ?PortCourriel $courriel = null,
        ?PortWebhook $webhook = null,
    ): array {
        $type = (int) ($action['type'] ?? self::AFFECTATION);
        $effets = [];
        $courrielEnvoye = null;
        $webhookAppele = null;

        if (in_array($type, [self::AFFECTATION, self::RETRAIT], true)) {
            $document = $type === self::AFFECTATION
                ? self::affecter($action, $document, $effets)
                : self::retirer($action, $document, $effets);
        } elseif ($type === self::COURRIEL) {
            $courrielEnvoye = self::envoyerCourriel($action, $document, $courriel, $effets);
        } elseif ($type === self::WEBHOOK) {
            $webhookAppele = self::appelerWebhook($action, $document, $webhook, $effets);
        } elseif ($type === self::SUPPRESSION) {
            $effets[] = 'suppression';
        } else {
            throw new ErreurWorkflow("action : type inconnu ($type)");
        }

        return ['document' => $document, 'effets' => $effets, 'courriel' => $courrielEnvoye, 'webhook' => $webhookAppele];
    }

    /**
     * @param array<string, mixed> $action
     * @param array<string, mixed> $document
     * @param list<string>         $effets
     * @return array<string, mixed>
     */
    private static function affecter(array $action, array $document, array &$effets): array
    {
        if (trim((string) ($action['assign_title'] ?? '')) !== '') {
            try {
                $document['titre'] = self::resoudre((string) $action['assign_title'], $document);
            } catch (ErreurWorkflow) {
                // Titre invalide : Paperless journalise et garde le titre d'origine.
                $effets[] = 'titre_refuse';
            }
        }
        foreach ([
            'assign_correspondent' => 'emetteur',
            'assign_document_type' => 'type',
            'assign_storage_path' => 'dossier',
            'assign_owner' => 'proprietaire',
        ] as $cle => $cible) {
            $valeur = $action[$cle] ?? null;
            if (is_string($valeur) && $valeur !== '') {
                $document[$cible] = $valeur;
            }
        }

        $etiquettes = self::liste((array) ($document['etiquettes'] ?? []));
        foreach (self::liste((array) ($action['assign_tags'] ?? [])) as $etiquette) {
            if (!in_array($etiquette, $etiquettes, true)) {
                $etiquettes[] = $etiquette;
            }
        }
        $document['etiquettes'] = $etiquettes;

        foreach (['view' => 'lecture', 'change' => 'modification'] as $mode => $cible) {
            foreach (['users' => 'utilisateurs', 'groups' => 'groupes'] as $sujet => $cibleSujet) {
                $ajouts = self::liste((array) ($action['assign_' . $mode . '_' . $sujet] ?? []));
                if ($ajouts !== []) {
                    $actuels = self::liste((array) ($document['droits'][$cible][$cibleSujet] ?? []));
                    $document['droits'][$cible][$cibleSujet] = array_values(array_unique(array_merge($actuels, $ajouts)));
                }
            }
        }

        $champs = (array) ($document['champs'] ?? []);
        $valeurs = (array) ($action['assign_custom_fields_values'] ?? []);
        foreach (self::liste((array) ($action['assign_custom_fields'] ?? [])) as $champ) {
            $champs[$champ] = $valeurs[$champ] ?? null;
        }
        $document['champs'] = $champs;

        if (($action['assign_tags'] ?? []) !== []) {
            $effets[] = 'etiquettes';
        }

        return $document;
    }

    /**
     * @param array<string, mixed> $action
     * @param array<string, mixed> $document
     * @param list<string>         $effets
     * @return array<string, mixed>
     */
    private static function retirer(array $action, array $document, array &$effets): array
    {
        $document['etiquettes'] = ($action['remove_all_tags'] ?? false) === true
            ? []
            : self::soustraire((array) ($document['etiquettes'] ?? []), (array) ($action['remove_tags'] ?? []));

        foreach ([
            'remove_correspondents' => 'emetteur',
            'remove_document_types' => 'type',
            'remove_storage_paths' => 'dossier',
            'remove_owners' => 'proprietaire',
        ] as $cle => $cible) {
            $exclus = array_map([self::class, 'normaliser'], self::liste((array) ($action[$cle] ?? [])));
            $tout = ($action['remove_all_' . self::singulier($cible)] ?? false) === true;
            if ($tout || ($document[$cible] !== null && in_array(self::normaliser((string) $document[$cible]), $exclus, true))) {
                $document[$cible] = null;
            }
        }

        if (($action['remove_all_permissions'] ?? false) === true) {
            $document['droits'] = ['lecture' => ['utilisateurs' => [], 'groupes' => []],
                'modification' => ['utilisateurs' => [], 'groupes' => []]];
        } else {
            foreach (['view' => 'lecture', 'change' => 'modification'] as $mode => $cible) {
                foreach (['users' => 'utilisateurs', 'groups' => 'groupes'] as $sujet => $cibleSujet) {
                    $retires = self::liste((array) ($action['remove_' . $mode . '_' . $sujet] ?? []));
                    if ($retires !== []) {
                        $document['droits'][$cible][$cibleSujet] = self::soustraire(
                            (array) ($document['droits'][$cible][$cibleSujet] ?? []),
                            $retires,
                        );
                    }
                }
            }
        }

        if (($action['remove_all_custom_fields'] ?? false) === true) {
            $document['champs'] = [];
        } else {
            $champs = (array) ($document['champs'] ?? []);
            foreach (self::liste((array) ($action['remove_custom_fields'] ?? [])) as $champ) {
                unset($champs[$champ]);
            }
            $document['champs'] = $champs;
        }

        $document['droits'] = self::normaliserDroits((array) $document['droits']);
        $effets[] = 'retrait';

        return $document;
    }

    /**
     * @param array<string, mixed> $action
     * @param array<string, mixed> $document
     * @param list<string>         $effets
     * @return array<string, mixed>|null
     */
    private static function envoyerCourriel(array $action, array $document, ?PortCourriel $port, array &$effets): ?array
    {
        $email = (array) ($action['email'] ?? []);
        $destinataires = array_values(array_filter(array_map('trim', explode(',', (string) ($email['to'] ?? '')))));
        if ($destinataires === []) {
            throw new ErreurWorkflow('action courriel : aucun destinataire');
        }
        $sujet = self::resoudreOuVide((string) ($email['subject'] ?? ''), $document);
        $corps = self::resoudreOuVide((string) ($email['body'] ?? ''), $document);
        $inclure = (bool) ($email['include_document'] ?? false);

        $port?->envoyer($destinataires, $sujet, $corps, $inclure, $document);
        $effets[] = 'courriel';

        return ['destinataires' => $destinataires, 'sujet' => $sujet, 'corps' => $corps, 'inclure_document' => $inclure];
    }

    /**
     * @param array<string, mixed> $action
     * @param array<string, mixed> $document
     * @param list<string>         $effets
     * @return array<string, mixed>|null
     */
    private static function appelerWebhook(array $action, array $document, ?PortWebhook $port, array &$effets): ?array
    {
        $webhook = (array) ($action['webhook'] ?? []);
        $url = trim((string) ($webhook['url'] ?? ''));
        if ($url === '') {
            throw new ErreurWorkflow('action webhook : URL vide');
        }
        $params = (array) ($webhook['params'] ?? []);
        $useParams = (bool) ($webhook['use_params'] ?? true);
        $donnees = $useParams
            ? array_map(fn (mixed $valeur): string => self::resoudreOuVide((string) $valeur, $document), $params)
            : self::resoudreOuVide((string) ($webhook['body'] ?? ''), $document);
        $entetes = [];
        foreach ((array) ($webhook['headers'] ?? []) as $nom => $valeur) {
            $entetes[(string) $nom] = (string) $valeur;
        }
        $json = (bool) ($webhook['as_json'] ?? false);
        $inclure = (bool) ($webhook['include_document'] ?? false);

        $port?->appeler($url, $entetes, $donnees, $json, $inclure, $document);
        $effets[] = 'webhook';

        return ['url' => $url, 'json' => $json, 'entetes' => $entetes, 'donnees' => $donnees, 'inclure_document' => $inclure];
    }

    /**
     * Résout les gabarits de Paperless `{{placeholder}}` (titre, émetteur, type, propriétaire, nom de
     * fichier, dates, année de création, URL du document). Un placeholder inconnu lève une erreur nommée
     * — l'appelant décide du repli (Paperless garde alors la valeur d'origine).
     *
     * @param array<string, mixed> $document
     */
    public static function resoudre(string $gabarit, array $document): string
    {
        $contexte = self::contexte($document);

        return (string) preg_replace_callback(
            '/\{\{\s*([a-z_]+)\s*\}\}/i',
            static function (array $trouve) use ($contexte): string {
                $cle = mb_strtolower($trouve[1]);
                if (!array_key_exists($cle, $contexte)) {
                    throw new ErreurWorkflow("gabarit : placeholder inconnu « $cle »");
                }

                return (string) $contexte[$cle];
            },
            $gabarit,
        );
    }

    /** Variante tolérante (courriel, webhook) : un placeholder inconnu devient vide, rien n'est bloqué. */
    private static function resoudreOuVide(string $gabarit, array $document): string
    {
        try {
            return self::resoudre($gabarit, $document);
        } catch (ErreurWorkflow) {
            return '';
        }
    }

    /**
     * @param array<string, mixed> $document
     * @return array<string, string|int>
     */
    private static function contexte(array $document): array
    {
        $dates = (array) ($document['dates'] ?? []);
        $creation = (string) ($dates['created'] ?? '');

        return [
            'title' => (string) ($document['titre'] ?? ''),
            'titre' => (string) ($document['titre'] ?? ''),
            'correspondent' => (string) ($document['emetteur'] ?? ''),
            'emetteur' => (string) ($document['emetteur'] ?? ''),
            'document_type' => (string) ($document['type'] ?? ''),
            'type' => (string) ($document['type'] ?? ''),
            'owner_username' => (string) ($document['proprietaire'] ?? ''),
            'filename' => (string) ($document['nom_fichier'] ?? ''),
            'current_filename' => (string) ($document['nom_fichier'] ?? ''),
            'added' => (string) ($dates['added'] ?? ''),
            'created' => $creation,
            'created_year' => preg_match('#^(\d{4})#', $creation) === 1 ? substr($creation, 0, 4) : '',
            'doc_url' => '/documents/' . (int) ($document['id'] ?? 0),
            'montant' => is_numeric($document['montant'] ?? null) ? (string) $document['montant'] : '',
        ];
    }

    /** @param list<mixed> $liste @return list<string> */
    private static function liste(array $liste): array
    {
        return array_values(array_unique(array_filter(
            array_map('strval', $liste),
            static fn (string $valeur): bool => trim($valeur) !== '',
        )));
    }

    /** @param list<mixed> $base @param list<mixed> $retires @return list<string> */
    private static function soustraire(array $base, array $retires): array
    {
        $exclus = array_map([self::class, 'normaliser'], self::liste($retires));

        return array_values(array_filter(
            self::liste($base),
            static fn (string $valeur): bool => !in_array(self::normaliser($valeur), $exclus, true),
        ));
    }

    /** @param array<string, mixed> $droits @return array<string, array<string, list<string>>> */
    private static function normaliserDroits(array $droits): array
    {
        $normalise = [];
        foreach (['lecture', 'modification'] as $mode) {
            foreach (['utilisateurs', 'groupes'] as $sujet) {
                $normalise[$mode][$sujet] = self::liste((array) ($droits[$mode][$sujet] ?? []));
            }
        }

        return $normalise;
    }

    private static function singulier(string $cible): string
    {
        return match ($cible) {
            'emetteur' => 'correspondents',
            'type' => 'document_types',
            'dossier' => 'storage_paths',
            'proprietaire' => 'owners',
            default => $cible,
        };
    }

    private static function normaliser(string $valeur): string
    {
        return mb_strtolower(trim($valeur), 'UTF-8');
    }
}
