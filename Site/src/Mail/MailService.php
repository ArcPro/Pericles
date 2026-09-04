<?php

declare(strict_types=1);

namespace Pericles\Mail;

final class MailService
{
    public function __construct(private string $fromAddress, private string $appUrl) {}

    public function sendPasswordReset(string $email, string $displayName, string $token): bool
    {
        $name=trim($displayName)!==''?trim($displayName):'there';
        $url=rtrim($this->appUrl,'/').'/reset-password?token='.rawurlencode($token);
        $message="Hello {$name},\n\nUse this secure single-use link to reset your Pericles password:\n{$url}\n\nIf you did not request it, ignore this email.\n";
        return mail($email,'Reset your Pericles password',$message,'From: Pericles <'.$this->fromAddress.">\r\nContent-Type: text/plain; charset=UTF-8");
    }
}
