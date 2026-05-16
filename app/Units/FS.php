<?php

namespace App\Units;

use Psr\Log\LoggerInterface;

class FS
{
    /**
     * Recursively delete a directory
     */
    public static function deleteDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $itemPath = $item->getRealPath();
            if ($item->isDir()) {
                rmdir($itemPath);
            } else {
                unlink($itemPath);
            }
        }

        rmdir($path);
    }

    /**
     * Recursively delete a directory and clean up empty parent directories
     */
    public static function deleteDirectoryDeep(string $path, string $storage_path, LoggerInterface $logger): bool
    {
        if (!is_dir($path)) {
            // Directory doesn't exist — consider it "deleted"
            return true;
        }

        try {
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($items as $item) {
                $itemPath = $item->getRealPath();

                if ($item->isDir()) {
                    rmdir($itemPath);
                } else {
                    unlink($itemPath);
                }
            }

            // Remove the repo directory itself
            rmdir($path);

            // Clean up empty parent directories going up the chain
            // Structure: /storage/{service}/{user}/{repo}.git/
            // After deleting repo.git: check if {user} is empty
            // After deleting {user}: check if {service} is empty
            FS::cleanupEmptyParents(dirname($path), $storage_path, $logger);

            return true;

        } catch (\Throwable $e) {
            $logger->error('Failed to delete directory', [
                'path'  => $path,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Clean up empty parent directories going up the tree.
     * Stops at the storage root to avoid deleting it.
     */
    public static function cleanupEmptyParents(string $path, string $storage_path, ?LoggerInterface $logger): void
    {
        $storagePath = rtrim($storage_path, '/');

        // Normalize paths for comparison
        $storagePath = realpath($storagePath) ?: $storagePath;

        while ($path && $path !== '/' && $path !== $storagePath) {
            // Resolve real path, but if directory was already deleted, use the path as-is
            $realPath = realpath($path) ?: $path;
            $storageRealPath = realpath($storagePath) ?: $storagePath;

            // Don't go above storage root
            if ($realPath === $storageRealPath) {
                break;
            }

            // Check if path is still within storage
            if (!str_starts_with($realPath, $storageRealPath)) {
                break;
            }

            // Check if directory is empty
            if (!is_dir($path)) {
                // Already gone — move up
                $path = dirname($path);
                continue;
            }

            $files = @scandir($path);

            if ($files === false) {
                // Cannot read directory — stop
                break;
            }

            // Filter out . and ..
            $files = array_diff($files, ['.', '..']);

            if (count($files) === 0) {
                // Directory is empty — remove it
                if (@rmdir($path)) {
                    $logger->info("      Cleaned up empty parent: {$path}");
                    // Move up to parent
                    $path = dirname($path);
                } else {
                    // Cannot remove (permissions?) — stop
                    break;
                }
            } else {
                // Directory not empty — stop going up
                break;
            }
        }
    }

}