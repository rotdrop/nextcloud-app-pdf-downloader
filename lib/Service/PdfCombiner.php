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

use RuntimeException;
use InvalidArgumentException;

use Psr\Log\LoggerInterface as ILogger;
use OCP\IL10N;
use OCP\ITempManager;

use OCA\PdfDownloader\Backend\PdfTk;

/**
 * A class which combines several PDFs into one.
 */
class PdfCombiner
{
  use \OCA\RotDrop\Toolkit\Traits\LoggerTrait;
  use \OCA\RotDrop\Toolkit\Traits\UtilTrait;

  public const OVERLAY_FONT = 'dejavusansmono';
  public const OVERLAY_FONT_SIZE = 16;
  public const OVERLAY_PAGE_WIDTH_FRACTION = 0.4;
  public const OVERLAY_TEXT_COLOR = [ 0xFF, 0x00, 0x00 ];
  public const OVERLAY_BACKGROUND_COLOR = [ 0xC8, 0xC8, 0xC8 ];

  const NAME_KEY = 'name';
  const PATH_KEY = 'path';
  const LEVEL_KEY = 'level';
  const FILES_KEY = 'files';
  const FOLDERS_KEY = 'folders';
  const META_KEY = 'meta';

  public const GROUP_FOLDERS_FIRST = 'folders-first';
  public const GROUP_FILES_FIRST = 'files-first';
  public const UNGROUPED = 'ungrouped';

  /** @var ITempManager */
  protected $tempManager;

  /**
   * @var array
   * The document-data in a tree resembling the folder structure
   */
  private $documentTree = [];

  /** @var bool */
  private $addPageLabels;

  /** @var string */
  private $overlayFont = self::OVERLAY_FONT;

  /** @var int */
  private $overlayFontSize = self::OVERLAY_FONT_SIZE;

  /** @var null|float */
  private $overlayPageWidthFraction = self::OVERLAY_PAGE_WIDTH_FRACTION;

  /** @var string */
  private $overlayTemplate;

  /** @var array */
  private $overlayTextColor = self::OVERLAY_TEXT_COLOR;

  /** @var array */
  private $overlayBackgroundColor = self::OVERLAY_BACKGROUND_COLOR;

  /** @var string */
  private $grouping = self::GROUP_FOLDERS_FIRST;

  /** @var null|array */
  private $pageLabelTemplateKeys = null;

  // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
  public function __construct(
    ITempManager $tempManager,
    ILogger $logger,
    IL10N $l,
    bool $addPageLabels = true,
    string $grouping = self::GROUP_FOLDERS_FIRST,
  ) {
    $this->tempManager = $tempManager;
    $this->logger = $logger;
    $this->l = $l;
    $this->initializeDocumentTree();
    $this->addPageLabels = $addPageLabels;
    $this->grouping = $grouping;
    $this->setOverlayTemplate(null);
  }

  /**
   * Set or get whether a pagination is added to the top of each page.
   *
   * @param bool|null $addPageLabels If non-null configure this setting, other
   * the function just returns the current state.
   *
   * @return bool The previous state of the setting.
   */
  public function addPageLabels(?bool $addPageLabels = null):bool
  {
    $oldState = $this->addPageLabels;
    if ($addPageLabels !== null) {
      $this->addPageLabels = $addPageLabels;
    }
    return $oldState;
  }

  /**
   * @param string $grouping
   *
   * @return PdfCombiner
   */
  public function setGrouping(string $grouping):PdfCombiner
  {
    $this->grouping = $grouping;

    return $this;
  }

  /** @return string */
  public function getGrouping():string
  {
    return $this->grouping;
  }

  /**
   * Return the name of the currently configured overlay font-name. The overlay font
   * is used to generated page decorations. ATM only page labels (i.e PAGE X
   * of Y) are implemented.
   *
   * @return string
   */
  public function getOverlayFont():string
  {
    return $this->overlayFont ?? self::OVERLAY_FONT;
  }

