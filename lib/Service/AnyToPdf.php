<?php
/**
 * Recursive PDF Downloader App for Nextcloud
 *
 * @author Claus-Justus Heine <himself@claus-justus-heine.de>
 * @copyright 2022, 2023 Claus-Justus Heine <himself@claus-justus-heine.de>
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

use RuntimeException;

use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception as ProcessExceptions;

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
  use \OCA\RotDrop\Toolkit\Traits\LoggerTrait;

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
    'image/jpeg' => [ [ 'img2pdf', self::FALLBACK, ] ],
    'image/tiff' => [ 'tiff2pdf' ],
    'text/html' =>  [ [ 'wkhtmltopdf', self::FALLBACK, ], ],
    'text/markdown' => [ 'pandoc', [ 'wkhtmltopdf', self::FALLBACK, ], ],
    'application/pdf' => [ self::PASS_THROUGH ],
  ];

  /**
   * @var int
   * Unoconv sometimes failes for no good reason and succeeds on the second try ...
   */
  private const UNOCONV_RETRIES = 3;

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

  /**
   * @param IMimeTypeDetector $mimeTypeDetector
   * @param ITempManager $tempManager
   * @param ExecutableFinder $executableFinder
   * @param ILogger $logger
   * @param IL10N $l10n
   */
  public function __construct(
    IMimeTypeDetector $mimeTypeDetector,
    ITempManager $tempManager,
    ExecutableFinder $executableFinder,
    ILogger $logger,
    IL10N $l10n
  ) {
    $this->mimeTypeDetector = $mimeTypeDetector;
    $this->tempManager = $tempManager;
    $this->executableFinder = $executableFinder;
    $this->logger = $logger;
    $this->l = $l10n;

    $this->fallbackConverter = self::DEFAULT_FALLBACK_CONVERTER;
  }

  /**
   * Install a fall-back converter script.
   *
   * @param null|string $converter The full path to the converter executatble
   *                               or null in order to reinstall the default.
   *
   * @return AnyToPdf Return $this for chainging.
   */
  public function setFallbackConverter(?string $converter):AnyToPdf
  {
    if (empty($converter)) {
      $converter = self::DEFAULT_FALLBACK_CONVERTER;
    }
    $this->fallbackConverter = $converter;
    return $this;
  }

  /**
   * Return the currently installed fallback-converter.
   *
   * @return string
   */
  public function getFallbackConverter():string
  {
    return $this->fallbackConverter;
  }

  /**
   * Set an "universal" converter executable to try before all others.
   *
   * @param null|string $converter The full path to the converter executable
   * or null in order to disable it.
   *
   * @return AnyToPdf Return $this for chaining purposes.
   */
  public function setUniversalConverter(?string $converter):AnyToPdf
  {
    $this->universalConverter = $converter;
    return $this;
  }

  /**
   * Return the currently installed universal converter executable (maybe
   * null).
   *
   * @return null|string
   */
  public function getUniversalConverter():?string
  {
    return $this->universalConverter;
  }

  /**
   * Disable the builtin converters.
   *
   * @return AnyToPdf Return $this for chaining purposes.
   */
  public function disableBuiltinConverters():AnyToPdf
  {
    $this->builtinConvertersDisabled = true;
    return $this;
  }

  /**
   * Enable the builtin converters.
   *
   * @return AnyToPdf Return $this for chaining purposes.
   */
  public function enableBuiltinConverters():AnyToPdf
  {
    $this->builtinConvertersDisabled = false;
    return $this;
  }

  /**
   * Return the current state of using the builtin converters.
   *
   * @return bool The disabled state of the builtin-converters.
   */
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
  public function findConverters():array
  {
    $result = [];

    if (!empty($this->universalConverter)) {
      $executable = $this->executableFinder->find($this->universalConverter, force: true);
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
            // TRANSLATORS: This is actually just the name of the "converter"
            // TRANSLATORS: which does "nothing", i.e. just copies the input
            // TRANSLATORS: data unchanged to the output.
            $probedConverters[$converter] = $this->l->t('pass through');
            continue;
          }
          if ($converter == self::FALLBACK) {
            $converter = $this->fallbackConverter;
          }
          try {
            $executable = $this->executableFinder->find($converter, force: true);
          } catch (Exceptions\EnduserNotificationException $e) {
            $this->logException($e);
          }
          if (empty($executable)) {
            $executable = $this->l->t('not found');
          }
          $probedConverters[$converter] = $executable;
        }
        $result[$mimeType][] = $probedConverters;
      }
    }
    try {
      $executable = $this->executableFinder->find($this->fallbackConverter, force: true);
    } catch (Exceptions\EnduserNotificationException $e) {
      $this->logException($e);
    }
    if (empty($executable)) {
      $executable = $this->l->t('not found');
    }
    $result[self::FALLBACK] = [ [ $this->fallbackConverter => $executable ] ];
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
   *
   * @return string The converted data.
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
          throw new RuntimeException(
            $this->l->t('Universal converter "%1$s" has failed trying to convert MIME type "%2$s"', [
              $this->universalConverter, $mimeType,
            ]));
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
      foreach ($converter as $tryConverter) {
        if ($tryConverter == self::FALLBACK) {
          $tryConverter = $this->fallbackConverter;
        } elseif ($tryConverter == self::PASS_THROUGH) {
          $tryConverter = 'passThrough';
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
        throw new RuntimeException(
          $this->l->t('Converter "%1$s" has failed trying to convert MIME type "%2$s"', [
            print_r($converter, true), $mimeType,
          ]));
      }
      $data = $convertedData;
      $convertedData = null;
    }

    return $data;
  }

  /**
   * Do-nothing pass-through converter.
   *
   * @param string $data Original data.
   *
   * @return string Converted-to-PDF data.
   */
  protected function passThroughConvert(string $data):string
  {
    return $data;
  }

  /**
   * Generic conversion for given mime-type and converter script.
   *
   * @param string $data Original data.
   *
   * @param string $mimeType The detected mime-type of the data.
   *
   * @param string $converterName The name of the executable. Must be either
   * the full path or contained in the search-path for executables.
   *
   * @return string Converted-to-PDF data.
   */
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

  /**
   * Convert using unoconv service (based on LibreOffice).
   *
   * @param string $data Original data.
   *
   * @return string Converted-to-PDF data.
   */
  protected function unoconvConvert(string $data):string
  {
    $converterName = 'unoconv';
    $converter = $this->findExecutable($converterName);
    $retry = false;
    $count = 0;
    do {
      $process = new Process([
        $converter,
        '-f', 'pdf',
        '--stdin', '--stdout',
        '-e', 'ExportNotes=False'
      ]);
      $process->setInput($data);
      try {
        $process->run();
        $retry = false;
      } catch (ProcessExceptions\ProcessTimedOutException $timedOutException) {
        $this->logException($timedOutException, 'Unrecoverable exception');
        $retry = false;
      } catch (\Throwable $t) {
        $this->logException($t, 'Retry after exception, trial number ' . ($count + 1));
        $retry = true;
      }
    } while ($retry && $count++ < self::UNOCONV_RETRIES);

    return $process->getOutput();
  }

  /**
   * Convert using mhonarc.
   *
   * @param string $data Original data.
   *
   * @return string Converted-to-HTML data.
   */
  protected function mhonarcConvert(string $data):string
  {
    $converterName = 'mhonarc';
    $converter = $this->findExecutable($converterName);
    $attachmentFolder = $this->tempManager->getTemporaryFolder();
    $process = new Process([
      $converter,
      '-single',
      '-attachmentdir', $attachmentFolder,
    ]);
    $process->setInput($data)->run();
    $htmlData = $process->getOutput();
    $replacements = [];
    foreach (scandir($attachmentFolder) as $dirEntry) {
      if (str_starts_with($dirEntry, '.')) {
        continue;
      }
      $attachmentData = file_get_contents($attachmentFolder . '/' . $dirEntry);
      $mimeType = $this->mimeTypeDetector->detectString($attachmentData);
      $dataUri = 'data:' . $mimeType . ';base64,' . base64_encode($attachmentData);
      $replacements[$dirEntry] = $dataUri;
      // $this->logInfo('ATTACHMENT ' . $dirEntry . ' -> ' . $dataUri);
      //
      // src="./jpg6CyWjpSPxE.jpg"
    }
    $htmlData = str_replace(array_keys($replacements), array_values($replacements), $htmlData);

    return $htmlData;
  }

  /**
   * Convert using ps2pdf.
   *
   * @param string $data Original data.
   *
   * @return string Converted-to-PDF data.
   */
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

  /**
   * Convert using wkhtmltopdf.
   *
   * @param string $data Original data.
   *
   * @return string Converted-to-PDF data.
   */
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

  /**
   * Convert to html using pandoc
   *
   * @param string $data Original data.
   *
   * @return string Converted-to-PDF data.
   */
  protected function pandocConvert(string $data):string
  {
    $converterName = 'pandoc';
    $converter = $this->findExecutable($converterName);
    $process = new Process([
      $converter,
      '-t', 'html'
    ]);
    $process->setInput($data)->run();
    return $process->getOutput();
  }

  /**
   * Convert using tiff2pdf.
   *
   * @param string $data Original data.
   *
   * @return string Converted-to-PDF data.
   */
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
   * Convert using img2pdf.
   *
   * @param string $data Original data.
   *
   * @return string Converted-to-PDF data.
   */
  protected function img2pdfConvert(string $data):string
  {
    putenv('LC_ALL=C');
    $converterName = 'img2pdf';
    $converter = $this->findExecutable($converterName);
    $process = new Process([
      $converter,
      '-', // from stdin
      '--rotation=ifvalid', // ignore broken rotation settings in EXIF meta data
    ]);
    $process->setInput($data)->run();
    return $process->getOutput();
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
  protected function findExecutable(string $program):string
  {
    return $this->executableFinder->find($program, force: false);
  }
}
