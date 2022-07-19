<?php
/**
 * @author Claus-Justus Heine
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

use Psr\Log\LoggerInterface as ILogger;
use OCP\IL10N;
use OCP\ITempManager;

use OCA\PdfDownloader\Util\PdfTk;

/**
 * A class which combines several PDFs into one.
 */
class PdfCombiner
{
  use \OCA\PdfDownloader\Traits\LoggerTrait;

  const OVERLAY_FONT = 'dejavusansmonoi';
  const OVERLAY_FONTSIZE = 16;

  const NAME_KEY = 'name';
  const PATH_KEY = 'path';
  const LEVEL_KEY = 'level';
  const FILES_KEY = 'files';
  const FOLDERS_KEY = 'folders';
  const META_KEY = 'meta';

  /** @var ITempManager */
  protected $tempManager;

  /** @var IL10N */
  protected $l;

  /**
   * @var array
   * The documents-data to be combined into one document.
   */
  private $documents = [];

  /**
   * @var array
   * The document-data in a tree resembling the folder structure
   */
  private $documentTree = [];

  /** @var bool */
  private $addPageLabels;

  /** @var string */
  private $overlayFont = self::OVERLAY_FONT;

  public function __construct(
    ITempManager $tempManager
    , ILogger $logger
    , IL10N $l
    , bool $addPageLabels = true
  ) {
    $this->tempManager = $tempManager;
    $this->logger = $logger;
    $this->l = $l;
    $this->initializeDocumentTree();
    $this->addPageLabels = $addPageLabels;
  }

  /**
   * Set or get whether a pagination is added to the top of each page.
   *
   * @param bool|null If non-null configure this setting, other the function
   * just returns the current state.
   *
   * @return bool The previous state of the setting.
   */
  public function addPageLabels(?bool $addPageLables = null):bool
  {
    $oldState = $this->addPageLabels;
    if ($addPageLables !== null) {
      $this->addPageLabels = $addPageLables;
    }
    return $oldState;
  }

  public function getOverlayFont():?string
  {
    return $this->overlayFont ?? self::OVERLAY_FONT;
  }

  public function setOverlayFont(?string $overlayFont)
  {
    $this->overlayFont = empty($overlayFont) ? self::OVERLAY_FONT : $overlayFont;
  }

  private function initializePdfGenerator():PdfGenerator
  {
    $pdf = new PdfGenerator;
    $pdf->setPageUnit('pt');
    $pdf->setFont($this->getOverlayFont());
    $margin = 0; // self::OVERLAY_FONTSIZE;
    $pdf->setMargins($margin, $margin, $margin, $margin);
    $pdf->setAutoPageBreak(false);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    return $pdf;
  }

  private function makePageLabel(array $fileNode, int $startingPage, int $pageMax)
  {
    $path = $fileNode[self::PATH_KEY];
    $tag = basename($path);

    $pdf = $this->initializePdfGenerator();

    $maxDigits = (int)floor(log10($pageMax)) + 1;

    $numberOfPages = $fileNode[self::META_KEY]['NumberOfPages'];
    $pageMedia = $fileNode[self::META_KEY]['PageMedia'];
    for ($pageNumber = $startingPage, $mediaNumber = 0; $pageNumber < $startingPage + $numberOfPages; ++$pageNumber, ++$mediaNumber) {
      list($pageWidth, $pageHeight) = explode(' ', $pageMedia[$mediaNumber]['Dimensions']);
      $orientation = $pageHeight > $pageWidth ? 'P' : 'L';

      $text = sprintf("%s %' " . $maxDigits . "d/%d", $tag, $pageNumber, $pageMax);

      $pdf->setFontSize(self::OVERLAY_FONTSIZE);
      $stringWidth = $pdf->GetStringWidth($text);
      $fontSize = 0.4 * $pageWidth / $stringWidth * self::OVERLAY_FONTSIZE;
      $pdf->setFontSize($fontSize);
      $padding = 0.25 * $fontSize;
      $pdf->setCellPaddings($padding, $padding, $padding, $padding);

      $pdf->startPage($orientation, [ $pageWidth, $pageHeight ]);

      $cellWidth = 0.4 * $pageWidth + 2.0 * $padding;
      $pdf->SetAlpha(1, 'Normal', 0.2);
      $pdf->Rect($pageWidth - $cellWidth, 0, $cellWidth, 1.5 * $fontSize, style: 'F', fill_color: [ 200 ]);

      $pdf->setXY($pageWidth - $cellWidth, 0.25 * $fontSize);
      $pdf->SetAlpha(1, 'Normal', 1.0);
      $pdf->setColor('text', 255, 0, 0);
      $pdf->Cell($cellWidth, 1.5 * $fontSize, $text, calign: 'A', valign: 'T', align: 'R', fill: false);
      $pdf->endPage();
    }
    return $pdf->Output($path, 'S');
  }

  /** Reset the directory tree to an empty nodes array */
  private function initializeDocumentTree()
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
   * @param string $data The PDF file data to add
   *
   * @param array $pathChain The exploded files-system path leading to $data
   *
   * @param array $tree The root of the current sub-tree:
   * ```
   * [
   *   'name' => NODE_NAME,
   *   'level' => TREE_LEVEL,
   *   'files' => FILE_NODE_ARRAY,
   *   'folders' => FOLDER_NODE_ARRAY,
   * ],
   *
   * @param array $bookmarks Bookmark array corresponding to $pathChain
   */
  private function addToDocumentTree(string $data, array $pathChain, array &$tree)
  {
    $level = $tree[self::LEVEL_KEY] + 1;
    $path = implode('/', array_filter([ $tree[self::PATH_KEY], $tree[self::NAME_KEY] ]));

    $nodeName = array_shift($pathChain);
    if (empty($pathChain)) {
      // leaf element -- always a plain file
      $fileName = $this->tempManager->getTemporaryFile();
      file_put_contents($fileName,  $data);
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

  public function addDocument(string $data, string $name)
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
   * @param array $tree The root of the current sub-tree:
   * ```
   * [
   *   'name' => NODE_NAME,
   *   'level' => TREE_LEVEL,
   *   'files' => FILE_NODE_ARRAY,
   *   'folders' => FOLDER_NODE_ARRAY,
   * ],
   */
  private function addFromDocumentTree(PdfTk $pdfTk, array $tree, array $bookmarks = [])
  {
    $level = $tree[self::LEVEL_KEY];

    // first walk down the directories
    usort($tree[self::FOLDERS_KEY], fn($a, $b) => strcmp($a['name'], $b['name']));
    $first = true;
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
    // then add the files from this level
    usort($tree[self::FILES_KEY], fn($a, $b) => strcmp($a['name'], $b['name']));

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
      $bookmarks[] = $nodeBookmark;

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

      $bookmarks = []; // only the first file gets the directory bookmarks

      // then add the bookmared file to the outer pdftk instance
      $pdfTk->addFile($fileName);
    }
  }

  public function combine():string
  {
    $pdfTk = new PdfTk;
    $this->addFromDocumentTree($pdfTk, $this->documentTree);
    $result = $pdfTk->cat()->toString();

    if ($result === false) {
      throw new \RuntimeException(
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
      throw new \RuntimeException(
        $this->l->t('Combining PDFs failed')
        . $pdfTk->getCommand()->getStdErr()
      );
    }

    $this->initializeDocumentTree();

    return $result;
  }
}
