<?php

namespace App\Domain\Keywords;

/** A fanmulu display name is a durable pin, separate from ordinary SEO keywords. */
final class AssignmentPurpose
{
    public static function seo(array $assignments): array
    {
        return array_values(array_filter($assignments, fn (array $assignment): bool => ($assignment['purpose'] ?? 'seo') !== 'fanmulu_title'));
    }

    public static function display(array $assignments): ?array
    {
        foreach ($assignments as $assignment) {
            if (($assignment['purpose'] ?? null) === 'fanmulu_title' && ($assignment['role'] ?? null) === 'primary') {
                return $assignment;
            }
        }

        // Legacy snapshots used their sole primary as the display-name pin.
        foreach ($assignments as $assignment) {
            if (($assignment['role'] ?? null) === 'primary') {
                return $assignment;
            }
        }

        return null;
    }

    public static function separated(array $assignments): bool
    {
        foreach ($assignments as $assignment) {
            if (($assignment['purpose'] ?? null) === 'fanmulu_title' && ($assignment['role'] ?? null) === 'primary') {
                return true;
            }
        }

        return false;
    }
}
