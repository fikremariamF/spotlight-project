import { Banknote, Boxes, ChartColumn, ClipboardList, Eye, FileSpreadsheet, History, LayoutDashboard, MessageCircle, Package, PieChart, Receipt, Settings2, ShoppingBag, Star, Store, Truck, Wallet, Warehouse } from 'lucide-react'
import { Outlet } from 'react-router-dom'
import WorkArea, { useAreaSummary } from '../../components/WorkArea'
import { useInbox } from '../../context/InboxContext'

function groups(store) {
  return [
    ['Daily', [
      { to: '/seller', label: 'Overview', icon: LayoutDashboard, end: true },
      { to: '/seller/orders', label: 'Orders', icon: ShoppingBag, count: (s) => s.orders.pending_confirmation },
      { to: '/seller/listings', label: 'Listings', icon: Package, count: (s) => s.products.rejected, also: ['/seller/products'] },
      { to: '/messages', label: 'Messages', icon: MessageCircle, count: (s) => s.unread },
      { to: '/seller/reviews', label: 'Reviews', icon: Star, count: (s) => s.reviews.low_unanswered },
    ]],
    ['ERP Management', [
      { to: '/seller/erp', label: 'ERP overview', icon: PieChart, end: true },
      { to: '/seller/erp/inventory', label: 'Inventory', icon: Boxes, count: (s) => (s.stock?.low || 0) + (s.stock?.out || 0) },
      { to: '/seller/erp/movements', label: 'Stock history', icon: History },
      { to: '/seller/erp/warehouses', label: 'Warehouses', icon: Warehouse, count: (s) => s.erp?.transfers_in_transit, also: ['/seller/erp/transfers'] },
      { to: '/seller/erp/suppliers', label: 'Suppliers', icon: Truck },
      { to: '/seller/erp/purchases', label: 'Purchases', icon: ClipboardList, count: (s) => s.erp?.late_purchases },
      { to: '/seller/erp/sales', label: 'Sales & invoices', icon: Receipt, count: (s) => s.erp?.overdue_sales },
      { to: '/seller/erp/expenses', label: 'Expenses', icon: Banknote },
      { to: '/seller/erp/finance', label: 'Finance & profit', icon: ChartColumn },
      { to: '/seller/erp/reports', label: 'Reports', icon: FileSpreadsheet },
      { to: '/seller/erp/settings', label: 'ERP settings', icon: Settings2 },
    ], { collapsible: true, defaultOpen: true }],
    ['Store', [
      { to: '/seller/balance', label: 'Balance', icon: Wallet },
      { to: '/seller/profile', label: 'Store profile', icon: Store },
      ...(store?.status === 'approved' ? [{ to: `/sellers/${store.slug}`, label: 'Your store page', icon: Eye }] : []),
    ]],
  ]
}

/**
 * The seller area. `children` lets a shared page (messages) sit inside the same shell at its own URL.
 * The summary is handed to pages through the outlet context so the overview does not load it twice.
 */
export default function SellerLayout({ children }) {
  const summary = useAreaSummary('/seller/summary')
  const { unread } = useInbox()
  const s = summary.data?.data
  return (
    <WorkArea label="Seller" groups={groups(s?.store)} counts={s && { ...s, unread }}>
      {children ?? <Outlet context={summary} />}
    </WorkArea>
  )
}

