<?php

namespace TurboPass\QpdfPhpWrapper;

use Exception;
use TurboPass\QpdfPhpWrapper\Enums\ExitCode;
use TurboPass\QpdfPhpWrapper\Enums\Rotation;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class Pdf
{
    protected string $executablePath;
    protected ?int $timeout = null;

    /**
     * Constructor.
     *
     * @param string $executablePath The absolute path to the qpdf executable.
     */
    public function __construct(string $executablePath = 'qpdf')
    {
        $this->executablePath = $executablePath;
    }

    public function setTimeout(?int $seconds): self
    {
        $this->timeout = $seconds;
        return $this;
    }

    /**
     * Get the version of qpdf installed on the server
     *
     * @return int
     * @throws Exception if version cannot be determined
     */
    public function getQpdfVersion(): int
    {
        $process = $this->makeProcess([$this->executablePath, '--version']);
        $process->run();
        preg_match('/qpdf version (?<version>\d+)\./', $process->getOutput(), $matches);
        if (! $this->isSuccessful($process)) {
            throw new ProcessFailedException($process);
        }
        return (int)$matches['version'];
    }

    /**
     * Validate a file is a pdf and is not corrupted
     *
     * @param string $path local file path
     * @return bool
     */
    public function fileIsPdf(string $path): bool
    {
        $process = $this->makeProcess([$this->executablePath, '--check', $path]);
        $process->run();
        return $this->isSuccessful($process);
    }

    /**
     * Decrypt a PDF file
     * 
     * @param string $path
     * @return bool
     * @throws ProcessFailedException
     */
    public function decrypt(string $path): bool
    {
        $process = $this->makeProcess([$this->executablePath, '--decrypt', $path, '--replace-input']);
        $process->run();
        if (!$this->isSuccessful($process)) {
            throw new ProcessFailedException($process);
        }
        return true;
    }

    /**
     * Get the number of pages in a pdf
     *
     * @param string $path
     * @return int
     * @throws ProcessFailedException
     */
    public function getNumberOfPages(string $path): int
    {
        $process = $this->makeProcess([$this->executablePath, '--show-npages', $path]);
        $process->run();
        if (!$this->isSuccessful($process)) {
            throw new ProcessFailedException($process);
        }
        preg_match('/\d+/', $process->getOutput(), $pages);
        return (int)$pages[0];
    }

    /**
     * Rotate a pdf
     *
     * @param string $path
     * @param Rotation $direction
     * @param string $range
     * @return bool
     * @throws ProcessFailedException
     */
    public function rotate(string $path, Rotation $direction, string $range): bool
    {
        $process = $this->makeProcess([$this->executablePath, $path, "--rotate=$direction->value:$range", '--', '--replace-input']);
        $process->run();
        if (!$this->isSuccessful($process)) {
            throw new ProcessFailedException($process);
        }
        return true;
    }

    /**
     * Remove all pages but the specified range
     *
     * @param string $path
     * @param string|int $range
     * @return bool
     * @throws ProcessFailedException
     */
    public function trimToRange(string $path, string|int $range): bool
    {
        $process = $this->makeProcess([$this->executablePath, $path, '--pages', '.', $range, '--', '--replace-input']);
        $process->run();
        if (!$this->isSuccessful($process)) {
            throw new ProcessFailedException($process);
        }
        return true;
    }

    /**
     * Combine pages from multiple pdfs into one pdf
     *
     * @param array $filePages Each file should have its own subarray
     * with the document filepath to copy from and optional page range.
     * Leaving the page range blank will copy the whole document.
     *
     * [
     *     ['/path/to/first.jpeg'],
     *     ['/path/to/second.pdf', '3-5'],
     *     ['/path/to/third']
     * ]
     *
     * @param string $outputPath
     * @return bool
     * @throws ProcessFailedException
     */
    public function combineRangesFromFiles(array $filePages, string $outputPath): bool
    {
        $process = $this->makeProcess([$this->executablePath, '--empty', '--pages', ...$this->flatten($filePages), '--', $outputPath]);
        $process->run();
        if (!$this->isSuccessful($process)) {
            throw new ProcessFailedException($process);
        }
        return true;
    }

    /**
     * Flatten a multi-dimensional array
     *
     * @param array $array
     * @return array
     */
    private function flatten(array $array): array
    {
        $return = [];
        array_walk_recursive($array, function ($a) use (&$return) {
            $return[] = $a;
        });
        return $return;
    }

    /**
     * Copy pages from one pdf to another
     *
     * @param string $path
     * @param string $outputPath
     * @param string $range
     * @return bool
     * @throws ProcessFailedException
     * @throws Exception
     */
    public function copyPages(string $path, string $outputPath, string $range): bool
    {
        $pages = $this->parseRange($range, $path);
        $process = $this->makeProcess([$this->executablePath, '--empty', '--pages', $path, join(',', $pages), '--', $outputPath]);
        $process->run();
        if (!$this->isSuccessful($process)) {
            throw new ProcessFailedException($process);
        }
        return true;
    }

    /**
     * Remove pages from a pdf
     *
     * This method works by overwriting the original file with the
     * inverse of the range you wish to remove. Opposite of trimToRange.
     *
     * @param string $path
     * @param string|int $range
     * @return bool
     * @throws Exception
     */
    public function removePages(string $path, string|int $range): bool
    {
        $pagesToCopy = implode(',', array_diff(range(1, $this->getNumberOfPages($path)), $this->parseRange($range, $path)));
        $process = $this->makeProcess([$this->executablePath, $path, '--pages', $path, $pagesToCopy, '--', '--replace-input']);
        $process->run();
        if (!$this->isSuccessful($process)) {
            throw new ProcessFailedException($process);
        }
        return true;
    }

    /**
     * Get PDF info in json format
     *
     * @param string $path
     * @return mixed
     * @throws ProcessFailedException
     */
    public function jsonInfo(string $path): mixed
    {
        $process = $this->makeProcess([$this->executablePath, $path, '--json']);
        $process->run();
        if (!$this->isSuccessful($process)) {
            throw new ProcessFailedException($process);
        }
        return json_decode($process->getOutput());
    }

    /**
     * Get page size in inches
     *
     * @param string $path
     * @return array Example: [[8.5, 11], [11, 8.5]]
     * @throws Exception
     */
    public function pageSizes(string $path): array
    {
        $pagesData = $this->getPagesData($path);
        $dimensions = $this->getPagesDimensions($pagesData);
        $orientations = $this->getPagesOrientations($pagesData);
        return array_map(function ($index, $page) use ($orientations) {
            if (isset($orientations[$index]) && $orientations[$index] % 180 !== 0) {
                return array_reverse((array)$page);
            }
            return $page;
        }, array_keys($dimensions), array_values($dimensions));
    }

    /**
     * Get page orientation in degrees
     *
     * @param mixed $pagesData
     * @return array
     */
    private function getPagesOrientations(mixed $pagesData): array
    {
        return array_map(fn($object) => $object->{'/Rotate'} ?? null, $pagesData);
    }

    /**
     * Get page dimensions in inches
     *
     * @param mixed $pagesData
     * @return array
     */
    private function getPagesDimensions(mixed $pagesData): array
    {
        return array_map(function ($object) {
            $dimensions = $object->{'/MediaBox'} ?? null;
            if ($dimensions) {
                return array_map(fn ($value) => $value / 72, array_slice($dimensions, 2));
            }
            return null;
        }, $pagesData);
    }

    /**
     * Get page data from qpdf json info
     *
     * @param string $path
     * @return array
     * @throws Exception
     */
    private function getPagesData(string $path) : array
    {
        $jsonInfo = $this->jsonInfo($path);
        $documentObjects = $this->getQpdfVersion() < 11 ? $jsonInfo->objects : $jsonInfo->qpdf[1];
        $pages = array_map(function ($page) {
            $page = $page->value ?? $page;
            if (isset($page->{'/Type'}) && $page->{'/Type'} === '/Page') {
                return $page;
            }
            return null;
        }, get_object_vars($documentObjects));
        return array_values(array_filter($pages));
    }

    /**
     * Convert the given range into a usable array of page numbers
     *
     * Example: '1,3,2,5-8,10-z' becomes [1,2,3,5,6,8,10,11,12] (assuming 12-page document)
     * You may only use 'z' with a file path
     *
     * @param string $range
     * @param string|null $path
     * @return array
     * @throws Exception
     */
    public function parseRange(string $range, string $path = null): array
    {
        $pages = explode(',', $range);
        $result = [];
        foreach ($pages as $page) {
            if (str_contains($page, '-')) {
                $range = explode('-', $page);
                if ($range[1] === 'z') { // 'z' means end of document
                    if (isset($path)) {
                        $result = array_merge($result, range($range[0], $this->getNumberOfPages($path)));
                    } else {
                        throw new Exception('Cannot use "z" without a file path');
                    }
                } else {
                    $result = array_merge($result, range($range[0], $range[1]));
                }
            } else {
                $result[] = intval($page);
            }
        }
        if (isset($path)) {
            // ignore ranges beyond the number of pages in the document
            $result = array_filter($result, fn($page) => $page <= $this->getNumberOfPages($path));
        }
        array_unique((array) sort($result));
        return $result;
    }

    /**
     * Overlay pdf on another pdf
     *
     * @param string $documentPath
     * @param string $stampPath
     * @param string|null $range
     * @return void
     * @throws Exception
     */
    public function applyStamp(string $documentPath, string $stampPath, string $range = null): void
    {
        // Overlay stamp on copy
        if (isset($range)) {
            $range = $this->parseRange($range, $documentPath);
            $range = ['--to=' . join(',', $range)];
        } else {
            $range = ['--repeat=1'];
        }
        $process = $this->makeProcess([
            $this->executablePath,
            $documentPath,
            '--overlay',
            $stampPath,
            ...$range,
            '--',
            '--replace-input'
        ]);
        $process->run();

        if (!$this->isSuccessful($process)) {
            throw new ProcessFailedException($process);
        }
    }

    private function makeProcess(array $command): Process
    {
        $process = new Process($command);

        if ($this->timeout !== null) {
            $process->setTimeout($this->timeout);
        }

        return $process;
    }

    /**
     * Many PDFs are not completely valid, but can still be processed by qpdf.
     *
     * @param Process $process
     * @return bool
     */
    private function isSuccessful(Process $process): bool
    {
        return in_array(ExitCode::tryFrom($process->getExitCode()), [ExitCode::Success, ExitCode::Warning]);
    }
}
