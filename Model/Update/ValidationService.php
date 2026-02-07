<?php

declare(strict_types=1);

namespace Girofeeds\ProductApi\Model\Update;

class ValidationService
{

    public function validate(array $resolvedData): array
    {
        $errors = [];

        if (array_key_exists('price', $resolvedData)) {
            $price = $resolvedData['price'];
            if (!is_numeric($price) || (float) $price <= 0) {
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
}
