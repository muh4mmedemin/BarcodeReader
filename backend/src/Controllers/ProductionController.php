<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Services\StationService;
use App\Services\Validator;
use App\Services\WorkOrderService;

/** Üretim cihazlarının kullandığı uçlar: istasyon listesi, barkod sorgulama ve okutma. */
final class ProductionController
{
    public function __construct(
        private readonly WorkOrderService $workOrders,
        private readonly StationService $stations,
    ) {
    }

    public function stations(): void
    {
        Response::data($this->stations->list());
    }

    public function lookup(Request $request, array $params): void
    {
        Response::data($this->workOrders->findByBarcode($params['barcode']));
    }

    public function scan(Request $request): void
    {
        $input   = $request->json();
        $barcode = trim((string) ($input['barcode'] ?? ''));
        if ($barcode === '') {
            throw HttpException::validation('barcode', 'Barkod zorunludur.');
        }

        // Üretim kullanıcısının istasyonu hesabına sabittir; gövdedeki station_id yok sayılır.
        $user = $request->user;
        if ($user['role'] === 'production') {
            $stationId = $user['station_id']
                ?? throw new HttpException(403, 'NO_STATION', 'Bu kullanıcıya istasyon atanmamış.');
        } else {
            if (!isset($input['station_id'])) {
                throw HttpException::validation('station_id', 'İstasyon seçilmeli.');
            }
            $stationId = Validator::positiveInt($input, 'station_id', 0);
        }

        Response::data($this->workOrders->scan($barcode, $stationId, $user['id']), 201);
    }
}
