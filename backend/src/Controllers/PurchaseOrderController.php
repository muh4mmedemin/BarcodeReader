<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Services\PurchaseOrderService;
use App\Services\WorkOrderService;

final class PurchaseOrderController
{
    public function __construct(
        private readonly PurchaseOrderService $purchaseOrders,
        private readonly WorkOrderService $workOrders,
    ) {
    }

    public function index(Request $request): void
    {
        $limit  = max(1, min(200, (int) ($request->query['limit'] ?? 50)));
        $offset = max(0, (int) ($request->query['offset'] ?? 0));
        $result = $this->purchaseOrders->list($request->query['search'] ?? null, $limit, $offset);

        Response::data($result['items'], meta: ['total' => $result['total'], 'limit' => $limit, 'offset' => $offset]);
    }

    public function overview(): void
    {
        Response::data($this->workOrders->overview());
    }

    public function show(Request $request, array $params): void
    {
        $id = (int) $params['id'];
        $po = $this->purchaseOrders->get($id);
        $po['work_orders'] = $this->workOrders->listByPo($id);
        Response::data($po);
    }

    public function store(Request $request): void
    {
        Response::data($this->purchaseOrders->create($request->json()), 201);
    }

    public function update(Request $request, array $params): void
    {
        Response::data($this->purchaseOrders->update((int) $params['id'], $request->json()));
    }

    public function destroy(Request $request, array $params): void
    {
        $this->purchaseOrders->delete((int) $params['id']);
        Response::noContent();
    }
}
