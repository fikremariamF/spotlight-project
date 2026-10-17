<?php

namespace App\Http\Resources;

use App\Models\SupportMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of an inquiry. Customers see Betoch's replies signed "Betoch Support", never an admin's name.
 *
 * @mixin SupportMessage
 */
class SupportMessageResource extends JsonResource
{
    private string $viewer = 'customer';

    public function as(string $viewer): static
    {
        $this->viewer = $viewer;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $admin = $this->viewer === 'admin';
        $from = match ($this->author_role) {
            'customer' => $admin ? ($this->relationLoaded('author') ? $this->author?->name : 'Customer') : 'You',
            'admin' => $admin && $this->relationLoaded('author') ? $this->author?->name : 'Betoch Support',
            default => 'Betoch',
        };

        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'from_role' => $this->author_role,
            'from' => $from,
            'is_mine' => $this->author_id !== null && $this->author_id === $request->user()?->id,
            'body' => $this->body,
            'quote_id' => $this->quote_id,
            'internal' => $this->when($admin, fn () => $this->internal),
            'meta' => $this->when($admin, fn () => $this->meta),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
