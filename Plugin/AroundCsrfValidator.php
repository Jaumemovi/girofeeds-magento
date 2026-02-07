<?php

declare(strict_types=1);

namespace Girofeeds\ProductApi\Plugin;

use Magento\Framework\App\Request\CsrfValidator;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ActionInterface;

class AroundCsrfValidator
{

    private const ALLOWED_ROUTES = [
        'girofeeds_feed_json',
        'girofeeds_updateproduct_index',
        'girofeeds_count_index',
        'girofeeds_test_index',
    ];

    public function aroundValidate(
        CsrfValidator $subject,
        \Closure $proceed,
        RequestInterface $request,
        ActionInterface $action
    ): void {
        if ($request->getModuleName() === 'girofeeds') {
            $fullAction = sprintf(
                '%s_%s_%s',
                $request->getModuleName(),
                $request->getControllerName(),
                $request->getActionName()
            );
            if (in_array($fullAction, self::ALLOWED_ROUTES, true)) {
                return;
            }
        }

        $proceed($request, $action);
    }
}