  /**
   * Configure the overlay font for page labels (in particular).
   *
   * @param string|null $overlayFont The font name, or `null` to restore the
   * default.
   *
   * @return PdfCombiner
   */
  public function setOverlayFont(?string $overlayFont):PdfCombiner
  {
    $this->overlayFont = empty($overlayFont) ? self::OVERLAY_FONT : $overlayFont;

    return $this;
  }

  /**
   * Return the name of the currently configured overlay font-size.
   *
   * @return int Font-size in [pt].
   *
   * @see getOverlayFont()
   */
  public function getOverlayFontSize():int
  {
    return $this->overlayFontSize ?? self::OVERLAY_FONT_SIZE;
  }

  /**
   * Configure the overlay font-size for page labels (in particular).
   *
   * @param null|int $overlayFontSize The font size in [pt] or `null` to
   * restore the default.
   *
   * @return PdfCombiner
   */
  public function setOverlayFontSize(?int $overlayFontSize):PdfCombiner
  {
    $this->overlayFontSize = empty($overlayFontSize) ? self::OVERLAY_FONT_SIZE : $overlayFontSize;

    return $this;
  }

  /**
   * Return the currently configured overlay text (foreground) color.
   *
   * @return array Configured RGB color array.
   */
  public function getOverlayTextColor():array
  {
    return $this->overlayTextColor ?? self::OVERLAY_TEXT_COLOR;
  }

  /**
   * Configure the overlay text (foreground) color
   *
   * @param null|string|array $overlayTextColor RGB color values as array or color string
   * "#RRGGBB". Set to null to restore the default.
   *
   * @return PdfCombiner
   */
  public function setOverlayTextColor(mixed $overlayTextColor):PdfCombiner
  {
    if (is_string($overlayTextColor)) {
      $overlayTextColor = $this->rgbaStringToArray($overlayTextColor);
      if (count($overlayTextColor) != 3) {
        throw new InvalidArgumentException($this->l->t(
          'Only RGB values without alpha channel are supported.'
        ));
      }
    }
    $this->overlayTextColor = empty($overlayTextColor) ? self::OVERLAY_TEXT_COLOR : $overlayTextColor;

    return $this;
  }

  /**
   * Return the currently configured overlay text (foreground) color.
   *
   * @return array Configured RGB color array.
   */
  public function getOverlayBackgroundColor():array
  {
    return $this->overlayBackgroundColor ?? self::OVERLAY_TEXT_COLOR;
  }

  /**
   * Configure the overlay text (foreground) color
   *
   * @param null|string|array $overlayBackgroundColor RGB color values as array or color string
   * "#RRGGBB". Set to null to restore the default.
   *
   * @return PdfCombiner
   */
  public function setOverlayBackgroundColor(mixed $overlayBackgroundColor):PdfCombiner
  {
    if (is_string($overlayBackgroundColor)) {
      $overlayBackgroundColor = $this->rgbaStringToArray($overlayBackgroundColor);
      if (count($overlayBackgroundColor) != 3) {
        throw new InvalidArgumentException($this->l->t(
          'Only RGB values without alpha channel are supported.'
        ));
      }
    }
    $this->overlayBackgroundColor = empty($overlayBackgroundColor) ? self::OVERLAY_BACKGROUND_COLOR : $overlayBackgroundColor;

    return $this;
  }

  /**
   * Return the name of the currently configured overlay font-size.
   *
   * @return int Font-size in [pt].
   *
   * @see getOverlayFont()
   */
  public function getOverlayPageWidthFraction():float
  {
    return $this->overlayPageWidthFraction;
  }

  /**
   * Configure the overlay font-size for page labels (in particular).
   *
   * @param null|float $overlayPageWidthFraction The page-width fraction of
   * the overlay-label or null to request a fixed font size independent from
   * the page-width.
   *
   * @return PdfCombiner
   */
  public function setOverlayPageWidthFraction(?float $overlayPageWidthFraction):PdfCombiner
  {
    $this->overlayPageWidthFraction = $overlayPageWidthFraction;

    return $this;
  }

