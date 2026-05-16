<?php

declare(strict_types=1);

namespace App\Service;

use App\UrlParser;
use App\LoggerAI;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Factory for creating Git service handlers
 *
 * фабрика для автоматического выбора сервиса по URL
 *
 * Automatically detects the appropriate service based on the host
 * extracted from the repository URL.
 */
class ServiceFactory
{
    private LoggerInterface $logger;

    /** @var array<string, GitServiceInterface> Cached service instances */
    private array $services = [];

    /** @var array<string, string> Registered service classes */
    private array $registeredServices = [
        'github' => GitHubService::class,
        'gitlab' => GitLabService::class,
    ];

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = is_null($logger) ? new NullLogger() : $logger;
    }

    /**
     * Register a custom service handler
     */
    public function registerService(string $name, string $className): void
    {
        if (!is_subclass_of($className, GitServiceInterface::class)) {
            throw new RuntimeException(
                "Service class '{$className}' must implement GitServiceInterface"
            );
        }

        $this->registeredServices[$name] = $className;
        $this->logger->info('Registered custom service', ['name' => $name, 'class' => $className]);
    }

    /**
     * Create a service handler from a URL string
     */
    public function createFromUrl(string $url): GitServiceInterface
    {
        $parsed = new UrlParser($url);
        return $this->create($parsed->getGitService());
    }

    /**
     * Create a service handler from a UrlParser instance
     */
    public function createFromParser(UrlParser $parser): GitServiceInterface
    {
        return $this->create($parser->getGitService());
    }

    /**
     * Create a service handler by service name
     */
    public function create(string $serviceName): GitServiceInterface
    {
        // Return cached instance if available
        if (isset($this->services[$serviceName])) {
            return $this->services[$serviceName];
        }

        $className = $this->registeredServices[$serviceName] ?? null;

        if ($className === null) {
            // Fallback: create a generic service using the service name
            $this->logger->warning('Unknown service, using generic handler', [
                'service' => $serviceName,
            ]);

            $service = new GenericGitService($serviceName);
        } else {
            $this->logger->debug('Creating service handler', [
                'service' => $serviceName,
                'class'   => $className,
            ]);

            $service = new $className();
        }

        $this->services[$serviceName] = $service;

        return $service;
    }

    /**
     * Get all registered service names
     *
     * @return string[]
     */
    public function getRegisteredServices(): array
    {
        return array_keys($this->registeredServices);
    }

    /**
     * Check if a service is supported
     */
    public function supports(string $serviceName): bool
    {
        return isset($this->registeredServices[$serviceName]);
    }
}