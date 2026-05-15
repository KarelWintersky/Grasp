<?php

declare(strict_types=1);

namespace App;

use InvalidArgumentException;

/**
 * URL Parser for Git Repository URLs
 *
 * Parses remote URLs from GitHub, GitLab, and other Git hosting services,
 * extracting service name, username, and repository name.
 *
 * Supported formats:
 *   - https://github.com/user/repo.git
 *   - https://gitlab.com/user/repo.git
 *   - git@github.com:user/repo.git
 *   - ssh://git@github.com/user/repo.git
 *   - https://github.com/user/repo (without .git)
 */
class UrlParser
{
    private string $originalUrl;
    private string $normalizedUrl;
    private string $gitService;
    private string $userName;
    private string $repoName;
    private string $cloneUrl;

    /**
     * Known Git hosting domains
     */
    private const KNOWN_HOSTS = [
        'github.com'    => 'github',
        'gitlab.com'    => 'gitlab',
        'bitbucket.org' => 'bitbucket',
        'codeberg.org'  => 'codeberg',
        'gitea.com'     => 'gitea',
        'gitee.com'     => 'gitee',
        'git.sr.ht'     => 'sourcehut',
    ];

    /**
     * Constructor - parse the URL immediately
     *
     * @throws InvalidArgumentException if URL cannot be parsed
     */
    public function __construct(string $url)
    {
        $this->originalUrl = trim($url);

        if (empty($this->originalUrl)) {
            throw new InvalidArgumentException('URL cannot be empty');
        }

        $this->parse();
    }

    /**
     * Parse the URL and extract all components
     */
    private function parse(): void
    {
        $url = $this->originalUrl;

        // Normalize the URL
        $url = $this->normalizeUrl($url);
        $this->normalizedUrl = $url;

        // Parse the URL
        $parsed = parse_url($url);

        if ($parsed === false) {
            throw new InvalidArgumentException("Cannot parse URL: {$this->originalUrl}");
        }

        // Extract host
        $host = $parsed['host'] ?? '';

        if (empty($host)) {
            throw new InvalidArgumentException("No host found in URL: {$this->originalUrl}");
        }

        // Determine service
        $this->gitService = $this->determineService($host);

        // Extract path
        $path = $parsed['path'] ?? '';
        $path = trim($path, '/');

        if (empty($path)) {
            throw new InvalidArgumentException("No repository path found in URL: {$this->originalUrl}");
        }

        // Remove .git suffix if present
        $path = preg_replace('/\.git$/', '', $path);

        // Split path into parts
        $parts = explode('/', $path);

        if (count($parts) < 2) {
            throw new InvalidArgumentException(
                "Invalid repository path format. Expected 'user/repo', got: {$path}"
            );
        }

        // Last part is repo name, second-to-last is username
        // (handles nested groups like gitlab.com/group/subgroup/repo)
        $this->repoName = array_pop($parts);
        $this->userName = implode('/', $parts);

        // Validate
        if (empty($this->userName) || empty($this->repoName)) {
            throw new InvalidArgumentException(
                "Cannot extract username/repository from URL: {$this->originalUrl}"
            );
        }

        // Sanitize - remove any characters that shouldn't be in names
        $this->userName = $this->sanitizeName($this->userName);
        $this->repoName = $this->sanitizeName($this->repoName);

        // Generate clone URL (always use HTTPS)
        $this->cloneUrl = "https://{$host}/{$this->userName}/{$this->repoName}.git";
    }

