<?php

declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\ExportController;
use App\Controllers\ProductionController;
use App\Controllers\PurchaseOrderController;
use App\Controllers\ReportController;
use App\Controllers\SystemController;
use App\Controllers\WorkOrderController;
use App\Http\Router;

/**
 * Tüm API uçları. Üçüncü parametre, uca erişebilen rollerdir ([] = herkese açık).
 *
 * @var Router $router
 * @var PurchaseOrderController $po
 * @var WorkOrderController $wo
 * @var ProductionController $production
 * @var SystemController $system
 * @var AuthController $authCtl
 * @var ReportController $reports
 * @var ExportController $exports
 */

const REP  = 'rep';
const PROD = 'production';
const BOARD = 'board';      // atölye panosu: yalnızca pano verisini görür
const ADMIN = 'admin';      // yönetici: iş emirlerini görür ve istediği konuma taşır

// Sistem
$router->add('GET', '/api/v1/health', [], $system->health(...));
$router->add('GET', '/api/v1/me', [REP, PROD, BOARD, ADMIN], $authCtl->me(...));

// Giriş / çıkış
$router->add('POST', '/api/v1/auth/login',  [],           $authCtl->login(...));
$router->add('POST', '/api/v1/auth/logout', [REP, PROD, BOARD, ADMIN], $authCtl->logout(...));
$router->add('GET', '/api/v1/check', [REP], $system->check(...));

// PO yönetimi (müşteri temsilcisi)
$router->add('GET',    '/api/v1/pos',      [REP], $po->index(...));
$router->add('GET',    '/api/v1/reports/overview', [REP, ADMIN], $po->overview(...));
$router->add('GET',    '/api/v1/reports/stations', [REP], $reports->stations(...));

// Atölye panosu
$router->add('GET', '/api/v1/board', [BOARD, REP], $reports->board(...));
$router->add('GET', '/api/v1/board/version', [BOARD, REP], $reports->boardVersion(...));
$router->add('POST',   '/api/v1/pos',      [REP], $po->store(...));
$router->add('GET',    '/api/v1/pos/{id}', [REP], $po->show(...));
$router->add('PUT',    '/api/v1/pos/{id}', [REP], $po->update(...));
$router->add('DELETE', '/api/v1/pos/{id}', [REP], $po->destroy(...));
$router->add('GET',    '/api/v1/pos/{id}/export', [REP], $exports->po(...));

// Excel şablonu
$router->add('GET',    '/api/v1/templates/po',      [REP], $exports->templateInfo(...));
$router->add('GET',    '/api/v1/templates/po/file', [REP], $exports->templateFile(...));
$router->add('POST',   '/api/v1/templates/po',      [REP], $exports->templateUpload(...));
$router->add('DELETE', '/api/v1/templates/po',      [REP], $exports->templateReset(...));

// İş emri yönetimi (müşteri temsilcisi)
$router->add('GET',    '/api/v1/pos/{poId}/work-orders', [REP], $wo->indexByPo(...));
$router->add('POST',   '/api/v1/pos/{poId}/work-orders', [REP], $wo->store(...));
$router->add('GET',    '/api/v1/work-orders/{id}',       [REP], $wo->show(...));
$router->add('GET',    '/api/v1/work-orders/{id}/scans', [REP, ADMIN], $wo->history(...));
$router->add('PUT',    '/api/v1/work-orders/{id}',       [REP], $wo->update(...));
$router->add('POST',   '/api/v1/work-orders/{id}/move',  [ADMIN], $wo->move(...));
$router->add('DELETE', '/api/v1/work-orders/{id}',       [REP], $wo->destroy(...));

// Üretim (barkod okutma). Temsilci istasyonu kendisi seçer; production kullanıcısının
// istasyonu hesabına sabittir (ProductionController::scan).
$router->add('GET',  '/api/v1/stations',                    [PROD, REP, ADMIN], $production->stations(...));
$router->add('GET',  '/api/v1/production/lookup/{barcode}', [PROD, REP], $production->lookup(...));
$router->add('POST', '/api/v1/production/scan',             [PROD, REP], $production->scan(...));
