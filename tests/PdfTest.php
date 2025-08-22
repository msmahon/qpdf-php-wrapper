<?php

namespace TurboPass\QpdfPhpWrapper\Tests;

use Exception;
use TurboPass\QpdfPhpWrapper\Enums\Rotation;
use TurboPass\QpdfPhpWrapper\Pdf;
use PHPUnit\Framework\TestCase;

class PdfTest extends TestCase
{
    private Pdf $pdfService;
    private string $workingDirectory = __DIR__ . '/workingDirectory';
    private string $onePagePdfPath = __DIR__ . '/workingDirectory/one_page.pdf';
    private string $twoPagePdfPath = __DIR__ . '/workingDirectory/two_pages.pdf';
    private string $threePagePdfPath = __DIR__ . '/workingDirectory/three_pages.pdf';
    private string $fourPagePdfPath = __DIR__ . '/workingDirectory/four_pages.pdf';
    private string $jpegPath = __DIR__ . '/workingDirectory/small.jpg';
    private string $stampPath = __DIR__ . '/workingDirectory/stamp.pdf';
    private string $stampCode = 'ffffffff0000ffd6d6ffa7a7ff7c7cff4f4fff5a5aff9f9ffff1f1ffc0c0ff9494ffe2e2ffc7c7ffc1c1ffeeeeffdbdbffa3a3ffb9b9fff3f3ff9797ff9d9dffaaaafffbfbfffcfcff7a7aff0d0dff9595ff6969ff3232ff2222fffdfdffd4d4ff8181ffdfdfffbbbbffceceffbebeff3838ffb6b6ffc3c3ffd5d5ff4545ff8484ff6363ff1616fff2f2ff4d4dff3b3bff7070ff7676ff4b4bff1c1cff3535ff8c8cfff5f5ff8282ffcdcdffe9e9ffb2b2ff2a2aff6e6effa1a1ffe5e5ff9b9bff2626ff4040ffb1b1ff3d3dff8d8dffe7e7ffb4b4ff4242ff2e2eff4949ffd0d0ffe1e1ff6262ff5f5fff6868ff5555ffababfffafaff7d7dffbdbdffbcbcff5858ff5656ffdedeffe8e8ffb7b7ff5353ffdcdcffd3d3ff6c6cff9c9cffccccfff6f6ffe0e0ff4747ff5d5dffd1d1ff8888ff9090ffa4a4ffd8d8ff9999ff8a8afffefeff5151ff9696ffc4c4ff6666ffd2d2ffa2a2ffc5c5ffd7d7ff5c5cff7171ffc9c9fff0f0';

    public function setUp(): void
    {
        $this->pdfService = new Pdf();
        $this->clearWorkingFiles();
        $this->createWorkingFiles();
        parent::setUp();
    }

    public function tearDown(): void
    {
        $this->clearWorkingFiles();
        parent::tearDown();
    }

    private function createWorkingFiles() : void
    {
        if (!file_exists($this->workingDirectory)) {
            mkdir($this->workingDirectory);
        }
        copy(__DIR__ . '/assets/' . basename($this->onePagePdfPath), $this->onePagePdfPath);
        copy(__DIR__ . '/assets/' . basename($this->twoPagePdfPath), $this->twoPagePdfPath);
        copy(__DIR__ . '/assets/' . basename($this->threePagePdfPath), $this->threePagePdfPath);
        copy(__DIR__ . '/assets/' . basename($this->fourPagePdfPath), $this->fourPagePdfPath);
        copy(__DIR__ . '/assets/' . basename($this->jpegPath), $this->jpegPath);
        copy(__DIR__ . '/assets/' . basename($this->stampPath), $this->stampPath);
    }

    private function clearWorkingFiles() : void
    {
        if (file_exists($this->workingDirectory)) {
            $files = glob($this->workingDirectory . DIRECTORY_SEPARATOR . '*');
            foreach ($files as $file) {
                unlink($file);
            }
            rmdir($this->workingDirectory);
        }
    }

    public function testGetQpdfVersion()
    {
        $this->assertIsInt($this->pdfService->getQpdfVersion());
    }

    public function testFileIsPdf()
    {
        $this->assertTrue($this->pdfService->fileIsPdf($this->onePagePdfPath));
    }

    public function testFileIsNotPdf()
    {
        $this->assertFalse($this->pdfService->fileIsPdf($this->jpegPath));
    }

    public function testDecrypt()
    {
        $this->assertTrue($this->pdfService->decrypt($this->onePagePdfPath));
    }

    public function testGetNumberOfPages()
    {
        $this->assertEquals(3, $this->pdfService->getNumberOfPages($this->threePagePdfPath));
    }

    public function testRotate()
    {
        $this->assertEquals([[8.5, 11], [8.5, 11]], $this->pdfService->pageSizes($this->twoPagePdfPath));
        $this->pdfService->rotate($this->twoPagePdfPath, Rotation::Right, '2');
        $this->assertEquals([[8.5, 11], [11, 8.5]], $this->pdfService->pageSizes($this->twoPagePdfPath));
    }

