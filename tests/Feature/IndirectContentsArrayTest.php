<?php declare(strict_types=1);

namespace PrinsFrank\PdfParser\Tests\Feature;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use PrinsFrank\PdfParser\PdfParser;

#[CoversNothing]
class IndirectContentsArrayTest extends TestCase {
    public function testContentsReferringToAnArrayOfContentStreams(): void {
        $document = (new PdfParser())->parseString(self::pdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] /Contents 4 0 R /Resources << /Font << /F1 7 0 R >> >> >>',
            4 => '[5 0 R 6 0 R]',
            5 => "<< /Length 25 >>\nstream\nBT /F1 12 Tf 10 100 Td\nendstream",
            6 => "<< /Length 18 >>\nstream\n(Hello world) Tj ET\nendstream",
            7 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ]));

        static::assertSame('Hello world', $document->getPage(1)?->getText());
    }

    /** @param array<int, string> $objects */
    private static function pdf(array $objects): string {
        $pdf = "%PDF-1.7\n";
        $offsets = [];
        foreach ($objects as $number => $body) {
            $offsets[$number] = strlen($pdf);
            $pdf .= "$number 0 obj\n$body\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= sprintf("xref\n0 %d\n0000000000 65535 f \n", count($objects) + 1);
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf . sprintf("trailer\n<< /Size %d /Root 1 0 R >>\nstartxref\n%d\n%%%%EOF\n", count($objects) + 1, $xref);
    }
}
