<?php declare(strict_types=1);

namespace Indexer;

class LockFileUtils
{
    public static array $lockList = [];

    public static function setLock(string $lockFilePath): bool
    {
        if (isset(self::$lockList[$lockFilePath])) {
            return true;
        }

        $fp = fopen($lockFilePath, 'c+b');
        if ($fp === false) {
            return false;
        }
        $fl = flock($fp, LOCK_EX | LOCK_NB);

        if ($fl) {
            self::$lockList[$lockFilePath] = $fp;
        } else {
            fclose($fp);
        }

        return $fl;
    }

    public static function releaseLock(string $lockFilePath): bool
    {
        clearstatcache(true, $lockFilePath);
        if (file_exists($lockFilePath)) {
            if (self::setLock($lockFilePath) &&
                unlink($lockFilePath) && flock(self::$lockList[$lockFilePath], LOCK_UN) &&
                fclose(self::$lockList[$lockFilePath])) {
                unset(self::$lockList[$lockFilePath]);
                return true;
            }
            return false;
        }

        return true;
    }

    public static function checkLock(string $lockFilePath): bool
    {
        return file_exists($lockFilePath);
    }

    public static function cleanLocks(): void
    {
        foreach (array_keys(self::$lockList) as $oneLock) {
            self::releaseLock($oneLock);
        }
    }
}
