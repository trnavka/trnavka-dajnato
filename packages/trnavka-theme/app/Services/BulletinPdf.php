<?php

namespace App\Services;

use App\Entity\Bulletin;
use App\Metabox\BulletinMetabox;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Server-side PDF export of a bulletin (dompdf). Ink-light, B&W, A4 layout in
 * the site font (Fira Sans, embedded from the theme's converted TTFs).
 */
class BulletinPdf
{
    public function stream(Bulletin $bulletin): void
    {
        $fontCache = WP_CONTENT_DIR . '/cache/dompdf';
        wp_mkdir_p($fontCache);

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('fontDir', $fontCache);
        $options->set('fontCache', $fontCache);
        $options->set('chroot', [get_template_directory(), WP_CONTENT_DIR, $fontCache]);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->html($bulletin), 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        $this->addPageNumbers($dompdf);
        $dompdf->stream('oznamy-' . ($bulletin->date ?: $bulletin->id) . '.pdf', ['Attachment' => true]);

        exit;
    }

    /**
     * Page number ("strana / spolu") in the footer, bottom-right of every page.
     * Drawn on the canvas after render so dompdf can substitute the running page
     * counters; the x is right-aligned to the content edge (matching @page margin).
     */
    private function addPageNumbers(Dompdf $dompdf): void
    {
        $canvas = $dompdf->getCanvas();
        $fontMetrics = $dompdf->getFontMetrics();
        $font = $fontMetrics->getFont('Fira Sans', 'normal') ?: $fontMetrics->getFont('DejaVu Sans', 'normal');
        $size = 8.0;
        $rightMargin = 1.7 * 72 / 2.54; // 1.7cm in points, matches the @page right margin
        $width = $fontMetrics->getTextWidth('8 / 8', $font, $size); // estimate so it stays right-aligned

        $canvas->page_text(
            $canvas->get_width() - $rightMargin - $width,
            $canvas->get_height() - 34,
            '{PAGE_NUM} / {PAGE_COUNT}',
            $font,
            $size,
            [0.4, 0.4, 0.4]
        );
    }

    private function html(Bulletin $bulletin): string
    {
        $fontDir = get_template_directory() . '/resources/fonts/pdf';
        $title = esc_html($bulletin->title);
        $dateInline = $bulletin->formattedDate()
            ? esc_html($bulletin->formattedDate()) . ' — '
            : '';

        $liturgyRows = '';
        foreach ($bulletin->liturgyRows() as $row) {
            $when = trim(($row['weekday'] ? $row['weekday'] . ', ' : '') . $row['date']);
            $liturgyRows .= '<tr>'
                . '<td class="lit-when">' . esc_html($when) . '</td>'
                . '<td class="lit-text">' . esc_html($row['text']) . '</td>'
                . '</tr>';
        }
        $liturgyBlock = $liturgyRows ? '<table class="lit">' . $liturgyRows . '</table>' : '';

        $qr = new QrCode();
        $annParts = [];
        foreach ($bulletin->items as $item) {
            $lead = empty($item['label']) ? '' : '<span class="lead">' . esc_html($item['label']) . ' — </span>';
            $text = '<p class="ann">' . $lead . wp_kses($item['text'] ?? '', BulletinMetabox::ALLOWED_HTML) . '</p>';
            $qrBlocks = $this->qrBlocks($qr, $item['links'] ?? []);

            // Lay text + QR codes as a 2-column table so the row grows to the
            // taller side — a float would let stacked QR codes overflow and clip.
            $annParts[] = $qrBlocks
                ? '<table class="ann-block"><tr><td class="ann-text">' . $text . '</td>'
                    . '<td class="qr-col">' . $qrBlocks . '</td></tr></table>'
                : $text;
        }
        $itemsBlock = $annParts ? implode('<div class="ann-sep"></div>', $annParts) : '';

        return <<<HTML
<!DOCTYPE html>
<html lang="sk">
<head>
<meta charset="utf-8">
<style>
    @font-face { font-family: "Fira Sans"; font-weight: normal; font-style: normal; src: url("{$fontDir}/FiraSans-Regular.ttf") format("truetype"); }
    @font-face { font-family: "Fira Sans"; font-weight: bold; font-style: normal; src: url("{$fontDir}/FiraSans-Bold.ttf") format("truetype"); }
    @font-face { font-family: "Fira Sans"; font-weight: normal; font-style: italic; src: url("{$fontDir}/FiraSans-Italic.ttf") format("truetype"); }
    @font-face { font-family: "Fira Sans"; font-weight: bold; font-style: italic; src: url("{$fontDir}/FiraSans-BoldItalic.ttf") format("truetype"); }

    @page { margin: 1.5cm 1.7cm 1.6cm; }
    * { font-family: "Fira Sans", "DejaVu Sans", sans-serif; }
    body { color: #000; font-size: 10.5pt; line-height: 1.4; }

    .masthead { border-bottom: 1.5px solid #000; padding-bottom: 6px; margin-bottom: 14px; font-size: 12pt; font-weight: bold; }

    table.lit { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
    table.lit td { vertical-align: top; padding: 3px 0; border-bottom: 0.5px solid #cfcfcf; font-size: 10pt; }
    table.lit .lit-when { width: 95px; font-weight: bold; white-space: nowrap; padding-right: 12px; }

    p.ann { margin: 0; text-align: justify; }
    p.ann .lead { font-weight: bold; }
    .ann-sep { width: 40px; border-top: 0.5px solid #b0b0b0; margin: 9px 0; }
    a { color: #000; text-decoration: none; }

    table.ann-block { width: 100%; border-collapse: collapse; }
    table.ann-block td { vertical-align: top; padding: 0; }
    td.ann-text { padding-right: 12px; }
    td.qr-col { width: 78px; text-align: center; }
    td.qr-col .qr { margin-bottom: 8px; }
    td.qr-col .qr:last-child { margin-bottom: 0; }
    td.qr-col img { width: 72px; height: 72px; }
    td.qr-col .qr-url { font-size: 6.5pt; color: #555; word-break: break-all; line-height: 1.2; }
</style>
</head>
<body>
    <div class="masthead">Farské oznamy, Trnávka — {$dateInline}{$title}</div>
    {$liturgyBlock}
    {$itemsBlock}
</body>
</html>
HTML;
    }

    /**
     * QR-code blocks for an announcement's links (one per link, stacked). The QR
     * is an SVG data: URI rendered locally (dompdf draws the SVG path natively,
     * and has remote loading off so it can't fetch images over HTTP anyway).
     *
     * @param array<int, array{url?:string,shortUrl?:string}> $links
     */
    private function qrBlocks(QrCode $qr, array $links): string
    {
        $blocks = '';

        foreach ($links as $link) {
            $shortUrl = (string)($link['shortUrl'] ?? '');

            if ($shortUrl === '') {
                continue;
            }

            $label = esc_html(preg_replace('#^https?://#i', '', $shortUrl));
            $blocks .= '<div class="qr"><img src="' . esc_attr($qr->dataUri($shortUrl)) . '" alt=""><div class="qr-url">' . $label . '</div></div>';
        }

        return $blocks;
    }
}
