import { Dialog, DialogBackdrop, DialogPanel, DialogTitle, Menu, MenuButton, MenuItem, MenuItems } from '@headlessui/react'
import { Bell, ChevronDown, GitCompareArrows, Heart, LayoutDashboard, LogOut, Menu as MenuIcon, MessageCircle, Search, ShoppingBag, Store, User, X } from 'lucide-react'
import { Suspense, useEffect, useState } from 'react'
import { Link, NavLink, Outlet, useLocation, useNavigate, useSearchParams } from 'react-router-dom'
import { Toaster } from 'sonner'
import { useAlerts } from '../context/AlertsContext'
import { useAuth } from '../context/AuthContext'
import { useCart } from '../context/CartContext'
import { useCompare } from '../context/CompareContext'
import { useConfig, usePaymentWording } from '../context/ConfigContext'
import { useInbox } from '../context/InboxContext'
import { categoryIcon, useCategories } from '../lib/categories'
import { Spinner } from './ui'

function SearchBox({ className = '', id = 'site-search' }) {
  const navigate = useNavigate()
  const [params] = useSearchParams()
  const [q, setQ] = useState(params.get('q') || '')

  useEffect(() => setQ(params.get('q') || ''), [params])

  return (
    <form
      role="search"
      className={className}
      onSubmit={(e) => {
        e.preventDefault()
        navigate(q.trim() ? `/search?q=${encodeURIComponent(q.trim())}` : '/search')
      }}
    >
      <label htmlFor={id} className="sr-only">Search furniture</label>
      <div className="relative">
        <Search className="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-stone-400" aria-hidden="true" />
        <input
          id={id}
          type="search"
          value={q}
          onChange={(e) => setQ(e.target.value)}
          placeholder="Search sofas, beds, wardrobes..."
          className="input rounded-full border-stone-200 bg-stone-100/80 pr-24 pl-10 focus:bg-white"
          maxLength={100}
        />
        <button type="submit" className="absolute inset-y-1 right-1 rounded-full bg-brand-700 px-4 text-sm font-semibold text-white hover:bg-brand-800">
          Search
        </button>
      </div>
    </form>
  )
}

function CountBadge({ count, tone = 'bg-brand-700' }) {
  if (!count) return null
  return (
    <span aria-hidden="true" className={`absolute -top-0.5 -right-0.5 inline-flex min-w-5 justify-center rounded-full px-1.5 text-[11px] leading-5 font-semibold text-white ring-2 ring-white ${tone}`}>
      {count > 99 ? '99+' : count}
    </span>
  )
}

/** Icon-only header link with a count; the count is part of the accessible name. */
function IconLink({ to, label, icon: Icon, count = 0, noun = 'unread', tone, onClick }) {
  return (
    <NavLink
      to={to}
      onClick={onClick}
      aria-label={`${label}${count > 0 ? `, ${count} ${noun}` : ''}`}
      title={label}
      className={({ isActive }) => `icon-btn ${isActive ? 'bg-brand-50 text-brand-800' : ''}`}
    >
      <Icon className="size-5" aria-hidden="true" />
      <CountBadge count={count} tone={tone} />
    </NavLink>
  )
}

/** Bell with the unread alert count, in the top bar on every screen size. */
function AlertsLink({ onNavigate }) {
  const { unread } = useAlerts()
  return <IconLink to="/notifications" label="Alerts" icon={Bell} count={unread} tone="bg-red-600" onClick={onNavigate} />
}

function CartLink() {
  const { count } = useCart()
  return <IconLink to="/cart" label="Cart" icon={ShoppingBag} count={count} noun={count === 1 ? 'item' : 'items'} />
}

function initials(name = '') {
  return name.trim().split(/\s+/).slice(0, 2).map((w) => w[0]?.toUpperCase()).join('') || '?'
}

/** Links for the account menu and the mobile drawer, by role. */
function accountLinks(role) {
  if (role === 'seller') {
    return [['/account', 'My account'], ['/seller', 'Seller dashboard'], ['/seller/orders', 'Orders'], ['/seller/balance', 'Balance'], ['/seller/reviews', 'Reviews'], ['/seller/profile', 'Store profile'], ['/account/alerts', 'Alert settings']]
  }
  if (role === 'admin') {
    return [['/account', 'My account'], ['/admin', 'Admin'], ['/account/alerts', 'Alert settings']]
  }
  return [['/account', 'My account'], ['/orders', 'Orders'], ['/account/addresses', 'Addresses'], ['/account/alerts', 'Alert settings']]
}

