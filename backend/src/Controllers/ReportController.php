<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Services\ReportService;

final class ReportController
{
    public function __construct(private readonly ReportService $reports)
    {
    }

    /** GET /reports/stations?days=30 (1–365) */
    public function stations(Request $request): void
    {
        $days = max(1, min(365, (int) ($request->query['days'] ?? 30)));
        Response::data($this->reports->stations($days));
    }

    public function boardVersion(): void
    {
        Response::data(['version' => $this->reports->boardVersion()]);
    }

    public function board(): void
    {
        Response::data($this->reports->board());
    }
}
