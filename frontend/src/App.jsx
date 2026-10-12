import { lazy } from 'react'
import { Route, Routes } from 'react-router-dom'
import Layout from './components/Layout'
import RequireRole from './components/RequireRole'
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
const ConversationView = lazy(() => import('./pages/Messages').then((m) => ({ default: m.ConversationView })))
const Inbox = lazy(() => import('./pages/Messages').then((m) => ({ default: m.Inbox })))
const AlertSettings = lazy(() => import('./pages/Notifications').then((m) => ({ default: m.AlertSettings })))
const Notifications = lazy(() => import('./pages/Notifications').then((m) => ({ default: m.Notifications })))
const OrderDetail = lazy(() => import('./pages/Orders').then((m) => ({ default: m.OrderDetail })))
const Orders = lazy(() => import('./pages/Orders').then((m) => ({ default: m.Orders })))
const PaymentReturn = lazy(() => import('./pages/Payment').then((m) => ({ default: m.PaymentReturn })))
const TestPay = lazy(() => import('./pages/Payment').then((m) => ({ default: m.TestPay })))
const SellerDashboard = lazy(() => import('./pages/seller/SellerDashboard'))
const SellerBalance = lazy(() => import('./pages/seller/SellerBalance'))
const SellerOrderDetail = lazy(() => import('./pages/seller/SellerOrders').then((m) => ({ default: m.SellerOrderDetail })))
const SellerOrders = lazy(() => import('./pages/seller/SellerOrders').then((m) => ({ default: m.SellerOrders })))
const SellerReviews = lazy(() => import('./pages/seller/SellerReviews'))
const SellerProductForm = lazy(() => import('./pages/seller/SellerProductForm'))
const SellerProfile = lazy(() => import('./pages/seller/SellerProfile'))

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
        <Route path="messages" element={<RequireRole roles={['customer', 'seller']}><Inbox /></RequireRole>} />
        <Route path="messages/:id" element={<RequireRole roles={['customer', 'seller']}><ConversationView /></RequireRole>} />
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

        <Route path="seller" element={<RequireRole roles={['seller']}><SellerDashboard /></RequireRole>} />
        <Route path="seller/orders" element={<RequireRole roles={['seller']}><SellerOrders /></RequireRole>} />
        <Route path="seller/orders/:id" element={<RequireRole roles={['seller']}><SellerOrderDetail /></RequireRole>} />
        <Route path="seller/balance" element={<RequireRole roles={['seller']}><SellerBalance /></RequireRole>} />
        <Route path="seller/reviews" element={<RequireRole roles={['seller']}><SellerReviews /></RequireRole>} />
        <Route path="seller/profile" element={<RequireRole roles={['seller']}><SellerProfile /></RequireRole>} />
        <Route path="seller/products/new" element={<RequireRole roles={['seller']}><SellerProductForm /></RequireRole>} />
        <Route path="seller/products/:id/edit" element={<RequireRole roles={['seller']}><SellerProductForm /></RequireRole>} />

        <Route path="admin" element={<RequireRole roles={['admin']}><AdminLayout /></RequireRole>}>
          <Route index element={<AdminOverview />} />
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
