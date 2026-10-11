<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Xml;

use OpsFour\S3Server\Exception\MalformedXmlException;

final class SafeXmlParser
{
    public static function parse(
        string $xml,
        string $errorMessage = 'The XML you provided was not well-formed or did not validate against our published schema.',
    ): \SimpleXMLElement {
        if (trim($xml) === '') {
            throw new MalformedXmlException('Request body is empty.');
        }

        // S3 control documents never require DTDs. Reject declarations instead
        // of attempting to rewrite attacker-controlled XML.
        // A NUL byte cannot occur in a valid UTF-8 XML document and is present
        // in UTF-16/32 encodings that could hide a declaration from the byte
        // pattern below.
        if (str_contains($xml, "\0") || preg_match('/<!\s*(?:DOCTYPE|ENTITY)\b/i', $xml) === 1) {
            throw new MalformedXmlException($errorMessage);
        }

        $previousUseErrors = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $element = new \SimpleXMLElement($xml, LIBXML_NONET | LIBXML_COMPACT);
            $errors = libxml_get_errors();
        } catch (\Throwable) {
            throw new MalformedXmlException($errorMessage);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousUseErrors);
        }

        if ($errors !== []) {
            throw new MalformedXmlException($errorMessage);
        }

        $domElement = dom_import_simplexml($element);
        if ($domElement->ownerDocument?->doctype !== null) {
            throw new MalformedXmlException($errorMessage);
        }

        return $element;
    }

    private function __construct() {}
}
