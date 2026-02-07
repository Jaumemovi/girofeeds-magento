<?php

declare(strict_types=1);

namespace Girofeeds\ProductApi\Model;

use Girofeeds\ProductApi\Api\Log\RepositoryInterface as LogRepository;

class ApiLogger
{

    private LogRepository $logRepository;

    public function __construct(LogRepository $logRepository)
    {
        $this->logRepository = $logRepository;
    }

    public function logRequest(
        string $endpoint,
        string $apiKeyHash,
        string $clientIp,
        int $httpStatus,
        float $executionTimeMs
    ): void {
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

    public function logUnknownFields(string $endpoint, array $fields): void
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

    public function logError(string $endpoint, string $message): void
    {
        $this->logRepository->addErrorLog('API', sprintf(
            '[%s] %s | error=%s',
            date('Y-m-d H:i:s'),
            $endpoint,
            $message
        ));
    }

    public static function hashKey(?string $key): string
    {
        if (empty($key)) {
            return 'none';
        }
        return substr(hash('sha256', $key), 0, 8);
    }
}
