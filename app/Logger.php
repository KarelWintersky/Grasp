<?php

declare(strict_types=1);

namespace App;

use RuntimeException;

/**
 * Simple PSR-3 inspired Logger
 *
 * Logs to file with rotation support for the GRASP application.
 * Log levels: debug, info, warning, error
 */
class Logger
{
    private static ?Logger $instance = null;

    private Config $config;
    private string $logPath;
    private string $logFile;
    private string $logLevel;

    private const LEVELS = [
        'debug'   => 0,
        'info'    => 1,
        'warning' => 2,
        'error'   => 3,
    ];

    private const LEVEL_LABELS = [
        0 => 'DEBUG',
        1 => 'INFO',
        2 => 'WARNING',
        3 => 'ERROR',
    ];

    /**
     * Get Logger singleton
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor
     */
    private function __construct()
    {
        $this->config = Config::getInstance();
        $this->logPath = $this->config->get('log_path', '/opt/grasp/logs');
        $this->logLevel = $this->config->get('log_level', 'info');

        // Ensure log directory exists
        if (!is_dir($this->logPath)) {
            if (!mkdir($this->logPath, 0755, true)) {
                throw new RuntimeException("Cannot create log directory: {$this->logPath}");
            }
        }

        $this->logFile = $this->logPath . '/grasp.log';
    }

    /**
     * Log a debug message
     */
    public function debug(string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    /**
     * Log an info message
     */
    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    /**
     * Log a warning message
     */
    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    /**
     * Log an error message
     */
    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    /**
     * Core log method
     */
    private function log(string $level, string $message, array $context = []): void
    {
        // Check if this level should be logged
        if (!$this->shouldLog($level)) {
            return;
        }

        $this->rotateIfNeeded();

        $timestamp = date('Y-m-d H:i:s.v');
        $levelLabel = self::LEVEL_LABELS[self::LEVELS[$level]] ?? strtoupper($level);

        // Format context
        $contextStr = '';
        if (!empty($context)) {
            $contextStr = ' ' . $this->formatContext($context);
        }

        // Handle multiline messages (like stack traces)
        $lines = explode("\n", $message);
        $firstLine = array_shift($lines);

        $logLine = sprintf(
            "[%s] %s.%s: %s%s\n",
            $timestamp,
            $levelLabel,
            $this->getCaller(),
            $firstLine,
            $contextStr
        );

        // Append remaining lines indented
        foreach ($lines as $line) {
            $logLine .= sprintf(
                "[%s] %s.%s:   %s\n",
                $timestamp,
                $levelLabel,
                $this->getCaller(),
                $line
            );
        }

        // Write to file
        $written = file_put_contents(
            $this->logFile,
            $logLine,
            FILE_APPEND | LOCK_EX
        );

        if ($written === false) {
            error_log("GRASP: Failed to write to log file: {$this->logFile}");
        }
    }

    /**
     * Check if the given log level should be recorded
     */
    private function shouldLog(string $level): bool
    {
        $currentLevel = self::LEVELS[$this->logLevel] ?? 1; // default info
        $messageLevel = self::LEVELS[$level] ?? 1;

        return $messageLevel >= $currentLevel;
    }

    /**
     * Get caller information for logging
     */
    private function getCaller(): string
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5);

        // Skip: getCaller -> log -> debug/info/warning/error -> actual caller
        $caller = null;
        foreach ($trace as $frame) {
            if (
                isset($frame['class']) &&
                !in_array($frame['class'], [self::class, static::class])
            ) {
                $caller = $frame;
                break;
            }
        }

        if ($caller === null && isset($trace[4])) {
            $caller = $trace[4];
        }

        if ($caller === null) {
            return 'unknown';
        }

        $class = $caller['class'] ?? '';
        $function = $caller['function'] ?? '';

        // Shorten class name
        $classParts = explode('\\', $class);
        $shortClass = array_pop($classParts);

        if ($shortClass) {
            return "{$shortClass}->{$function}";
        }

        return $function;
    }

    /**
     * Format context array for logging
     */
    private function formatContext(array $context): string
    {
        $parts = [];

        foreach ($context as $key => $value) {
            if ($value instanceof \Throwable) {
                $parts[] = "{$key}=[Exception: {$value->getMessage()}]";
            } elseif (is_array($value)) {
                $parts[] = "{$key}=" . json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            } elseif (is_object($value)) {
                $parts[] = "{$key}=[object:" . get_class($value) . "]";
            } elseif (is_bool($value)) {
                $parts[] = "{$key}=" . ($value ? 'true' : 'false');
            } elseif (is_null($value)) {
                $parts[] = "{$key}=null";
            } else {
                $parts[] = "{$key}={$value}";
            }
        }

        return '[' . implode(', ', $parts) . ']';
    }

    /**
     * Rotate log file if it exceeds max size
     */
    private function rotateIfNeeded(): void
    {
        if (!file_exists($this->logFile)) {
            return;
        }

        $maxSize = $this->config->get('log_max_size', 10485760); // 10 MB

        if (filesize($this->logFile) < $maxSize) {
            return;
        }

        $timestamp = date('Ymd_His');
        $rotatedFile = $this->logPath . "/grasp_{$timestamp}.log";

        rename($this->logFile, $rotatedFile);

        // Clean up old log files
        $this->cleanupOldLogs();
    }

    /**
     * Remove log files older than keep_days
     */
    private function cleanupOldLogs(): void
    {
        $keepDays = $this->config->get('log_keep_days', 30);
        $cutoff = time() - ($keepDays * 86400);

        $files = glob($this->logPath . '/grasp_*.log');

        foreach ($files as $file) {
            if (filemtime($file) < $cutoff) {
                unlink($file);
            }
        }
    }

    /**
     * Get log file path
     */
    public function getLogFile(): string
    {
        return $this->logFile;
    }

    /**
     * Get all log files
     */
    public function getLogFiles(): array
    {
        $files = glob($this->logPath . '/grasp*.log');
        rsort($files);
        return $files;
    }

    /**
     * Read last N lines from log file
     */
    public function tail(int $lines = 100): array
    {
        if (!file_exists($this->logFile)) {
            return [];
        }

        $content = file($this->logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($content === false) {
            return [];
        }

        return array_slice($content, -$lines);
    }

    /**
     * Prevent cloning
     */
    private function __clone() {}

    public function __wakeup()
    {
        throw new RuntimeException("Cannot unserialize singleton");
    }
}