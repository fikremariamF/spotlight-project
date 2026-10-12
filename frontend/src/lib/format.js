const whole = new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 })
const cents = new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })

/**
 * Format a price given in santim (integer) as "ETB 12,500" or "ETB 12,500.50".
 */
export function formatETB(santim) {
  if (santim === null || santim === undefined || Number.isNaN(Number(santim))) return ''
  const n = Math.round(Number(santim))
  // Show decimals only when there are santim, and never "12,500.5".
  return `ETB ${n % 100 === 0 ? whole.format(n / 100) : cents.format(n / 100)}`
}

export function formatDimensions(d) {
  if (!d) return ''
  const parts = [d.width_cm, d.depth_cm, d.height_cm]
  if (parts.every((p) => p == null)) return ''
  return `${parts.map((p) => (p == null ? '?' : p)).join(' x ')} cm (W x D x H)`
}

export const CONDITION_LABELS = { new: 'New', used: 'Used', refurbished: 'Refurbished' }

export const STATUS_LABELS = {
  draft: 'Draft',
  pending_review: 'In review',
  published: 'Published',
  rejected: 'Rejected',
  archived: 'Archived',
  pending: 'Pending approval',
  approved: 'Approved',
  suspended: 'Suspended',
  active: 'Active',
}

export const BUSINESS_TYPES = {
  shop: 'Furniture shop',
  manufacturer: 'Manufacturer',
  workshop: 'Workshop',
  individual: 'Individual seller',
}

/**
 * Build a clean query string, dropping empty values.
 */
export function toQuery(params) {
  const q = new URLSearchParams()
  for (const [k, v] of Object.entries(params)) {
    if (v !== '' && v !== null && v !== undefined && v !== false) q.set(k, v === true ? '1' : String(v))
  }
  return q.toString()
}

export const INQUIRY_TYPES = {
  question: 'Question',
  availability: 'Availability',
  negotiation: 'Price offer',
  custom_request: 'Custom request',
}

export const QUOTE_STATUS_LABELS = {
  pending: 'Waiting for your answer',
  accepted: 'Accepted',
  declined: 'Declined',
  withdrawn: 'Withdrawn',
  expired: 'Expired',
}

/**
 * Short relative time for message lists: "now", "5 min", "3 h", or a date.
 */
export function timeAgo(iso) {
  if (!iso) return ''
  const diff = (Date.now() - new Date(iso).getTime()) / 1000
  if (diff < 60) return 'now'
  if (diff < 3600) return `${Math.floor(diff / 60)} min`
  if (diff < 86400) return `${Math.floor(diff / 3600)} h`
  return new Date(iso).toLocaleDateString('en-GB', { day: 'numeric', month: 'short' })
}

/**
 * Convert a santim integer to the birr string the API accepts, e.g. 150050 -> "1500.50".
 */
export function santimToBirr(santim) {
  if (santim === null || santim === undefined) return ''
  const n = Math.round(Number(santim))
  return `${Math.floor(n / 100)}.${String(n % 100).padStart(2, '0')}`
}

export const ORDER_STATUS_LABELS = {
  awaiting_payment: 'Waiting for payment',
  pending_confirmation: 'Waiting for seller',
  confirmed: 'Confirmed',
  processing: 'Being prepared',
  ready: 'Ready',
  dispatched: 'On the way',
  delivered: 'Delivered',
  cancelled: 'Cancelled',
}

export const ORDER_STATUS_TONES = {
  awaiting_payment: 'bg-orange-100 text-orange-800',
  pending_confirmation: 'bg-amber-100 text-amber-800',
  confirmed: 'bg-sky-100 text-sky-800',
  processing: 'bg-sky-100 text-sky-800',
  ready: 'bg-sky-100 text-sky-800',
  dispatched: 'bg-indigo-100 text-indigo-800',
  delivered: 'bg-emerald-100 text-emerald-800',
  cancelled: 'bg-stone-200 text-stone-700',
}

/**
 * Wording for the "ready" step depends on whether the customer collects.
 */
export function orderStatusLabel(status, fulfillment) {
  if (status === 'ready') return fulfillment === 'pickup' ? 'Ready for pickup' : 'Ready for delivery'
  if (status === 'delivered' && fulfillment === 'pickup') return 'Picked up'
  return ORDER_STATUS_LABELS[status] || status
}

/**
 * Button text for the seller moving an order to `status`. Orders paid online need no cash collected.
 */
export function orderActionLabel(status, fulfillment, paymentMethod = 'cod') {
  const pickup = fulfillment === 'pickup'
  const paid = paymentMethod === 'online' ? '' : ' and paid'
  return {
    processing: 'Start preparing',
    ready: pickup ? 'Ready for pickup' : 'Ready for delivery',
    dispatched: 'Out for delivery',
    delivered: pickup ? `Picked up${paid}` : `Delivered${paid}`,
  }[status] || status
}

/**
 * One line about how an order is paid, for every viewer.
 */
export function paymentSummary(order) {
  if (order.payment_method !== 'online') return `Cash on delivery · ${order.payment_status === 'paid' ? 'paid' : 'not paid yet'}`
  return {
    unpaid: order.status === 'cancelled' ? 'Online payment · never paid' : 'Online payment · waiting for payment',
    paid: 'Paid online',
    refund_due: 'Paid online · refund on the way',
    refunded: 'Paid online · refunded',
  }[order.payment_status] || 'Online payment'
}

export function formatDateTime(iso) {
  if (!iso) return ''
  return new Date(iso).toLocaleString('en-GB', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })
}

/**
 * Random id for the checkout Idempotency-Key header; falls back where crypto.randomUUID is missing.
 */
export function newIdempotencyKey() {
  if (globalThis.crypto?.randomUUID) return globalThis.crypto.randomUUID()
  return `k-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 12)}`
}

export const ENTRY_LABELS = { commission: 'Commission', payment: 'Payment received', adjustment: 'Adjustment', sale: 'Online sale', payout: 'Paid out to store' }
export const METHOD_LABELS = { cash: 'Cash', bank_transfer: 'Bank transfer', telebirr: 'telebirr', chapa: 'Chapa', other: 'Other' }
