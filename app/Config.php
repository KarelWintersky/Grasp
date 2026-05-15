<?php

declare(strict_types=1);

namespace App;

use RuntimeException;

/**
 * Configuration Loader
 *
 * Reads config from /etc/arris/grasp/config.php
 * Falls back to default values if config is missing
 */
class Config
{
    private static ?Config $instance = null;

    private array $config = [];

    /**
     * Default configuration values
     */
    private const DEFAULTS = [
        'path_to_database' => '/opt/grasp/data/grasp.db',
        'path_to_storage'  => '/opt/grasp/storage',
        'log_path'         => '/opt/grasp/logs',
        'log_level'        => 'info',      // debug, info, warning, error
        'log_max_size'     => 10485760,     // 10 MB
        'log_keep_days'    => 30,
        'timezone'         => 'UTC',
        'default_update_interval' => '7d',
        'cron_lock_file'   => '/tmp/grasp_cron.lock',
        'cron_lock_timeout' => 300,         // 5 minutes
        'git_binary'       => '/usr/bin/git',
        'http_timeout'     => 30,
    ];

    /**
     * Get Config singleton
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor - load config file
     */
    private function __construct()
    {
        $this->loadConfig();
    }

    /**
     * Load configuration from file
     */
    private function loadConfig(): void
    {
        $configFile = '/etc/arris/grasp/config.php';

        $fileConfig = [];

        if (file_exists($configFile) && is_readable($configFile)) {
            $fileConfig = require $configFile;

            if (!is_array($fileConfig)) {
                throw new RuntimeException(
                    "Config file '{$configFile}' must return an array"
                );
            }
        }

        // Merge with defaults (file config overrides defaults)
        $this->config = array_merge(self::DEFAULTS, $fileConfig);

        // Apply environment overrides if present
        $this->applyEnvOverrides();
    }

    /**
     * Allow environment variables to override config
     * GRASP_DATABASE_PATH, GRASP_STORAGE_PATH, etc.
     */
    private function applyEnvOverrides(): void
    {
        $envMap = [
            'GRASP_DATABASE_PATH' => 'path_to_database',
            'GRASP_STORAGE_PATH'  => 'path_to_storage',
            'GRASP_LOG_PATH'      => 'log_path',
            'GRASP_LOG_LEVEL'     => 'log_level',
            'GRASP_TIMEZONE'      => 'timezone',
            'GRASP_GIT_BINARY'    => 'git_binary',
        ];

        foreach ($envMap as $envKey => $configKey) {
            $value = getenv($envKey);
            if ($value !== false && $value !== '') {
                $this->config[$configKey] = $value;
            }
        }
    }

    /**
     * Get a config value by key (supports dot notation)
     */
    public function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $this->config)) {
            return $this->config[$key];
        }

        return $default;
    }

    /**
     * Get all config as array
     */
    public function getAll(): array
    {
        return $this->config;
    }

    /**
     * Magic getter for convenience: Config::getInstance()->path_to_database
     */
    public function __get(string $key): mixed
    {
        return $this->get($key);
    }

    /**
     * Check if a config key exists
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->config);
    }

    /**
     * Prevent cloning of singleton
     */
    private function __clone() {}

    /**
     * Prevent unserialization of singleton
     */
    public function __wakeup()
    {
        throw new RuntimeException("Cannot unserialize singleton");
    }
}