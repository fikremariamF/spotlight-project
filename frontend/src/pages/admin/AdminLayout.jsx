import { Suspense } from 'react'
import { NavLink, Outlet } from 'react-router-dom'
import { Spinner } from '../../components/ui'

const LINKS = [
  ['/admin', 'Overview', true],
  ['/admin/orders', 'Orders'],
  ['/admin/settlements', 'Settlements'],
  ['/admin/payments', 'Payments'],
  ['/admin/sellers', 'Sellers'],
  ['/admin/listings', 'Listings'],
  ['/admin/categories', 'Categories'],
  ['/admin/reviews', 'Reviews'],
  ['/admin/users', 'Users'],
  ['/admin/reports', 'Reports'],
  ['/admin/delivery', 'Delivery'],
  ['/admin/settings', 'Settings'],
  ['/admin/sms', 'Text messages'],
  ['/admin/audit', 'Audit log'],
]

export default function AdminLayout() {
  return (
    <div className="container-page py-8">
      <nav aria-label="Admin" className="mb-6 flex gap-1 relative overflow-x-auto border-b border-stone-200">
        {LINKS.map(([to, label, end]) => (
          <NavLink key={to} to={to} end={end}
            className={({ isActive }) => `shrink-0 border-b-2 px-3 py-2 text-sm font-medium ${isActive ? 'border-brand-700 text-brand-800' : 'border-transparent text-stone-600 hover:text-stone-900'}`}>
            {label}
          </NavLink>
        ))}
      </nav>
      <Suspense fallback={<Spinner />}>
        <Outlet />
      </Suspense>
    </div>
  )
}
