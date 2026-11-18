<?php

namespace App\Services;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\ByteMatrix;
use BaconQrCode\Encoder\Encoder;

/**
 * Generates deterministic, offline vector QR Codes using BaconQrCode without GD/internet.
 */
class LocalQrCodeService
{
    public const DEFAULT_QUIET_ZONE = 4;
    public const DEFAULT_MODULE_SCALE = 3;

    /**
     * Encode content into a QR code ByteMatrix using Medium error correction.
     */
    public function encodeMatrix(string $content): ByteMatrix
    {
        return Encoder::encode($content, ErrorCorrectionLevel::M())->getMatrix();
    }

    /**
     * Render a crisp 1:1 SVG QR Code using merged horizontal dark module rectangles.
     * Matching width, height, and viewBox ensures DomPDF php-svg-lib scales the full matrix accurately.
     */
    public function toSvg(
        string $content,
        int $quietZone = self::DEFAULT_QUIET_ZONE,
        int $moduleScale = self::DEFAULT_MODULE_SCALE
    ): string {
        $matrix = $this->encodeMatrix($content);
        $matrixWidth = $matrix->getWidth();
        $matrixHeight = $matrix->getHeight();
        $totalModules = $matrixWidth + ($quietZone * 2);
        $canvasUnits = $totalModules * $moduleScale;

        $rects = '<rect x="0" y="0" width="' . $canvasUnits . '" height="' . $canvasUnits . '" fill="#ffffff"/>';

        for ($y = 0; $y < $matrixHeight; $y++) {
            $runStart = null;
            for ($x = 0; $x <= $matrixWidth; $x++) {
                $isDark = $x < $matrixWidth && $matrix->get($x, $y) === 1;

                if ($isDark && $runStart === null) {
                    $runStart = $x;
                } elseif (! $isDark && $runStart !== null) {
                    $rectX = ($runStart + $quietZone) * $moduleScale;
                    $rectY = ($y + $quietZone) * $moduleScale;
                    $rectW = ($x - $runStart) * $moduleScale;

                    $rects .= '<rect x="' . $rectX . '" y="' . $rectY . '" width="' . $rectW . '" height="' . $moduleScale . '" fill="#000000"/>';
                    $runStart = null;
                }
            }
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" version="1.1"'
            . ' width="' . $canvasUnits . '" height="' . $canvasUnits . '"'
            . ' viewBox="0 0 ' . $canvasUnits . ' ' . $canvasUnits . '"'
            . ' shape-rendering="crispEdges">'
            . $rects
            . '</svg>';
    }

    /**
     * Build a base64 SVG data URI compatible with DomPDF and offline HTML rendering.
     */
    public function toSvgDataUri(
        string $content,
        int $quietZone = self::DEFAULT_QUIET_ZONE,
        int $moduleScale = self::DEFAULT_MODULE_SCALE
    ): string {
        return 'data:image/svg+xml;base64,' . base64_encode(
            $this->toSvg($content, $quietZone, $moduleScale)
        );
    }
}

