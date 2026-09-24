<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Reviews;

/**
 * Same matching as the Site Reviews / WordPress comment blacklist (pure): one entry per line, case-insensitive
 * substring of the name, content, email, IP or title. Empty lines and lines over 256 characters are ignored.
 */
final class Blacklist
{
    public static function matches(string $entries, string $target): bool
    {
        if (trim($entries) === '' || trim($target) === '') {
            return false;
        }
        foreach (preg_split('/\R/u', $entries) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || strlen($line) > 256) {
                continue;
            }
            if (preg_match('#'.preg_quote($line, '#').'#iu', $target) === 1) {
                return true;
            }
        }

        return false;
    }
}
