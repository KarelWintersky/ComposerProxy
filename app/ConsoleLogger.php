<?php
declare(strict_types=1);

namespace App;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

class ConsoleLogger implements LoggerInterface
{
    private bool $isVerbose;
    private bool $isDebug;

    public function __construct()
    {
        $app = App::getInstance();
        $this->isVerbose = $app->isVerbose();
        $this->isDebug = $app->isDebug();
    }

    public function emergency(\Stringable|string $message, array $context = []): void { $this->log(LogLevel::EMERGENCY, $message, $context); }
    public function alert(\Stringable|string $message, array $context = []): void { $this->log(LogLevel::ALERT, $message, $context); }
    public function critical(\Stringable|string $message, array $context = []): void { $this->log(LogLevel::CRITICAL, $message, $context); }
    public function error(\Stringable|string $message, array $context = []): void { $this->log(LogLevel::ERROR, $message, $context); }
    public function warning(\Stringable|string $message, array $context = []): void { $this->log(LogLevel::WARNING, $message, $context); }
    public function notice(\Stringable|string $message, array $context = []): void { $this->log(LogLevel::NOTICE, $message, $context); }
    public function info(\Stringable|string $message, array $context = []): void { $this->log(LogLevel::INFO, $message, $context); }
    public function debug(\Stringable|string $message, array $context = []): void { $this->log(LogLevel::DEBUG, $message, $context); }

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        // 1. Всегда пишем ошибки и выше
        if (in_array($level, [LogLevel::EMERGENCY, LogLevel::ALERT, LogLevel::CRITICAL, LogLevel::ERROR])) {
            $this->write($level, $message, $context);
            return;
        }

        // 2. Warning и Notice пишем, если включен Verbose
        if (in_array($level, [LogLevel::WARNING, LogLevel::NOTICE]) && $this->isVerbose) {
            $this->write($level, $message, $context);
            return;
        }

        // 3. Info и Debug пишем только если включен Debug
        if ($this->isDebug) {
            $this->write($level, $message, $context);
        }
    }

    private function write(string $level, string $message, array $context): void
    {
        $timestamp = date('H:i:s');
        $prefix = match ($level) {
            LogLevel::ERROR => '❌',
            LogLevel::WARNING => '⚠️',
            LogLevel::INFO => 'ℹ️',
            LogLevel::DEBUG => '🐛',
            default => '•'
        };

        // Если есть исключение в контексте, добавим трейс
        if (isset($context['exception']) && $context['exception'] instanceof \Throwable) {
            $message .= "\n   Exception: " . $context['exception']->getMessage();
            if ($this->isDebug) {
                $message .= "\n" . $context['exception']->getTraceAsString();
            }
        }

        fwrite(STDERR, "[{$timestamp}] [{$level}] {$prefix} {$message}\n");
    }
}