  /**
   * Return the name of the currently configured overlay template-name. The overlay template
   * is used to generated page decorations. ATM only page labels (i.e PAGE X
   * of Y) are implemented.
   *
   * @return string
   */
  public function getOverlayTemplate():string
  {
    return $this->overlayTemplate;
  }

  /**
   * Configure the overlay template for page labels (in particular).
   *
   * @param string|null $overlayTemplate The template name, or `null` to restore the
   * default.
   *
   * @return PdfCombiner
   */
  public function setOverlayTemplate(?string $overlayTemplate):PdfCombiner
  {
    if (empty($overlayTemplate)) {
      $overlayTemplate = '{' . $this->l->t('BASENAME') . '}'
        . ' {0|' . $this->l->t('PAGE_NUMBER') . '}'
        . '/{' . $this->l->t('TOTAL_PAGES') . '}';
    }
    $this->overlayTemplate = $overlayTemplate;

    return $this;
  }

  /** @return PdfGenerator */
  private function initializePdfGenerator():PdfGenerator
  {
    $pdf = new PdfGenerator;
    $pdf->setPageUnit('pt');
    $pdf->setFont($this->getOverlayFont());
    $margin = 0; // $this->getOverlayFontSize();
    $pdf->setMargins($margin, $margin, $margin, $margin);
    $pdf->setAutoPageBreak(false);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    return $pdf;
  }

  /**
   * Generate the page label from its template, file-name and page numbers known.
   *
   * The general syntax of a replacement is {[C[N]|]KEY} where
   * where anything in square brackets is optional.
   *
   * - 'C' is any character used for optional padding to the left.
   * - 'N' is th1e padding length. If ommitted, the value of 1 is assumed with
   *   the exception when KEY is "PAGE_NUMBER" where N default to the
   *   strlen($pageMax) if omitted
   * - 'KEY' is the replacement key which can be one of the keys used in the
   *   PHP function pathinfo() and in addition to this PAGE_NUMBER of the
   *   curren page number and TOTAL_PAGES for the total number of pages in the
   *   PDF converted from $path.
   *
   * @param string $path Path of the original file.
   *
   * @param int $pageNumber Current page-number.
   *
   * @param int $pageMax Maximum page-number.
   *
   * @return string
   */
  public function makePageLabelFromTemplate(
    string $path,
    int $pageNumber,
    int $pageMax,
  ):string {
    if (empty($this->pageLabelTemplateKeys)) {
      $this->pageLabelTemplateKeys = [
        'BASENAME' => $this->l->t('BASENAME'),
        'FILENAME' => $this->l->t('FILENAME'),
        'EXTENSION' => $this->l->t('EXTENSION'),
        'DIRNAME' => $this->l->t('DIRNAME'),
        'PAGE_NUMBER' => $this->l->t('PAGE_NUMBER'),
        'TOTAL_PAGES' => $this->l->t('TOTAL_PAGES'),
      ];
    }
    $pathInfo = pathinfo($path);
    $templateValues = [
      'BASENAME' => $pathInfo['basename'],
      'FILENAME' => $pathInfo['filename'],
      'DIRNAME' => $pathInfo['dirname'],
      'EXTENSION' => $pathInfo['extension'] ?? null,
      'PAGE_NUMBER' => [
        'value' => $pageNumber,
        'padding' => 'TOTAL_PAGES',
      ],
      'TOTAL_PAGES' => $pageMax,
    ];
    return $this->replaceBracedPlaceholders($this->getOverlayTemplate(), $templateValues, $this->pageLabelTemplateKeys);
  }

