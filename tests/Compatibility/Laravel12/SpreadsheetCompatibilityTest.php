<?php

namespace Tests\Compatibility\Laravel12;

use Dcat\EasyExcel\Excel;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SpreadsheetCompatibilityTest extends TestCase
{
    public static function spreadsheetFormats(): array
    {
        return [
            'CSV' => ['csv', [['0', '12.5', '1'], ['2', '0.25', '0']]],
            'XLSX' => ['xlsx', [[0, 12.5, true], [2, 0.25, false]]],
        ];
    }

    /** @dataProvider spreadsheetFormats */
    #[DataProvider('spreadsheetFormats')]
    public function test_string_fields_round_trip_with_default_headings(string $format, array $numericRows): void
    {
        $directory = $this->createRuntimeDirectory();
        $rows = [
            [
                'name' => "\u{4E2D}\u{6587}\u{5546}\u{54C1}",
                'note' => "\u{652F}\u{4ED8} \"confirmed\",check\nsecond line",
                'zero' => '0',
                'date' => '2026-06-18 09:30:00',
                'amount' => '00123.40',
            ],
            [
                'name' => "\u{7535}\u{5B50}\u{5361}\u{5BC6}",
                'note' => 'comma,and "quotes"',
                'zero' => '0',
                'date' => '2026-06-19',
                'amount' => '0.00',
            ],
        ];

        try {
            $file = $this->exportFile($rows, $format, $directory, 'strings', true);
            $contents = file_get_contents($file);
            $this->assertIsString($contents);

            if ($format === 'csv') {
                $this->assertStringStartsWith("\xEF\xBB\xBF", $contents);
                $this->assertStringContainsString(
                    '"' . str_replace('"', '""', $rows[0]['note']) . '"',
                    $contents
                );
                $this->assertStringContainsString('00123.40', $contents);
            } else {
                $this->assertStringStartsWith("PK\x03\x04", $contents);
                $archive = new \ZipArchive;
                $this->assertTrue($archive->open($file, \ZipArchive::RDONLY));

                try {
                    $worksheet = $archive->getFromName('xl/worksheets/sheet1.xml');
                    $this->assertIsString($worksheet);
                    $document = new \DOMDocument;
                    $this->assertTrue($document->loadXML($worksheet, LIBXML_NONET));
                    $xpath = new \DOMXPath($document);
                    $xpath->registerNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
                    $this->assertSame('inlineStr', $xpath->evaluate('string(//s:c[@r="C2"]/@t)'));
                    $this->assertSame('0', $xpath->evaluate('string(//s:c[@r="C2"]/s:is/s:t)'));
                    $this->assertSame('00123.40', $xpath->evaluate('string(//s:c[@r="E2"]/s:is/s:t)'));
                } finally {
                    $archive->close();
                }
            }

            $sheets = Excel::import($file)->toArray();
            $this->assertCount(1, $sheets);
            // easy-excel preserves physical row numbers, including the skipped heading.
            $this->assertSame([2 => $rows[0], 3 => $rows[1]], array_values($sheets)[0]);
        } finally {
            $this->removeRuntimeDirectory($directory);
        }
    }

    /** @dataProvider spreadsheetFormats */
    #[DataProvider('spreadsheetFormats')]
    public function test_headerless_generator_uses_format_specific_scalar_types(string $format, array $numericRows): void
    {
        $directory = $this->createRuntimeDirectory();
        $sourceRows = [[0, 12.5, true], [2, 0.25, false]];
        $generator = (static function () use ($sourceRows) {
            yield from $sourceRows;
        })();

        try {
            $file = $this->exportFile($generator, $format, $directory, 'scalars', false);
            $sheets = Excel::import($file)->headings(false)->toArray();
            $this->assertCount(1, $sheets);
            // CSV reads strings; XLSX reads integer, fractional and boolean cells by type.
            $this->assertSame([1 => $numericRows[0], 2 => $numericRows[1]], array_values($sheets)[0]);
        } finally {
            $this->removeRuntimeDirectory($directory);
        }
    }

    private function exportFile($rows, string $format, string $directory, string $name, bool $headings): string
    {
        $file = $directory . '/' . $name . '.' . $format;
        $exporter = Excel::export($rows)->type($format);

        if (!$headings) {
            $exporter->headings(false);
        }

        if ($format === 'xlsx') {
            $exporter->option(static function (XlsxWriter $writer) use ($directory): void {
                $writer->getOptions()->setTempFolder($directory);
            });
        }

        $this->assertTrue($exporter->store($file));
        $this->assertFileExists($file);
        $this->assertGreaterThan(0, filesize($file));

        return $file;
    }

    private function createRuntimeDirectory(): string
    {
        $directory = dirname(__DIR__, 2) . '/.runtime/laravel12/spreadsheet-compatibility/' . bin2hex(random_bytes(12));
        $this->assertTrue(mkdir($directory, 0777, true));

        return $directory;
    }

    private function removeRuntimeDirectory(string $directory): void
    {
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($entries as $entry) {
            if ($entry->isDir()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }

        rmdir($directory);
    }
}
