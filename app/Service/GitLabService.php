<?php

declare(strict_types=1);

namespace App\Service;

use App\Config;
use App\LoggerAI;
use RuntimeException;
use InvalidArgumentException;

/**
 * GitLab Service Handler
 *
 * поддержка GitLab API (cloud и self-hosted)
 *
 * Implements GitServiceInterface for GitLab repositories.
 * Supports both gitlab.com and self-hosted GitLab instances.
 *
 * GitLab API docs: https://docs.gitlab.com/ee/api/
 */
class GitLabService implements GitServiceInterface
{
    private const SERVICE_NAME = 'gitlab';
    private const API_BASE = 'https://gitlab.com/api/v4';
    private const WEB_BASE = 'https://gitlab.com';
    private const DEFAULT_TIMEOUT = 15;
    private const MAX_RETRIES = 3;

    private Config $config;
    private LoggerAI $logger;
    private ?string $token;
    private int $timeout;
    private string $apiBase;
    private string $webBase;

    private static array $metadataCache = [];

    /**
     * Constructor
     */
    public function __construct(?string $apiBase = null, ?string $webBase = null)
    {
        $this->config = Config::getInstance();
        $this->logger = LoggerAI::getInstance();

        $this->token = $this->config->get('gitlab_token')
            ?? getenv('GITLAB_TOKEN');

        $this->timeout = (int) ($this->config->get('gitlab_api_timeout') ?? self::DEFAULT_TIMEOUT);
        $this->apiBase = $apiBase ?? $this->config->get('gitlab_api_base') ?? self::API_BASE;
        $this->webBase = $webBase ?? $this->config->get('gitlab_web_base') ?? self::WEB_BASE;
    }

    /**
     * Check if this service supports the given host
     */
    public static function supports(string $host): bool
    {
        $host = preg_replace('/:\d+$/', '', $host);
        return $host === 'gitlab.com' || str_ends_with($host, '.gitlab.com');
    }

    public function getServiceName(): string
    {
        return self::SERVICE_NAME;
    }

    public function fetchDescription(string $owner, string $repo): ?string
    {
        $metadata = $this->fetchMetadata($owner, $repo);
        return $metadata['description'] ?? null;
    }

    /**
     * Fetch repository metadata from GitLab API
     *
     * Note: GitLab uses URL-encoded project paths (owner%2Frepo)
     * for nested groups (e.g., group/subgroup/repo)
     */
    public function fetchMetadata(string $owner, string $repo): array
    {
        $this->validateOwnerRepo($owner, $repo);

        $cacheKey = "gitlab:{$owner}/{$repo}";

        if (isset(self::$metadataCache[$cacheKey])) {
            $cached = self::$metadataCache[$cacheKey];
            if ((time() - $cached['_ts']) < 300) {
                return $cached['data'];
            }
        }

        // GitLab API uses URL-encoded project path
        $projectPath = urlencode("{$owner}/{$repo}");
        $url = "{$this->apiBase}/projects/{$projectPath}";

        try {
            $response = $this->apiRequest('GET', $url);
            $data = json_decode($response, true);

            if (!is_array($data) || isset($data['message'])) {
                $this->logger->warning('GitLab API returned error', [
                    'repo'    => "{$owner}/{$repo}",
                    'message' => $data['message'] ?? 'Unknown error',
                ]);
                return $this->emptyMetadata();
            }

            $metadata = [
                'description'    => $data['description'] ?? null,
                'default_branch' => $data['default_branch'] ?? null,
                'stars'          => $data['star_count'] ?? null,
                'language'       => null, // GitLab doesn't provide language in project API
                'topics'         => $data['topics'] ?? $data['tag_list'] ?? [],
                'license'        => $data['license']['name'] ?? null,
                'is_private'     => ($data['visibility'] ?? 'private') !== 'public',
                'is_fork'        => !empty($data['forked_from_project']),
                'created_at'     => $data['created_at'] ?? null,
                'updated_at'     => $data['last_activity_at'] ?? null,
                'pushed_at'      => $data['last_activity_at'] ?? null,
                'size_kb'        => null, // GitLab doesn't expose size easily
                'open_issues'    => $data['open_issues_count'] ?? null,
            ];

            self::$metadataCache[$cacheKey] = [
                '_ts'   => time(),
                'data'  => $metadata,
            ];

            $this->logger->info('Fetched GitLab metadata', [
                'repo'        => "{$owner}/{$repo}",
                'description' => $metadata['description'],
            ]);

            return $metadata;

        } catch (\Throwable $e) {
            $this->logger->error('Failed to fetch GitLab metadata', [
                'repo'  => "{$owner}/{$repo}",
                'error' => $e->getMessage(),
            ]);
            return $this->emptyMetadata();
        }
    }

    public function getApiUrl(string $owner, string $repo): string
    {
        $projectPath = urlencode("{$owner}/{$repo}");
        return "{$this->apiBase}/projects/{$projectPath}";
    }

    public function getWebUrl(string $owner, string $repo): string
    {
        return "{$this->webBase}/{$owner}/{$repo}";
    }

    public function getCloneUrl(string $owner, string $repo): string
    {
        return "https://gitlab.com/{$owner}/{$repo}.git";
    }

    /**
     * Make an authenticated API request to GitLab
     */
    private function apiRequest(string $method, string $url, ?array $body = null): string
    {
        $ch = curl_init();

        $headers = [
            'Accept: application/json',
            'User-Agent: GRASP/1.0',
        ];

        if ($this->token) {
            $headers[] = "PRIVATE-TOKEN: {$this->token}";
        }

        $options = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
        ];

        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            if ($body) {
                $options[CURLOPT_POSTFIELDS] = json_encode($body);
                $headers[] = 'Content-Type: application/json';
                $options[CURLOPT_HTTPHEADER] = $headers;
            }
        }

        curl_setopt_array($ch, $options);

        $attempt = 0;

        while ($attempt < self::MAX_RETRIES) {
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);

            if ($response === false || !empty($error)) {
                curl_close($ch);
                throw new RuntimeException("GitLab API request failed: {$error}");
            }

            // Rate limit
            if ($httpCode === 429) {
                $retryAfter = (int) (curl_getinfo($ch, CURLINFO_RETRY_AFTER) ?? 60);
                $this->logger->warning('GitLab rate limit hit', ['retry_after' => $retryAfter]);
                sleep($retryAfter);
                $attempt++;
                continue;
            }

            curl_close($ch);

            if ($httpCode >= 400) {
                $responseData = json_decode($response, true);
                $message = $responseData['message'] ?? "HTTP {$httpCode}";
                throw new RuntimeException("GitLab API error: {$message}", $httpCode);
            }

            return $response;
        }

        curl_close($ch);
        throw new RuntimeException("GitLab API request failed after retries");
    }

    private function validateOwnerRepo(string $owner, string $repo): void
    {
        if (empty($owner) || empty($repo)) {
            throw new InvalidArgumentException('Owner and repo cannot be empty');
        }
    }

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

    public static function clearCache(): void
    {
        self::$metadataCache = [];
    }
}