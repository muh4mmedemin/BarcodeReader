<?php

declare(strict_types=1);

namespace App\Excel;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Excel şablonu doldurucu. Şablon Excel'de serbestçe tasarlanır; biçimlendirme korunur.
 *
 * Kurallar:
 *  - {anahtar}            : genel değer (ör. {po_number}). Hücrede yazının bir parçası olabilir.
 *  - {liste.alan}         : tekrar satırı (ör. {wo.ma_code}). Bu yer tutucunun bulunduğu SATIR,
 *                           listedeki her kayıt için bir kez tekrarlanır; altındaki satırlar kaydırılır.
 *  - Hücrede yalnızca tek bir yer tutucu varsa ve değer sayıysa hücreye sayı olarak yazılır.
 *  - Formüller kaydırılan satırlara göre güncellenir; tekrar satırını kapsayan aralıklar
 *    (ör. =TOPLA(E13:E13)) tüm tekrarları kapsayacak şekilde genişler. Excel açılışta yeniden hesaplar.
 *  - Birleştirilmiş hücreler, koşullu biçimlendirme ve veri doğrulama aralıkları da kaydırılır.
 *  - Desteklenmeyen: başka sayfaya işaret eden formül referansları, Excel "Tablo"ları (Ctrl+T),
 *    yazdırma alanı tanımları — bunlar kaydırılmaz.
 */
final class XlsxTemplate
{
    private const NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    private const XML_NS = 'http://www.w3.org/XML/1998/namespace';
    private const PLACEHOLDER = '/\{([a-z_]+(?:\.[a-z_]+)?)\}/';

    /** Bu fonksiyonlara tekrar satırından tek hücre verilirse tüm tekrarları kapsayan aralığa genişler. */
    private const AGGREGATES = ['SUM', 'AVERAGE', 'COUNT', 'COUNTA', 'COUNTBLANK', 'MIN', 'MAX', 'PRODUCT',
        'MEDIAN', 'SUMPRODUCT', 'SUBTOTAL', 'AGGREGATE', 'STDEV', 'STDEV.S', 'STDEV.P', 'VAR', 'VAR.S', 'VAR.P'];

    /** @var array<string, string> */
    private array $files;

    /** @var list<string> */
    private array $sharedStrings = [];

    public function __construct(string $xlsx)
    {
        $this->files = Zip::read($xlsx);
        if (!isset($this->files['xl/workbook.xml'], $this->files['[Content_Types].xml'])) {
            throw new \RuntimeException('Dosya bir Excel çalışma kitabı (.xlsx) değil.');
        }
        if (!array_filter(array_keys($this->files), static fn ($n) => self::isSheet($n))) {
            throw new \RuntimeException('Çalışma kitabında sayfa bulunamadı.');
        }
        $this->loadSharedStrings();
    }

    /**
     * @param array<string, scalar|null> $values genel yer tutucular
     * @param array<string, list<array<string, scalar|null>>> $lists tekrar satırı listeleri (ör. 'wo' => [...])
     */
    public function render(array $values, array $lists): string
    {
        $files = $this->files;
        foreach ($files as $name => $xml) {
            if (self::isSheet($name)) {
                $files[$name] = $this->renderSheet($xml, $values, $lists);
            }
        }

        // Hücreler yer değiştirdiği için hesaplama zinciri geçersiz: silinir, Excel açılışta yeniden hesaplar.
        unset($files['xl/calcChain.xml']);
        $files['[Content_Types].xml'] = preg_replace('#<Override[^>]*PartName="/xl/calcChain\.xml"[^>]*/>#', '', $files['[Content_Types].xml']);
        if (isset($files['xl/_rels/workbook.xml.rels'])) {
            $files['xl/_rels/workbook.xml.rels'] = preg_replace('#<Relationship[^>]*Target="[^"]*calcChain\.xml"[^>]*/>#', '', $files['xl/_rels/workbook.xml.rels']);
        }
        $files['xl/workbook.xml'] = self::forceRecalc($files['xl/workbook.xml']);

        return Zip::write($files);
    }

    private static function isSheet(string $name): bool
    {
        return (bool) preg_match('#^xl/worksheets/sheet\d+\.xml$#', $name);
    }

