<?php

declare(strict_types=1);

namespace Girofeeds\ProductApi\Model\Update;

class ValidationService
{
    /** Status values that mean "disabled" (CreateProduct::normalizeStatus returns 2 for them) */
    const DISABLED_STATUSES = [2, '2', false, 'false', '0', 0];

    /**
     * Validate resolved request data
     *
     * @param array $resolvedData
     * @param bool $allowZeroPrice createproduct only (see allowsZeroPrice): a price equal to 0 is
     *                             accepted; negative or non-numeric prices are still rejected
     * @return array
     */
    public function validate(array $resolvedData, bool $allowZeroPrice = false): array
    {
        $errors = [];

        if (array_key_exists('price', $resolvedData)) {
            $price = $resolvedData['price'];
            $isZero = is_numeric($price) && (float) $price == 0;
            if (!is_numeric($price) || ((float) $price <= 0 && !($allowZeroPrice && $isZero))) {
                $errors[] = "Field 'price' must be a positive number";
            }
        }

        if (array_key_exists('visibility', $resolvedData)) {
            $vis = (int) $resolvedData['visibility'];
            if ($vis < 1 || $vis > 4) {
                $errors[] = "Field 'visibility' must be between 1 and 4";
            }
        }

        if (array_key_exists('status', $resolvedData)) {
            $status = $resolvedData['status'];
            $validStatuses = [1, 2, '1', '2', true, false, 'true', 'false', '0'];
            if (!in_array($status, $validStatuses, true)) {
                $errors[] = "Field 'status' must be 1 (enabled) or 2 (disabled)";
            }
        }

        if (array_key_exists('qty', $resolvedData)) {
            if (!is_numeric($resolvedData['qty']) || (int) $resolvedData['qty'] < 0) {
                $errors[] = "Field 'qty' must be a non-negative integer";
            }
        }

        if (array_key_exists('weight', $resolvedData)) {
            if (!is_numeric($resolvedData['weight']) || (float) $resolvedData['weight'] < 0) {
                $errors[] = "Field 'weight' must be a non-negative number";
            }
        }

        if (array_key_exists('url_key', $resolvedData)) {
            if (!preg_match('/^[a-z0-9][a-z0-9-]*$/', (string) $resolvedData['url_key'])) {
                $errors[] = "Field 'url_key' must contain only lowercase letters, numbers, and hyphens";
            }
        }

        if (array_key_exists('backorders', $resolvedData)) {
            $bo = (int) $resolvedData['backorders'];
            if ($bo < 0 || $bo > 2) {
                $errors[] = "Field 'backorders' must be 0, 1, or 2";
            }
        }

        if (array_key_exists('min_sale_qty', $resolvedData)) {
            if (!is_numeric($resolvedData['min_sale_qty']) || (int) $resolvedData['min_sale_qty'] < 1) {
                $errors[] = "Field 'min_sale_qty' must be >= 1";
            }
        }

        return $errors;
    }

    /**
     * createproduct (1.27.0): a price of 0 is allowed when the product is created disabled, i.e.
     * the request's status is one of DISABLED_STATUSES. An absent status means enabled; an
     * invalid status is not disabled (and fails validate()).
     *
     * @param array $resolvedData
     * @return bool
     */
    public function allowsZeroPrice(array $resolvedData): bool
    {
        return array_key_exists('status', $resolvedData)
            && in_array($resolvedData['status'], self::DISABLED_STATUSES, true);
    }
}
