<?php

declare(strict_types=1);

namespace Girofeeds\ProductApi\Model\Create;

/**
 * Value rules for the product attributes added to a createproduct request (no Magento
 * dependencies, so the rules can be tested with the plain PHP CLI).
 *
 * Every normalize* method returns the same shape:
 * ['write' => bool, 'value' => mixed, 'warnings' => string[], 'failed' => bool]
 * - write: the value must be written to the attribute
 * - value: the value to write (null when nothing is written)
 * - warnings: messages for the response, already prefixed with "Field '<code>': "
 * - failed: the field (or part of it) was not written because of an invalid value
 */
class AttributeValueNormalizer
{
    const KIND_UNSUPPORTED = 'unsupported';
    const KIND_BOOLEAN = 'boolean';
    const KIND_SELECT = 'select';
    const KIND_MULTISELECT = 'multiselect';
    const KIND_NUMERIC = 'numeric';
    const KIND_DATE = 'date';
    const KIND_TEXT = 'text';

    /** Maximum number of option labels listed in a warning */
    const MAX_LISTED_OPTIONS = 50;

    const TRUE_VALUES = ['true', '1', 'yes', 'si', 'sí'];
    const FALSE_VALUES = ['false', '0', 'no'];

    /** Frontend inputs that are never written by the generic writer */
    const UNSUPPORTED_INPUTS = ['media_image', 'gallery', 'weee'];

    /**
     * Null or a string that is empty after trimming
     *
     * @param mixed $value
     * @return bool
     */
    public function isBlank($value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }

    /**
     * Value kind from the attribute's frontend input and backend type
     *
     * @param string|null $frontendInput
     * @param string|null $backendType
     * @return string one of the KIND_* constants
     */
    public function resolveKind($frontendInput, $backendType): string
    {
        $frontendInput = (string) $frontendInput;
        $backendType = (string) $backendType;

        if ($backendType === 'static' || in_array($frontendInput, self::UNSUPPORTED_INPUTS, true)) {
            return self::KIND_UNSUPPORTED;
        }
        if ($frontendInput === 'boolean') {
            return self::KIND_BOOLEAN;
        }
        if ($frontendInput === 'select') {
            return self::KIND_SELECT;
        }
        if ($frontendInput === 'multiselect') {
            return self::KIND_MULTISELECT;
        }
        if ($frontendInput === 'date' || $frontendInput === 'datetime' || $backendType === 'datetime') {
            return self::KIND_DATE;
        }
        if ($backendType === 'decimal' || $backendType === 'int') {
            return self::KIND_NUMERIC;
        }

        return self::KIND_TEXT;
    }

    /**
     * Normalize a request value for an attribute of the given kind
     *
     * @param string $code attribute code (used in the warnings)
     * @param string $kind one of the KIND_* constants except KIND_UNSUPPORTED
     * @param mixed $value
     * @param array $options [['value' => ..., 'label' => ...]] for select/multiselect
     * @return array see the class docblock
     */
    public function normalize(string $code, string $kind, $value, array $options = []): array
    {
        if ($this->isBlank($value)) {
            return $this->skip();
        }

        switch ($kind) {
            case self::KIND_BOOLEAN:
                return $this->normalizeBoolean($code, $value);
            case self::KIND_SELECT:
                return $this->normalizeSelect($code, $value, $options);
            case self::KIND_MULTISELECT:
                return $this->normalizeMultiselect($code, $value, $options);
            case self::KIND_NUMERIC:
                return $this->normalizeNumeric($code, $value);
            case self::KIND_DATE:
                return $this->normalizeDate($code, $value);
            default:
                return $this->normalizeText($code, $value);
        }
    }

