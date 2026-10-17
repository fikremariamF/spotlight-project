import { LifeBuoy, MapPin } from 'lucide-react'
import { Link } from 'react-router-dom'
import { ProductImage } from './ProductCard'
import { ORDER_STATUS_TONES, formatDateTime, formatETB, orderStatusLabel, paymentSummary } from '../lib/format'
import { directionsLink, mapsLink } from '../lib/maps'

export function OrderStatusBadge({ order, viewer }) {
  return (
    <span className={`inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold ${ORDER_STATUS_TONES[order.status] || 'bg-stone-100 text-stone-700'}`}>
      {orderStatusLabel(order.status, order.fulfillment, viewer)}
    </span>
  )
}

const BY = { customer: 'Customer', seller: 'Seller', admin: 'Marketplace team', system: 'The marketplace' }

/**
 * Order detail shared by customer, seller and admin pages. `actions` renders the viewer's buttons.
 */
export default function OrderView({ order, viewer, actions, itemExtra }) {
  const a = order.delivery_address
  const feeText = order.fulfillment === 'pickup'
    ? 'Pickup'
    : order.delivery_fee_santim === null ? 'To be confirmed by the seller' : order.delivery_fee_santim === 0 ? 'Free' : formatETB(order.delivery_fee_santim)

  return (
    <div className="grid gap-6 lg:grid-cols-[1fr_300px]">
      <div className="space-y-4">
        <section className="card p-4">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <h2 className="text-lg font-bold text-stone-900">{order.number}</h2>
            <OrderStatusBadge order={order} viewer={viewer} />
          </div>
          <p className="mt-1 text-sm text-stone-600">
            Placed {formatDateTime(order.created_at)}
            {viewer !== 'seller' && order.seller && <> · from <Link to={`/sellers/${order.seller.slug}`} className="text-brand-700 hover:underline">{order.seller.store_name}</Link></>}
            {viewer !== 'customer' && order.customer && <> · for {order.customer.name}{order.customer.email && ` (${order.customer.email})`}</>}
          </p>
          {order.status === 'cancelled' && (
            <p className="mt-2 rounded-lg bg-stone-100 p-3 text-sm text-stone-700">
              Cancelled by {BY[order.cancelled_by]?.toLowerCase() || 'the marketplace'}{order.cancel_reason && `: ${order.cancel_reason}`}
            </p>
          )}
          {order.seller_note && <p className="mt-2 rounded-lg bg-brand-50 p-3 text-sm text-brand-900">Seller note: {order.seller_note}</p>}
          {order.customer_note && <p className="mt-2 rounded-lg bg-sand-100 p-3 text-sm text-stone-800">Customer note: {order.customer_note}</p>}
          {actions && <div className="mt-4">{actions}</div>}
        </section>

        <section className="card px-4 py-2">
          <ul className="divide-y divide-stone-100">
            {order.items.map((i) => (
              <li key={i.id} className="py-3">
                <div className="flex gap-3">
                <ProductImage src={i.image} alt="" className="size-14 shrink-0 rounded-lg" />
                <div className="min-w-0 flex-1 text-sm">
                  <p className="font-medium text-stone-900">{i.product_slug && !i.quote_id ? <Link to={`/products/${i.product_slug}`} className="hover:underline">{i.name}</Link> : i.name}</p>
                  <p className="text-stone-600">
                    {i.quantity} x {formatETB(i.unit_price_santim)}
                    {i.quote_id && ' · custom quote'}
                    {i.production_days ? ` · ready in about ${i.production_days} days` : ''}
                  </p>
                </div>
                <p className="shrink-0 text-sm font-semibold">{formatETB(i.line_total_santim)}</p>
                </div>
                {itemExtra && itemExtra(i)}
              </li>
            ))}
          </ul>
        </section>

        <section className="card p-4">
          <h3 className="mb-2 text-sm font-semibold text-stone-700">History</h3>
          <ol className="space-y-2 text-sm">
            {order.history.map((h, idx) => (
              <li key={idx} className="flex gap-3">
                <span className="mt-1.5 size-2 shrink-0 rounded-full bg-brand-600" aria-hidden="true" />
                <div>
                  <p className="font-medium text-stone-800">{orderStatusLabel(h.to, order.fulfillment)} <span className="font-normal text-stone-500">by {BY[h.by]?.toLowerCase()}</span></p>
                  <p className="text-xs text-stone-500">{formatDateTime(h.at)}{h.note && ` · ${h.note}`}</p>
                </div>
              </li>
            ))}
          </ol>
        </section>
      </div>

      <aside className="space-y-4">
        <section className="card space-y-1 p-4 text-sm">
          <div className="flex justify-between"><span>Items</span><span>{formatETB(order.items_total_santim)}</span></div>
          <div className="flex justify-between gap-2"><span>{order.fulfillment === 'pickup' ? 'Fulfilment' : 'Delivery'}</span><span className="text-right">{feeText}</span></div>
          {order.fulfillment === 'delivery' && (order.delivery_distance_km !== null || order.carry_fee_santim > 0) && (
            <p className="text-xs text-stone-500">
              {order.delivery_distance_km !== null && `${order.delivery_distance_km} km${order.delivery_distance_estimated ? ' (estimated)' : ''}`}
              {order.delivery_distance_km !== null && order.carry_fee_santim > 0 && ' · '}
              {order.carry_fee_santim > 0 && `${order.delivery_fee_santim === null ? 'plus' : 'includes'} ${formatETB(order.carry_fee_santim)} carrying up stairs`}
            </p>
          )}
          <div className="flex justify-between border-t border-stone-100 pt-2 text-base font-bold"><span>Total</span><span>{formatETB(order.total_santim)}</span></div>
          <p className="pt-1 text-xs text-stone-500">{paymentSummary(order)}</p>
          {order.refund && (
            <p className={`mt-1 rounded-lg p-2 text-xs ${order.refund.status === 'refunded' ? 'bg-emerald-50 text-emerald-900' : 'bg-amber-50 text-amber-900'}`}>
              {order.refund.status === 'refunded'
                ? `Refunded ${formatETB(order.refund.amount_santim)} on ${formatDateTime(order.refund.refunded_at)}${order.refund.reference ? ` (ref ${order.refund.reference})` : ''}.`
                : `Refund of ${formatETB(order.refund.amount_santim)} on the way. The marketplace team sends it back to how you paid.`}
            </p>
          )}
          {viewer !== 'customer' && order.commission_santim !== undefined && (
            <p className="text-xs text-stone-500">Commission {order.commission_rate_percent}% on items: {formatETB(order.commission_santim)}</p>
          )}
        </section>
        {a && (
          <section className="card p-4 text-sm">
            <h3 className="mb-1 font-semibold text-stone-700">{order.fulfillment === 'pickup' ? 'Customer contact' : 'Delivery address'}</h3>
            {a.contact_hidden ? (
              <>
                {order.fulfillment === 'delivery' && <p>{a.area}</p>}
                <p className="text-stone-500">
                  {['pending_confirmation', 'awaiting_payment'].includes(order.status)
                    ? 'The name, phone and street appear once you confirm the order.'
                    : 'The customer\'s contact details are no longer shown. Ask Betoch Support if you need to reach them.'}
                </p>
              </>
            ) : (
              <>
                <p>{a.recipient_name}, {a.phone}</p>
                {order.fulfillment === 'delivery' && <p>{a.address_line}, {a.area}</p>}
              </>
            )}
            {order.fulfillment === 'delivery' && a.landmark && <p className="text-stone-500">Landmark: {a.landmark}</p>}
            {order.fulfillment === 'delivery' && a.floor > 0 && <p className="text-stone-500">Floor {a.floor}, {a.has_lift ? 'with a lift' : a.has_lift === false ? 'no lift' : 'lift not known'}</p>}
            {order.fulfillment === 'delivery' && a.latitude != null && (
              <a href={viewer === 'customer' ? mapsLink(a.latitude, a.longitude) : directionsLink(a.latitude, a.longitude)} target="_blank" rel="noreferrer"
                className="mt-1 inline-flex items-center gap-1 font-medium text-brand-700 hover:underline">
                <MapPin className="size-4" aria-hidden="true" />{viewer === 'customer' ? 'See the pin on Google Maps' : 'Directions in Google Maps'}
              </a>
            )}
          </section>
        )}
        {viewer === 'customer' && order.seller && (
          <section className="card p-4 text-sm">
            <h3 className="mb-1 font-semibold text-stone-700">Seller</h3>
            <p>{order.seller.store_name}{order.seller.area && `, ${order.seller.area}`}</p>
            <Link to={`/support/new?order=${order.id}`} className="btn-secondary mt-3 w-full justify-center"><LifeBuoy className="size-4" aria-hidden="true" />Get help with this order</Link>
            <p className="mt-2 text-xs text-stone-500">Betoch Support handles questions, returns and refunds, and checks with the store for you.</p>
          </section>
        )}
      </aside>
    </div>
  )
}
