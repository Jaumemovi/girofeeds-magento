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

    /** Maximum length of a value for a `varchar` backend attribute (MySQL would truncate it) */
    const MAX_VARCHAR_LENGTH = 255;

    /**
     * Accepted dates: Y-m-d, Y-m-d H:i[:s], and ISO 8601 Y-m-d\TH:i[:s] with optional Z/offset.
     * Groups: 1 year, 2 month, 3 day, 4 hour, 5 minute, 6 second, 7 zone (T form only).
     */
    const DATE_PATTERN = '/^(\d{4})-(\d{2})-(\d{2})(?:(?: (\d{2}):(\d{2})(?::(\d{2}))?)|(?:T(\d{2}):(\d{2})(?::(\d{2}))?(Z|[+-]\d{2}:?\d{2})?))?$/';

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
     * @param string $backendType attribute backend type (text length limit for `varchar`)
     * @return array see the class docblock
     */
    public function normalize(string $code, string $kind, $value, array $options = [], string $backendType = ''): array
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
                return $this->normalizeText($code, $value, $backendType);
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
     * Stock flags of a createproduct request (resolved data) with the yes/no rule of
     * normalizeBoolean(), before StockUpdater (which only takes true/1/'1'/'true' as yes).
     * A blank flag is dropped (as if not sent); an invalid one is dropped with a warning and
     * reported as a failed field. Other keys are returned unchanged.
     *
     * @param array $resolved
     * @param string[] $fields
     * @return array ['resolved' => array, 'warnings' => string[], 'failed_fields' => string[]]
     */
    public function normalizeStockFlags(
        array $resolved,
        array $fields = ['use_config_manage_stock', 'manage_stock']
    ): array {
        $warnings = [];
        $failedFields = [];
        foreach ($fields as $field) {
            if (!array_key_exists($field, $resolved)) {
                continue;
            }
            $value = $resolved[$field];
            if ($this->isBlank($value)) {
                unset($resolved[$field]);
                continue;
            }
            $result = $this->normalizeBoolean($field, $value);
            if ($result['write']) {
                $resolved[$field] = $result['value'];
                continue;
            }
            unset($resolved[$field]);
            $warnings[] = "Field '{$field}' ignored: invalid boolean value";
            $failedFields[] = $field;
        }

        return ['resolved' => $resolved, 'warnings' => $warnings, 'failed_fields' => $failedFields];
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
        if (is_int($value) || (is_float($value) && is_finite($value))) {
            return $this->accept($value);
        }
        if (is_string($value) && is_numeric(trim($value)) && is_finite((float) trim($value))) {
            return $this->accept(trim($value));
        }

        return $this->reject($code, 'value ' . $this->describe($value) . ' is not numeric');
    }

    /**
     * Date (Y-m-d) or date-time (Y-m-d H:i[:s], or ISO 8601 Y-m-d\TH:i[:s] with optional Z or
     * offset), checked with checkdate() and valid time ranges. Written as `Y-m-d` or
     * `Y-m-d H:i:s` (the wall-clock time as sent; a Z/offset is validated and dropped). Anything
     * else (digits only, d/m/Y, m/d/Y, words, integers) is rejected: Magento's datetime backend
     * would read it as something else.
     *
     * @param string $code
     * @param mixed $value
     * @return array
     */
    public function normalizeDate(string $code, $value): array
    {
        if (is_string($value)) {
            $formatted = $this->formatDate(trim($value));
            if ($formatted !== null) {
                return $this->accept($formatted);
            }
        }

        return $this->reject($code, 'value ' . $this->describe($value) . ' is not a valid date');
    }

    /**
     * Any scalar as a string; lists and objects are rejected
     *
     * A `varchar` attribute takes at most MAX_VARCHAR_LENGTH characters; `text` is unlimited.
     *
     * @param string $code
     * @param mixed $value
     * @param string $backendType
     * @return array
     */
    public function normalizeText(string $code, $value, string $backendType = ''): array
    {
        if (!is_scalar($value)) {
            return $this->reject($code, 'value must be text, received ' . $this->describe($value));
        }

        $text = $this->scalarToString($value);
        $length = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
        if ($backendType === 'varchar' && $length > self::MAX_VARCHAR_LENGTH) {
            return [
                'write' => false,
                'value' => null,
                'warnings' => ["Field '{$code}' ignored: value longer than " . self::MAX_VARCHAR_LENGTH . ' characters'],
                'failed' => true,
            ];
        }

        return $this->accept($text);
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
     * `Y-m-d` / `Y-m-d H:i:s` for an accepted date string, or null
     *
     * @param string $text
     * @return string|null
     */
    private function formatDate(string $text)
    {
        if (!preg_match(self::DATE_PATTERN, $text, $matches)) {
            return null;
        }

        $year = (int) $matches[1];
        $month = (int) $matches[2];
        $day = (int) $matches[3];
        if (!checkdate($month, $day, $year)) {
            return null;
        }

        $hour = null;
        $minute = 0;
        $second = 0;
        if (isset($matches[4]) && $matches[4] !== '') {
            $hour = (int) $matches[4];
            $minute = (int) $matches[5];
            $second = isset($matches[6]) && $matches[6] !== '' ? (int) $matches[6] : 0;
        } elseif (isset($matches[7]) && $matches[7] !== '') {
            $hour = (int) $matches[7];
            $minute = (int) $matches[8];
            $second = isset($matches[9]) && $matches[9] !== '' ? (int) $matches[9] : 0;
            if (isset($matches[10]) && $matches[10] !== '' && $matches[10] !== 'Z') {
                $offset = str_replace(':', '', substr($matches[10], 1));
                if ((int) substr($offset, 0, 2) > 14 || (int) substr($offset, 2, 2) > 59) {
                    return null;
                }
            }
        }

        $date = sprintf('%04d-%02d-%02d', $year, $month, $day);
        if ($hour === null) {
            return $date;
        }
        if ($hour > 23 || $minute > 59 || $second > 59) {
            return null;
        }

        return $date . sprintf(' %02d:%02d:%02d', $hour, $minute, $second);
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