  /**
   * @param array $fileNode
   *
   * @param int $startingPage
   *
   * @param int $pageMax
   *
   * @return string PDF data
   */
  private function makePageLabel(array $fileNode, int $startingPage, int $pageMax):string
  {
    $path = $fileNode[self::PATH_KEY];

    $pdf = $this->initializePdfGenerator();

    $numberOfPages = $fileNode[self::META_KEY]['NumberOfPages'];
    $pageMedia = $fileNode[self::META_KEY]['PageMedia'];
    // phpcs:ignore PSR2.ControlStructures.ControlStructureSpacing.SpacingAfterOpenBrace
    for (
      // phpcs:ignore Squiz.ControlStructures.ForLoopDeclaration.SpacingAfterFirst
      $pageNumber = $startingPage, $mediaNumber = 0;
      // phpcs:ignore Squiz.ControlStructures.ForLoopDeclaration.SpacingAfterSecond
      $pageNumber < $startingPage + $numberOfPages;
      ++$pageNumber, ++$mediaNumber
    ) {
      list($pageWidth, $pageHeight) = explode(' ', $pageMedia[$mediaNumber]['Dimensions']);

      // page media dimensions may be formatted with thousands separators ... hopefully in LANG=C
      $pageWidth = (float)str_replace(',', '', $pageWidth);
      $pageHeight = (float)str_replace(',', '', $pageHeight);

      switch ($pageMedia[$mediaNumber]['Rotation'] ?? '') {
        case '90':
        case '270':
          $tmp = $pageWidth;
          $pageWidth = $pageHeight;
          $pageHeight = $tmp;
          break;
        default:
          break;
      }

      $orientation = $pageHeight > $pageWidth ? 'P' : 'L';

      $text = $this->makePageLabelFromTemplate($path, $pageNumber, $pageMax);

      $fontSize = $this->getOverlayFontSize();
      $pdf->setFontSize($fontSize);
      $stringWidth = $pdf->GetStringWidth($text);

      $pageFraction = $this->getOverlayPageWidthFraction();
      if (!empty($pageFraction)) {
        $currentPageFraction = $stringWidth / $pageWidth;
        $fontSize = $pageFraction / $currentPageFraction * $fontSize;
        $pdf->setFontSize($fontSize);
        $stringWidth = $pageFraction * $pageWidth;
      }
      $padding = 0.25 * $fontSize;
      $pdf->setCellPaddings($padding, $padding, $padding, $padding);

      $pdf->startPage($orientation, [ $pageWidth, $pageHeight ]);

      $cellWidth = $stringWidth + 2.0 * $padding;
      $pdf->SetAlpha(1, 'Normal', 0.2);
      $pdf->Rect($pageWidth - $cellWidth, 0, $cellWidth, 1.5 * $fontSize, style: 'F', fill_color: $this->overlayBackgroundColor);

      $pdf->setXY($pageWidth - $cellWidth, 0.25 * $fontSize);
      $pdf->SetAlpha(1, 'Normal', 1.0);
      $pdf->setColorArray('text', $this->overlayTextColor);
      $pdf->Cell($cellWidth, 1.5 * $fontSize, $text, calign: 'A', valign: 'T', align: 'R', fill: false);
      $pdf->endPage();
    }
    return $pdf->Output($path, 'S');
  }

  /**
   * Reset the directory tree to an empty nodes array.
   *
   * @return void
   */
  private function initializeDocumentTree():void
  {
    $this->documentTree = [
      self::NAME_KEY => null,
      self::PATH_KEY => null,
      self::LEVEL_KEY => 0,
      self::FILES_KEY => [],
      self::FOLDERS_KEY => [], ];
  }