    /**
     * Yes/no value as 1/0
     *
     * @param string $code
     * @param mixed $value
     * @return array
     */
    public function normalizeBoolean(string $code, $value): array
    {
        if (is_bool($value)) {
            return $this->accept($value ? 1 : 0);
        }
        if (is_int($value) || is_string($value)) {
            $normalized = $this->lower(trim((string) $value));
            if (in_array($normalized, self::TRUE_VALUES, true)) {
                return $this->accept(1);
            }
            if (in_array($normalized, self::FALSE_VALUES, true)) {
                return $this->accept(0);
            }
        }

        return $this->reject($code, 'invalid yes/no value ' . $this->describe($value));
    }

    /**
     * Option id of a select value (label first, then exact option id). Options are never created.
     *
     * @param string $code
     * @param mixed $value
     * @param array $options
     * @return array
     */
    public function normalizeSelect(string $code, $value, array $options): array
    {
        if (!is_scalar($value)) {
            return $this->reject($code, 'a list is not a valid value for a single option attribute');
        }

        $optionId = $this->findOptionId($this->scalarToString($value), $options);
        if ($optionId === null) {
            return $this->reject($code, $this->invalidOptionMessage($code, $value, $options));
        }

        return $this->accept($optionId);
    }

    /**
     * Comma-separated option ids of a multiselect value (list or comma-separated string)
     *
     * Valid elements are written; each invalid one adds a warning and marks the field as failed.
     *
     * @param string $code
     * @param mixed $value
     * @param array $options
     * @return array
     */
    public function normalizeMultiselect(string $code, $value, array $options): array
    {
        if (is_array($value)) {
            $elements = array_values($value);
        } elseif (is_scalar($value)) {
            $elements = explode(',', $this->scalarToString($value));
        } else {
            return $this->reject($code, 'invalid value ' . $this->describe($value));
        }

        $ids = [];
        $warnings = [];
        foreach ($elements as $element) {
            if ($this->isBlank($element)) {
                continue;
            }
            if (!is_scalar($element)) {
                $warnings[] = $this->prefix($code, 'invalid list element ' . $this->describe($element));
                continue;
            }
            $optionId = $this->findOptionId($this->scalarToString($element), $options);
            if ($optionId === null) {
                $warnings[] = $this->prefix($code, $this->invalidOptionMessage($code, $element, $options));
                continue;
            }
            if (!in_array($optionId, $ids, true)) {
                $ids[] = $optionId;
            }
        }

        if (empty($ids)) {
            return [
                'write' => false,
                'value' => null,
                'warnings' => $warnings,
                'failed' => !empty($warnings),
            ];
        }

        return [
            'write' => true,
            'value' => implode(',', $ids),
            'warnings' => $warnings,
            'failed' => !empty($warnings),
        ];
    }

    /**
     * Number (int/float, or a numeric string, trimmed)
     *
     * @param string $code
     * @param mixed $value
     * @return array
     */
    public function normalizeNumeric(string $code, $value): array
    {
        if (is_int($value) || is_float($value)) {
            return $this->accept($value);
        }
        if (is_string($value) && is_numeric(trim($value))) {
            return $this->accept(trim($value));
        }

        return $this->reject($code, 'value ' . $this->describe($value) . ' is not numeric');
    }

    /**
     * Date or date-time string that strtotime() understands (written as received, trimmed)
     *
     * @param string $code
     * @param mixed $value
     * @return array
     */
    public function normalizeDate(string $code, $value): array
    {
        if (is_scalar($value) && !is_bool($value)) {
            $text = trim((string) $value);
            if ($text !== '' && strtotime($text) !== false && $this->isRealDate($text)) {
                return $this->accept($text);
            }
        }

        return $this->reject($code, 'value ' . $this->describe($value) . ' is not a valid date');
    }

    /**
     * Any scalar as a string; lists and objects are rejected
     *
     * @param string $code
     * @param mixed $value
     * @return array
     */
    public function normalizeText(string $code, $value): array
    {
        if (!is_scalar($value)) {
            return $this->reject($code, 'value must be text, received ' . $this->describe($value));
        }

        return $this->accept($this->scalarToString($value));
    }

