<?php

declare(strict_types=1);

namespace Ged\Archivage;

/** Pont court vers OpenSSL : son vérificateur RFC 3161 contrôle CMS, EKU timeStamping et chaîne de confiance. */
final class OpenSslTsa
{
    public static function verifier(string $requete, string $reponse, ?string $chaineConservee = null): string
    {
        $temp = sys_get_temp_dir() . '/gedv2-tsa-' . bin2hex(random_bytes(8));
        if (!mkdir($temp, 0700) && !is_dir($temp)) {
            throw ErreurScellement::horodatage('impossible de préparer la vérification OpenSSL');
        }
        try {
            $tsq = $temp . '/requete.tsq'; $tsr = $temp . '/reponse.tsr'; $token = $temp . '/jeton.der'; $chaine = $temp . '/chaine.pem';
            self::ecrire($tsq, $requete, 'requête TSA');
            self::ecrire($tsr, $reponse, 'réponse TSA');
            $arguments = ['ts', '-verify', '-queryfile', $tsq, '-in', $tsr, '-CAfile', self::racines()];
            if ($chaineConservee !== null) {
                $chaineConserveePath = $temp . '/chaine-conservee.pem';
                self::ecrire($chaineConserveePath, $chaineConservee, 'chaîne TSA conservée');
                $arguments[] = '-untrusted';
                $arguments[] = $chaineConserveePath;
            }
            self::executer($arguments);
            self::executer(['ts', '-reply', '-in', $tsr, '-token_out', '-out', $token]);
            self::executer(['pkcs7', '-inform', 'DER', '-in', $token, '-print_certs', '-out', $chaine]);
            $contenu = file_get_contents($chaine);
            if ($contenu === false) {
                throw ErreurScellement::horodatage('impossible de lire la chaîne de certificats TSA');
            }
            if (!str_contains($contenu, 'BEGIN CERTIFICATE')) {
                throw ErreurScellement::horodatage('la TSA n’a fourni aucun certificat à conserver');
            }
            return $contenu;
        } finally {
            // Répertoire système éphémère, isolé de tout fonds GED ; laissé au nettoyage du système en cas d'échec.
        }
    }

    public static function racines(): string { return __DIR__ . '/certificats/racines-tsa.pem'; }

    private static function ecrire(string $chemin, string $contenu, string $nom): void
    {
        if (file_put_contents($chemin, $contenu, LOCK_EX) === false) {
            throw ErreurScellement::horodatage("impossible d’écrire $nom pour OpenSSL");
        }
    }

    /** @param list<string> $arguments */
    private static function executer(array $arguments): void
    {
        $bin = getenv('OPENSSL_BIN') ?: 'C:/Program Files/Git/usr/bin/openssl.exe';
        $commande = implode(' ', array_map('escapeshellarg', array_merge([$bin], $arguments)));
        $descripteurs = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $processus = proc_open($commande, $descripteurs, $pipes);
        if (!is_resource($processus)) { throw ErreurScellement::horodatage('openssl indisponible'); }
        $sortie = stream_get_contents($pipes[1]); $erreur = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        if (proc_close($processus) !== 0) {
            throw ErreurScellement::horodatage('vérification cryptographique TSA refusée : ' . trim($erreur . ' ' . $sortie));
        }
    }
}