    private function loadSharedStrings(): void
    {
        $xml = $this->files['xl/sharedStrings.xml'] ?? null;
        if ($xml === null) {
            return;
        }
        [$doc, $xp] = self::load($xml);
        foreach ($xp->query('/m:sst/m:si') as $si) {
            $text = '';
            foreach ($xp->query('.//m:t[not(ancestor::m:rPh)]', $si) as $t) {
                $text .= $t->textContent;
            }
            $this->sharedStrings[] = $text;
        }
    }

    /** @return array{DOMDocument, DOMXPath} */
    private static function load(string $xml): array
    {
        $doc = new DOMDocument();
        $doc->preserveWhiteSpace = true;
        if (!$doc->loadXML($xml, LIBXML_NONET | LIBXML_PARSEHUGE)) {
            throw new \RuntimeException('Şablondaki XML okunamadı.');
        }
        $xp = new DOMXPath($doc);
        $xp->registerNamespace('m', self::NS);
        return [$doc, $xp];
    }

    private function renderSheet(string $xml, array $values, array $lists): string
    {
        [$doc, $xp] = self::load($xml);
        $sheetData = $xp->query('/m:worksheet/m:sheetData')->item(0);
        if (!$sheetData instanceof DOMElement) {
            return $xml;
        }

        $this->expandSharedFormulas($xp, $sheetData);

        // 1) Satır numaraları ve tekrar satırları
        /** @var list<DOMElement> $rows */
        $rows = [];
        $repeat = [];   // satır no => ['list' => anahtar, 'count' => n]
        $last = 0;
        foreach ($xp->query('m:row', $sheetData) as $row) {
            $r = (int) $row->getAttribute('r') ?: $last + 1;
            $row->setAttribute('r', (string) $r);
            $last = $r;
            $rows[] = $row;

            foreach ($xp->query('m:c', $row) as $c) {
                $text = $this->cellText($xp, $c);
                if ($text !== null && preg_match_all(self::PLACEHOLDER, $text, $m)) {
                    foreach ($m[1] as $key) {
                        $prefix = strstr($key, '.', true);
                        if ($prefix !== false && isset($lists[$prefix]) && !isset($repeat[$r])) {
                            // Liste boşsa satır bir kez, boş değerlerle kalır (formül aralıkları bozulmasın)
                            $repeat[$r] = ['list' => $prefix, 'count' => max(1, count($lists[$prefix]))];
                        }
                    }
                }
            }
        }

        // 2) Eski satır no → yeni satır no
        $shiftAbove = static function (int $r) use ($repeat): int {
            $shift = 0;
            foreach ($repeat as $rr => $info) {
                if ($rr < $r) {
                    $shift += $info['count'] - 1;
                }
            }
            return $shift;
        };
        $first = [];
        foreach ($repeat as $rr => $info) {
            $first[$rr] = $rr + $shiftAbove($rr);
        }
        /**
         * @param ?int $ctxRow  formülün bulunduğu tekrar satırı (kendi satırına referans → aynı kopya)
         */
        $map = static function (int $r, bool $isEnd, ?int $ctxRow = null, int $ctxIndex = 0) use ($repeat, $first, $shiftAbove): int {
            if (isset($repeat[$r])) {
                if ($ctxRow === $r) {
                    return $first[$r] + $ctxIndex;
                }
                return $isEnd ? $first[$r] + $repeat[$r]['count'] - 1 : $first[$r];
            }
            return $r + $shiftAbove($r);
        };

        // 3) Satırları yeniden kur
        $newRows = [];
        foreach ($rows as $row) {
            $r = (int) $row->getAttribute('r');
            if (!isset($repeat[$r])) {
                $this->processRow($xp, $row, $map($r, false), $values, null, static fn (int $x, bool $end) => $map($x, $end));
                $newRows[] = $row;
                continue;
            }
            $prefix = $repeat[$r]['list'];
            for ($i = 0; $i < $repeat[$r]['count']; $i++) {
                $clone = $row->cloneNode(true);
                $vals = $values;
                foreach ($lists[$prefix][$i] ?? [] as $k => $v) {
                    $vals["$prefix.$k"] = $v;
                }
                $this->processRow($xp, $clone, $first[$r] + $i, $vals, $prefix,
                    static fn (int $x, bool $end) => $map($x, $end, $r, $i));
                $newRows[] = $clone;
            }
        }
        while ($sheetData->firstChild) {
            $sheetData->removeChild($sheetData->firstChild);
        }
        foreach ($newRows as $row) {
            $sheetData->appendChild($row);
        }

        // 4) Aralık içeren diğer öğeler
        $mapRange = static fn (string $ref): string => preg_replace_callback(
            '/(\$?[A-Z]{1,3}\$?)(\d+)(?::(\$?[A-Z]{1,3}\$?)(\d+))?/',
            static fn (array $m): string => $m[1] . $map((int) $m[2], false)
                . (isset($m[3]) && $m[3] !== '' ? ':' . $m[3] . $map((int) $m[4], true) : ''),
            $ref
        );

        foreach ($xp->query('//m:mergeCells/m:mergeCell') as $mc) {
            $ref = $mc->getAttribute('ref');
            // Tamamen bir tekrar satırı içindeki birleştirme her kopyada tekrarlanır
            if (preg_match('/^([A-Z]+)(\d+):([A-Z]+)(\d+)$/', $ref, $m) && $m[2] === $m[4] && isset($repeat[(int) $m[2]])) {
                $rr = (int) $m[2];
                for ($i = $repeat[$rr]['count'] - 1; $i >= 1; $i--) {
                    $copy = $mc->cloneNode();
                    $copy->setAttribute('ref', $m[1] . ($first[$rr] + $i) . ':' . $m[3] . ($first[$rr] + $i));
                    $mc->parentNode->insertBefore($copy, $mc->nextSibling);
                }
                $mc->setAttribute('ref', $m[1] . $first[$rr] . ':' . $m[3] . $first[$rr]);
                continue;
            }
            $mc->setAttribute('ref', $mapRange($ref));
        }
        foreach ($xp->query('//m:mergeCells') as $mcs) {
            $mcs->setAttribute('count', (string) $xp->query('m:mergeCell', $mcs)->length);
        }
        foreach ($xp->query('//m:conditionalFormatting[@sqref] | //m:dataValidation[@sqref]') as $el) {
            $el->setAttribute('sqref', $mapRange($el->getAttribute('sqref')));
        }
        foreach ($xp->query('//m:hyperlinks/m:hyperlink[@ref]') as $el) {
            $el->setAttribute('ref', $mapRange($el->getAttribute('ref')));
        }
        foreach ($xp->query('/m:worksheet/m:dimension') as $dim) {
            $dim->parentNode->removeChild($dim);   // isteğe bağlı; Excel kendisi hesaplar
        }

        return $doc->saveXML();
    }