    /**
     * Option id whose label matches (case-insensitive, trimmed); otherwise the option whose id
     * is exactly the value. Options with an empty value are ignored.
     *
     * @param string $value
     * @param array $options
     * @return string|null
     */
    private function findOptionId(string $value, array $options)
    {
        $needle = $this->lower(trim($value));
        $validOptions = $this->validOptions($options);

        foreach ($validOptions as $option) {
            if ($this->lower(trim($option['label'])) === $needle) {
                return $option['value'];
            }
        }
        foreach ($validOptions as $option) {
            if ($option['value'] === trim($value)) {
                return $option['value'];
            }
        }

        return null;
    }

    /**
     * Options with a scalar, non-empty value, as strings
     *
     * @param array $options
     * @return array [['value' => string, 'label' => string]]
     */
    private function validOptions(array $options): array
    {
        $valid = [];
        foreach ($options as $option) {
            if (!is_array($option) || !array_key_exists('value', $option) || !is_scalar($option['value'])) {
                continue;
            }
            $optionValue = (string) $option['value'];
            if ($optionValue === '') {
                continue;
            }
            $label = isset($option['label']) ? $option['label'] : '';
            $valid[] = [
                'value' => $optionValue,
                'label' => is_scalar($label) || (is_object($label) && method_exists($label, '__toString'))
                    ? (string) $label
                    : '',
            ];
        }

        return $valid;
    }

    /**
     * Warning text for a value that matches no option (at most MAX_LISTED_OPTIONS labels)
     *
     * @param string $code
     * @param mixed $value
     * @param array $options
     * @return string
     */
    private function invalidOptionMessage(string $code, $value, array $options): string
    {
        $labels = [];
        foreach ($this->validOptions($options) as $option) {
            $label = trim($option['label']);
            if ($label !== '' && !in_array($label, $labels, true)) {
                $labels[] = $label;
            }
        }

        $listed = array_slice($labels, 0, self::MAX_LISTED_OPTIONS);
        $available = empty($listed) ? '(none)' : implode(', ', $listed);
        if (count($labels) > self::MAX_LISTED_OPTIONS) {
            $available .= ', …';
        }

        return "Value '" . trim($this->scalarToString($value)) . "' is not a valid option for attribute '{$code}'. "
            . 'Available options: ' . $available;
    }

    /**
     * strtotime() accepts overflowing dates such as 2026-13-45; reject them when the value
     * starts with a Y-m-d date
     *
     * @param string $text
     * @return bool
     */
    private function isRealDate(string $text): bool
    {
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $text, $matches)) {
            return checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1]);
        }

        return true;
    }

    /**
     * @param mixed $value
     * @return string
     */
    private function scalarToString($value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return (string) $value;
    }

    /**
     * Short description of a value for a warning
     *
     * @param mixed $value
     * @return string
     */
    private function describe($value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_scalar($value)) {
            return "'" . (string) $value . "'";
        }
        if (is_array($value)) {
            return 'a list';
        }

        return 'an object';
    }

    /**
     * @param string $value
     * @return string
     */
    private function lower(string $value): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }

    /**
     * @param string $code
     * @param string $message
     * @return string
     */
    private function prefix(string $code, string $message): string
    {
        return "Field '{$code}': {$message}";
    }

    /**
     * @param mixed $value
     * @return array
     */
    private function accept($value): array
    {
        return ['write' => true, 'value' => $value, 'warnings' => [], 'failed' => false];
    }

    /**
     * @return array
     */
    private function skip(): array
    {
        return ['write' => false, 'value' => null, 'warnings' => [], 'failed' => false];
    }

    /**
     * @param string $code
     * @param string $message
     * @return array
     */
    private function reject(string $code, string $message): array
    {
        return ['write' => false, 'value' => null, 'warnings' => [$this->prefix($code, $message)], 'failed' => true];
    }
}