function AccountMenu({ user, onSignOut }) {
  return (
    <Menu as="div" className="relative">
      <MenuButton className="flex items-center gap-1.5 rounded-full py-1 pr-2 pl-1 text-sm font-medium text-stone-700 hover:bg-stone-100" aria-label="Account menu">
        <span className="flex size-8 items-center justify-center rounded-full bg-brand-100 text-xs font-bold text-brand-800" aria-hidden="true">{initials(user.name)}</span>
        <ChevronDown className="size-4 text-stone-500" aria-hidden="true" />
      </MenuButton>
      <MenuItems
        anchor="bottom end"
        transition
        className="z-50 mt-2 w-60 origin-top-right rounded-2xl border border-stone-200 bg-white p-1.5 shadow-lift transition duration-100 ease-out focus:outline-none data-closed:scale-95 data-closed:opacity-0"
      >
        <div className="px-3 py-2">
          <p className="truncate text-sm font-semibold text-stone-900">{user.name}</p>
          <p className="truncate text-xs text-stone-500">{user.email}</p>
        </div>
        <div className="my-1 h-px bg-stone-100" />
        {accountLinks(user.role).map(([to, label]) => (
          <MenuItem key={to}>
            <Link to={to} className="block rounded-lg px-3 py-2 text-sm text-stone-700 data-focus:bg-stone-100">{label}</Link>
          </MenuItem>
        ))}
        <div className="my-1 h-px bg-stone-100" />
        <MenuItem>
          <button type="button" onClick={onSignOut} className="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-left text-sm text-stone-700 data-focus:bg-stone-100">
            <LogOut className="size-4" aria-hidden="true" /> Sign out
          </button>
        </MenuItem>
      </MenuItems>
    </Menu>
  )
}

function DesktopActions({ onSignOut }) {
  const { user } = useAuth()
  const { unread } = useInbox()
  const linkClass = ({ isActive }) => `rounded-full px-3.5 py-2 text-sm font-medium ${isActive ? 'bg-brand-50 text-brand-800' : 'text-stone-700 hover:bg-stone-100'}`

  if (!user) {
    return (
      <>
        <NavLink to="/sell" className={linkClass}>Sell on our marketplace</NavLink>
        <NavLink to="/login" className={linkClass}>Sign in</NavLink>
        <Link to="/register" className="btn-primary rounded-full">Create account</Link>
      </>
    )
  }

  return (
    <>
      {user.role === 'seller' && (
        <NavLink to="/seller" end className={linkClass}>
          <span className="inline-flex items-center gap-1.5"><LayoutDashboard className="size-4" aria-hidden="true" />Seller dashboard</span>
        </NavLink>
      )}
      {user.role === 'admin' && <NavLink to="/admin" className={linkClass}>Admin</NavLink>}
      {user.role !== 'admin' && <IconLink to="/messages" label="Messages" icon={MessageCircle} count={unread} />}
      {user.role !== 'admin' && <IconLink to="/wishlist" label="Wishlist" icon={Heart} />}
      {user.role === 'customer' && <CartLink />}
      <AlertsLink />
      <AccountMenu user={user} onSignOut={onSignOut} />
    </>
  )
}

