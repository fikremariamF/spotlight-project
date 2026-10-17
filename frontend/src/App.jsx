import { Suspense, lazy } from 'react'
import { Route, Routes } from 'react-router-dom'
import Layout from './components/Layout'
import RequireRole from './components/RequireRole'
import { useAuth } from './context/AuthContext'
import { NotFound, Spinner } from './components/ui'
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
const SellerLayout = lazy(() => import('./pages/seller/SellerLayout'))
const SellerOverview = lazy(() => import('./pages/seller/SellerOverview'))
const SellerListings = lazy(() => import('./pages/seller/SellerListings'))
const SellerBalance = lazy(() => import('./pages/seller/SellerBalance'))
const SellerOrderDetail = lazy(() => import('./pages/seller/SellerOrders').then((m) => ({ default: m.SellerOrderDetail })))
const SellerOrders = lazy(() => import('./pages/seller/SellerOrders').then((m) => ({ default: m.SellerOrders })))
const SellerReviews = lazy(() => import('./pages/seller/SellerReviews'))
const SellerProductForm = lazy(() => import('./pages/seller/SellerProductForm'))
const SellerProfile = lazy(() => import('./pages/seller/SellerProfile'))
const ErpOverview = lazy(() => import('./pages/seller/erp/ErpOverview'))
const ErpInventory = lazy(() => import('./pages/seller/erp/ErpInventory'))
const ErpItem = lazy(() => import('./pages/seller/erp/ErpItem'))
const ErpMovements = lazy(() => import('./pages/seller/erp/ErpMovements'))
const ErpSettings = lazy(() => import('./pages/seller/erp/ErpSettings'))
const ErpWarehouses = lazy(() => import('./pages/seller/erp/ErpWarehouses'))
const ErpWarehouse = lazy(() => import('./pages/seller/erp/ErpWarehouse'))
const ErpTransfer = lazy(() => import('./pages/seller/erp/ErpTransfer'))
const ErpTransferNew = lazy(() => import('./pages/seller/erp/ErpTransfer').then((m) => ({ default: m.ErpTransferNew })))
const ErpSuppliers = lazy(() => import('./pages/seller/erp/ErpSuppliers'))
const ErpSupplier = lazy(() => import('./pages/seller/erp/ErpSupplier'))
const ErpSupplierNew = lazy(() => import('./pages/seller/erp/ErpSupplier').then((m) => ({ default: m.ErpSupplierNew })))
const ErpPurchases = lazy(() => import('./pages/seller/erp/ErpPurchases'))
const ErpPurchase = lazy(() => import('./pages/seller/erp/ErpPurchase'))
const ErpPurchaseForm = lazy(() => import('./pages/seller/erp/ErpPurchaseForm'))
const ErpPurchasePrint = lazy(() => import('./pages/seller/erp/ErpPurchasePrint'))
const ErpSales = lazy(() => import('./pages/seller/erp/ErpSales'))
const ErpSaleNew = lazy(() => import('./pages/seller/erp/ErpSaleNew'))
const ErpSale = lazy(() => import('./pages/seller/erp/ErpSale'))
const ErpOrderSale = lazy(() => import('./pages/seller/erp/ErpOrderSale'))
const ErpSalePrint = lazy(() => import('./pages/seller/erp/ErpSalePrint'))
const ErpExpenses = lazy(() => import('./pages/seller/erp/ErpExpenses'))
const ErpExpense = lazy(() => import('./pages/seller/erp/ErpExpense'))
const ErpFinance = lazy(() => import('./pages/seller/erp/ErpFinance'))
const ErpReports = lazy(() => import('./pages/seller/erp/ErpReports'))

/** Messages are shared with customers: sellers see them inside the seller area, customers in the shop. */
function SellerShellFor({ children }) {
  const { user } = useAuth()
  return user?.role === 'seller' ? <SellerLayout>{children}</SellerLayout> : children
}

export default function App() {
  return (
    <Routes>
      {/* A printable page without the site around it. */}
      <Route path="seller/erp/purchases/:id/print" element={<RequireRole roles={['seller']}><Suspense fallback={<Spinner />}><ErpPurchasePrint /></Suspense></RequireRole>} />
      <Route path="seller/erp/sales/offline/:id/print" element={<RequireRole roles={['seller']}><Suspense fallback={<Spinner />}><ErpSalePrint /></Suspense></RequireRole>} />
      <Route path="seller/erp/sales/orders/:id/print" element={<RequireRole roles={['seller']}><Suspense fallback={<Spinner />}><ErpSalePrint marketplace /></Suspense></RequireRole>} />
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
        <Route path="messages" element={<RequireRole roles={['customer', 'seller']}><SellerShellFor><Inbox /></SellerShellFor></RequireRole>} />
        <Route path="messages/:id" element={<RequireRole roles={['customer', 'seller']}><SellerShellFor><ConversationView /></SellerShellFor></RequireRole>} />
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
          <Route path="profile" element={<SellerProfile />} />
          <Route path="products/new" element={<SellerProductForm />} />
          <Route path="products/:id/edit" element={<SellerProductForm />} />
          <Route path="erp" element={<ErpOverview />} />
          <Route path="erp/inventory" element={<ErpInventory />} />
          <Route path="erp/inventory/:id" element={<ErpItem />} />
          <Route path="erp/movements" element={<ErpMovements />} />
          <Route path="erp/settings" element={<ErpSettings />} />
          <Route path="erp/warehouses" element={<ErpWarehouses />} />
          <Route path="erp/warehouses/:id" element={<ErpWarehouse />} />
          <Route path="erp/transfers/new" element={<ErpTransferNew />} />
          <Route path="erp/transfers/:id" element={<ErpTransfer />} />
          <Route path="erp/suppliers" element={<ErpSuppliers />} />
          <Route path="erp/suppliers/new" element={<ErpSupplierNew />} />
          <Route path="erp/suppliers/:id" element={<ErpSupplier />} />
          <Route path="erp/purchases" element={<ErpPurchases />} />
          <Route path="erp/purchases/new" element={<ErpPurchaseForm />} />
          <Route path="erp/purchases/:id" element={<ErpPurchase />} />
          <Route path="erp/purchases/:id/edit" element={<ErpPurchaseForm />} />
          <Route path="erp/sales" element={<ErpSales />} />
          <Route path="erp/sales/new" element={<ErpSaleNew />} />
          <Route path="erp/sales/offline/:id" element={<ErpSale />} />
          <Route path="erp/sales/orders/:id" element={<ErpOrderSale />} />
          <Route path="erp/expenses" element={<ErpExpenses />} />
          <Route path="erp/expenses/:id" element={<ErpExpense />} />
          <Route path="erp/finance" element={<ErpFinance />} />
          <Route path="erp/reports" element={<ErpReports />} />
        </Route>

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
