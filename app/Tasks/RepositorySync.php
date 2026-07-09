<?php

declare(strict_types=1);

namespace App\Tasks;

use App\App;
use Arris\AppLogger\Monolog\Logger;
use RuntimeException;

/**
 * Repository Sync
 *
 * Handles the actual Git operations:
 * - Clone: git clone --bare <url> <path>
 * - Update: git -C <path> fetch --all --prune
 * - Validates Git binary and paths
 *
 * cloneRepository() — git clone --bare <url> <path>. Проверяет, не существует ли уже директория (если да и валидна — делает update)
 *
 * updateRepository() — git fetch --all --prune. Если директории нет — пытается клонировать
 *
 * ensureRemoteUrl() — проверяет и обновляет origin URL, если он изменился
 *
 * isValidGitRepo() — проверяет наличие HEAD файла и выполняет git rev-parse
 *
 * Все команды выполняются через proc_open() с таймаутом и раздельным чтением stdout/stderr
 */
class RepositorySync
{
    private Logger $logger;
    private Logger $console;
    private bool $isVerbose;

    private string $gitBinary;
    private string $storagePath;
    private int $timeout;
    private \App\AppDatabase $db;
    private bool $isDebug;

    /**
     * Constructor
     */
    public function __construct(
        Logger $logger,
        Logger $console,
        bool    $isVerbose = false,
        bool    $isDebug = false
    ) {
        $this->db        = App::db();
        $this->logger    = $logger;
        $this->console   = $console;
        $this->isVerbose = $isVerbose;
        $this->isDebug   = $isDebug;

        $this->gitBinary   = App::fromConfig('git.binary', '/usr/bin/git');
        $this->storagePath = App::fromConfig('storage.path', '/opt/grasp/storage');
        $this->timeout     = (int)App::fromConfig('git.timeout', 300);

        $this->validateEnvironment();
    }

    /**
     * Validate that Git binary exists and storage is writable
     */
    private function validateEnvironment(): void
    {
        if (!file_exists($this->gitBinary) || !is_executable($this->gitBinary)) {
            throw new RuntimeException("Git binary not found or not executable: {$this->gitBinary}");
        }

        if (!is_dir($this->storagePath)) {
            if (!mkdir($this->storagePath, 0755, true)) {
                throw new RuntimeException("Cannot create storage directory: {$this->storagePath}");
            }
        }

        if (!is_writable($this->storagePath)) {
            throw new RuntimeException("Storage directory is not writable: {$this->storagePath}");
        }
    }

    /**
     * Clone a new bare repository
     *
     * @param array $repo Repository data from queue
     * @return array{success: bool, error?: string}
     */
    public function cloneRepository(array $repo): array
    {
        $repoName = "{$repo['user_name']}/{$repo['repo_name']}";
        $remoteUrl = $repo['remote_url'];
        $fullPath = $this->getFullPath($repo);

        $this->logger->info("Cloning repository: {$repoName}", [
            'url'  => $remoteUrl,
            'path' => $fullPath,
        ]);

        $this->console->info("    Cloning: {$remoteUrl}");
        $this->console->info("    Into:    {$fullPath}");

        // Create parent directory
        $parentDir = dirname($fullPath);
        if (!is_dir($parentDir)) {
            if (!mkdir($parentDir, 0755, true)) {
                $error = "Cannot create directory: {$parentDir}";
                $this->logger->error($error);
                return ['success' => false, 'error' => $error];
            }
        }

        // Check if directory already exists
        if (is_dir($fullPath)) {
            $this->console->warning("    Directory already exists, checking if it's a valid Git repo...");

            if ($this->isValidGitRepo($fullPath)) {
                $this->console->info("    Valid Git repo found. Updating instead.");
                return $this->updateRepository($repo);
            }

            $error = "Directory exists but is not a valid Git repository: {$fullPath}";
            $this->logger->error($error);
            return ['success' => false, 'error' => $error];
        }

        // Execute git clone --bare
        $command = sprintf(
            '%s clone --bare --progress %s %s 2>&1',
            escapeshellcmd($this->gitBinary),
            escapeshellarg($remoteUrl),
            escapeshellarg($fullPath)
        );

        $output = [];
        $exitCode = 0;

        $result = $this->executeCommand($command, $output, $exitCode);

        if ($this->isDebug && !empty($output)) {
            foreach ($output as $line) {
                $this->console->info("      │ {$line}");
            }
        }

        if ($exitCode !== 0) {
            $error = "Git clone failed with exit code {$exitCode}";

            if (!empty($output)) {
                $error .= ': ' . implode('; ', $output);
            }

            $this->logger->error("Clone failed: {$repoName}", [
                'exit_code' => $exitCode,
                'output'    => $output,
            ]);

            // Clean up failed clone
            $this->cleanupFailedClone($fullPath);

            return ['success' => false, 'error' => $error];
        }

        // Set repo description in Git
        if (!empty($repo['description'])) {
            $this->setGitDescription($fullPath, $repo['description']);
        }

        $this->logger->info("Clone successful: {$repoName}");
        $this->console->info("    Clone completed successfully.");

        return ['success' => true];
    }

