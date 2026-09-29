<?php

declare(strict_types=1);

namespace Ged\Archivage;

/**
 * TSA simulée des tests : aucun réseau, réponse DER déterministe qui porte le statut « granted » et
 * l'empreinte horodatée. Elle permet de prouver la chaîne scellement → jeton → vérification de bout en bout.
 */
final class TsaSimulee implements Horodateur
{
    public function __construct(private readonly Horloge $horloge = new HorlogeSysteme(), private readonly string $nom = 'TSA simulée')
    {
    }

    /** Réponse TSA simulée, reproductible : statut granted, empreinte couverte, instant de l'horloge. */
    public static function reponseDepuis(string $empreinteBinaire, int $horodatage): string
    {
        return Der::tlv(0x30, Der::tlv(0x02, "\x00")                       // PKIStatus granted
            . Der::tlv(0x04, $empreinteBinaire)                           // MessageImprint (empreinte)
            . Der::tlv(0x18, gmdate('YmdHis', $horodatage) . 'Z'));       // GeneralizedTime
    }

    public function horodater(string $empreinteBinaire): string
    {
        if (strlen($empreinteBinaire) !== 32) {
            throw ErreurScellement::horodatage('l’empreinte à horodater doit faire 32 octets (sha256)');
        }

        return self::reponseDepuis($empreinteBinaire, $this->horloge->maintenant()->getTimestamp());
    }

    public function etiquette(): string
    {
        return $this->nom;
    }
}
