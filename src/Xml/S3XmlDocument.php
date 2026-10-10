<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Xml;

use OpsFour\S3Server\Exception\MalformedXmlException;

/** Namespace-aware traversal for S3 XML control documents. */
final class S3XmlDocument
{
    private const string S3_NAMESPACE = 'http://s3.amazonaws.com/doc/2006-03-01/';

    public static function assertRoot(\SimpleXMLElement $element, string $name): void
    {
        $namespace = dom_import_simplexml($element)->namespaceURI;
        if ($element->getName() !== $name || ! in_array($namespace, [null, self::S3_NAMESPACE], true)) {
            throw new MalformedXmlException("Expected {$name} XML root element.");
        }
    }

    /** @return list<\SimpleXMLElement> */
    public static function children(\SimpleXMLElement $element, bool $allowText = false): array
    {
        $domElement = dom_import_simplexml($element);
        $namespace = $domElement->namespaceURI;
        if (! $allowText) {
            foreach ($domElement->childNodes as $node) {
                if ($node instanceof \DOMText && trim($node->textContent) !== '') {
                    throw new MalformedXmlException('XML container contains unexpected text.');
                }
            }
        }
        $children = $element->xpath('./*') ?: [];
        foreach ($children as $child) {
            if (dom_import_simplexml($child)->namespaceURI !== $namespace) {
                throw new MalformedXmlException('XML element uses an unexpected namespace.');
            }
        }

        return array_values($children);
    }

    /**
     * SimpleXML property access does not expose prefixed children, even when
     * their namespace is the same as the root. Return an internal copy whose
     * element names are namespace-neutral after validating the complete tree.
     */
    public static function namespaceNeutral(\SimpleXMLElement $element): \SimpleXMLElement
    {
        $source = dom_import_simplexml($element);
        if (! $source instanceof \DOMElement) {
            throw new MalformedXmlException('Unable to inspect XML document.');
        }
        $namespace = $source->namespaceURI;
        if ($namespace === null) {
            return $element;
        }

        $document = new \DOMDocument('1.0', 'UTF-8');
        $root = self::copyElement($source, $document, $namespace);
        $document->appendChild($root);
        $copy = simplexml_import_dom($root);
        if ($copy === null) {
            throw new MalformedXmlException('Unable to normalize XML document.');
        }

        return $copy;
    }

    private static function copyElement(\DOMElement $source, \DOMDocument $document, string $namespace): \DOMElement
    {
        if ($source->namespaceURI !== $namespace) {
            throw new MalformedXmlException('XML element uses an unexpected namespace.');
        }
        $localName = $source->localName;
        if ($localName === null || $localName === '') {
            throw new MalformedXmlException('XML element has no local name.');
        }
        $copy = $document->createElement($localName);
        foreach ($source->attributes as $attribute) {
            if ($attribute->namespaceURI === 'http://www.w3.org/2000/xmlns/') {
                continue;
            }
            if ($attribute->namespaceURI === null) {
                $copy->setAttribute($attribute->name, $attribute->value);
            } else {
                $copy->setAttributeNS($attribute->namespaceURI, $attribute->nodeName, $attribute->value);
            }
        }
        foreach ($source->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                $copy->appendChild(self::copyElement($child, $document, $namespace));
            } elseif ($child instanceof \DOMCdataSection) {
                $copy->appendChild($document->createCDATASection($child->data));
            } elseif ($child instanceof \DOMText) {
                $copy->appendChild($document->createTextNode($child->data));
            }
        }

        return $copy;
    }
}