  /**
   * Build as file-system like tree structure for the added documents and add
   * bookmarks. The book-mark level needs to be adjusted later as higher
   * bookmark-level can only follow lower bookmark level. So we store the
   * level of the bookmarks in their title and make an additional fix-up run
   * afterwards.
   *
   * @param string $data The PDF file data to add.
   *
   * @param array $pathChain The exploded files-system path leading to $data.
   *
   * @param array $tree The root of the current sub-tree:
   * ```
   * [
   *   'name' => NODE_NAME,
   *   'level' => TREE_LEVEL,
   *   'files' => FILE_NODE_ARRAY,
   *   'folders' => FOLDER_NODE_ARRAY,
   * ]
   * ```.
   *
   * @return void
   */
  private function addToDocumentTree(string $data, array $pathChain, array &$tree):void
  {
    $level = $tree[self::LEVEL_KEY] + 1;
    $path = implode('/', array_filter([ $tree[self::PATH_KEY], $tree[self::NAME_KEY] ]));

    $nodeName = array_shift($pathChain);
    if (empty($pathChain)) {
      // leaf element -- always a plain file
      $fileName = $this->tempManager->getTemporaryFile();
      file_put_contents($fileName, $data);
      $pdfData = (array)(new PdfTk($fileName))->getData();
      $tree[self::FILES_KEY][$nodeName] = [
        self::NAME_KEY => $nodeName,
        self::PATH_KEY => $path,
        self::LEVEL_KEY => $level,
        'file' => $fileName,
        self::META_KEY => $pdfData,
      ];
    } else {
      if (!isset($tree[self::FOLDERS_KEY][$nodeName])) {
        $tree[self::FOLDERS_KEY][$nodeName] = [
          self::NAME_KEY => $nodeName,
          self::PATH_KEY => $path,
          self::LEVEL_KEY => $level,
          self::FILES_KEY => [],
          self::FOLDERS_KEY => [],
        ];
      }
      $this->addToDocumentTree($data, $pathChain, $tree[self::FOLDERS_KEY][$nodeName]);
    }
  }

  /**
   * Add the given file data with the given file-system path.
   *
   * @param string $data
   *
   * @param string $name
   *
   * @return void
   */
  public function addDocument(string $data, string $name):void
  {
    $name = trim(preg_replace('|//+|', '/', $name), '/');
    $pathChain = explode('/', $name);

    $this->addToDocumentTree($data, $pathChain, $this->documentTree);
  }

  /**
   * Add the file-nodes of the document-tree to the PdfTk instance. The tree
   * is traversed with folders first. Nodes of the same level or traversed in
   * alphabetical order.
   *
   * @param PdfTk $pdfTk
   *
   * @param array $tree The root of the current sub-tree:
   * ```
   * [
   *   'name' => NODE_NAME,
   *   'level' => TREE_LEVEL,
   *   'files' => FILE_NODE_ARRAY,
   *   'folders' => FOLDER_NODE_ARRAY,
   * ]
   * ```.
   *
   * @param array $bookmarks
   *
   * @return void
   */
  private function addFromDocumentTree(PdfTk $pdfTk, array $tree, array $bookmarks = []):void
  {
    $first = true;
    switch ($this->grouping) {
      case self::GROUP_FOLDERS_FIRST:
        $this->addFoldersFromDocumentTree($pdfTk, $tree, $bookmarks, $first);
        $this->addFilesFromDocumentTree($pdfTk, $tree, $bookmarks, $first);
        break;
      case self::GROUP_FILES_FIRST:
        $this->addFilesFromDocumentTree($pdfTk, $tree, $bookmarks, $first);
        $this->addFoldersFromDocumentTree($pdfTk, $tree, $bookmarks, $first);
        break;
    }
  }

  /**
   * @param PdfTk $pdfTk
   *
   * @param array $tree mutable.
   *
   * @param array $bookmarks mutable.
   *
   * @param bool $first mutable.
   *
   * @return void
   */
  private function addFoldersFromDocumentTree(
    PdfTk $pdfTk,
    array &$tree,
    array &$bookmarks,
    bool &$first,
  ):void {
    $level = $tree[self::LEVEL_KEY];

    // first walk down the directories
    usort($tree[self::FOLDERS_KEY], fn($dirA, $dirB) => strcmp($dirA['name'], $dirB['name']));
    foreach ($tree[self::FOLDERS_KEY] as $folderNode) {
      $nodeName = $folderNode['name'];
      $folderBookmarks = [
        [
          'Title' => ($level + 1). '|' . $nodeName,
          'Level' => 1,
          'PageNumber' => 1,
        ],
      ];
      if ($first) {
        $folderBookmarks = array_merge($bookmarks, $folderBookmarks);
      }
      $this->addFromDocumentTree($pdfTk, $folderNode, $folderBookmarks);
      $first = false;
    }
  }

