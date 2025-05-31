<?php

declare(strict_types=1);

namespace LaravelOCI\LaravelOciDriver\Tests\Unit;

use LaravelOCI\LaravelOciDriver\Enums\LogLevel;
use LaravelOCI\LaravelOciDriver\Tests\TestCase;

final class LogLevelTest extends TestCase
{
    public function test_enum_has_correct_values(): void
    {
        expect(LogLevel::EMERGENCY->value)->toBe('emergency');
        expect(LogLevel::ALERT->value)->toBe('alert');
        expect(LogLevel::CRITICAL->value)->toBe('critical');
        expect(LogLevel::ERROR->value)->toBe('error');
        expect(LogLevel::WARNING->value)->toBe('warning');
        expect(LogLevel::NOTICE->value)->toBe('notice');
        expect(LogLevel::INFO->value)->toBe('info');
        expect(LogLevel::DEBUG->value)->toBe('debug');
    }

    public function test_can_get_priority(): void
    {
        expect(LogLevel::EMERGENCY->getPriority())->toBe(0);
        expect(LogLevel::ALERT->getPriority())->toBe(1);
        expect(LogLevel::CRITICAL->getPriority())->toBe(2);
        expect(LogLevel::ERROR->getPriority())->toBe(3);
        expect(LogLevel::WARNING->getPriority())->toBe(4);
        expect(LogLevel::NOTICE->getPriority())->toBe(5);
        expect(LogLevel::INFO->getPriority())->toBe(6);
        expect(LogLevel::DEBUG->getPriority())->toBe(7);
    }

    public function test_should_log_based_on_minimum_level(): void
    {
        // Emergency should always log
        expect(LogLevel::EMERGENCY->shouldLog(LogLevel::DEBUG))->toBeTrue();
        expect(LogLevel::EMERGENCY->shouldLog(LogLevel::INFO))->toBeTrue();
        expect(LogLevel::EMERGENCY->shouldLog(LogLevel::ERROR))->toBeTrue();
        expect(LogLevel::EMERGENCY->shouldLog(LogLevel::EMERGENCY))->toBeTrue();

        // Error should log for error and above
        expect(LogLevel::ERROR->shouldLog(LogLevel::ERROR))->toBeTrue();
        expect(LogLevel::ERROR->shouldLog(LogLevel::WARNING))->toBeTrue();
        expect(LogLevel::ERROR->shouldLog(LogLevel::DEBUG))->toBeTrue();
        expect(LogLevel::ERROR->shouldLog(LogLevel::CRITICAL))->toBeFalse();

        // Debug should only log for debug level
        expect(LogLevel::DEBUG->shouldLog(LogLevel::DEBUG))->toBeTrue();
        expect(LogLevel::DEBUG->shouldLog(LogLevel::INFO))->toBeFalse();
        expect(LogLevel::DEBUG->shouldLog(LogLevel::WARNING))->toBeFalse();
    }

    public function test_can_create_from_string(): void
    {
        expect(LogLevel::fromString('emergency'))->toBe(LogLevel::EMERGENCY);
        expect(LogLevel::fromString('ALERT'))->toBe(LogLevel::ALERT);
        expect(LogLevel::fromString('Critical'))->toBe(LogLevel::CRITICAL);
        expect(LogLevel::fromString('error'))->toBe(LogLevel::ERROR);
        expect(LogLevel::fromString('warning'))->toBe(LogLevel::WARNING);
        expect(LogLevel::fromString('notice'))->toBe(LogLevel::NOTICE);
        expect(LogLevel::fromString('info'))->toBe(LogLevel::INFO);
        expect(LogLevel::fromString('debug'))->toBe(LogLevel::DEBUG);

        // Invalid or null values should default to INFO
        expect(LogLevel::fromString('invalid'))->toBe(LogLevel::INFO);
        expect(LogLevel::fromString(null))->toBe(LogLevel::INFO);
        expect(LogLevel::fromString(''))->toBe(LogLevel::INFO);
    }

    public function test_value_method_returns_correct_string(): void
    {
        expect(LogLevel::EMERGENCY->value())->toBe('emergency');
        expect(LogLevel::DEBUG->value())->toBe('debug');
        expect(LogLevel::INFO->value())->toBe('info');
    }

    public function test_get_all_by_priority(): void
    {
        $levels = LogLevel::getAllByPriority();

        expect($levels)->toBeArray();
        expect($levels)->toHaveCount(8);

        // Should be ordered by priority (most severe first)
        expect($levels[0])->toBe(LogLevel::EMERGENCY);
        expect($levels[1])->toBe(LogLevel::ALERT);
        expect($levels[2])->toBe(LogLevel::CRITICAL);
        expect($levels[3])->toBe(LogLevel::ERROR);
        expect($levels[4])->toBe(LogLevel::WARNING);
        expect($levels[5])->toBe(LogLevel::NOTICE);
        expect($levels[6])->toBe(LogLevel::INFO);
        expect($levels[7])->toBe(LogLevel::DEBUG);
    }

    public function test_all_levels_have_unique_priorities(): void
    {
        $levels = LogLevel::cases();
        $priorities = array_map(fn (LogLevel $level) => $level->getPriority(), $levels);

        expect(count($priorities))->toBe(count(array_unique($priorities)));
    }

    public function test_priority_order_is_consistent(): void
    {
        $levels = LogLevel::getAllByPriority();

        for ($i = 0; $i < count($levels) - 1; $i++) {
            expect($levels[$i]->getPriority())->toBeLessThan($levels[$i + 1]->getPriority());
        }
    }

    public function test_case_insensitive_string_conversion(): void
    {
        expect(LogLevel::fromString('EMERGENCY'))->toBe(LogLevel::EMERGENCY);
        expect(LogLevel::fromString('Emergency'))->toBe(LogLevel::EMERGENCY);
        expect(LogLevel::fromString('eMeRgEnCy'))->toBe(LogLevel::EMERGENCY);
    }
}
