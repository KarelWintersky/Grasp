<?php

declare(strict_types=1);

namespace App\Service;

use App\Config;
use App\LoggerAI;
use RuntimeException;
use InvalidArgumentException;

/**
 * GitHub Service Handler
 *
 * полная поддержка GitHub API с аутентификацией, кэшированием и обработкой rate limit
 *
 * Implements GitServiceInterface for GitHub repositories.
 * Handles API interaction, metadata fetching, and URL construction.
 *
 * GitHub API docs: https://docs.github.com/en/rest
 *
 * Supports:
 *   - github.com (public)
 *   - GitHub Enterprise Server (self-hosted)
 *   - Authenticated requests (via personal access token)
 *   - Rate limit handling
 */
class GitHubService implements GitServiceInterface
{
    private const SERVICE_NAME = 'github';

    /** GitHub API base URL */
    private const API_BASE = 'https://api.github.com';

    /** GitHub web URL */
    private const WEB_BASE = 'https://github.com';

    /** Default request timeout in seconds */
    private const DEFAULT_TIMEOUT = 15;

    /** Maximum retries on rate limit */
    private const MAX_RETRIES = 3;

    private Config $config;
    private LoggerAI $logger;
    private ?string $token;
    private int $timeout;
    private string $apiBase;
    private string $webBase;

    /** @var array<string, array{remaining: int, reset: int}> Rate limit cache per token */
    private static array $rateLimitCache = [];

    /** @var array<string, array> Response cache for metadata */
    private static array $metadataCache = [];

    /**
     * Constructor
     *
     * @param string|null $apiBase  Override API base URL (for Enterprise)
     * @param string|null $webBase  Override web base URL (for Enterprise)
     */
    public function __construct(?string $apiBase = null, ?string $webBase = null)
    {
        $this->config = Config::getInstance();
        $this->logger = LoggerAI::getInstance();

        // Get GitHub token from config or environment
        $this->token = $this->config->get('github_token')
            ?? getenv('GITHUB_TOKEN')
            ?? getenv('GH_TOKEN');

        $this->timeout = (int) ($this->config->get('github_api_timeout') ?? self::DEFAULT_TIMEOUT);

        // Allow overriding API/Web base for Enterprise
        $this->apiBase = $apiBase ?? $this->config->get('github_api_base') ?? self::API_BASE;
        $this->webBase = $webBase ?? $this->config->get('github_web_base') ?? self::WEB_BASE;

        $this->logger->debug('GitHubService initialized', [
            'api_base' => $this->apiBase,
            'web_base' => $this->webBase,
            'has_token' => !empty($this->token),
        ]);
    }

    /**
     * Check if this service supports the given host
     */
    public static function supports(string $host): bool
    {
        // Remove port if present
        $host = preg_replace('/:\d+$/', '', $host);

        return $host === 'github.com' || str_ends_with($host, '.github.com');
    }

    /**
     * Get the service identifier
     */
    public function getServiceName(): string
    {
        return self::SERVICE_NAME;
    }

    /**
     * Fetch repository description
     */
    public function fetchDescription(string $owner, string $repo): ?string
    {
        $metadata = $this->fetchMetadata($owner, $repo);
        return $metadata['description'] ?? null;
    }

    /**
     * Fetch repository metadata from GitHub API
     *
     * @param string $owner Repository owner (user or organization)
     * @param string $repo  Repository name
     * @return array{
     *     description: string|null,
     *     default_branch: string|null,
     *     stars: int|null,
     *     language: string|null,
     *     topics: string[],
     *     license: string|null,
     *     is_private: bool,
     *     is_fork: bool,
     *     created_at: string|null,
     *     updated_at: string|null,
     *     pushed_at: string|null,
     *     size_kb: int|null,
     *     open_issues: int|null
     * }
     */
    public function fetchMetadata(string $owner, string $repo): array
    {
        $this->validateOwnerRepo($owner, $repo);

        $cacheKey = "github:{$owner}/{$repo}";

        // Check cache (5 minute TTL)
        if (isset(self::$metadataCache[$cacheKey])) {
            $cached = self::$metadataCache[$cacheKey];
            if ((time() - $cached['_ts']) < 300) {
                $this->logger->debug('Using cached metadata', ['repo' => "{$owner}/{$repo}"]);
                return $cached['data'];
            }
        }

        $url = $this->getApiUrl($owner, $repo);

        try {
            $response = $this->apiRequest('GET', $url);
            $data = json_decode($response, true);

            if (!is_array($data)) {
                throw new RuntimeException("Invalid API response for {$owner}/{$repo}");
            }

            // Check for API error
            if (isset($data['message']) && !isset($data['full_name'])) {
                $this->logger->warning('GitHub API returned error', [
                    'repo'    => "{$owner}/{$repo}",
                    'message' => $data['message'],
                ]);

                return $this->emptyMetadata();
            }

            $metadata = [
                'description'    => $data['description'] ?? null,
                'default_branch' => $data['default_branch'] ?? null,
                'stars'          => $data['stargazers_count'] ?? null,
                'language'       => $data['language'] ?? null,
                'topics'         => $data['topics'] ?? [],
                'license'        => $data['license']['spdx_id'] ?? null,
                'is_private'     => (bool) ($data['private'] ?? false),
                'is_fork'        => (bool) ($data['fork'] ?? false),
                'created_at'     => $data['created_at'] ?? null,
                'updated_at'     => $data['updated_at'] ?? null,
                'pushed_at'      => $data['pushed_at'] ?? null,
                'size_kb'        => $data['size'] ?? null,
                'open_issues'    => $data['open_issues_count'] ?? null,
            ];

            // Cache the result
            self::$metadataCache[$cacheKey] = [
                '_ts'   => time(),
                'data'  => $metadata,
            ];

            $this->logger->info('Fetched GitHub metadata', [
                'repo'        => "{$owner}/{$repo}",
                'description' => $metadata['description'],
                'stars'       => $metadata['stars'],
            ]);

            return $metadata;

        } catch (\Throwable $e) {
            $this->logger->error('Failed to fetch GitHub metadata', [
                'repo'  => "{$owner}/{$repo}",
                'error' => $e->getMessage(),
            ]);

            return $this->emptyMetadata();
        }
    }

