<?php

declare(strict_types=1);

namespace Bode\Logs\Plugin;

use Bode\Logs\Model\Config;
use Bode\Logs\Model\RecordAssembler;
use Throwable;

/**
 * Magento logs exceptions as $logger->critical($e). Monolog turns that Throwable into the message text: a whole stack
 * trace in one multi-line string, and the exception object is gone. This plugin on Magento's logger moves the Throwable
 * into the context first (under its own key, so the system.log / exception.log routing of Magento stays as it is) and
 * leaves a one-line message, so the JSON line can carry the exception as structured data.
 */
class ThrowableAsMessage
{
    /**
     * @param Config $config
     */
    public function __construct(private readonly Config $config)
    {
    }

    /**
     * Before emergency().
     *
     * @param object $subject
     * @param mixed $message
     * @param array<string,mixed> $context
     * @return array{0: mixed, 1: array<string,mixed>}
     */
    public function beforeEmergency(object $subject, mixed $message, array $context = []): array
    {
        return $this->convert($message, $context);
    }

    /**
     * Before alert().
     *
     * @param object $subject
     * @param mixed $message
     * @param array<string,mixed> $context
     * @return array{0: mixed, 1: array<string,mixed>}
     */
    public function beforeAlert(object $subject, mixed $message, array $context = []): array
    {
        return $this->convert($message, $context);
    }

    /**
     * Before critical().
     *
     * @param object $subject
     * @param mixed $message
     * @param array<string,mixed> $context
     * @return array{0: mixed, 1: array<string,mixed>}
     */
    public function beforeCritical(object $subject, mixed $message, array $context = []): array
    {
        return $this->convert($message, $context);
    }

    /**
     * Before error().
     *
     * @param object $subject
     * @param mixed $message
     * @param array<string,mixed> $context
     * @return array{0: mixed, 1: array<string,mixed>}
     */
    public function beforeError(object $subject, mixed $message, array $context = []): array
    {
        return $this->convert($message, $context);
    }

    /**
     * Before warning().
     *
     * @param object $subject
     * @param mixed $message
     * @param array<string,mixed> $context
     * @return array{0: mixed, 1: array<string,mixed>}
     */
    public function beforeWarning(object $subject, mixed $message, array $context = []): array
    {
        return $this->convert($message, $context);
    }

    /**
     * Before notice().
     *
     * @param object $subject
     * @param mixed $message
     * @param array<string,mixed> $context
     * @return array{0: mixed, 1: array<string,mixed>}
     */
    public function beforeNotice(object $subject, mixed $message, array $context = []): array
    {
        return $this->convert($message, $context);
    }

    /**
     * Before info().
     *
     * @param object $subject
     * @param mixed $message
     * @param array<string,mixed> $context
     * @return array{0: mixed, 1: array<string,mixed>}
     */
    public function beforeInfo(object $subject, mixed $message, array $context = []): array
    {
        return $this->convert($message, $context);
    }

    /**
     * Before debug().
     *
     * @param object $subject
     * @param mixed $message
     * @param array<string,mixed> $context
     * @return array{0: mixed, 1: array<string,mixed>}
     */
    public function beforeDebug(object $subject, mixed $message, array $context = []): array
    {
        return $this->convert($message, $context);
    }

    /**
     * Before log().
     *
     * @param object $subject
     * @param mixed $level
     * @param mixed $message
     * @param array<string,mixed> $context
     * @return array{0: mixed, 1: mixed, 2: array<string,mixed>}
     */
    public function beforeLog(object $subject, mixed $level, mixed $message, array $context = []): array
    {
        return [$level, ...$this->convert($message, $context)];
    }

    /**
     * Moves a Throwable message into the context.
     *
     * @param mixed $message
     * @param array<string,mixed> $context
     * @return array{0: mixed, 1: array<string,mixed>}
     */
    private function convert(mixed $message, array $context): array
    {
        if (!$message instanceof Throwable || !$this->config->isEnabled()) {
            return [$message, $context];
        }
        $context[RecordAssembler::EXCEPTION_KEY] ??= $message;

        return [$message::class . ': ' . $message->getMessage(), $context];
    }
}