    private function processRow(DOMXPath $xp, DOMElement $row, int $newRow, array $values, ?string $listPrefix, callable $mapRow): void
    {
        $row->setAttribute('r', (string) $newRow);
        foreach ($xp->query('m:c', $row) as $c) {
            /** @var DOMElement $c */
            if (preg_match('/^([A-Z]+)/', $c->getAttribute('r'), $m)) {
                $c->setAttribute('r', $m[1] . $newRow);
            }

            $f = $xp->query('m:f', $c)->item(0);
            if ($f instanceof DOMElement) {
                $f->textContent = self::shiftFormula($f->textContent, $mapRow);
                foreach (iterator_to_array($xp->query('m:v', $c)) as $v) {
                    $c->removeChild($v);   // önbellekteki eski sonuç; Excel yeniden hesaplar
                }
                if (in_array($c->getAttribute('t'), ['str', 'b', 'e'], true)) {
                    $c->removeAttribute('t');
                }
                continue;
            }

            $text = $this->cellText($xp, $c);
            if ($text !== null && preg_match(self::PLACEHOLDER, $text)) {
                $this->fillCell($c, $text, $values, $listPrefix);
            }
        }
    }

    private function cellText(DOMXPath $xp, DOMElement $c): ?string
    {
        $type = $c->getAttribute('t');
        if ($type === 's') {
            $v = $xp->query('m:v', $c)->item(0);
            return $v ? ($this->sharedStrings[(int) $v->textContent] ?? null) : null;
        }
        if ($type === 'inlineStr') {
            $text = '';
            foreach ($xp->query('m:is//m:t[not(ancestor::m:rPh)]', $c) as $t) {
                $text .= $t->textContent;
            }
            return $text;
        }
        return null;
    }

