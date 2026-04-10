<?php
/**
 * PHPMailer Exception class.
 * Minimal Exception class — based on PHPMailer 6.x (LGPL 2.1)
 */
namespace PHPMailer\PHPMailer;

class Exception extends \Exception
{
    public function errorMessage(): string
    {
        return $this->getMessage();
    }
}