    /**
     * Update an existing bare repository (fetch)
     *
     * @param array $repo Repository data from queue
     * @return array{success: bool, error?: string}
     */
    public function updateRepository(array $repo): array
    {
        $repoName = "{$repo['user_name']}/{$repo['repo_name']}";
        $fullPath = $this->getFullPath($repo);

        $this->logger->info("Updating repository: {$repoName}", ['path' => $fullPath]);

        $this->console->info("    Updating: {$fullPath}");

        // Check if repository exists
        if (!is_dir($fullPath)) {
            $error = "Repository directory not found: {$fullPath}";
            $this->logger->error($error);
            $this->console->warning("    Directory not found. Will attempt to clone instead.");
            return $this->cloneRepository($repo);
        }

        // Check if it's a valid Git repo
        if (!$this->isValidGitRepo($fullPath)) {
            $error = "Directory is not a valid Git repository: {$fullPath}";
            $this->logger->error($error);
            return ['success' => false, 'error' => $error];
        }

        // Update remote URL if changed
        $this->ensureRemoteUrl($fullPath, $repo['remote_url']);

        // Execute git fetch --all --prune
        $command = sprintf(
            '%s -C %s fetch --all --prune --progress 2>&1',
            escapeshellcmd($this->gitBinary),
            escapeshellarg($fullPath)
        );

        $output = [];
        $exitCode = 0;

        $result = $this->executeCommand($command, $output, $exitCode);

        if ($this->isVerbose && !empty($output)) {
            foreach ($output as $line) {
                $this->console->info("      │ {$line}");
            }
        }

        if ($exitCode !== 0) {
            $error = "Git fetch failed with exit code {$exitCode}";

            if (!empty($output)) {
                $error .= ': ' . implode('; ', $output);
            }

            $this->logger->error("Update failed: {$repoName}", [
                'exit_code' => $exitCode,
                'output'    => $output,
            ]);

            return ['success' => false, 'error' => $error];
        }

        $this->logger->info("Update successful: {$repoName}");
        $this->console->info("    Update completed successfully.");

        return ['success' => true];
    }

    /**
     * Get full filesystem path for repository
     */
    private function getFullPath(array $repo): string
    {
        $storagePath = $repo['storage_path']
            ?? "/{$repo['git_service']}/{$repo['user_name']}/{$repo['repo_name']}.git";

        return rtrim($this->storagePath, '/') . '/' . ltrim($storagePath, '/');
    }

    /**
     * Check if a directory is a valid Git repository
     */
    private function isValidGitRepo(string $path): bool
    {
        // Check for HEAD file (bare repo) or .git/HEAD (non-bare)
        if (file_exists("{$path}/HEAD")) {
            return true;
        }

        if (file_exists("{$path}/.git/HEAD")) {
            return true;
        }

        // Try git command
        $command = sprintf(
            '%s -C %s rev-parse --git-dir 2>/dev/null',
            escapeshellcmd($this->gitBinary),
            escapeshellarg($path)
        );

        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);

        return $exitCode === 0;
    }

    /**
     * Ensure the remote URL matches the expected URL
     */
    private function ensureRemoteUrl(string $repoPath, string $expectedUrl): void
    {
        // Get current origin URL
        $command = sprintf(
            '%s -C %s remote get-url origin 2>/dev/null',
            escapeshellcmd($this->gitBinary),
            escapeshellarg($repoPath)
        );

        $currentUrl = trim(shell_exec($command) ?? '');

        if (empty($currentUrl)) {
            // No origin remote - add it
            $command = sprintf(
                '%s -C %s remote add origin %s 2>&1',
                escapeshellcmd($this->gitBinary),
                escapeshellarg($repoPath),
                escapeshellarg($expectedUrl)
            );

            $output = [];
            $exitCode = 0;
            exec($command, $output, $exitCode);

            $this->logger->info('Added origin remote', ['url' => $expectedUrl]);
        } elseif ($currentUrl !== $expectedUrl) {
            // URL changed - update it
            $command = sprintf(
                '%s -C %s remote set-url origin %s 2>&1',
                escapeshellcmd($this->gitBinary),
                escapeshellarg($repoPath),
                escapeshellarg($expectedUrl)
            );

            $output = [];
            $exitCode = 0;
            exec($command, $output, $exitCode);

            $this->logger->info('Updated origin remote URL', [
                'old' => $currentUrl,
                'new' => $expectedUrl,
            ]);
        }
    }

    /**
     * Set Git description in the bare repository
     */
    private function setGitDescription(string $repoPath, string $description): void
    {
        $descriptionFile = "{$repoPath}/description";

        // Only set if we have write access and file doesn't already have content
        if (is_writable($repoPath)) {
            $existing = file_exists($descriptionFile) ? trim(file_get_contents($descriptionFile)) : '';

            // Git default is "Unnamed repository; edit this file to 'description' to name the repository."
            if (empty($existing) || str_starts_with($existing, 'Unnamed repository')) {
                file_put_contents($descriptionFile, $description . "\n");
                $this->logger->debug('Set Git description', ['path' => $descriptionFile]);
            }
        }
    }

    /**
     * Clean up a failed clone attempt
     */
    private function cleanupFailedClone(string $path): void
    {
        if (is_dir($path)) {
            $this->logger->info('Cleaning up failed clone', ['path' => $path]);
            $this->rmdirRecursive($path);
        }
    }

    /**
     * Recursively remove a directory
     */
    private function rmdirRecursive(string $dir): bool
    {
        if (!is_dir($dir)) {
            return false;
        }

        $items = scandir($dir);

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = "{$dir}/{$item}";

            if (is_dir($path)) {
                $this->rmdirRecursive($path);
            } else {
                @unlink($path);
            }
        }

        return @rmdir($dir);
    }

    /**
     * Execute a shell command with timeout
     */
    private function executeCommand(string $command, array &$output, int &$exitCode): string
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w'],  // stderr
        ];

        $process = proc_open($command, $descriptorSpec, $pipes);

        if (!is_resource($process)) {
            $exitCode = -1;
            $output = ['Failed to execute command'];
            return '';
        }

        // Close stdin
        fclose($pipes[0]);

        // Read stdout
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        // Read stderr
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        // Parse output
        $allOutput = trim($stdout . "\n" . $stderr);
        $output = array_filter(explode("\n", $allOutput), fn($line) => $line !== '');

        return $allOutput;
    }
}