    private function fillCell(DOMElement $c, string $text, array $values, ?string $listPrefix): void
    {
        $doc = $c->ownerDocument;
        while ($c->firstChild) {
            $c->removeChild($c->firstChild);
        }

        // Tek yer tutucu + sayısal değer → sayı hücresi (Excel'de toplanabilir, biçimlenebilir)
        if (preg_match('/^\{([a-z_]+(?:\.[a-z_]+)?)\}$/', trim($text), $m)
            && array_key_exists($m[1], $values)
            && (is_int($values[$m[1]]) || is_float($values[$m[1]]))) {
            $c->removeAttribute('t');
            $c->appendChild($doc->createElementNS(self::NS, 'v', (string) $values[$m[1]]));
            return;
        }

        $result = preg_replace_callback(self::PLACEHOLDER, static function (array $m) use ($values, $listPrefix): string {
            if (array_key_exists($m[1], $values)) {
                return (string) ($values[$m[1]] ?? '');
            }
            // Boş listede tekrar satırının alanları boş kalır; bilinmeyen anahtar olduğu gibi bırakılır
            return $listPrefix !== null && str_starts_with($m[1], "$listPrefix.") ? '' : $m[0];
        }, $text);

        $c->setAttribute('t', 'inlineStr');
        $is = $doc->createElementNS(self::NS, 'is');
        $t = $doc->createElementNS(self::NS, 't');
        $t->setAttributeNS(self::XML_NS, 'xml:space', 'preserve');
        $t->textContent = $result;
        $is->appendChild($t);
        $c->appendChild($is);
    }

    /**
     * Formüldeki aynı sayfa hücre referanslarının satırlarını eşler. Tırnak içindeki metinlere ve
     * başka sayfaya işaret eden (Sayfa2!A1) referanslara dokunmaz.
     * @param callable(int $row, bool $isRangeEnd): int $mapRow
     */
    public static function shiftFormula(string $formula, callable $mapRow): string
    {
        return self::eachRef($formula, static function (string $col, int $row, bool $isEnd, bool $aggregateArg) use ($mapRow): string {
            $start = $mapRow($row, false);
            $end = $mapRow($row, true);
            // =SUM(E12) gibi: tekrar satırına tek hücre referansı → tüm kopyalar (LibreOffice
            // tek hücrelik aralığı E12:E12 yerine E12 diye kaydeder)
            if ($aggregateArg && $start !== $end) {
                return $col . $start . ':' . $col . $end;
            }
            return $col . ($isEnd ? $end : $start);
        });
    }

    /** Ortak (shared) formül kopyalarını, ana formülden göreli kaydırarak açık formüle çevirir. */
    private function expandSharedFormulas(DOMXPath $xp, DOMElement $sheetData): void
    {
        $masters = [];
        foreach ($xp->query('m:row/m:c/m:f[@t="shared"][@ref]', $sheetData) as $f) {
            [$col, $row] = self::splitRef($f->parentNode->getAttribute('r'));
            $masters[$f->getAttribute('si')] = [$f->textContent, $row, $col];
        }
        foreach (iterator_to_array($xp->query('m:row/m:c/m:f[@t="shared"]', $sheetData)) as $f) {
            $si = $f->getAttribute('si');
            if (!isset($masters[$si])) {
                continue;
            }
            [$text, $mRow, $mCol] = $masters[$si];
            [$col, $row] = self::splitRef($f->parentNode->getAttribute('r'));
            $dRow = $row - $mRow;
            $dCol = $col - $mCol;
            $f->textContent = self::eachRef($text, static function (string $c, int $r) use ($dRow, $dCol): string {
                $absCol = str_starts_with($c, '$');
                $absRow = str_ends_with($c, '$');
                $letters = trim($c, '$');
                $newCol = $absCol ? $letters : self::colName(self::colIndex($letters) + $dCol);
                return ($absCol ? '$' : '') . $newCol . ($absRow ? '$' : '') . ($absRow ? $r : $r + $dRow);
            });
            foreach (['t', 'si', 'ref'] as $attr) {
                $f->removeAttribute($attr);
            }
        }
    }

