<?php
/**
 * PDF の部品（外部ライブラリなし）。kappstore/public/kapp_invoice.php から写して kv_ にした（2026-11-17）。
 * 日本語は Adobe-Japan1 の CID フォント（KozMinPro-Regular-Acro）を埋め込まずに参照する。共有レンタルサーバーで日本語PDFを出す一番軽い方法。
 * kvcart では複数ページに対応させた（kv_pdf_document にページの配列を渡す）。
 */
declare(strict_types=1);

/** UTF-8 を UTF-16BE のhex文字列にする（UniJIS-UCS2-H が要求する形）。 */
function kv_pdf_hex($text) {
    $utf16 = mb_convert_encoding((string)$text, 'UTF-16BE', 'UTF-8');
    return strtoupper(bin2hex($utf16));
}

/** Helvetica の字幅（AFM標準・1000分率）。ASCIIの送り幅を出すのに使う。 */
function kv_pdf_helvetica_widths() {
    static $w = null;
    if ($w !== null) { return $w; }
    $widths = array(
        278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,
        556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,
        1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,
        667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,
        333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,
        556,556,333,500,278,556,500,722,500,500,500,334,260,334,584,
    );
    $w = array();
    foreach ($widths as $i => $width) { $w[32 + $i] = $width; }
    return $w;
}

/** 文字列を ASCII の並びと非ASCII（日本語）の並びに切り分ける。 */
function kv_pdf_runs($text) {
    $runs = array();
    $len = mb_strlen($text, 'UTF-8');
    $buf = ''; $ascii = null;
    for ($i = 0; $i < $len; $i++) {
        $ch = mb_substr($text, $i, 1, 'UTF-8');
        $is_ascii = (strlen($ch) === 1 && ord($ch) >= 32 && ord($ch) <= 126);
        if ($ascii === null) { $ascii = $is_ascii; }
        if ($is_ascii !== $ascii) {
            $runs[] = array($ascii, $buf);
            $buf = ''; $ascii = $is_ascii;
        }
        $buf .= $ch;
    }
    if ($buf !== '') { $runs[] = array($ascii, $buf); }
    return $runs;
}

/**
 * テキスト描画。$size はpt、$x/$y はページ左下原点。
 *
 * ASCIIは Helvetica(F2)、日本語は CIDフォント(F1) で描く。CIDフォントは
 * ASCIIのグリフを持たないため、全部F1で描くと英数字が豆腐になる（実測）。
 * 送り幅を自前で積んで、runをつなげて配置する。
 */
function kv_pdf_text($x, $y, $size, $text) {
    $out = "BT\n";
    $cursor = $x;
    $widths = kv_pdf_helvetica_widths();
    foreach (kv_pdf_runs((string)$text) as $run) {
        list($is_ascii, $chunk) = $run;
        if ($chunk === '') { continue; }
        if ($is_ascii) {
            $out .= sprintf("/F2 %s Tf 1 0 0 1 %s %s Tm (%s) Tj\n",
                $size, round($cursor, 2), $y, kv_pdf_escape($chunk));
            $advance = 0;
            for ($i = 0; $i < strlen($chunk); $i++) {
                $code = ord($chunk[$i]);
                $advance += isset($widths[$code]) ? $widths[$code] : 556;
            }
            $cursor += $advance * $size / 1000;
        } else {
            $out .= sprintf("/F1 %s Tf 1 0 0 1 %s %s Tm <%s> Tj\n",
                $size, round($cursor, 2), $y, kv_pdf_hex($chunk));
            // 全角は1em。半角カナ等の例外はこの請求書では使わない。
            $cursor += mb_strlen($chunk, 'UTF-8') * $size;
        }
    }
    return $out . "ET\n";
}

/** Helvetica用。PDF文字列リテラルのエスケープ。 */
function kv_pdf_escape($text) {
    return str_replace(array('\\', '(', ')'), array('\\\\', '\\(', '\\)'), $text);
}

