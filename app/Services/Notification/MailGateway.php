<?php

namespace App\Services\Notification;

interface MailGateway
{
    /**
     * Sends one transactional email.
     *
     * $template is a logical name ('school_account_invite', 'password_reset');
     * $data carries the variables that template renders.
     *
     * @param  list<array{filename: string, content: string}>  $attachments  raw bytes; the gateway encodes them
     */
    public function send(string $to, string $template, array $data, array $attachments = []): NotificationResult;
}
