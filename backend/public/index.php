<?php

declare(strict_types=1);

use App\Controllers\ProductionController;
use App\Controllers\PurchaseOrderController;
use App\Controllers\SystemController;
use App\Controllers\WorkOrderController;
use App\Database;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Services\PurchaseOrderService;
use App\Services\StationService;
use App\Services\UniqueGuard;
use App\Services\WorkOrderService;

$config = require __DIR__ . '/../bootstrap.php';

$request = Request::fromGlobals();
Response::cors($config['cors_origins'], $request->header('origin'));

if ($request->method === 'OPTIONS') {
    Response::noContent();
    return;
}

try {
    // Kimlik: X-API-Key başlığı hangi client/rol olduğunu belirler.
    $apiKey = $request->header('x-api-key');
    $request->role = $apiKey !== null ? ($config['api_keys'][$apiKey] ?? null) : null;
    if ($apiKey !== null && $request->role === null) {
        throw new HttpException(401, 'INVALID_API_KEY', 'Geçersiz API anahtarı.');
    }

    $db = Database::connect($config['db_path']);

    $unique         = new UniqueGuard($db);
    $stationService = new StationService($db);
    $poService      = new PurchaseOrderService($db, $unique);
    $woService      = new WorkOrderService($db, $unique, $poService, $stationService);

    $router     = new Router();
    $po         = new PurchaseOrderController($poService, $woService);
    $wo         = new WorkOrderController($woService);
    $production = new ProductionController($woService, $stationService);
    $system     = new SystemController($unique);
    require __DIR__ . '/../routes.php';

    $router->dispatch($request);
} catch (HttpException $e) {
    if ($e->status === 403 && $request->role === null) {
        $e = new HttpException(401, 'UNAUTHORIZED', 'X-API-Key başlığı gerekli.');
    }
    Response::json($e->toArray(), $e->status);
} catch (Throwable $e) {
    error_log((string) $e);
    Response::json(['error' => ['code' => 'SERVER_ERROR', 'message' => 'Sunucu hatası.']], 500);
}