  /**
   * @param PdfTk $pdfTk
   *
   * @param array $tree mutable.
   *
   * @param array $bookmarks mutable.
   *
   * @param bool $first mutable.
   *
   * @return void
   */
  private function addFilesFromDocumentTree(PdfTk $pdfTk, array &$tree, array &$bookmarks, bool &$first)
  {
    $level = $tree[self::LEVEL_KEY];

    // then add the files from this level
    usort($tree[self::FILES_KEY], fn($fileA, $fileB) => strcmp($fileA['name'], $fileB['name']));

    // first pass: compute the total number of pages at this level
    $numberOfFolderPages = 0;
    foreach ($tree[self::FILES_KEY] as $fileNode) {
      $pdfData = $fileNode[self::META_KEY];
      $numberOfFolderPages += $pdfData['NumberOfPages'];
    }

    $folderPageCounter = 1;
    foreach ($tree[self::FILES_KEY] as $fileNode) {
      $nodeName = $fileNode[self::NAME_KEY];
      $fileName = $fileNode['file'];
      $pdfData = $fileNode[self::META_KEY];
      $nodeBookmark = [
        'Title' => ($level + 1). '|' . $nodeName,
        'Level' => 1,
        'PageNumber' => 1,
      ];
      if ($first) {
        // only the first node gets the directory bookmarks
        $bookmarks[] = $nodeBookmark;
        $first = false;
      } else {
        $bookmarks = [ $nodeBookmark ];
      }

      // merge the file-start bookmarks with any existing bookmarks
      $pdfData['Bookmark'] = $pdfData['Bookmark'] ?? [];
      foreach ($pdfData['Bookmark'] as &$bookmark) {
        $bookmark['Title'] = ($bookmark['Level'] + $level + 1) . '|'. $bookmark['Title'];
      }
      $pdfData['Bookmark'] = array_merge($bookmarks, $pdfData['Bookmark']);
      $pdfTk2 = new PdfTk($fileName);
      $pdfTk2->updateInfo($pdfData);

      if ($this->addPageLabels) {
        $stampData = $this->makePageLabel($fileNode, $folderPageCounter, $numberOfFolderPages);
        $folderPageCounter += $pdfData['NumberOfPages'];

        $pdfTk2 = new PdfTk($pdfTk2);
        $pdfTk2->multiStamp('-');
        $command = $pdfTk2->getCommand();
        $command->setStdIn($stampData);
      }
      $pdfTk2->saveAs($fileName);

      // then add the bookmared file to the outer pdftk instance
      $pdfTk->addFile($fileName);
    }
  }

  /**
   * The work-horse. Combine all added documents and decorate them. Return the
   * resulting PDF document as a "blob".
   *
   * @return string The combined PDF data.
   */
  public function combine():string
  {
    $pdfTk = new PdfTk;
    $this->addFromDocumentTree($pdfTk, $this->documentTree);
    $result = $pdfTk->cat()->toString();

    if ($result === false) {
      throw new RuntimeException(
        $this->l->t('Combining PDFs failed')
        . $pdfTk->getCommand()->getStdErr()
      );
    }

    $pdfTk = new PdfTk('-');
    $command = $pdfTk->getCommand();
    $command->setStdIn($result);
    $pdfData = (array)$pdfTk->getData();

    foreach ($pdfData['Bookmark'] as &$bookmark) {
      list($level, $title) = explode('|', $bookmark['Title'], 2);
      $bookmark['Title'] = $title;
      $bookmark['Level'] = $level;
    }

    $pdfTk = new PdfTk('-');
    $command = $pdfTk->getCommand();
    $command->setStdIn($result);
    $result = $pdfTk->updateInfo($pdfData)->toString();

    if ($result === false) {
      throw new RuntimeException(
        $this->l->t('Combining PDFs failed')
        . $pdfTk->getCommand()->getStdErr()
      );
    }

    $this->initializeDocumentTree();

    return $result;
  }
}
