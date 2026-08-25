<?php
namespace WHCM\Api\Controllers;

use WHCM\Core\Bootstrap;
use WHCM\Core\RequestContext;

final class HealthApiController
{
    public function live(): void
    {
        \WHCM\Api\MobileApiResponse::success(['ok'=>true, 'service'=>'postyar-api', 'api_version'=>'v1', 'request_id'=>RequestContext::id()]);
    }

    public function ready(): void
    {
        try {
            Bootstrap::getDB()->query('SELECT 1')->fetchColumn();
            \WHCM\Api\MobileApiResponse::success(['ok'=>true, 'db'=>true, 'request_id'=>RequestContext::id()]);
        } catch (\Throwable $e) {
            \WHCM\Core\Logger::error('api_readiness_failed', ['exception'=>get_class($e)]);
            http_response_code(503);
            \WHCM\Api\MobileApiResponse::success(['ok'=>false, 'db'=>false, 'request_id'=>RequestContext::id()]);
        }
    }
}
