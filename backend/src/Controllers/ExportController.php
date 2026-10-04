<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Services\PoExportService;

final class ExportController
{
    private const XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    public function __construct(private readonly PoExportService $export)
    {
    }

    /** GET /pos/{id}/export — PO'yu Excel şablonuna doldurup indirir */
    public function po(Request $request, array $params): void
    {
        $file = $this->export->export((int) $params['id'], $request->user);
        Response::file($file['content'], $file['filename'], self::XLSX);
    }

    /** GET /templates/po — şablon durumu ve yer tutucu listesi */
    public function templateInfo(): void
    {
        Response::data($this->export->templateInfo());
    }

    /** GET /templates/po/file — geçerli şablonu indirir (düzenlemek için) */
    public function templateFile(): void
    {
        $file = $this->export->templateFile();
        Response::file($file['content'], $file['filename'], self::XLSX);
    }

    /** POST /templates/po — multipart "file" alanıyla yeni şablon yükler */
    public function templateUpload(Request $request): void
    {
        $path = $request->uploadedFile('file')
            ?? throw HttpException::validation('file', 'Bir .xlsx dosyası seçin.');
        $this->export->saveTemplate((string) file_get_contents($path));
        Response::data($this->export->templateInfo());
    }

    /** DELETE /templates/po — varsayılan şablona döner */
    public function templateReset(): void
    {
        $this->export->resetTemplate();
        Response::data($this->export->templateInfo());
    }
}
