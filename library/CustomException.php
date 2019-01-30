<?php

interface IException {
  /**
   * Protected methods inherited from Exception class
   *
   * Exception message
   * @return mixed
   */
  public function getMessage();

  /**
   * User-defined Exception code
   * @return mixed
   */
  public function getCode();

  /**
   * Source filename
   * @return mixed
   */
  public function getFile();

  /**
   * Source line
   * @return mixed
   */
  public function getLine();

  /**
   * An array of the backtrace()
   * @return mixed
   */
  public function getTrace();

  /**
   * Formated string of trace
   * @return mixed
   */
  public function getTraceAsString();

  /**
   * Overrideable methods inherited from Exception class
   * formated string for display
   * @return mixed
   */
  public function __toString();

  public function __construct($message = NULL, $code = 0);

}

abstract class CustomException extends Exception implements IException {
  protected $message = 'Unknown exception';     // Exception message
  private $string;                              // Unknown
  protected $code = 0;                          // User-defined exception code
  protected $file;                              // Source filename of exception
  protected $line;                              // Source line of exception
  private $trace;

  public function __construct($message = NULL, $code = 0) {
    if (!$message) {
      throw new $this('Unknown ' . get_class($this));
    }
    parent::__construct($message, $code);
  }

  public function __toString() {
    return get_class($this) . " '{$this->message}' in {$this->file}({$this->line})\n"
      . "{$this->getTraceAsString()}";
  }

}