    public function testTrimToRange()
    {
        // Document does not contain stamp
        $this->assertStringNotContainsString($this->stampCode, file_get_contents($this->fourPagePdfPath));
        $this->pdfService->applyStamp($this->fourPagePdfPath, $this->stampPath,'1,2');
        // Document contains stamp
        $this->assertStringContainsString($this->stampCode, file_get_contents($this->fourPagePdfPath));
        // Remove stamped pages by trimming
        $this->pdfService->trimToRange($this->fourPagePdfPath, '3,4');
        $this->assertEquals(2, $this->pdfService->getNumberOfPages($this->fourPagePdfPath));
        // Document does not contain stamp
        $this->assertStringNotContainsString($this->stampCode, file_get_contents($this->fourPagePdfPath));
    }

    public function testCombineRangesFromFiles()
    {
        $pages = [
            [$this->onePagePdfPath, '1'], // 1 page
            [$this->twoPagePdfPath, '1-2'], // 2 pages
            [$this->fourPagePdfPath, '2-4'], // 3 pages
        ];
        $output = $this->workingDirectory . DIRECTORY_SEPARATOR . 'combined.pdf';
        $this->pdfService->combineRangesFromFiles($pages, $output);
        $this->assertEquals(6, $this->pdfService->getNumberOfPages($output));
    }

    public function testCopyPages()
    {
        $output = $this->workingDirectory . DIRECTORY_SEPARATOR . 'copied.pdf';
        // stamp the first page
        $this->pdfService->applyStamp($this->fourPagePdfPath, $this->stampPath, '1');

        // Stamp found on first page
        $stampedDocument = file_get_contents($this->fourPagePdfPath);
        $this->assertStringContainsString($this->stampCode, $stampedDocument);

        // Copy unstamped pages
        $this->pdfService->copyPages($this->fourPagePdfPath, $output, '2, 4');
        $unstampedPages = file_get_contents($output);

        // Stamp not found on copied pages
        $this->assertStringNotContainsString($this->stampCode, $unstampedPages);
    }

    public function testRemovePages()
    {
        // Similar comment as above. This test is weak in that it does not verify the content of the combined file.
        $this->pdfService->removePages($this->fourPagePdfPath, '2-4');
        $this->assertEquals(1, $this->pdfService->getNumberOfPages($this->fourPagePdfPath));
    }

    /**
     * @dataProvider providerTestParsePages
     */
    public function testParseRange(string $input, array $expected)
    {
        $this->assertEquals($expected, $this->pdfService->parseRange($input));
    }

    public static function providerTestParsePages() : array
    {
        return [
            ['1',            [1]],
            ['1,3,4',        [1,3,4]],
            ['1,3,4-6',      [1,3,4,5,6]],
            ['1,3,4-6,8-10', [1,3,4,5,6,8,9,10]],
            ['8-10,4-6,1,3', [1,3,4,5,6,8,9,10]],
            ['12-16',        [12,13,14,15,16]]
        ];
    }

    public function testParseRangeWithPath()
    {
        $this->assertEquals([1,2,3], $this->pdfService->parseRange('1-z', $this->threePagePdfPath));
        $this->assertEquals([1,3,4], $this->pdfService->parseRange('1,3-z', $this->fourPagePdfPath));
        $this->expectException(Exception::class);
        // You must include a file path when using a range that includes a 'z'
        $this->pdfService->parseRange('1,3-z');
    }

    public function testApplyStamp()
    {
        // Stamp contains expected string
        $this->assertStringContainsString($this->stampCode, file_get_contents($this->stampPath));

        // Original does not contain string
        $unstampedDocument = file_get_contents($this->threePagePdfPath);
        $this->assertStringNotContainsString($this->stampCode, $unstampedDocument);

        $this->pdfService->applyStamp($this->threePagePdfPath, $this->stampPath);
        $stampedDocument = file_get_contents($this->threePagePdfPath);

        // Document has stamp string and is not
        $this->assertStringContainsString($this->stampCode, $stampedDocument);
        $this->assertNotEquals($unstampedDocument, $stampedDocument);
    }

    public function testEnumUtilities()
    {
        // Int
        $this->assertEquals(Rotation::Right, Rotation::fromInt(90));
        $this->assertEquals(Rotation::Left, Rotation::fromInt(-90));
        $this->assertEquals(Rotation::Down, Rotation::fromInt(180));
        $this->assertEquals(Rotation::Up, Rotation::fromInt(-180));
        // Cardinal
        $this->assertEquals(Rotation::Right, Rotation::fromCardinal('right'));
        $this->assertEquals(Rotation::Left, Rotation::fromCardinal('left'));
        $this->assertEquals(Rotation::Down, Rotation::fromCardinal('down'));
        $this->assertEquals(Rotation::Up, Rotation::fromCardinal('up'));
    }
}
