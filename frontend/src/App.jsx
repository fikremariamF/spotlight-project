import { lazy } from 'react'
import { Navigate, Route, Routes } from 'react-router-dom'
import Layout from './components/Layout'
import RequireRole from './components/RequireRole'
import { useAuth } from './context/AuthContext'
import { NotFound } from './components/ui'
import Compare from './pages/Compare'
import { Login, Register, SellerApply } from './pages/Auth'
import Cart from './pages/Cart'
import Catalog from './pages/Catalog'
import Help from './pages/Help'
import Home from './pages/Home'
import ProductDetail from './pages/ProductDetail'
import Storefront from './pages/Storefront'
import Wishlist from './pages/Wishlist'

// Account, seller and admin screens load on first visit, so shoppers download only the store.
const AdminAudit = lazy(() => import('./pages/admin/AdminAudit'))
const AdminCategories = lazy(() => import('./pages/admin/AdminCategories'))
const AdminDelivery = lazy(() => import('./pages/admin/AdminDelivery'))
const AdminLayout = lazy(() => import('./pages/admin/AdminLayout'))
const AdminOrderDetail = lazy(() => import('./pages/admin/AdminOrders').then((m) => ({ default: m.AdminOrderDetail })))
const AdminOrders = lazy(() => import('./pages/admin/AdminOrders').then((m) => ({ default: m.AdminOrders })))
const AdminReports = lazy(() => import('./pages/admin/AdminReports'))
const AdminReviews = lazy(() => import('./pages/admin/AdminReviews'))
const AdminSettlementDetail = lazy(() => import('./pages/admin/AdminSettlements').then((m) => ({ default: m.AdminSettlementDetail })))
const AdminSettlements = lazy(() => import('./pages/admin/AdminSettlements').then((m) => ({ default: m.AdminSettlements })))
const AdminUserDetail = lazy(() => import('./pages/admin/AdminUsers').then((m) => ({ default: m.AdminUserDetail })))
const AdminUsers = lazy(() => import('./pages/admin/AdminUsers').then((m) => ({ default: m.AdminUsers })))
const AdminListings = lazy(() => import('./pages/admin/AdminListings'))
const AdminOverview = lazy(() => import('./pages/admin/AdminOverview'))
const AdminPayments = lazy(() => import('./pages/admin/AdminPayments'))
const AdminSellers = lazy(() => import('./pages/admin/AdminSellers'))
const AdminSettings = lazy(() => import('./pages/admin/AdminSettings'))
const AdminSms = lazy(() => import('./pages/admin/AdminSms'))
const Addresses = lazy(() => import('./pages/Addresses'))
const Account = lazy(() => import('./pages/Account'))
const Checkout = lazy(() => import('./pages/Checkout'))
const AdminOldChat = lazy(() => import('./pages/admin/AdminSupport').then((m) => ({ default: m.AdminOldChat })))
const AdminSellerRequest = lazy(() => import('./pages/admin/AdminSupport').then((m) => ({ default: m.AdminSellerRequest })))
const AdminSupport = lazy(() => import('./pages/admin/AdminSupport'))
const AdminSupportTicket = lazy(() => import('./pages/admin/AdminSupport').then((m) => ({ default: m.AdminSupportTicket })))
const NewSupportRequest = lazy(() => import('./pages/Support').then((m) => ({ default: m.NewSupportRequest })))
const SupportInbox = lazy(() => import('./pages/Support').then((m) => ({ default: m.SupportInbox })))
const SupportTicketView = lazy(() => import('./pages/Support').then((m) => ({ default: m.SupportTicketView })))
const SellerRequests = lazy(() => import('./pages/seller/SellerRequests').then((m) => ({ default: m.SellerRequests })))
const SellerRequestView = lazy(() => import('./pages/seller/SellerRequests').then((m) => ({ default: m.SellerRequestView })))
const ConversationView = lazy(() => import('./pages/Messages').then((m) => ({ default: m.ConversationView })))
const Inbox = lazy(() => import('./pages/Messages').then((m) => ({ default: m.Inbox })))
const AlertSettings = lazy(() => import('./pages/Notifications').then((m) => ({ default: m.AlertSettings })))
const Notifications = lazy(() => import('./pages/Notifications').then((m) => ({ default: m.Notifications })))
const OrderDetail = lazy(() => import('./pages/Orders').then((m) => ({ default: m.OrderDetail })))
const Orders = lazy(() => import('./pages/Orders').then((m) => ({ default: m.Orders })))
const PaymentReturn = lazy(() => import('./pages/Payment').then((m) => ({ default: m.PaymentReturn })))
const TestPay = lazy(() => import('./pages/Payment').then((m) => ({ default: m.TestPay })))
const SellerLayout = lazy(() => import('./pages/seller/SellerLayout'))
const SellerOverview = lazy(() => import('./pages/seller/SellerOverview'))
const SellerListings = lazy(() => import('./pages/seller/SellerListings'))
const SellerBalance = lazy(() => import('./pages/seller/SellerBalance'))
const SellerOrderDetail = lazy(() => import('./pages/seller/SellerOrders').then((m) => ({ default: m.SellerOrderDetail })))
const SellerOrders = lazy(() => import('./pages/seller/SellerOrders').then((m) => ({ default: m.SellerOrders })))
const SellerReviews = lazy(() => import('./pages/seller/SellerReviews'))
const SellerProductForm = lazy(() => import('./pages/seller/SellerProductForm'))
const SellerProfile = lazy(() => import('./pages/seller/SellerProfile'))

