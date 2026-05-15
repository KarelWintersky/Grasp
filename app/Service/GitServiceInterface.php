<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Interface for Git hosting service handlers
 *
 * контракт для всех сервисов
 *
 * Each supported Git service (GitHub, GitLab, etc.) implements this interface
 * to provide service-specific functionality like API interaction,
 * authentication, and repository metadata fetching.
 */
interface GitServiceInterface
{
    /**
     * Check if this service can handle the given host
     */
    public static function supports(string $host): bool;

    /**
     * Get the service identifier (github, gitlab, etc.)
     */
    public function getServiceName(): string;

    /**
     * Fetch repository description from the hosting service API
     *
     * @param string $owner Repository owner
     * @param string $repo  Repository name
     * @return string|null Description or null if unavailable
     */
    public function fetchDescription(string $owner, string $repo): ?string;

    /**
     * Fetch repository metadata from the hosting service API
     *
     * @param string $owner Repository owner
     * @param string $repo  Repository name
     * @return array{description: string|null, default_branch: string|null, stars: int|null, language: string|null}
     */
    public function fetchMetadata(string $owner, string $repo): array;

    /**
     * Build API URL for a repository
     */
    public function getApiUrl(string $owner, string $repo): string;

    /**
     * Build web URL for a repository
     */
    public function getWebUrl(string $owner, string $repo): string;

    /**
     * Get default clone URL format for this service
     */
    public function getCloneUrl(string $owner, string $repo): string;
}