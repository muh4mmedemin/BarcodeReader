<?php

declare(strict_types=1);

use App\Controllers\ProductionController;
use App\Controllers\PurchaseOrderController;
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
 */

const REP  = 'rep';
const PROD = 'production';

// Sistem
$router->add('GET', '/api/v1/health', [], $system->health(...));
$router->add('GET', '/api/v1/me', [REP, PROD], $system->me(...));
$router->add('GET', '/api/v1/check', [REP], $system->check(...));

// PO yönetimi (müşteri temsilcisi)
$router->add('GET',    '/api/v1/pos',      [REP], $po->index(...));
$router->add('POST',   '/api/v1/pos',      [REP], $po->store(...));
$router->add('GET',    '/api/v1/pos/{id}', [REP], $po->show(...));
$router->add('PUT',    '/api/v1/pos/{id}', [REP], $po->update(...));
$router->add('DELETE', '/api/v1/pos/{id}', [REP], $po->destroy(...));

// İş emri yönetimi (müşteri temsilcisi)
$router->add('GET',    '/api/v1/pos/{poId}/work-orders', [REP], $wo->indexByPo(...));
$router->add('POST',   '/api/v1/pos/{poId}/work-orders', [REP], $wo->store(...));
$router->add('GET',    '/api/v1/work-orders/{id}',       [REP], $wo->show(...));
$router->add('PUT',    '/api/v1/work-orders/{id}',       [REP], $wo->update(...));
$router->add('DELETE', '/api/v1/work-orders/{id}',       [REP], $wo->destroy(...));

// Üretim (barkod okutma) — temsilci de sorgulayabilir
$router->add('GET',  '/api/v1/stations',                    [PROD, REP], $production->stations(...));
$router->add('GET',  '/api/v1/production/lookup/{barcode}', [PROD, REP], $production->lookup(...));
$router->add('POST', '/api/v1/production/scan',             [PROD],      $production->scan(...));
