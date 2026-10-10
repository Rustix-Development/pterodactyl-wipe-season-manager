<?php

namespace RustixDev\WipeSeasonManager\Support;

/**
 * A file or folder to delete during a wipe, relative to the server's root.
 * Only the last part may hold wildcards (* and ?), for example
 * "server/rust/player.blueprints.*". Folders above it are matched exactly.
 */
final class PathPattern
{
    public function __construct(public readonly string $directory, public readonly string $name) {}

    /** Null when the line is not a safe pattern. */
    public static function parse(string $line): ?self
    {
        $line = trim(str_replace('\\', '/', $line));
        $parts = array_values(array_filter(explode('/', $line), fn (string $part) => $part !== '' && $part !== '.'));

        if ($parts === [] || in_array('..', $parts, true)) {
            return null;
        }

        $name = array_pop($parts);
        foreach ($parts as $part) {
            if (strpbrk($part, '*?[') !== false) {
                return null;
            }
        }

        // Never allow a pattern that matches everything in the server's root folder.
        if ($parts === [] && trim($name, '*') === '') {
            return null;
        }

        if (strlen($line) > 255 || strpbrk($name, '[]') !== false) {
            return null;
        }

        return new self('/'.implode('/', $parts), $name);
    }

    public function hasWildcard(): bool
    {
        return strpbrk($this->name, '*?') !== false;
    }

    public function matches(string $filename): bool
    {
        return fnmatch($this->name, $filename, FNM_PERIOD);
    }
}
