<?php

declare(strict_types=1);

namespace Ged\Workflows;

/**
 * Traduit le formulaire d'administration d'un workflow en définition du modèle (déclencheur + action).
 * Aucune règle de gestion n'est inventée ici : les clés produites sont celles que `Declencheur` et
 * `Action` lisent déjà, donc celles du rejeu de parité.
 */
final class Formulaire
{
    /**
     * @param array<string, mixed> $corps
     * @return array{nom:string, ordre:int, actif:bool, triggers:list<array<string,mixed>>, actions:list<array<string,mixed>>}
     */
    public static function workflow(array $corps): array
    {
        $nom = trim((string) ($corps['nom'] ?? ''));
        if ($nom === '') {
            throw new ErreurWorkflow('le nom du workflow est requis');
        }

        $trigger = ['type' => (int) ($corps['declencheur_type'] ?? Declencheur::AJOUT)];
        $filtre = (string) ($corps['declencheur_filtre'] ?? 'aucun');
        $valeur = trim((string) ($corps['declencheur_valeur'] ?? ''));
        if ($filtre !== 'aucun' && $valeur !== '') {
            $trigger[$filtre] = $filtre === 'filter_has_tags' ? self::liste($valeur) : $valeur;
        }

        return [
            'nom' => $nom,
            'ordre' => (int) ($corps['ordre'] ?? 0),
            'actif' => !empty($corps['actif']),
            'triggers' => [$trigger],
            'actions' => [self::action($corps)],
        ];
    }

    /**
     * @param array<string, mixed> $corps
     * @return array<string, mixed>
     */
    private static function action(array $corps): array
    {
        $type = (int) ($corps['action_type'] ?? Action::AFFECTATION);
        $champ = (string) ($corps['action_champ'] ?? 'titre');
        $valeur = trim((string) ($corps['action_valeur'] ?? ''));

        return match ($type) {
            Action::COURRIEL => ['type' => Action::COURRIEL, 'order' => 0, 'email' => [
                'to' => trim((string) ($corps['courriel_to'] ?? '')),
                'subject' => (string) ($corps['courriel_sujet'] ?? 'Notification {{title}}'),
                'body' => (string) ($corps['courriel_corps'] ?? 'Pièce {{filename}}'),
                'include_document' => !empty($corps['courriel_piece']),
            ]],
            Action::WEBHOOK => ['type' => Action::WEBHOOK, 'order' => 0, 'webhook' => [
                'url' => trim((string) ($corps['webhook_url'] ?? '')),
                'use_params' => true,
                'as_json' => false,
                'params' => ['titre' => '{{title}}'],
                'headers' => [],
                'include_document' => false,
            ]],
            Action::RETRAIT => ['type' => Action::RETRAIT, 'order' => 0] + self::retrait($champ, $valeur),
            default => ['type' => Action::AFFECTATION, 'order' => 0] + self::affectation($champ, $valeur),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function affectation(string $champ, string $valeur): array
    {
        return match ($champ) {
            'etiquettes' => ['assign_tags' => self::liste($valeur)],
            'type' => ['assign_document_type' => $valeur],
            'emetteur' => ['assign_correspondent' => $valeur],
            'dossier' => ['assign_storage_path' => $valeur],
            'proprietaire' => ['assign_owner' => $valeur],
            default => ['assign_title' => $valeur],
        };
    }

    /**
     * Retrait : valeur vide = « tout retirer » ; sinon les valeurs visées.
     *
     * @return array<string, mixed>
     */
    private static function retrait(string $champ, string $valeur): array
    {
        $singulier = match ($champ) {
            'etiquettes' => 'tags',
            'type' => 'document_types',
            'emetteur' => 'correspondents',
            'dossier' => 'storage_paths',
            'proprietaire' => 'owners',
            default => throw new ErreurWorkflow('un titre ne se retire pas : choisissez « Affecter »'),
        };
        if ($valeur === '') {
            return ['remove_all_' . $singulier => true];
        }

        return ['remove_' . $singulier => self::liste($valeur)];
    }

    /** @return list<string> */
    private static function liste(string $valeur): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $valeur)), static fn (string $v): bool => $v !== ''));
    }
}
