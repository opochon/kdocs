<?php

declare(strict_types=1);

namespace Ged\Ingestion;

/**
 * Messages courts de statut d'une consommation.
 *
 * Équivalent GEDv2 de `ConsumerStatusShortMessage` : les mêmes clés machine que Paperless, pour que
 * l'écran de suivi et le journal parlent le même langage qu'un import Paperless (un statut est une
 * valeur stable, jamais un texte traduit). Les valeurs sont celles du contrat Paperless v2.20.5.
 */
final class MessageStatut
{
    public const DOCUMENT_ALREADY_EXISTS = 'document_already_exists';
    public const DOCUMENT_ALREADY_EXISTS_IN_TRASH = 'document_already_exists_in_trash';
    public const ASN_ALREADY_EXISTS = 'asn_already_exists';
    public const ASN_ALREADY_EXISTS_IN_TRASH = 'asn_already_exists_in_trash';
    public const ASN_RANGE = 'asn_value_out_of_range';
    public const FILE_NOT_FOUND = 'file_not_found';
    public const NEW_FILE = 'new_file';
    public const UNSUPPORTED_TYPE = 'unsupported_type';
    public const PARSING_DOCUMENT = 'parsing_document';
    public const GENERATING_THUMBNAIL = 'generating_thumbnail';
    public const PARSE_DATE = 'parse_date';
    public const SAVE_DOCUMENT = 'save_document';
    public const FINISHED = 'finished';
    public const FAILED = 'failed';

    /** @return list<string> */
    public static function tous(): array
    {
        return [
            self::DOCUMENT_ALREADY_EXISTS, self::DOCUMENT_ALREADY_EXISTS_IN_TRASH,
            self::ASN_ALREADY_EXISTS, self::ASN_ALREADY_EXISTS_IN_TRASH, self::ASN_RANGE,
            self::FILE_NOT_FOUND, self::NEW_FILE, self::UNSUPPORTED_TYPE, self::PARSING_DOCUMENT,
            self::GENERATING_THUMBNAIL, self::PARSE_DATE, self::SAVE_DOCUMENT, self::FINISHED,
            self::FAILED,
        ];
    }
}
