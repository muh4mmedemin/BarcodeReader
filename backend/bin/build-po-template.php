<?php

declare(strict_types=1);

// Varsayılan PO Excel şablonunu üretir: backend/templates/po.xlsx
// Kullanım: php backend/bin/build-po-template.php
// Şablonu değiştirmek için bu dosyayı düzenlemek gerekmez: Excel Şablonu sayfasından şablonu
// indirip Excel'de düzenleyip geri yükleyin. Bu script yalnızca varsayılanı yeniden üretir.

use App\Excel\Zip;

require __DIR__ . '/../bootstrap.php';

/**
 * Sayfa tanımı: hücreler [satır => [sütun => [değer, stil]]]. "=" ile başlayan değer formüldür.
 * Stiller (styles.xml cellXfs sırası): 0 normal, 1 başlık, 2 etiket, 3 tablo başlığı, 4 tablo hücresi,
 * 5 not, 6 sayı tablo hücresi, 7 değer (kalın), 8 toplam etiketi, 9 toplam sayısı
 */
$sheets = [
    'PO' => [
        'cols' => [6, 16, 18, 30, 8, 14, 18, 18, 18],
        'merge' => ['A1:I1'],
        'rows' => [
            1  => ['A' => ['{po_number} — PO raporu', 1]],
            3  => ['A' => ['PO numarası', 2], 'C' => ['{po_number}', 7], 'F' => ['Rapor tarihi', 2], 'G' => ['{export_date}', 0]],
            4  => ['A' => ['Müşteri', 2], 'C' => ['{customer}', 7], 'F' => ['Hazırlayan', 2], 'G' => ['{exported_by}', 0]],
            5  => ['A' => ['Termin', 2], 'C' => ['{due_date}', 0], 'D' => ['{due_status}', 0], 'F' => ['Kayıt', 2], 'G' => ['{created_at}', 0]],
            6  => ['A' => ['Not', 2], 'C' => ['{note}', 0]],
            8  => ['A' => ['Toplam', 3], 'B' => ['', 3], 'C' => ['Bekliyor', 3], 'D' => ['Hatta', 3], 'E' => ['', 3], 'F' => ['Tamamlandı', 3], 'G' => ['İptal', 3], 'H' => ['Tamamlanma', 3]],
            9  => ['A' => ['{total}', 6], 'B' => ['', 4], 'C' => ['{open}', 6], 'D' => ['{in_progress}', 6], 'E' => ['', 4], 'F' => ['{done}', 6], 'G' => ['{cancelled}', 6], 'H' => ['{completion}', 4]],
            11 => ['A' => ['#', 3], 'B' => ['MA kodu', 3], 'C' => ['Barkod', 3], 'D' => ['Açıklama', 3], 'E' => ['Adet', 3],
                   'F' => ['Durum', 3], 'G' => ['Konum', 3], 'H' => ['Son hareket', 3], 'I' => ['Kayıt', 3]],
            12 => ['A' => ['{wo.no}', 6], 'B' => ['{wo.ma_code}', 4], 'C' => ['{wo.barcode}', 4], 'D' => ['{wo.description}', 4],
                   'E' => ['{wo.quantity}', 6], 'F' => ['{wo.status}', 4], 'G' => ['{wo.location}', 4],
                   'H' => ['{wo.last_scan}', 4], 'I' => ['{wo.created_at}', 4]],
            13 => ['D' => ['Toplam adet', 8], 'E' => ['=SUM(E12:E12)', 9]],
            15 => ['A' => ['BarcodeReader tarafından oluşturuldu.', 5]],
        ],
    ],
    'Geçmiş' => [
        'cols' => [6, 16, 18, 20, 18, 14, 16],
        'merge' => ['A1:G1'],
        'rows' => [
            1 => ['A' => ['{po_number} — istasyon geçmişi', 1]],
            3 => ['A' => ['#', 3], 'B' => ['MA kodu', 3], 'C' => ['Barkod', 3], 'D' => ['Konum', 3], 'E' => ['Tarih', 3], 'F' => ['Okutan', 3], 'G' => ['Geçen süre', 3]],
            4 => ['A' => ['{scan.no}', 6], 'B' => ['{scan.ma_code}', 4], 'C' => ['{scan.barcode}', 4], 'D' => ['{scan.station}', 4],
                  'E' => ['{scan.date}', 4], 'F' => ['{scan.user}', 4], 'G' => ['{scan.duration}', 4]],
        ],
    ],
    'Dağılım' => [
        'cols' => [26, 10, 10],
        'merge' => ['A1:C1'],
        'rows' => [
            1 => ['A' => ['{po_number} — konum dağılımı', 1]],
            3 => ['A' => ['Konum', 3], 'B' => ['İE', 3], 'C' => ['Oran', 3]],
            4 => ['A' => ['{loc.name}', 4], 'B' => ['{loc.count}', 6], 'C' => ['{loc.percent}', 6]],
            5 => ['A' => ['Toplam', 8], 'B' => ['=SUM(B4:B4)', 9]],
        ],
    ],
];

$esc = static fn (string $s): string => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
$strings = [];
$sid = static function (string $s) use (&$strings): int {
    if (!array_key_exists($s, $strings)) {
        $strings[$s] = count($strings);
    }
    return $strings[$s];
};

