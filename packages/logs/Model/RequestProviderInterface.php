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
     * Resource name of the current web request.
     *
     * The resolved route/controller/action (CatalogProductController#view) or the REST route template, matching
     * Bode_Tracing's span name. Null before routing or outside web requests.
     *
     * @return string|null
     */
    public function route(): ?string;

    /**
     * Whether the request was forwarded to Magento's built-in "no route" (404) handler.
     *
     * @return bool
     */
    public function isNoRoute(): bool;

    /**
     * Real PHP class of the controller or REST service resolved for the current request.
     *
     * Bridged from Bode_Tracing's hook layer (TelemetryStateInterface). Null before a route resolves, for a full
     * page cache hit, and for GraphQL (many resolvers, no single class).
     *
     * @return string|null
     */
    public function controllerClass(): ?string;

    /**
     * Resolved action name (Magento's action name, or a REST service method).
     *
     * Bridged from Bode_Tracing. Null in the same cases as controllerClass().
     *
     * @return string|null
     */
    public function magentoAction(): ?string;
}
