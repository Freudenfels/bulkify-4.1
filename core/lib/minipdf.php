<?php
// Sehr schlanker, abhaengigkeitsfreier PDF-Generator (Kernfonts Helvetica).
// Reicht fuer einseitige/mehrseitige Dokumente mit Text, Linien und Flaechen.
// Koordinaten sind "von oben" (y=0 = Seitenoberkante); intern auf PDF umgerechnet.

class MiniPDF
{
    public $w = 595.28;   // A4 Breite (pt)
    public $h = 841.89;   // A4 Hoehe (pt)
    private $pages = [];
    private $buf = '';
    private $images = [];

    public function __construct()
    {
        $this->buf = '';
    }

    public function addPage(): void
    {
        $this->pages[] = $this->buf;
        $this->buf = '';
    }

    private function py(float $topY): float
    {
        return $this->h - $topY;
    }

    private function esc(string $s): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);
    }

    private function enc(string $s): string
    {
        $s = str_replace(
            ['—', '–', '’', '‘', '“', '”', '…', "\xC2\xA0"],
            ['-', '-', "'", "'", '"', '"', '...', ' '],
            $s
        );
        if (function_exists('iconv')) {
            $r = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $s);
            if ($r !== false) {
                return $r;
            }
        }
        return @utf8_decode($s);
    }

    private function charw(int $code): int
    {
        static $w = null;
        if ($w === null) {
            $w = array_fill(0, 256, 556);
            for ($c = 65; $c <= 90; $c++) { $w[$c] = 667; }   // Grossbuchstaben
            for ($d = 48; $d <= 57; $d++) { $w[$d] = 556; }   // Ziffern
            $set = [
                ' ' => 278, '!' => 278, '"' => 355, "'" => 191, '(' => 333, ')' => 333,
                '*' => 389, ',' => 278, '-' => 333, '.' => 278, '/' => 278, ':' => 278,
                ';' => 278, '|' => 260, 'i' => 222, 'j' => 222, 'l' => 222, 't' => 278,
                'f' => 278, 'r' => 333, 'I' => 278, 'm' => 833, 'M' => 833, 'w' => 722,
                'W' => 944, '@' => 1015, '#' => 556, '%' => 889, 'å' => 556,
            ];
            foreach ($set as $ch => $v) { $w[ord($ch)] = $v; }
        }
        return $w[$code] ?? 556;
    }

    public function strwidth(string $s, float $size, bool $bold = false): float
    {
        $e = $this->enc($s);
        $tot = 0;
        $len = strlen($e);
        for ($i = 0; $i < $len; $i++) {
            $tot += $this->charw(ord($e[$i]));
        }
        if ($bold) { $tot *= 1.04; }
        return $tot / 1000 * $size;
    }

    public function fit(string $s, float $maxw, float $size, bool $bold = false): string
    {
        if ($this->strwidth($s, $size, $bold) <= $maxw) {
            return $s;
        }
        while (strlen($s) > 1 && $this->strwidth($s . '...', $size, $bold) > $maxw) {
            $s = function_exists('mb_substr') ? mb_substr($s, 0, -1, 'UTF-8') : substr($s, 0, -1);
        }
        return $s . '...';
    }

    public function wrap(string $s, float $maxw, float $size, bool $bold = false): array
    {
        $words = preg_split('/\s+/', trim($s));
        $lines = [];
        $cur = '';
        foreach ($words as $word) {
            $try = $cur === '' ? $word : $cur . ' ' . $word;
            if ($this->strwidth($try, $size, $bold) <= $maxw) {
                $cur = $try;
            } else {
                if ($cur !== '') { $lines[] = $cur; }
                $cur = $word;
            }
        }
        if ($cur !== '') { $lines[] = $cur; }
        return $lines;
    }

    private function col(array $c): string
    {
        return sprintf('%.3F %.3F %.3F', $c[0] / 255, $c[1] / 255, $c[2] / 255);
    }

    public function text(float $x, float $y, string $s, float $size = 10, bool $bold = false, array $color = [44, 44, 42]): void
    {
        $f = $bold ? '/F2' : '/F1';
        $this->buf .= sprintf(
            "%s rg BT %s %.2F Tf 1 0 0 1 %.2F %.2F Tm (%s) Tj ET\n",
            $this->col($color), $f, $size, $x, $this->py($y), $this->esc($this->enc($s))
        );
    }

    public function textRight(float $xRight, float $y, string $s, float $size = 10, bool $bold = false, array $color = [44, 44, 42]): void
    {
        $this->text($xRight - $this->strwidth($s, $size, $bold), $y, $s, $size, $bold, $color);
    }

    public function textCenter(float $xc, float $y, string $s, float $size = 10, bool $bold = false, array $color = [44, 44, 42]): void
    {
        $this->text($xc - $this->strwidth($s, $size, $bold) / 2, $y, $s, $size, $bold, $color);
    }

    public function rect(float $x, float $y, float $w, float $hh, array $color): void
    {
        $this->buf .= sprintf(
            "%s rg %.2F %.2F %.2F %.2F re f\n",
            $this->col($color), $x, $this->py($y + $hh), $w, $hh
        );
    }

    public function rectStroke(float $x, float $y, float $w, float $hh, float $lw, array $color): void
    {
        $this->buf .= sprintf(
            "%s RG %.2F w %.2F %.2F %.2F %.2F re S\n",
            $this->col($color), $lw, $x, $this->py($y + $hh), $w, $hh
        );
    }

    public function line(float $x1, float $y1, float $x2, float $y2, float $lw, array $color): void
    {
        $this->buf .= sprintf(
            "%s RG %.2F w %.2F %.2F m %.2F %.2F l S\n",
            $this->col($color), $lw, $x1, $this->py($y1), $x2, $this->py($y2)
        );
    }

    // Registriert ein JPEG-Bild (Bytes + Pixelmaße) und liefert seine ID.
    public function registerJpeg(string $data, int $w, int $h): int
    {
        $this->images[] = ['data' => $data, 'w' => $w, 'h' => $h];
        return count($this->images) - 1;
    }

    // Zeichnet ein registriertes Bild (x/topY = obere linke Ecke, w/hh in pt).
    public function drawImage(int $id, float $x, float $topY, float $w, float $hh): void
    {
        $this->buf .= sprintf(
            "q %.2F 0 0 %.2F %.2F %.2F cm /Im%d Do Q\n",
            $w, $hh, $x, $this->py($topY + $hh), $id
        );
    }

    public function output(): string
    {
        $this->pages[] = $this->buf;
        $this->buf = '';
        $n = count($this->pages);

        $objs = [];
        $objs[1] = "<< /Type /Catalog /Pages 2 0 R >>";
        $objs[3] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>";
        $objs[4] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>";

        $xobjRes = '';
        foreach ($this->images as $i => $img) {
            $on = 5 + $i;
            $objs[$on] = "<< /Type /XObject /Subtype /Image /Width {$img['w']} /Height {$img['h']} "
                . "/ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length "
                . strlen($img['data']) . " >>\nstream\n" . $img['data'] . "\nendstream";
            $xobjRes .= "/Im{$i} {$on} 0 R ";
        }
        $resources = "/Font << /F1 3 0 R /F2 4 0 R >>";
        if ($xobjRes !== '') {
            $resources .= " /XObject << " . trim($xobjRes) . " >>";
        }

        $num = 5 + count($this->images);
        $contentNums = [];
        $pageNums = [];
        foreach ($this->pages as $i => $c) {
            $contentNums[$i] = $num++;
            $pageNums[$i] = $num++;
        }

        $kids = [];
        foreach ($pageNums as $pn) { $kids[] = $pn . ' 0 R'; }
        $objs[2] = "<< /Type /Pages /Kids [" . implode(' ', $kids) . "] /Count $n >>";

        $mb = sprintf("[0 0 %.2F %.2F]", $this->w, $this->h);
        foreach ($this->pages as $i => $content) {
            $len = strlen($content);
            $objs[$contentNums[$i]] = "<< /Length $len >>\nstream\n" . $content . "endstream";
            $objs[$pageNums[$i]] = "<< /Type /Page /Parent 2 0 R /MediaBox $mb "
                . "/Contents {$contentNums[$i]} 0 R "
                . "/Resources << " . $resources . " >> >>";
        }

        ksort($objs);
        $max = max(array_keys($objs));
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        for ($k = 1; $k <= $max; $k++) {
            if (!isset($objs[$k])) { continue; }
            $offsets[$k] = strlen($pdf);
            $pdf .= "$k 0 obj\n" . $objs[$k] . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $count = $max + 1;
        $pdf .= "xref\n0 $count\n0000000000 65535 f \n";
        for ($k = 1; $k <= $max; $k++) {
            $pdf .= isset($offsets[$k])
                ? sprintf("%010d 00000 n \n", $offsets[$k])
                : "0000000000 65535 f \n";
        }
        $pdf .= "trailer\n<< /Size $count /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";
        return $pdf;
    }
}
