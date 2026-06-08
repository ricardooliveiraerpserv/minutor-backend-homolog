<?php

namespace App\Notifications\Concerns;

/**
 * Suporte a Cc nas notifications via Central de Workflows.
 * Use $mail = $this->applyCc($mail) dentro do toMail().
 */
trait HasWorkflowCc
{
    /** @var array<int, string> */
    public array $ccEmails = [];

    public function withCc(array $cc): static
    {
        $this->ccEmails = array_values(array_filter($cc));
        return $this;
    }

    protected function applyCc($mail)
    {
        if (!empty($this->ccEmails)) {
            $mail->cc($this->ccEmails);
        }
        return $mail;
    }
}