$files = [];
$sheetEntries = '';
$sheetRels = '';
$overrides = '';
$i = 0;
foreach ($sheets as $name => $sheet) {
    $i++;
    $cols = '';
    foreach ($sheet['cols'] as $n => $w) {
        $cols .= sprintf('<col min="%d" max="%d" width="%s" customWidth="1"/>', $n + 1, $n + 1, $w);
    }
    $rowsXml = '';
    foreach ($sheet['rows'] as $r => $cells) {
        $rowsXml .= '<row r="' . $r . '"' . ($r === 1 ? ' ht="24" customHeight="1"' : '') . '>';
        foreach ($cells as $col => [$value, $style]) {
            $ref = $col . $r;
            if (str_starts_with($value, '=')) {
                $rowsXml .= '<c r="' . $ref . '" s="' . $style . '"><f>' . $esc(substr($value, 1)) . '</f></c>';
            } elseif ($value === '') {
                $rowsXml .= '<c r="' . $ref . '" s="' . $style . '"/>';
            } else {
                $rowsXml .= '<c r="' . $ref . '" s="' . $style . '" t="s"><v>' . $sid($value) . '</v></c>';
            }
        }
        $rowsXml .= '</row>';
    }
    $merge = '';
    if ($sheet['merge']) {
        $merge = '<mergeCells count="' . count($sheet['merge']) . '">'
            . implode('', array_map(static fn ($m) => '<mergeCell ref="' . $m . '"/>', $sheet['merge'])) . '</mergeCells>';
    }
    $files["xl/worksheets/sheet$i.xml"] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheetViews><sheetView workbookViewId="0" showGridLines="0"/></sheetViews>'
        . '<sheetFormatPr defaultRowHeight="15"/>'
        . '<cols>' . $cols . '</cols><sheetData>' . $rowsXml . '</sheetData>' . $merge
        . '<pageMargins left="0.5" right="0.5" top="0.6" bottom="0.6" header="0.3" footer="0.3"/>'
        . '<pageSetup orientation="landscape" paperSize="9" fitToHeight="0"/>'
        . '</worksheet>';
    $sheetEntries .= '<sheet name="' . $esc($name) . '" sheetId="' . $i . '" r:id="rId' . $i . '"/>';
    $sheetRels .= '<Relationship Id="rId' . $i . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $i . '.xml"/>';
    $overrides .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
}

$n = count($sheets);
$sst = '';
foreach (array_keys($strings) as $s) {
    $sst .= '<si><t xml:space="preserve">' . $esc((string) $s) . '</t></si>';
}

$files = [
    '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . $overrides
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
        . '</Types>',
    '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>',
    'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets>' . $sheetEntries . '</sheets><calcPr calcId="191029"/></workbook>',
    'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . $sheetRels
        . '<Relationship Id="rId' . ($n + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '<Relationship Id="rId' . ($n + 2) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>'
        . '</Relationships>',
    'xl/styles.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="5">'
        . '<font><sz val="10"/><name val="Arial"/></font>'
        . '<font><b/><sz val="14"/><color rgb="FF1C1F23"/><name val="Arial"/></font>'
        . '<font><b/><sz val="10"/><color rgb="FF5C636B"/><name val="Arial"/></font>'
        . '<font><i/><sz val="9"/><color rgb="FF8F969E"/><name val="Arial"/></font>'
        . '<font><b/><sz val="10"/><name val="Arial"/></font>'
        . '</fonts>'
        . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFE3E5E8"/><bgColor indexed="64"/></patternFill></fill></fills>'
        . '<borders count="3"><border><left/><right/><top/><bottom/><diagonal/></border>'
        . '<border><left style="thin"><color rgb="FFC4C8CD"/></left><right style="thin"><color rgb="FFC4C8CD"/></right>'
        . '<top style="thin"><color rgb="FFC4C8CD"/></top><bottom style="thin"><color rgb="FFC4C8CD"/></bottom><diagonal/></border>'
        . '<border><left/><right/><top style="medium"><color rgb="FF8F969E"/></top><bottom/><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="10">'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'                                              // 0 normal
        . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"><alignment vertical="center"/></xf>' // 1 başlık
        . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'                                // 2 etiket
        . '<xf numFmtId="0" fontId="4" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>'  // 3 tablo başlığı
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"/>'                              // 4 tablo hücresi
        . '<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1"/>'                                // 5 not
        . '<xf numFmtId="1" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"/>'        // 6 sayı
        . '<xf numFmtId="0" fontId="4" fillId="0" borderId="0" xfId="0" applyFont="1"/>'                                // 7 değer
        . '<xf numFmtId="0" fontId="4" fillId="0" borderId="2" xfId="0" applyFont="1" applyBorder="1"><alignment horizontal="right"/></xf>' // 8 toplam etiketi
        . '<xf numFmtId="1" fontId="4" fillId="0" borderId="2" xfId="0" applyFont="1" applyBorder="1" applyNumberFormat="1"/>' // 9 toplam
        . '</cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        . '</styleSheet>',
    'xl/sharedStrings.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . count($strings) . '" uniqueCount="' . count($strings) . '">'
        . $sst . '</sst>',
] + $files;

$target = __DIR__ . '/../templates/po.xlsx';
if (!is_dir(dirname($target))) {
    mkdir(dirname($target), 0775, true);
}
file_put_contents($target, Zip::write($files));
echo "Şablon yazıldı: " . realpath($target) . "\n";