/** 描いたときの送り幅(pt)。右揃えに使う。kv_pdf_text と同じ計算にすること。 */
function kv_pdf_width($size, $text) {
    $widths = kv_pdf_helvetica_widths();
    $w = 0;
    foreach (kv_pdf_runs((string)$text) as $run) {
        list($is_ascii, $chunk) = $run;
        if ($is_ascii) {
            for ($i = 0; $i < strlen($chunk); $i++) {
                $code = ord($chunk[$i]);
                $w += (isset($widths[$code]) ? $widths[$code] : 556) * $size / 1000;
            }
        } else {
            $w += mb_strlen($chunk, 'UTF-8') * $size;   // 全角は1em
        }
    }
    return $w;
}

/** 右揃え。$x が右端。金額欄は桁が揃っていないと読めない。 */
function kv_pdf_text_right($x, $y, $size, $text) {
    return kv_pdf_text($x - kv_pdf_width($size, $text), $y, $size, $text);
}

function kv_pdf_line($x1, $y1, $x2, $y2, $width = 0.5) {
    return sprintf("%s w %s %s m %s %s l S\n", $width, $x1, $y1, $x2, $y2);
}

function kv_pdf_rect_fill($x, $y, $w, $h, $gray = 0.94) {
    return sprintf("%s g %s %s %s %s re f 0 g\n", $gray, $x, $y, $w, $h);
}


/** ページごとの描画命令の配列から PDF を作る（A4 縦）。 */
function kv_pdf_document(array $pages): string
{
    $n = max(1, count($pages));
    $objects = [];
    $objects[1] = "<< /Type /Catalog /Pages 2 0 R >>";
    $kids = [];
    // 3,4,5 はフォント。ページは 6 から（ページ・内容の2つずつ）
    $objects[3] = "<< /Type /Font /Subtype /Type0 /BaseFont /KozMinPro-Regular-Acro /Encoding /UniJIS-UCS2-H /DescendantFonts [4 0 R] >>";
    $objects[4] = "<< /Type /Font /Subtype /CIDFontType0 /BaseFont /KozMinPro-Regular-Acro "
                . "/CIDSystemInfo << /Registry (Adobe) /Ordering (Japan1) /Supplement 2 >> /FontDescriptor 5 0 R /DW 1000 /W [1 [250] 231 632 500] >>";
    $objects[5] = "<< /Type /FontDescriptor /FontName /KozMinPro-Regular-Acro /Flags 6 /FontBBox [-437 -340 1147 1317] /ItalicAngle 0 "
                . "/Ascent 1317 /Descent -349 /CapHeight 742 /StemV 80 >>";
    $objects[6] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>";
    $num = 7;
    foreach (array_values($pages ?: ['']) as $content) {
        $pg = $num++; $ct = $num++;
        $kids[] = "$pg 0 R";
        $objects[$pg] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595.28 841.89] /Resources << /Font << /F1 3 0 R /F2 6 0 R >> >> /Contents $ct 0 R >>";
        $objects[$ct] = "<< /Length " . strlen($content) . " >>\nstream\n" . $content . "endstream";
    }
    $objects[2] = "<< /Type /Pages /Kids [" . implode(' ', $kids) . "] /Count " . count($kids) . " >>";
    ksort($objects);
    $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
    $offsets = [];
    foreach ($objects as $i => $body) { $offsets[$i] = strlen($pdf); $pdf .= $i . " 0 obj\n" . $body . "\nendobj\n"; }
    $xref = strlen($pdf);
    $count = count($objects) + 1;
    $pdf .= "xref\n0 " . $count . "\n0000000000 65535 f \n";
    for ($i = 1; $i < $count; $i++) { $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]); }
    return $pdf . "trailer\n<< /Size " . $count . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF";
}

/** 幅 $w(pt) に収まるよう、文字列を行に分ける（全角1em・英数字は Helvetica の幅）。 */
function kv_pdf_wrap(string $text, float $size, float $w, int $maxLines = 3): array
{
    $lines = []; $cur = '';
    foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
        if ($ch === "\n") { $lines[] = $cur; $cur = ''; continue; }
        if (kv_pdf_width($size, $cur . $ch) > $w) { $lines[] = $cur; $cur = ''; }
        $cur .= $ch;
    }
    if ($cur !== '') { $lines[] = $cur; }
    if (count($lines) > $maxLines) { $lines = array_slice($lines, 0, $maxLines); $lines[$maxLines - 1] = mb_substr($lines[$maxLines - 1], 0, -1) . '…'; }
    return $lines ?: [''];
}