function MobileDrawer({ open, onClose, onSignOut }) {
  const { user } = useAuth()
  const { unread } = useInbox()
  const { count: cartCount } = useCart()
  const { data: categories } = useCategories()
  const item = ({ isActive }) => `flex items-center justify-between rounded-xl px-3 py-2.5 text-sm font-medium ${isActive ? 'bg-brand-50 text-brand-800' : 'text-stone-700 hover:bg-stone-100'}`

  return (
    <Dialog open={open} onClose={onClose} className="relative z-50 lg:hidden">
      <DialogBackdrop transition className="fixed inset-0 bg-stone-900/40 transition-opacity duration-200 data-closed:opacity-0" />
      <DialogPanel
        id="mobile-menu"
        transition
        className="fixed inset-y-0 right-0 flex w-[min(22rem,88vw)] flex-col overflow-y-auto bg-white shadow-lift transition duration-200 ease-out data-closed:translate-x-full"
      >
        <div className="flex items-center justify-between border-b border-stone-100 px-4 py-3">
          <DialogTitle className="font-semibold text-stone-900">{user ? user.name : 'Menu'}</DialogTitle>
          <button type="button" className="icon-btn" onClick={onClose} aria-label="Close menu"><X className="size-5" aria-hidden="true" /></button>
        </div>
        <nav aria-label="Account" className="flex flex-col gap-0.5 p-3">
          {!user && (
            <>
              <NavLink to="/login" className={item} onClick={onClose}>Sign in</NavLink>
              <NavLink to="/register" className={item} onClick={onClose}>Create account</NavLink>
              <NavLink to="/sell" className={item} onClick={onClose}>Sell on our marketplace</NavLink>
            </>
          )}
          {user && user.role !== 'admin' && (
            <NavLink to="/messages" className={item} onClick={onClose}>
              Messages {unread > 0 && <span className="rounded-full bg-brand-700 px-2 text-xs font-semibold text-white">{unread}<span className="sr-only"> unread</span></span>}
            </NavLink>
          )}
          {user && user.role !== 'admin' && <NavLink to="/wishlist" className={item} onClick={onClose}>Wishlist</NavLink>}
          {user?.role === 'customer' && (
            <NavLink to="/cart" className={item} onClick={onClose}>
              Cart {cartCount > 0 && <span className="rounded-full bg-stone-800 px-2 text-xs font-semibold text-white">{cartCount}<span className="sr-only"> items</span></span>}
            </NavLink>
          )}
          {user && accountLinks(user.role).map(([to, label]) => (
            <NavLink key={to} to={to} end className={item} onClick={onClose}>{label === 'My account' ? 'Account' : label}</NavLink>
          ))}
          <NavLink to="/compare" className={item} onClick={onClose}>Compare</NavLink>
          {user && (
            <button type="button" className="flex items-center gap-2 rounded-xl px-3 py-2.5 text-left text-sm font-medium text-stone-700 hover:bg-stone-100" onClick={() => { onClose(); onSignOut() }}>
              <LogOut className="size-4" aria-hidden="true" /> Sign out
            </button>
          )}
        </nav>
        {categories?.length > 0 && (
          <nav aria-label="Categories" className="border-t border-stone-100 p-3">
            <p className="px-3 pb-1 text-xs font-semibold tracking-wide text-stone-500 uppercase">Shop by category</p>
            {categories.map((c) => {
              const Icon = categoryIcon(c.slug)
              return (
                <NavLink key={c.id} to={`/category/${c.slug}`} className={item} onClick={onClose}>
                  <span className="flex items-center gap-3"><Icon className="size-4 text-stone-500" aria-hidden="true" />{c.name}</span>
                </NavLink>
              )
            })}
          </nav>
        )}
      </DialogPanel>
    </Dialog>
  )
}

/** Category links under the header on wide screens. Not sticky, so the sticky header stays slim. */
function CategoryBar() {
  const { data: categories } = useCategories()
  const linkClass = ({ isActive }) => `inline-flex shrink-0 items-center gap-2 rounded-full px-3 py-1.5 text-sm font-medium ${isActive ? 'bg-brand-50 text-brand-800' : 'text-stone-600 hover:bg-stone-100 hover:text-stone-900'}`
  if (!categories?.length) return null
  return (
    <nav aria-label="Categories" className="hidden border-b border-stone-200/80 bg-white lg:block">
      <div className="container-page no-scrollbar flex gap-1 relative overflow-x-auto py-2">
        <NavLink to="/search" className={linkClass}>All furniture</NavLink>
        {categories.map((c) => {
          const Icon = categoryIcon(c.slug)
          return (
            <NavLink key={c.id} to={`/category/${c.slug}`} className={linkClass}>
              <Icon className="size-4" aria-hidden="true" />{c.name}
            </NavLink>
          )
        })}
      </div>
    </nav>
  )
}

/** Floating reminder of the compare list, so picking items and opening the comparison is one tap. */
/** Floating link to the compare page. Hidden on phone product pages, where the buy bar owns the bottom edge. */
function CompareTray() {
  const { slugs, clear } = useCompare()
  const location = useLocation()
  if (slugs.length === 0 || location.pathname === '/compare' || location.pathname.startsWith('/admin') || location.pathname.startsWith('/seller')) return null
  return (
    <div className={`pointer-events-none fixed inset-x-0 bottom-4 z-30 justify-center px-4 lg:bottom-6 ${location.pathname.startsWith('/products/') ? 'hidden lg:flex' : 'flex'}`}>
      <div className="pointer-events-auto flex items-center gap-1 rounded-full bg-stone-900 py-1.5 pr-1.5 pl-4 text-sm text-white shadow-lift">
        <GitCompareArrows className="size-4" aria-hidden="true" />
        <span className="mr-2 ml-1">{slugs.length} {slugs.length === 1 ? 'item' : 'items'} to compare</span>
        <Link to="/compare" className="rounded-full bg-white px-3.5 py-1.5 font-semibold text-stone-900 hover:bg-stone-100">Compare</Link>
        <button type="button" onClick={clear} className="rounded-full p-1.5 text-stone-300 hover:bg-white/10 hover:text-white" aria-label="Clear compare list">
          <X className="size-4" aria-hidden="true" />
        </button>
      </div>
    </div>
  )
}