    /**
     * Fetch repository description only (lighter API call)
     * Uses the repo API but only extracts description
     */
    public function fetchDescriptionOnly(string $owner, string $repo): ?string
    {
        $metadata = $this->fetchMetadata($owner, $repo);
        return $metadata['description'] ?? null;
    }

    /**
     * Fetch repository topics
     *
     * @return string[]
     */
    public function fetchTopics(string $owner, string $repo): array
    {
        $this->validateOwnerRepo($owner, $repo);

        $url = "{$this->apiBase}/repos/{$owner}/{$repo}/topics";

        // Topics API requires a custom Accept header
        $headers = [
            'Accept: application/vnd.github.mercy-preview+json',
        ];

        try {
            $response = $this->apiRequest('GET', $url, null, $headers);
            $data = json_decode($response, true);

            return $data['names'] ?? [];

        } catch (\Throwable $e) {
            $this->logger->warning('Failed to fetch topics', [
                'repo'  => "{$owner}/{$repo}",
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Check if a repository exists and is accessible
     */
    public function repositoryExists(string $owner, string $repo): bool
    {
        $this->validateOwnerRepo($owner, $repo);

        $url = $this->getApiUrl($owner, $repo);

        try {
            $this->apiRequest('GET', $url);
            return true;
        } catch (RuntimeException $e) {
            if (str_contains($e->getMessage(), '404')) {
                return false;
            }
            // For other errors (network, rate limit), assume it exists
            return true;
        }
    }

    /**
     * Get repository visibility (public/private)
     */
    public function isPrivate(string $owner, string $repo): ?bool
    {
        $metadata = $this->fetchMetadata($owner, $repo);
        return $metadata['is_private'] ?? null;
    }

    /**
     * Build API URL for a repository
     */
    public function getApiUrl(string $owner, string $repo): string
    {
        return "{$this->apiBase}/repos/{$owner}/{$repo}";
    }

    /**
     * Build web URL for a repository
     */
    public function getWebUrl(string $owner, string $repo): string
    {
        return "{$this->webBase}/{$owner}/{$repo}";
    }

    /**
     * Get clone URL for a repository
     */
    public function getCloneUrl(string $owner, string $repo): string
    {
        return "https://github.com/{$owner}/{$repo}.git";
    }

    /**
     * Get SSH clone URL
     */
    public function getSshCloneUrl(string $owner, string $repo): string
    {
        return "git@github.com:{$owner}/{$repo}.git";
    }

    /**
     * Get current rate limit status
     *
     * @return array{limit: int, remaining: int, reset: int, used: int}|null
     */
    public function getRateLimit(): ?array
    {
        $url = "{$this->apiBase}/rate_limit";

        try {
            $response = $this->apiRequest('GET', $url);
            $data = json_decode($response, true);

            if (!isset($data['rate'])) {
                return null;
            }

            return [
                'limit'     => $data['rate']['limit'],
                'remaining' => $data['rate']['remaining'],
                'reset'     => $data['rate']['reset'],
                'used'      => $data['rate']['used'],
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Check if rate limit is exceeded
     */
    public function isRateLimited(): bool
    {
        $rateLimit = $this->getRateLimit();

        if ($rateLimit === null) {
            return false;
        }

        return $rateLimit['remaining'] <= 0 && $rateLimit['reset'] > time();
    }

    /**
     * Make an authenticated API request to GitHub
     *
     * @param string       $method  HTTP method (GET, POST, etc.)
     * @param string       $url     Full API URL
     * @param array|null   $body    Request body (will be JSON encoded)
     * @param array        $headers Additional headers
     * @return string      Response body
     * @throws RuntimeException On HTTP error or network failure
     */
    private function apiRequest(
        string $method,
        string $url,
        ?array $body = null,
        array $headers = []
    ): string {
        $ch = curl_init();

        $defaultHeaders = [
            'Accept: application/vnd.github+json',
            'User-Agent: GRASP/1.0',
            'X-GitHub-Api-Version: 2022-11-28',
        ];

        // Add authorization if token is available
        if ($this->token) {
            $defaultHeaders[] = "Authorization: Bearer {$this->token}";
        }

        $allHeaders = array_merge($defaultHeaders, $headers);

        $options = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => $allHeaders,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];

        // Set method
        $method = strtoupper($method);
        if ($method === 'GET') {
            $options[CURLOPT_HTTPGET] = true;
        } elseif ($method === 'POST') {
            $options[CURLOPT_POST] = true;
        } elseif ($method !== 'GET') {
            $options[CURLOPT_CUSTOMREQUEST] = $method;
        }

        // Add body for POST/PATCH/PUT
        if ($body !== null && in_array($method, ['POST', 'PATCH', 'PUT'])) {
            $options[CURLOPT_POSTFIELDS] = json_encode($body);
            $allHeaders[] = 'Content-Type: application/json';
            $options[CURLOPT_HTTPHEADER] = $allHeaders;
        }

        curl_setopt_array($ch, $options);

        // Execute with retry logic for rate limits
        $attempt = 0;

        while ($attempt < self::MAX_RETRIES) {
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);

            if ($response === false || !empty($error)) {
                curl_close($ch);
                throw new RuntimeException(
                    "GitHub API request failed: {$error}",
                    0
                );
            }

            // Rate limit exceeded
            if ($httpCode === 429 || $httpCode === 403) {
                // Check if it's a rate limit
                $responseData = json_decode($response, true);
                $isRateLimit = isset($responseData['message']) &&
                    (str_contains($responseData['message'], 'rate limit') ||
                        str_contains($responseData['message'], 'secondary rate limit'));

                if ($isRateLimit) {
                    // Get retry-after header or default to 60 seconds
                    $retryAfter = (int) (curl_getinfo($ch, CURLINFO_RETRY_AFTER) ?? 60);
                    $retryAfter = max(1, min($retryAfter, 300)); // Cap at 5 minutes

                    $this->logger->warning('GitHub rate limit hit, waiting', [
                        'retry_after' => $retryAfter,
                        'attempt'     => $attempt + 1,
                        'url'         => $url,
                    ]);

                    sleep($retryAfter);
                    $attempt++;
                    continue;
                }
            }

            // Success or non-rate-limit error
            curl_close($ch);

            if ($httpCode >= 400) {
                $message = "GitHub API returned HTTP {$httpCode}";
                $responseData = json_decode($response, true);

                if (isset($responseData['message'])) {
                    $message .= ": {$responseData['message']}";
                }

                throw new RuntimeException($message, $httpCode);
            }

            return $response;
        }

        curl_close($ch);
        throw new RuntimeException(
            "GitHub API request failed after " . self::MAX_RETRIES . " retries due to rate limiting"
        );
    }

    /**
     * Validate owner and repo names
     */
    private function validateOwnerRepo(string $owner, string $repo): void
    {
        if (empty($owner) || empty($repo)) {
            throw new InvalidArgumentException('Owner and repo cannot be empty');
        }

        // GitHub username rules: alphanumeric, hyphens, max 39 chars
        if (!preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-]*[a-zA-Z0-9])?$/', $owner) || strlen($owner) > 39) {
            throw new InvalidArgumentException("Invalid GitHub owner: {$owner}");
        }

        // GitHub repo name rules: alphanumeric, hyphens, underscores, dots, max 100 chars
        if (!preg_match('/^[a-zA-Z0-9\._\-]+$/', $repo) || strlen($repo) > 100) {
            throw new InvalidArgumentException("Invalid GitHub repository name: {$repo}");
        }
    }

    /**
     * Return empty metadata structure
     */
    private function emptyMetadata(): array
    {
        return [
            'description'    => null,
            'default_branch' => null,
            'stars'          => null,
            'language'       => null,
            'topics'         => [],
            'license'        => null,
            'is_private'     => false,
            'is_fork'        => false,
            'created_at'     => null,
            'updated_at'     => null,
            'pushed_at'      => null,
            'size_kb'        => null,
            'open_issues'    => null,
        ];
    }

    /**
     * Clear the metadata cache (useful for testing)
     */
    public static function clearCache(): void
    {
        self::$metadataCache = [];
        self::$rateLimitCache = [];
    }

    /**
     * Get service configuration for debugging
     */
    public function getConfig(): array
    {
        return [
            'service'   => self::SERVICE_NAME,
            'api_base'  => $this->apiBase,
            'web_base'  => $this->webBase,
            'has_token' => !empty($this->token),
            'timeout'   => $this->timeout,
        ];
    }
}