import {
  BarChart3, CreditCard, History, LayoutDashboard, LifeBuoy, MessageSquare, Package, Settings, ShoppingBag, Star, Store, Tags, Truck,
  Users, Wallet,
} from 'lucide-react'
import { Outlet } from 'react-router-dom'
import WorkArea, { useAreaSummary } from '../../components/WorkArea'

// Grouped by how often an admin needs them. `count` picks a number from /admin/summary to show
// beside the link when something is waiting.
const GROUPS = [
  ['Daily', [
    { to: '/admin', label: 'Overview', icon: LayoutDashboard, end: true },
    { to: '/admin/support', label: 'Support', icon: LifeBuoy, count: (s) => (s.support?.needs_reply || 0) + (s.support?.store_answers || 0) },
    { to: '/admin/orders', label: 'Orders', icon: ShoppingBag },
    { to: '/admin/payments', label: 'Payments', icon: CreditCard, count: (s) => s.refunds_due },
    { to: '/admin/sellers', label: 'Sellers', icon: Store, count: (s) => s.sellers.pending },
    { to: '/admin/listings', label: 'Listings', icon: Package, count: (s) => s.products.pending_review },
    { to: '/admin/reviews', label: 'Reviews', icon: Star },
  ]],
  ['Money', [
    { to: '/admin/settlements', label: 'Settlements', icon: Wallet },
    { to: '/admin/reports', label: 'Reports', icon: BarChart3 },
  ]],
  ['Setup', [
    { to: '/admin/categories', label: 'Categories', icon: Tags },
    { to: '/admin/delivery', label: 'Delivery', icon: Truck },
    { to: '/admin/settings', label: 'Settings', icon: Settings },
  ]],
  ['Records', [
    { to: '/admin/users', label: 'Users', icon: Users },
    { to: '/admin/sms', label: 'Text messages', icon: MessageSquare },
    { to: '/admin/audit', label: 'Audit log', icon: History },
  ]],
]
export default function AdminLayout() {
  const summary = useAreaSummary('/admin/summary')
  return (
    <WorkArea label="Admin" groups={GROUPS} counts={summary.data?.data}>
      <Outlet context={summary} />
    </WorkArea>
  )
}