function Footer({ name }) {
  const { data: categories } = useCategories()
  const pay = usePaymentWording()
  return (
    <footer className="mt-20 bg-brand-900 text-brand-100">
      <div className="container-page grid gap-10 py-14 text-sm sm:grid-cols-2 lg:grid-cols-[1.4fr_1fr_1fr_1fr]">
        <div>
          <p className="flex items-center gap-2 font-display text-xl font-semibold text-white">
            <img src="/favicon.svg" alt="" className="size-7 rounded-md bg-white/90 p-0.5" />{name}
          </p>
          <p className="mt-3 max-w-xs text-brand-200">Furniture from trusted shops, workshops and makers in Addis Ababa. {pay.short}</p>
        </div>
        <div>
          <p className="font-semibold text-white">Shop</p>
          <ul className="mt-3 space-y-2">
            {(categories || []).slice(0, 5).map((c) => <li key={c.id}><Link to={`/category/${c.slug}`} className="hover:text-white">{c.name}</Link></li>)}
            <li><Link to="/compare" className="hover:text-white">Compare furniture</Link></li>
          </ul>
        </div>
        <div>
          <p className="font-semibold text-white">Help</p>
          <ul className="mt-3 space-y-2">
            <li><Link to="/help/delivery" className="hover:text-white">Delivery information</Link></li>
            <li><Link to="/help/returns" className="hover:text-white">Returns and refunds</Link></li>
            <li><Link to="/help/policies" className="hover:text-white">Marketplace policies</Link></li>
          </ul>
        </div>
        <div>
          <p className="font-semibold text-white">Sellers</p>
          <ul className="mt-3 space-y-2">
            <li><Link to="/sell" className="inline-flex items-center gap-1.5 hover:text-white"><Store className="size-4" aria-hidden="true" />Apply to sell</Link></li>
            <li><Link to="/help/selling" className="hover:text-white">How selling works</Link></li>
          </ul>
        </div>
      </div>
      <div className="border-t border-white/10 py-5 text-center text-xs text-brand-200">
        Prices in Ethiopian birr (ETB). Currently serving Addis Ababa.
      </div>
    </footer>
  )
}

export default function Layout() {
  const { name } = useConfig()
  const { user, signOut } = useAuth()
  const navigate = useNavigate()
  const [menuOpen, setMenuOpen] = useState(false)
  const location = useLocation()

  useEffect(() => setMenuOpen(false), [location.pathname])

  async function handleSignOut() {
    await signOut()
    navigate('/')
  }

  return (
    <div className="flex min-h-screen flex-col">
      <a href="#main" className="sr-only focus:not-sr-only focus:absolute focus:z-50 focus:bg-white focus:p-2">Skip to content</a>
      <header className="sticky top-0 z-30 border-b border-stone-200/80 bg-white/90 backdrop-blur-md">
        <div className="container-page flex h-16 items-center gap-3 lg:gap-5">
          <Link to="/" className="flex shrink-0 items-center gap-2 font-bold text-brand-800">
            <img src="/favicon.svg" alt="" className="size-8" />
            <span className="font-display text-lg font-semibold tracking-tight sm:text-xl">{name}</span>
          </Link>
          <SearchBox className="hidden flex-1 md:block md:max-w-xl" />
          <nav className="ml-auto hidden items-center gap-1 lg:flex" aria-label="Account">
            <DesktopActions onSignOut={handleSignOut} />
          </nav>
          <div className="ml-auto flex items-center gap-0.5 lg:hidden">
            {user?.role === 'customer' && <CartLink />}
            {user && <AlertsLink />}
            {!user && <Link to="/login" className="icon-btn" aria-label="Sign in"><User className="size-5" aria-hidden="true" /></Link>}
            <button
              type="button"
              className="icon-btn"
              aria-expanded={menuOpen}
              aria-controls="mobile-menu"
              aria-label="Menu"
              onClick={() => setMenuOpen(true)}
            >
              <MenuIcon className="size-6" aria-hidden="true" />
            </button>
          </div>
        </div>
        <div className="container-page pb-3 md:hidden">
          <SearchBox id="site-search-mobile" />
        </div>
      </header>
      <CategoryBar />
      <MobileDrawer open={menuOpen} onClose={() => setMenuOpen(false)} onSignOut={handleSignOut} />

      <main id="main" className="flex-1">
        <Suspense fallback={<Spinner />}>
          <Outlet />
        </Suspense>
      </main>

      <CompareTray />
      <Footer name={name} />
      <Toaster position="top-center" richColors closeButton toastOptions={{ className: 'font-sans' }} />
    </div>
  )
}
