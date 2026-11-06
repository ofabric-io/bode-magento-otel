<?php

declare(strict_types=1);

namespace Bode\Tracing\Hook;

use Magento\Framework\Interception\InterceptorInterface;
use Magento\Framework\ObjectManager\NoninterceptableInterface;

/**
 * Recognises the classes Magento generates around a real one: `...\Interceptor` (plugins) and `...\Proxy` (lazy
 * dependencies).
 *
 * ext-opentelemetry runs a hook registered for `Class::method` for every override of that method in the hierarchy, so a
 * call through a generated wrapper runs the hook twice: once for the wrapper's override and once for the real method it
 * delegates to. The wrapper frame is skipped; the real frame carries the span.
 */
class GeneratedWrapper
{
    private const INTERCEPTOR_INTERFACE = InterceptorInterface::class;
    private const PROXY_INTERFACE = NoninterceptableInterface::class;

    /**
     * @var array<string,bool>
     */
    private array $known = [];

    /**
     * True when the declaring class of the running function is a generated Interceptor or Proxy.
     *
     * The name decides (no Magento class is needed, so this is testable and free of autoloading). When Magento's marker
     * interfaces are declared, they must agree, so a hand-written class that is merely named "Proxy" is not skipped.
     *
     * @param string|null $declaringClass
     * @return bool
     */
    public function isWrapper(?string $declaringClass): bool
    {
        if ($declaringClass === null || $declaringClass === '') {
            return false;
        }

        return $this->known[$declaringClass] ??= $this->classify($declaringClass);
    }

    /**
     * Decides one class name (the result is cached by isWrapper).
     *
     * @param string $class
     * @return bool
     */
    private function classify(string $class): bool
    {
        if (str_ends_with($class, '\Interceptor')) {
            return !interface_exists(self::INTERCEPTOR_INTERFACE, false)
                || is_a($class, self::INTERCEPTOR_INTERFACE, true);
        }
        if (str_ends_with($class, '\Proxy')) {
            return !interface_exists(self::PROXY_INTERFACE, false)
                || is_a($class, self::PROXY_INTERFACE, true);
        }

        return false;
    }
}
