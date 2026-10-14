<?php

declare(strict_types=1);

namespace DeployEcommerce\CommunityPatches;

/**
 * Helpers for unified diffs in git format.
 */
class Diff
{
    /**
     * Splits a diff into its per-file sections, keyed by the path each section changes.
     *
     * @return array<string, string>
     */
    public static function sections(string $diff): array
    {
        $sections = [];
        foreach (preg_split('/^(?=diff --git )/m', $diff) ?: [] as $section) {
            if (preg_match('#^diff --git a/(\S+) b/(\S+)#', $section, $m)) {
                $sections[$m[2]] = $section;
            }
        }

        return $sections;
    }

    /**
     * @return string[] every path the diff reads or writes
     */
    public static function paths(string $diff): array
    {
        preg_match_all('#^(?:---|\+\+\+) [ab]/(\S+)#m', $diff, $m);

        return array_values(array_unique($m[1]));
    }
}
