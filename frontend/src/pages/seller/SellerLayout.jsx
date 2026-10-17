import { Eye, Inbox, LayoutDashboard, Package, ShoppingBag, Star, Store, Wallet } from 'lucide-react'
import { Outlet } from 'react-router-dom'
import WorkArea, { useAreaSummary } from '../../components/WorkArea'
import { useInbox } from '../../context/InboxContext'

function groups(store) {
  return [
    ['Daily', [
      { to: '/seller', label: 'Overview', icon: LayoutDashboard, end: true },
      { to: '/seller/orders', label: 'Orders', icon: ShoppingBag, count: (s) => s.orders.pending_confirmation },
      { to: '/seller/listings', label: 'Listings', icon: Package, count: (s) => s.products.rejected, also: ['/seller/products'] },
      { to: '/seller/requests', label: 'Admin requests', icon: Inbox, count: (s) => s.unread },
      { to: '/seller/reviews', label: 'Reviews', icon: Star, count: (s) => s.reviews.low_unanswered },
    ]],
    ['Store', [
      { to: '/seller/balance', label: 'Balance', icon: Wallet },
      { to: '/seller/profile', label: 'Store profile', icon: Store },
      ...(store?.status === 'approved' ? [{ to: `/sellers/${store.slug}`, label: 'Your store page', icon: Eye }] : []),
    ]],
  ]
}

/**
 * The seller area. The summary is handed to pages through the outlet context so the overview does
 * not load it twice.
 */
export default function SellerLayout() {
  const summary = useAreaSummary('/seller/summary')
  const { unread } = useInbox()
  const s = summary.data?.data
  return (
    <WorkArea label="Seller" groups={groups(s?.store)} counts={s && { ...s, unread }}>
      <Outlet context={summary} />
    </WorkArea>
  )
}

