<?php

declare(strict_types=1);

namespace Girofeeds\ProductApi\Model\Update;

class FieldProcessor
{

    private array $aliasMap = [
        'stock'          => 'qty',
        'reference'      => 'sku',
        'ean13'          => 'ean',
        'specific_price' => 'special_price',
        'active'         => 'status',
        'visible'        => 'visibility',
        'image'          => 'image_link',
        'manufacturer'   => 'brand',
    ];

    private array $priorityRules = [
        'qty'           => ['qty', 'stock'],
        'sku'           => ['sku', 'reference'],
        'ean'           => ['ean', 'ean13', 'gtin'],
        'status'        => ['status', 'active'],
        'visibility'    => ['visibility', 'visible'],
        'special_price' => ['special_price', 'specific_price'],
        'image_link'    => ['image_link', 'image'],
    ];

    private array $valueConversionFields = ['active', 'visible'];

    public function resolveFields(array $data): array
    {
        $resolved = [];
        $processedAliases = [];

        foreach ($this->priorityRules as $canonical => $aliases) {
            foreach ($aliases as $alias) {
                if (array_key_exists($alias, $data) && !in_array($canonical, $processedAliases)) {
                    $value = $data[$alias];

                    if ($alias === 'active') {
                        $value = $this->convertActiveToStatus($value);
                    } elseif ($alias === 'visible') {
                        $value = $this->convertVisibleToVisibility($value);
                    }

                    $resolved[$canonical] = $value;
                    $processedAliases[] = $canonical;

                    foreach ($aliases as $a) {
                        unset($data[$a]);
                    }
                    break;
                }
            }
        }

        foreach ($data as $field => $value) {
            if ($field === 'id_product' || $field === 'store') {
                continue;
            }

            if (isset($this->aliasMap[$field])) {
                $canonical = $this->aliasMap[$field];
                if (!isset($resolved[$canonical])) {
                    if ($field === 'active') {
                        $value = $this->convertActiveToStatus($value);
                        $canonical = 'status';
                    } elseif ($field === 'visible') {
                        $value = $this->convertVisibleToVisibility($value);
                        $canonical = 'visibility';
                    }
                    $resolved[$canonical] = $value;
                }
            } else {
                $resolved[$field] = $value;
            }
        }

        if (array_key_exists('special_price', $resolved)) {
            $resolved['special_price'] = $this->processSpecialPrice($resolved['special_price']);
        }

        return $resolved;
    }

    private function convertActiveToStatus($value): int
    {
        if ($value === true || $value === 1 || $value === '1' || $value === 'true') {
            return 1;
        }
        return 2;
    }

    private function convertVisibleToVisibility($value): int
    {
        if ($value === true || $value === 1 || $value === '1' || $value === 'true') {
            return 4;
        }
        return 1;
    }

    private function processSpecialPrice($value): ?float
    {
        if ($value === null || $value === '' || $value === 0 || $value === '0') {
            return null;
        }
        return (float) $value;
    }
}
