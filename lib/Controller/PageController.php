<?php

namespace OCA\PdfDownloader\Controller;

use OCA\PdfDownloader\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\Util;

class PageController extends Controller
{
  public function __construct(
    string $appName
    , IRequest $request
  ) {
    parent::__construct($appName, $request);
  }

  /**
   * @NoAdminRequired
   * @NoCSRFRequired
   *
   * Render default template
   */
  public function index() {
    Util::addScript($this->appName, $this->appName . '-main');

    return new TemplateResponse($this->appName, 'main');
  }
}
