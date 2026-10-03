<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Services\UniqueGuard;

final class SystemController
{
    public function __construct(private readonly UniqueGuard $unique)
    {
    }

    public function health(): void
    {
        Response::data(['status' => 'ok', 'version' => 'v1', 'time' => date(DATE_ATOM)]);
    }

    public function me(Request $request): void
    {
        Response::data(['role' => $request->role]);
    }

    /**
     * Form doldururken anlık benzersizlik kontrolü.
     * GET /api/v1/check?field=barcode&value=XYZ[&except_id=5]
     */
    public function check(Request $request): void
    {
        $field = (string) ($request->query['field'] ?? '');
        $value = trim((string) ($request->query['value'] ?? ''));
        if (!UniqueGuard::supports($field)) {
            throw HttpException::validation('field', 'field: po_number, ma_code veya barcode olmalı.');
        }
        $exceptId = isset($request->query['except_id']) ? (int) $request->query['except_id'] : null;

        Response::data([
            'field'     => $field,
            'value'     => $value,
            'available' => $value !== '' && $this->unique->isAvailable($field, $value, $exceptId),
        ]);
    }
}