    /**
     * Formüldeki her hücre referansı için geri çağırır (aralık sonları $isEnd = true).
     * $aggregateArg: tek hücre referansı, bir toplama fonksiyonunun (SUM vb.) argümanının tamamı.
     * @param callable(string $colWithDollars, int $row, bool $isEnd, bool $aggregateArg): string $fn
     */
    private static function eachRef(string $formula, callable $fn): string
    {
        $parts = preg_split('/("(?:[^"]|"")*")/', $formula, -1, PREG_SPLIT_DELIM_CAPTURE);
        $before = '';   // fonksiyon bağlamı için o ana kadarki formül (tırnak içleri dahil)
        foreach ($parts as $i => $part) {
            if ($i % 2 === 1) {
                $before .= $part;
                continue;   // tırnak içi metin
            }
            $prefix = $before;
            $parts[$i] = preg_replace_callback(
                '/(?<![A-Za-z0-9_.!\'$])(\$?[A-Z]{1,3}\$?)(\d+)(?::(\$?[A-Z]{1,3}\$?)(\d+))?(?![A-Za-z0-9_(!])/',
                static function (array $m) use ($fn, $part, $prefix): string {
                    $offset = $m[0][1];
                    $isRange = isset($m[3]) && $m[3][0] !== '';
                    $aggregateArg = false;
                    if (!$isRange) {
                        $prev = substr(rtrim($prefix . substr($part, 0, $offset)), -1);
                        $next = substr(ltrim(substr($part, $offset + strlen($m[0][0]))), 0, 1);
                        $aggregateArg = in_array($prev, ['(', ',', ';'], true)
                            && in_array($next, [')', ',', ';'], true)
                            && in_array(self::enclosingFunction($prefix . substr($part, 0, $offset)), self::AGGREGATES, true);
                    }
                    $out = $fn($m[1][0], (int) $m[2][0], false, $aggregateArg);
                    if ($isRange) {
                        $out .= ':' . $fn($m[3][0], (int) $m[4][0], true, false);
                    }
                    return $out;
                },
                $part,
                -1,
                $count,
                PREG_OFFSET_CAPTURE
            );
            $before .= $part;
        }
        return implode('', $parts);
    }

    /** Konumu çevreleyen en içteki fonksiyonun adı (büyük harf), yoksa ''. "_xlfn." öneki atılır. */
    private static function enclosingFunction(string $textBefore): string
    {
        $depth = 0;
        for ($i = strlen($textBefore) - 1; $i >= 0; $i--) {
            $ch = $textBefore[$i];
            if ($ch === ')') {
                $depth++;
            } elseif ($ch === '(') {
                if ($depth === 0) {
                    preg_match('/([A-Za-z_][A-Za-z0-9_.]*)\s*$/', substr($textBefore, 0, $i), $m);
                    return strtoupper(preg_replace('/^_xlfn\./i', '', $m[1] ?? ''));
                }
                $depth--;
            }
        }
        return '';
    }

    /** "C12" → [3, 12] */
    private static function splitRef(string $ref): array
    {
        preg_match('/^([A-Z]+)(\d+)$/', $ref, $m);
        return [self::colIndex($m[1]), (int) $m[2]];
    }

    private static function colIndex(string $letters): int
    {
        $n = 0;
        foreach (str_split($letters) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }
        return $n;
    }

    private static function colName(int $n): string
    {
        $s = '';
        while ($n > 0) {
            $m = ($n - 1) % 26;
            $s = chr(65 + $m) . $s;
            $n = intdiv($n - 1, 26);
        }
        return $s;
    }

    /** Excel'in açılışta tüm formülleri yeniden hesaplamasını sağlar. */
    private static function forceRecalc(string $workbook): string
    {
        if (preg_match('/<calcPr\b[^>]*>/', $workbook)) {
            return preg_replace_callback('/<calcPr\b([^>]*?)(\/?)>/', static function (array $m): string {
                $attrs = preg_replace('/\sfullCalcOnLoad="[^"]*"/', '', $m[1]);
                return '<calcPr' . $attrs . ' fullCalcOnLoad="1"' . $m[2] . '>';
            }, $workbook, 1);
        }
        // calcPr şemada belirli öğelerden önce gelmeli
        foreach (['<oleSize', '<customWorkbookViews', '<pivotCaches', '<smartTagPr', '<smartTagTypes',
                     '<webPublishing', '<fileRecoveryPr', '<webPublishObjects', '<extLst', '</workbook>'] as $tag) {
            $pos = strpos($workbook, $tag);
            if ($pos !== false) {
                return substr_replace($workbook, '<calcPr fullCalcOnLoad="1"/>', $pos, 0);
            }
        }
        return $workbook;
    }
}
