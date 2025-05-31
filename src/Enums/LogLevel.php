<?php

declare(strict_types=1);

namespace LaravelOCI\LaravelOciDriver\Enums;

/**
 * Logging Levels for OCI Driver
 *
 * Standard PSR-3 compatible logging levels for OCI operations.
 */
enum LogLevel: string
{
    case EMERGENCY = 'emergency';
    case ALERT = 'alert';
    case CRITICAL = 'critical';
    case ERROR = 'error';
    case WARNING = 'warning';
    case NOTICE = 'notice';
    case INFO = 'info';
    case DEBUG = 'debug';

    /**
     * Get the log level value.
     */
    public function value(): string
    {
        return $this->value;
    }

    /**
     * Get the numeric priority of the log level.
     */
    public function getPriority(): int
    {
        return match ($this) {
            self::EMERGENCY => 0,
            self::ALERT => 1,
            self::CRITICAL => 2,
            self::ERROR => 3,
            self::WARNING => 4,
            self::NOTICE => 5,
            self::INFO => 6,
            self::DEBUG => 7,
        };
    }

    /**
     * Check if this level should be logged based on minimum level.
     */
    public function shouldLog(self $minimumLevel): bool
    {
        return $this->getPriority() <= $minimumLevel->getPriority();
    }

    /**
     * Create from string value.
     */
    public static function fromString(?string $level): self
    {
        if ($level === null) {
            return self::INFO;
        }

        foreach (self::cases() as $case) {
            if (strtolower($case->value) === strtolower($level)) {
                return $case;
            }
        }

        return self::INFO;
    }

    /**
     * Get all levels ordered by priority (most severe first).
     *
     * @return array<self>
     */
    public static function getAllByPriority(): array
    {
        return [
            self::EMERGENCY,
            self::ALERT,
            self::CRITICAL,
            self::ERROR,
            self::WARNING,
            self::NOTICE,
            self::INFO,
            self::DEBUG,
        ];
    }
}
