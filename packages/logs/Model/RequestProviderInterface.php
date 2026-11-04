<?php

declare(strict_types=1);

namespace Bode\Logs\Model;

/**
 * What only Magento's own services know about the current unit of work. Separate from ExecutionContext so that class
 * stays free of Magento classes (and unit-testable), and so a failing lookup can never break logging.
 */
interface RequestProviderInterface
{
    /**
     * Current area code (frontend, adminhtml, webapi_rest, graphql, crontab...), null when none is set yet.
     *
     * @return string|null
     */
    public function area(): ?string;

    /**
     * Magento's full action name of the current web request, such as catalog_product_view.
     *
     * Null before routing or outside web requests.
     *
     * @return string|null
     */
    public function route(): ?string;
}
