<?php
/**
 * Recursive PDF Downloader App for Nextcloud
 *
 * @author Claus-Justus Heine <himself@claus-justus-heine.de>
 * @copyright 2022 Claus-Justus Heine <himself@claus-justus-heine.de>
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

use Imagick;
use Throwable;

use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception as ProcessExceptions;

use Psr\Log\LoggerInterface as ILogger;
use OCP\IL10N;
use OCP\ITempManager;
use OCP\Files\IAppData;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\Files\NotFoundException as FileNotFoundException;
use OCP\Files\IMimeTypeDetector;

use OCA\PdfDownloader\Exceptions;

/** Some font stuff support things. */
class FontService
{
  use \OCA\PdfDownloader\Toolkit\Traits\LoggerTrait;

  private const PDF_TO_SVG = 'pdf2svg';

  /** @var string */
  public const FONT_SAMPLE_FORMAT_PDF = 'pdf';

  /** @var string */
  public const FONT_SAMPLE_FORMAT_SVG = 'svg';

  /** @var string */
  public const FONT_SAMPLE_FORMAT_PNG = 'png';

  /**
   * @var int
   *
   * Resolution in PPI when generating font samples in bitmap formats.
   */
  private const FONT_SAMPLE_PIXEL_RESOLUTION = 150;

  /**
   * @var array
   *
   * Supported font-sample output formats. Additionally any format supported
   * by Imagick works also.
   *
   * @see Imagick::setImageFormat()
   */
  public const FONT_SAMPLE_FORMATS = [
    self::FONT_SAMPLE_FORMAT_PDF,
    self::FONT_SAMPLE_FORMAT_SVG,
  ];

  public const FONTS_SAMPLE_MIME_TYPES = [
    self::FONT_SAMPLE_FORMAT_PDF => 'application/pdf',
    self::FONT_SAMPLE_FORMAT_SVG => 'image/svg+xml',
    self::FONT_SAMPLE_FORMAT_PNG => 'image/png',
  ];

  /** @var ExecutableFinder */
  protected $executableFinder;

  /** @var ITempManager */
  protected $tempManager;

  /** @var IAppData */
  protected $appData;

  /** @var IMimeTypeDetector */
  protected $mimeTypeDetector;

  /**
   * @param ExecutableFinder $executableFinder
   *
   * @param ITempManager $tempManager
   *
   * @param IAppData $appData
   *
   * @param IMimeTypeDetector $mimeTypeDetector
   *
   * @param ILogger $logger
   *
   * @param IL10N $l10n
   */
  public function __construct(
    ExecutableFinder $executableFinder,
    ITempManager $tempManager,
    IAppData $appData,
    IMimeTypeDetector $mimeTypeDetector,
    ILogger $logger,
    IL10N $l10n
  ) {
    $this->executableFinder = $executableFinder;
    $this->tempManager = $tempManager;
    $this->appData = $appData;
    $this->mimeTypeDetector = $mimeTypeDetector;
    $this->logger = $logger;
    $this->l = $l10n;
  }

  /**
   * Return the array of available fonts
   *
   * @return array
   */
  public function getFonts():array
  {
    $fonts = (new PdfGenerator)->getFonts();

    return $fonts;
  }

