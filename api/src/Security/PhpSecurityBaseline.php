<?php

declare(strict_types=1);

namespace Muninn\Api\Security;

/**
 * The oldest PHP release on each supported branch that carries the known critical security fixes (D069).
 *
 * Muninn's own PHP requirement (8.2) only says the code runs. A server is only safe to put on the
 * internet when its PHP also has the fixes for "PHP2Shell", CVE-2026-109618 and CVE-2026-109619
 * (unauthenticated remote code execution in PHP itself, fixed by php.net on 2026-10-21). Those bugs
 * fire before any Muninn code runs, so no application change can work around them: only a patched
 * PHP helps. bin/check-setup.php and the admin Overview both use this class to say so.
 */
final class PhpSecurityBaseline
{
    /**
     * First patched release per "major.minor" branch. Raise these when php.net ships a new critical
     * fix, and record why in DECISIONS.md.
     */
    public const PATCHED_RELEASE_BY_BRANCH = [
        '8.2' => '8.2.35',
        '8.3' => '8.3.36',
        '8.4' => '8.4.27',
        '8.5' => '8.5.12',
    ];

    /** The CVEs the baseline covers, for messages. */
    public const COVERED_CVES = 'CVE-2026-109618 and CVE-2026-109619';

    /**
     * Returns the PHP version the server must be updated to, or null when $phpVersion is already
     * patched. Branches newer than the newest one listed are assumed to ship with the fixes
     * (8.6 has them from its release candidates on); branches older than 8.2 are not supported by
     * Muninn at all, so they are pointed at the oldest patched supported release.
     */
    public static function requiredUpdateFor(string $phpVersion): ?string
    {
        $branch = self::branchOf($phpVersion);
        $knownBranches = array_keys(self::PATCHED_RELEASE_BY_BRANCH);
        $oldestKnownBranch = (string) reset($knownBranches);
        $newestKnownBranch = (string) end($knownBranches);

        if (version_compare($branch, $newestKnownBranch, '>')) {
            return null;
        }
        if (version_compare($branch, $oldestKnownBranch, '<')) {
            return self::PATCHED_RELEASE_BY_BRANCH[$oldestKnownBranch];
        }

        $patchedRelease = self::PATCHED_RELEASE_BY_BRANCH[$branch];

        return version_compare(self::releaseNumberOf($phpVersion), $patchedRelease, '>=') ? null : $patchedRelease;
    }

    /** "8.4.27-0051" or "8.4.27" → "8.4". */
    private static function branchOf(string $phpVersion): string
    {
        $versionParts = explode('.', self::releaseNumberOf($phpVersion));

        return ($versionParts[0] ?? '0') . '.' . ($versionParts[1] ?? '0');
    }

    /**
     * Keeps only the leading "major.minor.patch" digits, so distribution suffixes such as
     * "-1~deb13u1" or "-0051" do not confuse version_compare().
     */
    private static function releaseNumberOf(string $phpVersion): string
    {
        return preg_match('/^\d+\.\d+(\.\d+)?/', $phpVersion, $versionMatch) === 1 ? $versionMatch[0] : '0.0.0';
    }
}
