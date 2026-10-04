<?php

declare(strict_types=1);

namespace App\Excel;

/**
 * Basit ZIP okuyucu/yazıcı (.xlsx dosyaları ZIP arşividir).
 * PHP'nin zip eklentisi gerekmesin diye yalnızca zlib ile yazıldı.
 * Desteklenen: "stored" (0) ve "deflate" (8) sıkıştırma; ZIP64 desteklenmez (4 GB altı).
 */
final class Zip
{
    /** @return array<string, string> dosya adı => içerik (arşivdeki sırayla) */
    public static function read(string $data): array
    {
        $eocd = strrpos($data, "PK\x05\x06");
        if ($eocd === false || strlen($data) < $eocd + 22) {
            throw new \RuntimeException('Geçerli bir ZIP / XLSX dosyası değil.');
        }
        $end = unpack('vdisk/vcdDisk/ventriesDisk/ventries/Vsize/Voffset', substr($data, $eocd + 4, 16));

        $files = [];
        $pos = $end['offset'];
        for ($i = 0; $i < $end['entries']; $i++) {
            if (substr($data, $pos, 4) !== "PK\x01\x02") {
                throw new \RuntimeException('ZIP merkezi dizini bozuk.');
            }
            $h = unpack(
                'vmadeBy/vneeded/vflags/vmethod/vtime/vdate/Vcrc/VcompSize/Vsize/vnameLen/vextraLen/vcommentLen/vdisk/vintAttr/VextAttr/Voffset',
                substr($data, $pos + 4, 42)
            );
            $name = substr($data, $pos + 46, $h['nameLen']);
            $pos += 46 + $h['nameLen'] + $h['extraLen'] + $h['commentLen'];

            $local = unpack('vnameLen/vextraLen', substr($data, $h['offset'] + 26, 4));
            $raw = substr($data, $h['offset'] + 30 + $local['nameLen'] + $local['extraLen'], $h['compSize']);

            $content = match ($h['method']) {
                0 => $raw,
                8 => @gzinflate($raw),
                default => throw new \RuntimeException("Desteklenmeyen ZIP sıkıştırması: {$h['method']}"),
            };
            if ($content === false || strlen($content) !== $h['size']) {
                throw new \RuntimeException("ZIP içeriği okunamadı: $name");
            }
            if (!str_ends_with($name, '/')) {
                $files[$name] = $content;
            }
        }
        return $files;
    }

    /** @param array<string, string> $files dosya adı => içerik */
    public static function write(array $files): string
    {
        [$time, $date] = self::dosTime(time());
        $out = '';
        $central = '';

        foreach ($files as $name => $content) {
            $deflated = gzdeflate($content, 6);
            $crc = crc32($content);
            $offset = strlen($out);
            $common = pack('vvvvvVVV', 20, 0x0800, 8, $time, $date, $crc, strlen($deflated), strlen($content))
                . pack('v', strlen($name));

            $out .= "PK\x03\x04" . $common . pack('v', 0) . $name . $deflated;
            $central .= "PK\x01\x02" . pack('v', 20) . $common
                . pack('vvvvVV', 0, 0, 0, 0, 0, $offset) . $name;
        }

        return $out . $central . "PK\x05\x06"
            . pack('vvvvVVv', 0, 0, count($files), count($files), strlen($central), strlen($out), 0);
    }

    /** @return array{int, int} MS-DOS saat ve tarih alanları */
    private static function dosTime(int $ts): array
    {
        $d = getdate($ts);
        return [
            ($d['hours'] << 11) | ($d['minutes'] << 5) | intdiv($d['seconds'], 2),
            (max(0, $d['year'] - 1980) << 9) | ($d['mon'] << 5) | $d['mday'],
        ];
    }
}
