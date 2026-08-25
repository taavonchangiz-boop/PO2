<?php
namespace WHCM\Controllers;

use WHCM\Core\Bootstrap;
use WHCM\Core\RequestContext;

final class HealthController
{
    public function live(): void
    {
        $this->json(200, ['ok'=>true, 'service'=>'postyar', 'request_id'=>RequestContext::id()]);
    }

    public function ready(): void
    {
        try {
            $db = Bootstrap::getDB();
            $db->query('SELECT 1')->fetchColumn();
            $this->json(200, ['ok'=>true, 'db'=>true, 'request_id'=>RequestContext::id()]);
        } catch (\Throwable $e) {
            \WHCM\Core\Logger::error('readiness_failed', ['exception'=>get_class($e)]);
            $this->json(503, ['ok'=>false, 'db'=>false, 'request_id'=>RequestContext::id()]);
        }
    }

    public function metrics(): void
    {
        $token = (string)Bootstrap::getConfig('observability.metrics_token', '');
        $provided = (string)($_SERVER['HTTP_X_METRICS_TOKEN'] ?? '');
        if ($token === '' || !hash_equals($token, $provided)) {
            http_response_code(404);
            return;
        }
        header('Content-Type: text/plain; version=0.0.4; charset=utf-8');
        echo \WHCM\Core\Metrics::render();
    }

    private function json(int $status, array $data): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