/**
 * Stores no longer have customer chats: links to the old Messages pages (bookmarks, old emails) lead
 * a store to its Admin requests. Customers keep their old chats to read.
 */
function OldMessages({ children }) {
  const { user } = useAuth()
  if (user?.role === 'seller') return <Navigate to="/seller/requests" replace />
  return <RequireRole roles={['customer']}>{children}</RequireRole>
}

export default function App() {
  return (
    <Routes>
      <Route element={<Layout />}>
        <Route index element={<Home />} />
        <Route path="search" element={<Catalog />} />
        <Route path="category/:slug" element={<Catalog />} />
        <Route path="products/:slug" element={<ProductDetail />} />
        <Route path="sellers/:slug" element={<Storefront />} />
        <Route path="help/:topic" element={<Help />} />
        <Route path="login" element={<Login />} />
        <Route path="register" element={<Register />} />
        <Route path="sell" element={<SellerApply />} />
        <Route path="compare" element={<Compare />} />

        <Route path="wishlist" element={<RequireRole><Wishlist /></RequireRole>} />
        <Route path="support" element={<RequireRole roles={['customer']}><SupportInbox /></RequireRole>} />
        <Route path="support/new" element={<RequireRole roles={['customer']}><NewSupportRequest /></RequireRole>} />
        <Route path="support/:id" element={<RequireRole roles={['customer']}><SupportTicketView /></RequireRole>} />
        <Route path="messages" element={<OldMessages><Inbox /></OldMessages>} />
        <Route path="messages/:id" element={<OldMessages><ConversationView /></OldMessages>} />
        <Route path="notifications" element={<RequireRole><Notifications /></RequireRole>} />
        <Route path="account" element={<RequireRole><Account /></RequireRole>} />
        <Route path="account/alerts" element={<RequireRole><AlertSettings /></RequireRole>} />
        <Route path="account/addresses" element={<RequireRole roles={['customer']}><Addresses /></RequireRole>} />
        <Route path="cart" element={<RequireRole roles={['customer']}><Cart /></RequireRole>} />
        <Route path="checkout" element={<RequireRole roles={['customer']}><Checkout /></RequireRole>} />
        <Route path="orders" element={<RequireRole roles={['customer']}><Orders /></RequireRole>} />
        <Route path="orders/:id" element={<RequireRole roles={['customer']}><OrderDetail /></RequireRole>} />
        <Route path="payments/return" element={<RequireRole roles={['customer']}><PaymentReturn /></RequireRole>} />
        <Route path="pay/test" element={<RequireRole roles={['customer']}><TestPay /></RequireRole>} />

        <Route path="seller" element={<RequireRole roles={['seller']}><SellerLayout /></RequireRole>}>
          <Route index element={<SellerOverview />} />
          <Route path="listings" element={<SellerListings />} />
          <Route path="orders" element={<SellerOrders />} />
          <Route path="orders/:id" element={<SellerOrderDetail />} />
          <Route path="balance" element={<SellerBalance />} />
          <Route path="reviews" element={<SellerReviews />} />
          <Route path="requests" element={<SellerRequests />} />
          <Route path="requests/:id" element={<SellerRequestView />} />
          <Route path="profile" element={<SellerProfile />} />
          <Route path="products/new" element={<SellerProductForm />} />
          <Route path="products/:id/edit" element={<SellerProductForm />} />
        </Route>

        <Route path="admin" element={<RequireRole roles={['admin']}><AdminLayout /></RequireRole>}>
          <Route index element={<AdminOverview />} />
          <Route path="support" element={<AdminSupport />} />
          <Route path="support/:id" element={<AdminSupportTicket />} />
          <Route path="support/requests/:id" element={<AdminSellerRequest />} />
          <Route path="support/old-chats/:id" element={<AdminOldChat />} />
          <Route path="orders" element={<AdminOrders />} />
          <Route path="orders/:id" element={<AdminOrderDetail />} />
          <Route path="settlements" element={<AdminSettlements />} />
          <Route path="settlements/:id" element={<AdminSettlementDetail />} />
          <Route path="payments" element={<AdminPayments />} />
          <Route path="reviews" element={<AdminReviews />} />
          <Route path="users" element={<AdminUsers />} />
          <Route path="users/:id" element={<AdminUserDetail />} />
          <Route path="reports" element={<AdminReports />} />
          <Route path="sellers" element={<AdminSellers />} />
          <Route path="listings" element={<AdminListings />} />
          <Route path="categories" element={<AdminCategories />} />
          <Route path="delivery" element={<AdminDelivery />} />
          <Route path="settings" element={<AdminSettings />} />
          <Route path="sms" element={<AdminSms />} />
          <Route path="audit" element={<AdminAudit />} />
        </Route>

        <Route path="*" element={<NotFound />} />
      </Route>
    </Routes>
  )
}
