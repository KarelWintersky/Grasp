<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Generic Git Service Handler
 *
 * fallback для неизвестных сервисов
 *
 * Fallback for unknown or self-hosted Git services that don't have
 * a specific API integration. Returns minimal metadata.
 */
class GenericGitService implements GitServiceInterface
{
    private string $serviceName;

    public function __construct(string $serviceName = 'unknown')
    {
        $this->serviceName = $serviceName;
    }

    public static function supports(string $host): bool
    {
        // Generic service supports any host as fallback
        return true;
    }

    public function getServiceName(): string
    {
        return $this->serviceName;
    }

    public function fetchDescription(string $owner, string $repo): ?string
    {
        return null;
    }

    public function fetchMetadata(string $owner, string $repo): array
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

    public function getApiUrl(string $owner, string $repo): string
    {
        return '';
    }

    public function getWebUrl(string $owner, string $repo): string
    {
        return '';
    }

    public function getCloneUrl(string $owner, string $repo): string
    {
        return "https://{$this->serviceName}/{$owner}/{$repo}.git";
    }
}