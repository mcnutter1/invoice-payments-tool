<?php

namespace App\Services\Documents;

use Dompdf\Dompdf;
use Dompdf\Options;

/** Offline PDF rendering: no remote fetches, no PHP, embedded DejaVu fonts. */
class PdfRenderer
{
    public const FONTS = ['DejaVu Sans', 'DejaVu Serif', 'DejaVu Sans Mono'];

    public function render(string $view, array $data): string
    {
        $html = view($view, $data)->render();

        return $this->renderHtml($html);
    }

    public function renderHtml(string $html): string
    {
        $fontDir = storage_path('fonts');
        if (! is_dir($fontDir)) {
            @mkdir($fontDir, 0775, true);
        }
        $options = new Options([
            'isRemoteEnabled' => false,
            'isPhpEnabled' => false,
            'isJavascriptEnabled' => false,
            'isHtml5ParserEnabled' => true,
            'defaultFont' => 'DejaVu Sans',
            'fontDir' => $fontDir,
            'fontCache' => $fontDir,
            'tempDir' => sys_get_temp_dir(),
            'chroot' => [resource_path('views')],
            'allowedProtocols' => ['data://' => ['rules' => []]],
            'dpi' => 96,
        ]);
        $pdf = new Dompdf($options);
        $pdf->setPaper('letter');
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->render();

        return (string) $pdf->output();
    }
}
