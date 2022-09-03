<?php
/**
 * @copyright Copyright 2022 Claus-Justus Heine <himself@claus-justus-heine.de>
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

namespace OCA\PdfDownloader\Traits;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

use OCP\ILogger;

trait LoggerTrait
{
  /** @var LoggerInterface */
  protected $logger;

  /** Return the stored logger class */
  public function logger():LoggerInterface
  {
    return $this->logger;
  }

  /**
   * Map PSR log-levels to ILogger log-levels as the PsrLoggerAdapter only
   * understands those.
   */
  protected function mapLogLevels($level)
  {
    if (is_int($level) || is_numeric($level)) {
      return $level;
    }
    switch ($level) {
      case LogLevel::EMERGENCY:
        return ILogger::FATAL;
      case LogLevel::ALERT:
        return ILogger::ERROR;
      case LogLevel::CRITICAL:
        return ILogger::ERROR;
      case LogLevel::ERROR:
        return ILogger::ERROR;
      case LogLevel::WARNING:
        return ILogger::WARN;
      case LogLevel::NOTICE:
        return ILogger::INFO;
      case LogLevel::INFO:
        return ILogger::INFO;
      case LogLevel::DEBUG:
        return ILogger::DEBUG;
      default:
        return ILogger::ERROR;
    }
  }

  public function log($level, string $message, array $context = [], $shift = 0, bool $showTrace = false)
  {
    $level = $this->mapLogLevels($level);
    $trace = debug_backtrace();
    $prefix = '';
    $shift = min($shift, count($trace));

    do {
      $caller = $trace[$shift];
      $file = $caller['file']??'unknown';
      $line = $caller['line']??'unknown';
      $caller = $trace[$shift+1]??'unknown';
      $class = $caller['class']??'unknown';
      $method = $caller['function'];

      $prefix .= $file.':'.$line.': '.$class.'::'.$method.'(): ';
    } while ($showTrace && --$shift > 0);
    return $this->logger->log($level, $prefix.$message, $context);
  }

  public function logException($exception, $message = null, $shift = 0, bool $showTrace = false) {
    $trace = debug_backtrace();
    $caller = $trace[$shift];
    $file = $caller['file']??'unknown';
    $line = $caller['line']??0;
    $caller = $trace[$shift+1];
    $class = $caller['class'];
    $method = $caller['function'];

    $prefix = $file.':'.$line.': '.$class.'::'.$method.': ';

    empty($message) && ($message = "Caught an Exception");
    $this->logger->error($prefix . $message, [ 'exception' => $exception ]);
  }

  public function logError(string $message, array $context = [], $shift = 1, bool $showTrace = false) {
    return $this->log(LogLevel::ERROR, $message, $context, $shift, $showTrace);
  }

  public function logDebug(string $message, array $context = [], $shift = 1, bool $showTrace = false) {
    return $this->log(LogLevel::DEBUG, $message, $context, $shift, $showTrace);
  }

  public function logInfo(string $message, array $context = [], $shift = 1, bool $showTrace = false) {
    return $this->log(LogLevel::INFO, $message, $context, $shift, $showTrace);
  }

  public function logWarn(string $message, array $context = [], $shift = 1, bool $showTrace = false) {
    return $this->log(LogLevel::WARNING, $message, $context, $shift, $showTrace);
  }

  public function logFatal(string $message, array $context = [], $shift = 1, bool $showTrace = false) {
    return $this->log(LogLevel::EMERGENCY, $message, $context, $shift, $showTrace);
  }

}
