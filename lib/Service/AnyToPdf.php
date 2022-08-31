<?php
/**
 * @copyright Copyright (c) 2022 Claus-Justus Heine <himself@claus-justus-heine.de>
 * @author Claus-Justus Heine <himself@claus-justus-heine.de>
 * @license AGPL-3.0-or-later
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace OCA\PdfDownloader\Service;

use Symfony\Component\Process\Process;
use Symfony\Component\Process\ExecutableFinder;

use Psr\Log\LoggerInterface as ILogger;
use OCP\IL10N;
use OCP\Files\IMimeTypeDetector;
use OCP\ITempManager;

use OCA\PdfDownloader\Exceptions;

/**
 * A class which can convert "any" (read: some) file-data to PDF format.
 * Currently anything supported by LibreOffice via unoconv and .eml via
 * mhonarc will work.
 */
class AnyToPdf
{
  use \OCA\PdfDownloader\Traits\LoggerTrait;

  const UNIVERSAL = '[universal]';
  const FALLBACK = '[fallback]';
  const PASS_THROUGH = '[pass-through]';

  const DEFAULT_FALLBACK_CONVERTER = 'unoconv';

  /**
   * @var string Array of available converters per mime-type. These form a
   * chain. If part of the chain is again an error then the first succeeding
   * sub-converter wins.
   */
  const CONVERTERS = [
    'message/rfc822' => [ 'mhonarc', [ 'wkhtmltopdf', self::FALLBACK, ], ],
    'application/postscript' => [ 'ps2pdf', ],
    'image/tiff' => [ 'tiff2pdf' ],
    'application/pdf' => [ self::PASS_THROUGH ],
  ];

  /** @var IMimeTypeDetector */
  protected $mimeTypeDetector;

  /** @var ITempManager */
  protected $tempManager;

  /** @var IL10N */
  protected $l;

  /**
   * @var string
   * @todo Make it configurable
   * Paper size for converters which need it.
   */
  protected $paperSize = 'a4';

  /** @var ExecutableFinder */
  protected $executableFinder;

  /** @var string */
  protected $fallbackConverter;

  /** @var string */
  protected $universalConverter;

  /** @var bool */
  protected $builtinConvertersDisabled;

  /**
   * @var array
   * Cache of found executables for the current request.
   */
  protected $executables = [];

  public function __construct(
    IMimeTypeDetector $mimeTypeDetector
    , ITempManager $tempManager
    , ExecutableFinder $executableFinder
    , ILogger $logger
    , IL10N $l
  ) {
    $this->mimeTypeDetector = $mimeTypeDetector;
    $this->tempManager = $tempManager;
    $this->executableFinder = $executableFinder;
    $this->logger = $logger;
    $this->l = $l;

    $this->fallbackConverter = self::DEFAULT_FALLBACK_CONVERTER;
  }

  public function setFallbackConverter(?string $converter)
  {
    if (empty($converter)) {
      $converter = self::DEFAULT_FALLBACK_CONVERTER;
    }
    $this->fallbackConverter = $converter;
  }

  public function getFallbackConverter():string
  {
    return $this->fallbackConverter;
  }

  public function setUniversalConverter(?string $converter)
  {
    $this->universalConverter = $converter;
  }

  public function getUniversalConverter():?string
  {
    return $this->universalConverter;
  }

  public function disableBuiltinConverters(bool $state = true)
  {
    $this->builtinConvertersDisabled = $state;
  }

  public function builtinConvertersDisabled():bool
  {
    return !empty($this->builtinConvertersDisabled);
  }

  /**
   * Diagnose the state of the builtin-converter chains, i.e. try to find the
   * binaries.
   *
   * @return array
   */
  public function findConverters()
  {
    $result = [];

    if (!empty($this->universalConverter)) {
      $executable = $this->executableFinder->find($this->universalConverter);
      if (empty($executable)) {
        $executable = $this->l->t('not found');
      }
      $result[self::UNIVERSAL] = [ [ $this->universalConverter => $executable ] ];
    }
    if ($this->builtinConvertersDisabled) {
      return $result;
    }
    foreach (self::CONVERTERS as $mimeType => $converterChain) {
      $result[$mimeType] = [];
      foreach ($converterChain as $converters) {
        if (!is_array($converters)) {
          $converters = [ $converters ];
        }
        $probedConverters = [];
        foreach ($converters as $converter) {
          if ($converter == self::PASS_THROUGH) {
            $probedConverters[$converter] = $this->l->t('pass through');
            continue;
          }
          if ($converter == self::FALLBACK) {
            $converter = $this->fallbackConverter;
          }
          $executable = $this->executableFinder->find($converter);
          if (empty($executable)) {
            $executable = $this->l->t('not found');
          }
          $probedConverters[$converter] = $executable;
        }
        $result[$mimeType][] = $probedConverters;
      }
    }
    return $result;
  }

