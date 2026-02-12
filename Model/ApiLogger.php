<?php

declare(strict_types=1);

namespace Girofeeds\ProductApi\Model;

use Girofeeds\ProductApi\Api\Log\RepositoryInterface as LogRepository;

class ApiLogger
{
    /**
     * @var LogRepository
     */
    private $logRepository;

    /**
     * Constructor
     *
     * @param LogRepository $logRepository
     */
    public function __construct(LogRepository $logRepository)
    {
        $this->logRepository = $logRepository;
    }

    /**
     * Log API request
     *
     * @param string $endpoint
     * @param string $apiKeyHash
     * @param string $clientIp
     * @param int $httpStatus
     * @param float $executionTimeMs
     * @return void
     */
    public function logRequest(
        $endpoint,
        $apiKeyHash,
        $clientIp,
        $httpStatus,
        $executionTimeMs
    ) {
        $this->logRepository->addDebugLog('API', sprintf(
            '[%s] %s | key=%s | ip=%s | status=%d | time=%.1fms',
            date('Y-m-d H:i:s'),
            $endpoint,
            $apiKeyHash,
            $clientIp,
            $httpStatus,
            $executionTimeMs
        ));
    }

    /**
     * Log unknown fields in API request
     *
     * @param string $endpoint
     * @param array $fields
     * @return void
     */
    public function logUnknownFields($endpoint, array $fields)
    {
        if (empty($fields)) {
            return;
        }

        $this->logRepository->addDebugLog('UnknownFields', sprintf(
            '[%s] %s | fields=%s',
            date('Y-m-d H:i:s'),
            $endpoint,
            implode(', ', $fields)
        ));
    }

    /**
     * Log API error
     *
     * @param string $endpoint
     * @param string $message
     * @return void
     */
    public function logError($endpoint, $message)
    {
        $this->logRepository->addErrorLog('API', sprintf(
            '[%s] %s | error=%s',
            date('Y-m-d H:i:s'),
            $endpoint,
            $message
        ));
    }

    /**
     * Hash API key for logging
     *
     * @param string|null $key
     * @return string
     */
    public static function hashKey($key)
    {
        if (empty($key)) {
            return 'none';
        }
        return substr(hash('sha256', $key), 0, 8);
    }
}
