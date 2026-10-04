<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Services\WorkOrderService;

final class WorkOrderController
{
    public function __construct(private readonly WorkOrderService $workOrders)
    {
    }

    public function indexByPo(Request $request, array $params): void
    {
        Response::data($this->workOrders->listByPo((int) $params['poId']));
    }

    public function store(Request $request, array $params): void
    {
        Response::data($this->workOrders->create((int) $params['poId'], $request->json()), 201);
    }

    public function show(Request $request, array $params): void
    {
        Response::data($this->workOrders->get((int) $params['id']));
    }

    public function history(Request $request, array $params): void
    {
        Response::data($this->workOrders->history((int) $params['id']));
    }

    public function update(Request $request, array $params): void
    {
        Response::data($this->workOrders->update((int) $params['id'], $request->json()));
    }

    /** Yönetici: iş emrini istenen istasyona / Bekliyor / İptal durumuna taşır. */
    public function move(Request $request, array $params): void
    {
        Response::data($this->workOrders->move((int) $params['id'], $request->json(), $request->user['id']));
    }

    public function destroy(Request $request, array $params): void
    {
        $this->workOrders->delete((int) $params['id']);
        Response::noContent();
    }
}