    /**
     * Normalize various Git URL formats to standard HTTPS URL
     */
    private function normalizeUrl(string $url): string
    {
        // Remove leading/trailing whitespace
        $url = trim($url);

        // Handle SCP-like syntax: git@github.com:user/repo.git
        if (preg_match('#^git@([^:]+):(.+)$#', $url, $matches)) {
            $host = $matches[1];
            $path = $matches[2];
            return "https://{$host}/{$path}";
        }

        // Handle SSH URLs: ssh://git@github.com/user/repo.git
        if (preg_match('#^ssh://(?:git@)?([^/]+)/(.+)$#', $url, $matches)) {
            $host = $matches[1];
            $path = $matches[2];
            return "https://{$host}/{$path}";
        }

        // Handle git:// protocol
        if (preg_match('#^git://([^/]+)/(.+)$#', $url, $matches)) {
            $host = $matches[1];
            $path = $matches[2];
            return "https://{$host}/{$path}";
        }

        // Handle HTTP/HTTPS URLs (already mostly normalized)
        if (preg_match('#^https?://#', $url)) {
            // Force HTTPS
            $url = preg_replace('#^http://#', 'https://', $url);
            return $url;
        }

        // If it looks like host/path without protocol, add https://
        if (preg_match('#^[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/#', $url)) {
            return "https://{$url}";
        }

        // As fallback, just add https://
        if (!preg_match('#^https?://#', $url)) {
            return "https://{$url}";
        }

        return $url;
    }

    /**
     * Determine Git service from hostname
     */
    private function determineService(string $host): string
    {
        // Remove port if present
        $host = preg_replace('/:\d+$/', '', $host);

        // Check known hosts
        foreach (self::KNOWN_HOSTS as $knownHost => $service) {
            if ($host === $knownHost || str_ends_with($host, '.' . $knownHost)) {
                return $service;
            }
        }

        // Handle self-hosted GitLab instances
        if (str_contains($host, 'gitlab')) {
            return 'gitlab';
        }

        // Handle self-hosted Gitea instances
        if (str_contains($host, 'gitea')) {
            return 'gitea';
        }

        // Handle generic Git hosting
        if (str_contains($host, 'git')) {
            return 'git';
        }

        // Unknown - use hostname as service identifier
        return $host;
    }

    /**
     * Sanitize a name component
     */
    private function sanitizeName(string $name): string
    {
        // Remove anything that's not alphanumeric, dash, underscore, dot, or slash
        $name = preg_replace('/[^a-zA-Z0-9\-_\.\/]/', '', $name);

        // Remove leading/trailing special chars
        $name = trim($name, './-');

        return $name;
    }

    /**
     * Create UrlParser from URL string (static factory)
     */
    public static function fromUrl(string $url): self
    {
        return new self($url);
    }

    /**
     * Validate if a URL is a valid Git repository URL
     */
    public static function isValid(string $url): bool
    {
        try {
            new self($url);
            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    // === Getters ===

    /**
     * Get original URL as provided
     */
    public function getOriginalUrl(): string
    {
        return $this->originalUrl;
    }

    /**
     * Get normalized HTTPS URL
     */
    public function getNormalizedUrl(): string
    {
        return $this->normalizedUrl;
    }

    /**
     * Get Git hosting service name (github, gitlab, etc.)
     */
    public function getGitService(): string
    {
        return $this->gitService;
    }

    /**
     * Get repository owner/username
     */
    public function getUserName(): string
    {
        return $this->userName;
    }

    /**
     * Get repository name
     */
    public function getRepoName(): string
    {
        return $this->repoName;
    }

    /**
     * Get full repository identifier (user/repo)
     */
    public function getFullName(): string
    {
        return "{$this->userName}/{$this->repoName}";
    }

    /**
     * Get clone URL (always HTTPS format)
     */
    public function getCloneUrl(): string
    {
        return $this->cloneUrl;
    }

    /**
     * Get storage path relative to storage root
     */
    public function getStoragePath(): string
    {
        return "/{$this->gitService}/{$this->userName}/{$this->repoName}.git";
    }

    /**
     * Get all parsed data as array
     */
    public function toArray(): array
    {
        return [
            'original_url'   => $this->originalUrl,
            'normalized_url' => $this->normalizedUrl,
            'git_service'    => $this->gitService,
            'user_name'      => $this->userName,
            'repo_name'      => $this->repoName,
            'full_name'      => $this->getFullName(),
            'clone_url'      => $this->cloneUrl,
            'storage_path'   => $this->getStoragePath(),
        ];
    }

    /**
     * String representation
     */
    public function __toString(): string
    {
        return $this->getFullName();
    }
}