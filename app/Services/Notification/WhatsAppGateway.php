<?php

namespace App\Services\Notification;

interface WhatsAppGateway
{
    public function sendMessage(string $phone, string $message): NotificationResult;
}
