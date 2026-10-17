<?php

namespace App\Http\Controllers\Api;

use App\Enums\QuoteStatus;
use App\Enums\StockType;
use App\Http\Controllers\Controller;
use App\Models\Area;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\Quote;
use App\Models\User;
use App\Services\CheckoutCalculator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CartController extends Controller
{
    public function __construct(private readonly CheckoutCalculator $calculator) {}

    /**
     * The cart, priced. Pass area_id (or address_id) and fulfillment[seller_id]=pickup|delivery
     * to include delivery fees, exactly as checkout will charge them.
     */
    public function show(Request $request): JsonResponse
    {
        $data = $request->validate([
            'area_id' => ['nullable', 'integer', Rule::exists('areas', 'id')->where('is_active', true)],
            'address_id' => ['nullable', 'integer'],
            'fulfillment' => ['nullable', 'array'],
            'fulfillment.*' => [Rule::in(['delivery', 'pickup'])],
        ]);

        $user = $request->user();
        $areaId = $data['area_id'] ?? null;
        $address = null;
        if (! empty($data['address_id'])) {
            $address = $user->addresses()->findOrFail($data['address_id']);
            $areaId = $address->area_id;
        }
        $area = $areaId ? Area::with('deliveryZone')->find($areaId) : null;

        return response()->json(['data' => $this->calculator->calculate(self::lines($user), $area, $data['fulfillment'] ?? [], $address)]);
    }

    public function count(Request $request): JsonResponse
    {
        return response()->json(['data' => ['count' => CartItem::where('user_id', $request->user()->id)->count()]]);
    }

    /**
     * Add a listing, or raise its quantity if it is already in the cart.
     */
    public function add(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_slug' => ['required', 'string', 'max:200'],
            'quantity' => ['sometimes', 'integer', 'min:1', 'max:'.config('marketplace.cart.max_quantity')],
        ]);
        $user = $request->user();

        $product = Product::visible()->where('slug', $data['product_slug'])->first();
        if (! $product) {
            throw ValidationException::withMessages(['product_slug' => 'This listing is not available.']);
        }

        DB::transaction(function () use ($user, $product, $data) {
            User::whereKey($user->id)->lockForUpdate()->first();
            $item = CartItem::where('user_id', $user->id)->where('product_id', $product->id)->first();
            $quantity = ($item?->quantity ?? 0) + ($data['quantity'] ?? 1);

            if (! $item) {
                $this->assertRoom($user);
                $item = new CartItem;
                $item->user_id = $user->id;
                $item->product_id = $product->id;
            }
            $item->quantity = $this->checkedQuantity($product, $quantity);
            $item->save();
        });

        return $this->show($request)->setStatusCode(201);
    }

    /**
     * Put an accepted quote in the cart so it is ordered through the normal checkout.
     */
    public function addQuote(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $quote = Quote::forCustomer($user->id)->findOrFail($id);

        if ($quote->status !== QuoteStatus::Accepted) {
            throw ValidationException::withMessages(['quote' => 'Accept the quote before ordering it.']);
        }
        if ($quote->orderDeadline()->isPast()) {
            throw ValidationException::withMessages(['quote' => 'This offer has expired. Ask Betoch Support for a new one.']);
        }
        if ($quote->isOrdered()) {
            throw ValidationException::withMessages(['quote' => 'This quote has already been ordered.']);
        }

        DB::transaction(function () use ($user, $quote) {
            User::whereKey($user->id)->lockForUpdate()->first();
            if (CartItem::where('user_id', $user->id)->where('quote_id', $quote->id)->exists()) {
                return;
            }
            $this->assertRoom($user);
            $item = new CartItem;
            $item->user_id = $user->id;
            $item->quote_id = $quote->id;
            $item->quantity = $quote->quantity;
            $item->save();
        });

        return $this->show($request)->setStatusCode(201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'quantity' => ['required', 'integer', 'min:1', 'max:'.config('marketplace.cart.max_quantity')],
        ]);
        $item = CartItem::where('user_id', $request->user()->id)->with('product')->findOrFail($id);
        if ($item->quote_id) {
            throw ValidationException::withMessages(['quantity' => 'The quantity of a quote is set by the seller.']);
        }

        $item->quantity = $this->checkedQuantity($item->product, $data['quantity']);
        $item->save();

        return $this->show($request);
    }

    public function remove(Request $request, int $id): JsonResponse
    {
        CartItem::where('user_id', $request->user()->id)->findOrFail($id)->delete();

        return $this->show($request);
    }

    /**
     * Cart lines with everything the calculator needs, oldest first.
     *
     * @return Collection<int, CartItem>
     */
    public static function lines(User $user): Collection
    {
        $items = CartItem::where('user_id', $user->id)
            ->with([
                'product' => fn ($q) => $q->withTrashed()->with(['seller.area', 'primaryImage']),
                'quote' => fn ($q) => $q->with(['seller.area', 'product']),
            ])
            ->orderBy('id')
            ->get();

        // Drop lines whose listing was hard-deleted out from under the cart.
        return $items->filter(fn (CartItem $i) => $i->product || $i->quote)->values();
    }

    private function assertRoom(User $user): void
    {
        $max = (int) config('marketplace.cart.max_lines');
        if (CartItem::where('user_id', $user->id)->count() >= $max) {
            throw ValidationException::withMessages(['product_slug' => "Your cart can hold up to {$max} different items."]);
        }
    }

    private function checkedQuantity(Product $product, int $quantity): int
    {
        $max = (int) config('marketplace.cart.max_quantity');
        if ($product->stock_type === StockType::InStock) {
            if ($product->stock_quantity < 1) {
                throw ValidationException::withMessages(['quantity' => 'This item is out of stock.']);
            }
            $max = min($max, $product->stock_quantity);
        }
        if ($quantity > $max) {
            throw ValidationException::withMessages(['quantity' => "Only {$max} can be ordered."]);
        }

        return $quantity;
    }
}
