<?php

namespace App\Notifications\Channels;

use App\Models\SmsMessage;
use App\Models\User;
use App\Notifications\Alert;
use App\Sms\SmsFailed;
use App\Sms\SmsGateway;

class SmsChannel
{
    public function __construct(private readonly SmsGateway $gateway) {}

    public function send(User $notifiable, Alert $notification): void
    {
        $to = $notifiable->routeNotificationForSms($notification);
        if ($to === null) {
            return;
        }

        // A retried job finds its row and never texts the same alert twice once it went out.
        $message = SmsMessage::firstOrCreate(['notification_id' => $notification->id], [
            'user_id' => $notifiable->id,
            'kind' => $notification->kind,
            'to' => $to,
            'body' => $notification->toSms($notifiable),
            'driver' => $this->gateway->name(),
            'status' => 'pending',
        ]);
        if (in_array($message->status, ['sent', 'logged'], true)) {
            return;
        }

        $message->attempts++;
        try {
            $providerId = $this->gateway->send($message->to, $message->body);
        } catch (SmsFailed $e) {
            $message->forceFill(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500)])->save();
            if ($e->retryable) {
                throw $e;
            }

            return;
        }

        $message->forceFill([
            'status' => $this->gateway->delivers() ? 'sent' : 'logged',
            'driver' => $this->gateway->name(),
            'provider_message_id' => $providerId,
            'error' => null,
            'sent_at' => now(),
        ])->save();
    }
}
