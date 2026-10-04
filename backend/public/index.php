<?php

declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\ExportController;
use App\Controllers\ProductionController;
use App\Controllers\PurchaseOrderController;
use App\Controllers\ReportController;
use App\Controllers\SystemController;
use App\Controllers\WorkOrderController;
use App\Database;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Services\AuthService;
use App\Services\PoExportService;
use App\Services\PurchaseOrderService;
use App\Services\ReportService;
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
    $db = Database::connect($config['db_path']);

    // Kimlik: "Authorization: Bearer <token>" — token girişte (POST /auth/login) verilir.
    $authService = new AuthService($db);
    $token = $request->bearerToken();
    if ($token !== null) {
        $request->user = $authService->userFromToken($token)
            ?? throw new HttpException(401, 'SESSION_EXPIRED', 'Oturum geçersiz veya süresi dolmuş. Tekrar giriş yapın.');
        $request->role = $request->user['role'];
    }

    $unique         = new UniqueGuard($db);
    $stationService = new StationService($db);
    $poService      = new PurchaseOrderService($db, $unique);
    $woService      = new WorkOrderService($db, $unique, $poService, $stationService);

    $router     = new Router();
    $po         = new PurchaseOrderController($poService, $woService);
    $wo         = new WorkOrderController($woService);
    $production = new ProductionController($woService, $stationService);
    $system     = new SystemController($unique);
    $authCtl    = new AuthController($authService);
    $reports    = new ReportController(new ReportService($db));
    $exports    = new ExportController(new PoExportService(
        $db, $poService, $woService, $stationService,
        $config['po_template_default'], $config['po_template_custom'],
    ));
    require __DIR__ . '/../routes.php';

    $router->dispatch($request);
} catch (HttpException $e) {
    if ($e->status === 403 && $request->role === null) {
        $e = new HttpException(401, 'UNAUTHORIZED', 'Giriş yapmanız gerekiyor.');
    }
    Response::json($e->toArray(), $e->status);
} catch (Throwable $e) {
    error_log((string) $e);
    Response::json(['error' => ['code' => 'SERVER_ERROR', 'message' => 'Sunucu hatası.']], 500);
}