  /**
   * Try to convert the given data-block $data to PDF using any of the known
   * converters. If no converter can do the job provide an error-page with
   * information in PDF format.
   *
   * @param string $data Data-block to be converted.
   *
   * @param string|null $mimeType If null or 'application/octet-stream' the
   * cloud's mime-type detector is used to detect the mime-type.
   */
  public function convertData(string $data, ?string $mimeType = null):string
  {
    if (empty($mimeType) || $mimeType == 'application/octet-stream') {
      $mimeType = $this->mimeTypeDetector->detectString($data);
    }

    if (!empty($this->universalConverter)) {
      try {
        $data = $this->genericConvert($data, $mimeType, $this->universalConverter);
      } catch (\Throwable $t) {
        if ($this->builtinConvertersDisabled) {
          throw new \RuntimeException($this->l->t('Universal converter "%1$s" fas failed trying to convert mime-type "%2$s"', [ $this->universalConverter, $mimeType ]));
        } else {
          $this->logException($t, 'Ignoring failed universal converter ' . $this->universalConverter);
        }
      }
    }

    $converters = self::CONVERTERS[$mimeType] ?? [ self::FALLBACK ];

    foreach ($converters as $converter) {
      if (!is_array($converter)) {
        $converter = [ $converter ];
      }

      $convertedData = null;
      foreach  ($converter as $tryConverter) {
        if ($tryConverter == self::FALLBACK) {
          $tryConverter == $this->fallbackConverter;
        }
        try {
          $method = $tryConverter . 'Convert';
          if (method_exists($this, $method)) {
            $convertedData = $this->$method($data);
          } else {
            $convertedData = $this->genericConvert($data, $mimeType, $tryConverter);
          }
          break;
        } catch (\Throwable $t) {
          $this->logException($t, 'Ignoring failed converter ' . $tryConverter);
        }
      }
      if (empty($convertedData)) {
        throw new \RuntimeException($this->l->t('Converter "%1$s" has failed trying to convert mime-type "%2$s"', [ print_r($converter, true), $mimeType ]));
      }
      $data = $convertedData;
      $convertedData = null;
    }

    return $data;
  }

  protected function passthroughConvert(string $data):string
  {
    return $data;
  }

  protected function genericConvert(string $data, string $mimeType, string $converterName):string
  {
    $converter = $this->findExecutable($converterName);
    $process = new Process([
      $converter,
      '--mime-type=' . $mimeType,
    ]);
    $process->setInput($data)->run();
    return $process->getOutput();
  }

  protected function unoconvConvert(string $data):string
  {
    $converterName = 'unoconv';
    $converter = $this->findExecutable($converterName);
    $retry = false;
    do {
      $process = new Process([
        $converter,
        '-f', 'pdf',
        '--stdin', '--stdout',
        '-e', 'ExportNotes=False'
      ]);
      $process->setInput($data);
      try  {
        $process->run();
        $retry = false;
      } catch (\Throwable $t) {
        $this->logException($t);
        $this->logError('RETRY');
        $retry = true;
      }
    } while ($retry);

    return $process->getOutput();
  }

  protected function mhonarcConvert(string $data):string
  {
    $converterName = 'mhonarc';
    $converter = $this->findExecutable($converterName);
    $process = new Process([
      $converter,
      '-single',
    ]);
    $process->setInput($data)->run();
    return $process->getOutput();
  }

  protected function ps2pdfConvert(string $data):string
  {
    $converterName = 'ps2pdf';
    $converter = $this->findExecutable($converterName);
    $process = new Process([
      $converter,
      '-', '-',
    ]);
    $process->setInput($data)->run();
    return $process->getOutput();
  }

  protected function wkhtmltopdfConvert(string $data):string
  {
    $converterName = 'wkhtmltopdf';
    $converter = $this->findExecutable($converterName);
    $process = new Process([
      $converter,
      '-', '-',
    ]);
    $process->setInput($data)->run();
    return $process->getOutput();
  }

  protected function tiff2pdfConvert(string $data):string
  {
    $converterName = 'tiff2pdf';
    $converter = $this->findExecutable($converterName);
    $inputFile = $this->tempManager->getTemporaryFile();
    $outputFile = $this->tempManager->getTemporaryFile();
    file_put_contents($inputFile, $data);

    // As of mow tiff2pdf cannot write to stdout.
    $process = new Process([
      $converter,
      '-p', $this->paperSize,
      '-o', $outputFile,
      $inputFile,
    ]);
    $process->run();
    $data = file_get_contents($outputFile);

    unlink($inputFile);
    unlink($outputFile);
    return $data;
  }

  /**
   * Try to find the given executable.
   *
   * @param string $program The program to search for. This must be the
   * basename of a Un*x program.
   *
   * @return string The full path to $program.
   *
   * @throws Exceptions\EnduserNotificationException
   */
  protected function findExecutable(string $program)
  {
    if (empty($this->executables[$program])) {
      $executable = $this->executableFinder->find($program);
      if (empty($executable)) {
        $this->executables[$program] = [
          'exception' => throw new Exceptions\EnduserNotificationException($this->l->t('Please install the "%s" program on the server.', $converterName)),
          'path' => null,
        ];
      } else {
        $this->executables[$program] = [
          'exception' => null,
          'path' => $executable,
        ];
      }
    }
    if (empty($this->executables[$program]['path'])) {
      throw $this->executables[$program]['exception'];
    }
    return $this->executables[$program]['path'];
  }
}
