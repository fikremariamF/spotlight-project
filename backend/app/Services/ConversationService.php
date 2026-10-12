<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConversationService
{
    public function __construct(private readonly Alerts $alerts) {}

    /**
     * Throw a 422 unless the side may write to this conversation right now.
     * Existing threads stay readable when a store is suspended, but nobody can add to them.
     */
    public function assertWritable(Conversation $conversation): void
    {
        if (! $conversation->isOpen()) {
            throw ValidationException::withMessages(['body' => 'This conversation is closed.']);
        }

        $sellerActive = $conversation->seller()->publiclyVisible()->exists();
        if (! $sellerActive) {
            throw ValidationException::withMessages(['body' => 'This store is not accepting messages right now.']);
        }
    }

    /**
     * Append a message and move both the conversation's latest pointer and the sender's read pointer,
     * so senders never see their own messages as unread. Quote messages alert through the quote itself.
     */
    public function post(Conversation $conversation, User $sender, string $side, string $body, ?Quote $quote = null, bool $isNewInquiry = false): Message
    {
        return DB::transaction(function () use ($conversation, $sender, $side, $body, $quote, $isNewInquiry) {
            $message = new Message(['body' => $body]);
            $message->conversation_id = $conversation->id;
            $message->sender_id = $sender->id;
            $message->quote_id = $quote?->id;
            $message->save();

            $conversation->forceFill([
                'last_message_id' => $message->id,
                'last_message_at' => $message->created_at,
                Conversation::readColumn($side) => $message->id,
            ])->save();

            if ($quote === null) {
                $this->alerts->messagePosted($conversation, $side, $isNewInquiry);
            }

            return $message;
        });
    }
}
