<?php

declare(strict_types=1);

namespace PigIphoneUse;

use RuntimeException;
use SimpleXMLElement;

/**
 * The XML property lists usbmuxd speaks: dictionaries, arrays, strings, integers, reals, booleans
 * and data — what `ListDevices` and `Connect` need, nothing more.
 */
final class Plist
{
    /** @param array<string, mixed> $value a dictionary */
    public static function encode(array $value): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">' . "\n"
            . '<plist version="1.0">' . self::value($value) . '</plist>' . "\n";
    }

    public static function decode(string $xml): mixed
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $root = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET);
        } finally {
            libxml_use_internal_errors($previous);
        }

        if ($root === false || $root->getName() !== 'plist') {
            throw new RuntimeException('Not a property list.');
        }

        foreach ($root->children() as $child) {
            return self::read($child);
        }

        return null;
    }

    private static function value(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? '<true/>' : '<false/>',
            is_int($value) => "<integer>{$value}</integer>",
            is_float($value) => '<real>' . $value . '</real>',
            is_string($value) => '<string>' . htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</string>',
            is_array($value) && array_is_list($value) && $value !== [] => '<array>' . implode('', array_map(self::value(...), $value)) . '</array>',
            is_array($value) => '<dict>' . implode('', array_map(
                static fn (string|int $key, mixed $item): string => '<key>' . htmlspecialchars((string) $key, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</key>' . self::value($item),
                array_keys($value),
                $value,
            )) . '</dict>',
            default => throw new RuntimeException('A property list cannot hold a ' . get_debug_type($value) . '.'),
        };
    }

    private static function read(SimpleXMLElement $node): mixed
    {
        switch ($node->getName()) {
            case 'dict':
                $out = [];
                $key = null;
                foreach ($node->children() as $child) {
                    if ($child->getName() === 'key') {
                        $key = (string) $child;
                    } elseif ($key !== null) {
                        $out[$key] = self::read($child);
                        $key = null;
                    }
                }

                return $out;
            case 'array':
                $out = [];
                foreach ($node->children() as $child) {
                    $out[] = self::read($child);
                }

                return $out;
            case 'string':
                return (string) $node;
            case 'integer':
                return (int) (string) $node;
            case 'real':
                return (float) (string) $node;
            case 'true':
                return true;
            case 'false':
                return false;
            case 'data':
                return base64_decode(preg_replace('/\s+/', '', (string) $node) ?? '', true);
            case 'date':
                return (string) $node;
            default:
                return null;
        }
    }
}
