<?php

declare(strict_types=1);

namespace App\Tasks;

use App\App;
use Arris\Toolkit\CLIConsole;

/**
 * GRASP Console Tasks — CLI-команды
 *
 * @package App
 */
class ConsoleTasks
{
    // ============================================================
    //  Commands
    // ============================================================

    public static function cmdExport(array $argv): void
    {
        $repoArg = null;
        $outPath = null;
        $format  = 'zip';

        $i = 0;
        $count = count($argv);

        while ($i < $count) {
            $arg = $argv[$i];

            if ($arg === '-o' || $arg === '--out') {
                $outPath = $argv[$i + 1] ?? null;
                $i += 2;
                continue;
            }

            if (str_starts_with($arg, '--out=')) {
                $outPath = substr($arg, 6);
                $i++;
                continue;
            }

            if (str_starts_with($arg, '-o') && strlen($arg) > 2) {
                $outPath = substr($arg, 2);
                $i++;
                continue;
            }

            if ($arg === '-f' || $arg === '--format') {
                $format = $argv[$i + 1] ?? 'zip';
                $i += 2;
                continue;
            }

            if (str_starts_with($arg, '--format=')) {
                $format = substr($arg, 9);
                $i++;
                continue;
            }

            if (str_starts_with($arg, '-f') && strlen($arg) > 2) {
                $format = substr($arg, 2);
                $i++;
                continue;
            }

            if ($repoArg === null && !str_starts_with($arg, '-')) {
                $repoArg = $arg;
            }

            $i++;
        }

        if (!$repoArg || in_array('--help', $argv, true) || in_array('-h', $argv, true)) {
            self::showExportHelp();
            return;
        }

        CLIConsole::say("<font color='cyan'>Looking up repository: {$repoArg}</font>");

        $repo = self::findRepo($repoArg);

        if (!$repo) {
            CLIConsole::say("<font color='red'>Error:</font> repository not found: {$repoArg}");
            exit(1);
        }

        CLIConsole::say("<font color='green'>Found:</font> {$repo['user_name']}/{$repo['repo_name']} ({$repo['git_service']})");

        self::exportRepo($repo, $outPath, $format);
    }

    // ============================================================
    //  Repository lookup
    // ============================================================

    private static function findRepo(string $identifier): ?array
    {
        $db = App::db();

        $repo = $db->fetchOne('SELECT * FROM v_repositories WHERE remote_url = ?', [$identifier]);
        if ($repo) return $repo;

        $repo = $db->fetchOne('SELECT * FROM v_repositories WHERE remote_url LIKE ?', ['%' . $identifier . '%']);
        if ($repo) return $repo;

        if (str_contains($identifier, '/')) {
            $parts = explode('/', $identifier);

            if (count($parts) === 2) {
                $repo = $db->fetchOne(
                    'SELECT * FROM v_repositories WHERE user_name = ? AND repo_name = ?',
                    [$parts[0], $parts[1]]
                );
                if ($repo) return $repo;
            }

            if (count($parts) === 3) {
                $repo = $db->fetchOne(
                    'SELECT * FROM v_repositories WHERE git_service = ? AND user_name = ? AND repo_name = ?',
                    [$parts[0], $parts[1], $parts[2]]
                );
                if ($repo) return $repo;
            }
        }

        return null;
    }

    // ============================================================
    //  Export logic
    // ============================================================

    private static function exportRepo(array $repo, ?string $outPath, string $format): void
    {
        $storageRoot = App::config('storage.path');
        $fullPath = rtrim($storageRoot, '/') . '/' . ltrim($repo['storage_path'] ?? '', '/');

        if (!is_dir($fullPath)) {
            CLIConsole::say("<font color='red'>Error:</font> repository directory not found on disk: {$fullPath}");
            exit(1);
        }

        if (!$outPath) {
            $filename = "{$repo['user_name']}-{$repo['repo_name']}.{$format}";
            $outPath = getcwd() . '/' . $filename;
        }

        $gitBinary = App::config('git.binary') ?? 'git';

        $command = sprintf(
            '%s -C %s archive --format=%s --output=%s HEAD 2>&1',
            escapeshellcmd($gitBinary),
            escapeshellarg($fullPath),
            escapeshellarg($format),
            escapeshellarg($outPath)
        );

        CLIConsole::say("Archiving <font color='cyan'>HEAD</font> ...");

        $output = shell_exec($command);

        if (!file_exists($outPath)) {
            foreach (['master', 'main'] as $tryRef) {
                @unlink($outPath);
                $command = sprintf(
                    '%s -C %s archive --format=%s --output=%s %s 2>&1',
                    escapeshellcmd($gitBinary),
                    escapeshellarg($fullPath),
                    escapeshellarg($format),
                    escapeshellarg($outPath),
                    escapeshellarg($tryRef)
                );
                shell_exec($command);
                if (file_exists($outPath)) {
                    break;
                }
            }
        }

        if (!file_exists($outPath) || filesize($outPath) === 0) {
            CLIConsole::say("<font color='red'>Error:</font> failed to create archive. Output: " . ($output ? trim($output) : 'no output'));
            @unlink($outPath);
            exit(1);
        }

        $size = self::formatBytes(filesize($outPath));
        CLIConsole::say("<font color='green'>Done:</font> {$outPath} ({$size})");
    }

    // ============================================================
    //  Helpers
    // ============================================================

    private static function formatBytes(int $bytes): string
    {
        if ($bytes === 0) return '0 B';
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = (int) floor(log($bytes, 1024));
        return round($bytes / (1024 ** $i), 1) . ' ' . $units[$i];
    }

    public static function showMainHelp(?string $error = null): void
    {
        if ($error) {
            CLIConsole::say("<font color='red'>Error:</font> {$error}\n");
        }

        CLIConsole::say("<font color='cyan'>GRASP CLI Tool</font>\n");
        CLIConsole::say("<font color='green'>Available commands:</font>\n");

        $commands = [
            '  export    <repo> [--out=path] [--format=zip]  Export repository as archive',
        ];

        foreach ($commands as $line) {
            CLIConsole::say($line);
        }

        CLIConsole::say("\n<font color='yellow'>See:</font> php grasp.php <command> --help");
    }

    private static function showExportHelp(): void
    {
        CLIConsole::say("<font color='cyan'>GRASP: export</font>\n");
        CLIConsole::say("Export a repository as an archive file.");
        CLIConsole::say("");
        CLIConsole::say("<font color='green'>Usage:</font>");
        CLIConsole::say("  php grasp.php export <repo-url|owner/name> [options]\n");
        CLIConsole::say("<font color='green'>Arguments:</font>");
        CLIConsole::say("  <repo>    Repository URL (full or partial) or owner/name");
        CLIConsole::say("");
        CLIConsole::say("<font color='green'>Options:</font>");
        CLIConsole::say("  -o, --out=<path>       Output file path (default: ./owner-name.zip)");
        CLIConsole::say("  -f, --format=<format>  Archive format: zip, tar, tar.gz (default: zip)");
        CLIConsole::say("  -h, --help             Show this help");
        CLIConsole::say("");
        CLIConsole::say("<font color='green'>Examples:</font>");
        CLIConsole::say("  php grasp.php export laravel/laravel");
        CLIConsole::say("  php grasp.php export https://github.com/laravel/laravel.git --out=./backups/laravel.zip");
    }
}
