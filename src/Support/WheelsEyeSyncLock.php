<?php

namespace App\Support;

/**
 * Shared non-blocking lock for WheelsEye pull sync (CLI loop + optional manual HTTP sync).
 * Same path as scripts/wheelseye-sync-loop.sh flock file.
 */
class WheelsEyeSyncLock
{
    public const LOCK_PATH = '/tmp/wheelseye-sync.lock';

    /**
     * @return resource|null File handle with exclusive lock, or null if another sync is running
     */
    public static function tryAcquire()
    {
        $fp = @fopen(self::LOCK_PATH, 'c');
        if ($fp === false) {
            return null;
        }

        if (!flock($fp, LOCK_EX | LOCK_NB)) {
            fclose($fp);
            return null;
        }

        return $fp;
    }

    /**
     * @param resource|null $fp
     */
    public static function release($fp): void
    {
        if (!is_resource($fp)) {
            return;
        }

        flock($fp, LOCK_UN);
        fclose($fp);
    }
}
