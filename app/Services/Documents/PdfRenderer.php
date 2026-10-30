<?php

namespace App\Services\Documents;

use Dompdf\Dompdf;
use Dompdf\Options;

/** Offline PDF rendering: no remote fetches, no PHP, embedded DejaVu and bundled Inter fonts. */
class PdfRenderer
{
    public const FONTS = ['Inter', 'DejaVu Sans', 'DejaVu Serif', 'DejaVu Sans Mono'];

    /** Bundled TTFs (SIL OFL), registered into the font cache on first use. */
    private const INTER = [
        'normal' => ['400', 'normal'], 'italic' => ['400', 'italic'], '500' => ['500', 'normal'], '600' => ['600', 'normal'], 'bold' => ['700', 'normal'],
    ];

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
        $bundled = realpath(resource_path('fonts')) ?: resource_path('fonts');
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
            'allowedProtocols' => [
                'data://' => ['rules' => []],
                // Local files are limited to the bundled font directory.
                'file://' => ['rules' => [function (string $uri) use ($bundled) {
                    $path = realpath(preg_replace('#^file://#', '', $uri));

                    return $path && str_starts_with($path, $bundled.DIRECTORY_SEPARATOR) ? [true, null] : [false, 'Only bundled fonts may be loaded.'];
                }]],
            ],
            'dpi' => 96,
        ]);
        $pdf = new Dompdf($options);
        $this->registerInter($pdf);
        $pdf->setPaper('letter');
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->render();

        return (string) $pdf->output();
    }

    private function registerInter(Dompdf $pdf): void
    {
        $metrics = $pdf->getFontMetrics();
        $have = $metrics->getFamily('inter') ?? [];
        foreach (self::INTER as $key => [$weight, $style]) {
            if (isset($have[$key]) && is_file($have[$key].'.ufm')) continue;
            $file = resource_path('fonts/inter/inter-'.$weight.($style === 'italic' ? '-italic' : '').'.ttf');
            $metrics->registerFont(['family' => 'Inter', 'weight' => $weight, 'style' => $style], 'file://'.$file);
        }
    }
}