  /**
   * @param string $sampleText Sample text to render.
   *
   * @param string $font Font family as returned by getFonts()
   *
   * @param int $fontSize Font size in pt, default to 12.
   *
   * @param string $rgbTextColor Text-color to use, default black "#000000".
   *
   * @param string $format One of PdfGenerator::FONT_SAMPLE_FORMATS.
   *
   * @param string $hash Hash of the font-file. If given invalidates existing
   * cache values on mismatch.
   *
   * @param null|array $sampleMetaData Optionally an array is filled with
   * ```
   * [
   *   'text' => SAMPLE_TEXT,
   *   'font' => FONT_FAMILY,
   *   'fontSize' => FONT_SIZE,
   *   'fileName' => CACHE_FILE_NAME,
   *   'mimeType' => MIME_TYPE_ACCORDING_TO_FORMAT,
   * ]
   * ```
   *
   * @return string The raw font-sample data.
   */
  public function generateFontSample(
    string $sampleText,
    string $font,
    int $fontSize = 12,
    string $rgbTextColor = '#000000',
    string $format = self::FONT_SAMPLE_FORMAT_SVG,
    string $hash = null,
    ?array &$sampleMetaData = null,
  ):string {
    $fontFolder = $this->getSampleFolder($font);
    $fontFileBaseName = urlencode(trim($sampleText, '.')) . '-' . $fontSize;
    if ($rgbTextColor !== '#000000') {
      $fontFileBaseName .= '-' . trim($rgbTextColor, '#');
    }

    $mimeType = self::FONTS_SAMPLE_MIME_TYPES[$format] ?? null;
    if (empty($mimeType)) {
      $mimeType = $this->mimeTypeDetector->detectPath($fontFileBaseName . '.' . $format);
    }

    $sampleMetaData = [
      'text' => $sampleText,
      'font' => $font,
      'fontSize' => $fontSize,
      'fileName' => $fontFileBaseName . '.' . $format,
      'mimeType' => $mimeType,
      'hash' => $hash,
    ];

    if (!empty($hash)) {
      $hashFileName = $fontFileBaseName . '.md5';
      try {
        $fontFileHash = $fontFolder->getFile($hashFileName);
        $cacheHash = $fontFileHash->getContent();
      } catch (FileNotFoundException $e) {
        // fall through
      }
      if (empty($cacheHash) || $cacheHash !== $hash) {
        /** @var ISimpleFile $file */
        foreach ($fontFolder->getDirectoryListing() as $file) {
          $file->delete();
        }
        $fontFolder->newFile($hashFileName, $hash);
      }
    }

    try {
      /** @var ISimpleFile $fontFileFormat */
      $fontFileFormat = $fontFolder->getFile($fontFileBaseName . '.' . $format);
      return $fontFileFormat->getContent();
    } catch (FileNotFoundException $e) {
      // fall through
    }

    /** @var ISimpleFile $fontFilePdf */
    try {
      $fontFilePdf = $fontFolder->getFile($fontFileBaseName . '.pdf');
      $pdfData = $fontFilePdf->getContent();
    } catch (FileNotFoundException $e) {
      $pdfData = $this->generateFontSamplePdf($sampleText, $font, $fontSize, $rgbTextColor);
      $fontFolder->newFile($fontFileBaseName . '.pdf', $pdfData);
    }

    $endUserException = null;
    switch ($format) {
      case self::FONT_SAMPLE_FORMAT_PDF:
        return $pdfData;
      case self::FONT_SAMPLE_FORMAT_SVG:
        try {
          try {
            $converter = $this->executableFinder->find(self::PDF_TO_SVG);
          } catch (Exceptions\EnduserNotificationException $e) {
            $endUserException = new Exceptions\EnduserNotificationException(
              $this->l->t('Font sample could not be generated: %s', $e->getMessage())
            );
            throw $endUserException;
          }
          $inputFile = $this->tempManager->getTemporaryFile();
          $outputFile = $this->tempManager->getTemporaryFile();
          file_put_contents($inputFile, $pdfData);

          $process = new Process([
            $converter,
            $inputFile,
            $outputFile,
          ]);
          $process->run();
          $data = file_get_contents($outputFile);
          if (empty($data)) {
            $endUserException = new Exceptions\EnduserNotificationException(
              $this->l->t('Font sample could not be generated with "%s".', self::PDF_TO_SVG)
            );
            throw $endUserException;
          }
          unlink($inputFile);
          unlink($outputFile);
          break;
        } catch (Throwable $t) {
          $this->logException($t, 'Unable to convert to SVG with "' . self::PDF_TO_SVG . '".');
        }
        // fallthrough
      default:
        try {
          // Just pipe through Imagick and see what happens ;)
          $imagick = new Imagick;
          $imagick->readImageBlob($pdfData);
          $imagick->setResolution(self::FONT_SAMPLE_PIXEL_RESOLUTION, self::FONT_SAMPLE_PIXEL_RESOLUTION);
          $imagick->setImageFormat($format);
          $data = $imagick->getImageBlob();
        } catch (Throwable $t) {
          $this->logException($t, 'Unable to convert to SVG with "ImageMagick".');
          $data = null;
        }
        if (empty($data)) {
          $this->logInfo('Font sample could not be generated with "ImageMagick".');
          if (empty($endUserException)) {
            $endUserException = new Exceptions\EnduserNotificationException(
              $this->l->t('Font sample could not be generated with "%s".', 'ImageMagick')
            );
          }
        }
        break;
    }

    if (!empty($endUserException)) {
      throw $endUserException;
    }

    $fontFolder->newFile($fontFileBaseName . '.' . $format, $data);
    return $data;
  }

  /**
   * @param string $sampleText Sample text to render.
   *
   * @param string $font Font family as returned by getFonts().
   *
   * @param int $fontSize Font size in pt, default to 12.
   *
   * @param string $rgbTextColor Text-color "#RRGGBB" to use, default black "#000000".
   *
   * @return string
   */
  private function generateFontSamplePdf(
    string $sampleText,
    string $font,
    int $fontSize = 12,
    string $rgbTextColor = '#000000',
  ):string {
    $pdf = new PdfGenerator;
    $pdf->setPageUnit('pt');
    $pdf->setFont($font);
    $margin = 0;
    $pdf->setMargins($margin, $margin, $margin, $margin);
    $pdf->setAutoPageBreak(false);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);

    $pdf->setFontSize($fontSize);
    $stringWidth = $pdf->GetStringWidth($sampleText);
    $padding = 0.25 * $fontSize;
    $pdf->setCellPaddings($padding, $padding, $padding, $padding);

    $pageWidth = 2.0 * $padding + $stringWidth;
    $pageHeight = 2.0 * $padding + $fontSize;

    $orientation = $pageHeight > $pageWidth ? 'P' : 'L';

    $pdf->startPage($orientation, [ $pageWidth, $pageHeight ]);
    $pdf->setXY(0, $padding);
    $pdf->SetAlpha(1, 'Normal', 1.0);

    $color = trim($rgbTextColor, '#');
    $red = hexdec(substr($color, 0, 2));
    $green = hexdec(substr($color, 2, 2));
    $blue = hexdec(substr($color, 4, 2));
    $pdf->setColor('text', $red, $green, $blue);
    // $this->logInfo('SET COLOR ' . $red . ' ' . $green . ' ' . $blue);
    $pdf->Cell($pageWidth, $pageHeight, $sampleText, calign: 'A', valign: 'T', align: 'R', fill: false);
    $pdf->endPage();

    $pdfData = $pdf->Output(str_replace(' ', '_', $sampleText) . '.pdf', 'S');

    return $pdfData;
  }

  /**
   * @param string $font
   *
   * @param int $fontSize
   *
   * @return ISimpleFolder
   */
  private function getSampleFolder(string $font):ISimpleFolder
  {
    $fontFolder = 'font-samples-' . $font;
    try {
      $folder = $this->appData->getFolder($fontFolder);
    } catch (FileNotFoundException $e) {
      $folder = $this->appData->newFolder($fontFolder);
    }
    return $folder;
  }
}
