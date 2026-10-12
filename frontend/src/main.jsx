import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { BrowserRouter } from 'react-router-dom'
import App from './App'
import { AlertsProvider } from './context/AlertsContext'
import { AuthProvider } from './context/AuthContext'
import { CartProvider } from './context/CartContext'
import { CompareProvider } from './context/CompareContext'
import { ConfigProvider } from './context/ConfigContext'
import { InboxProvider } from './context/InboxContext'
import './index.css'

createRoot(document.getElementById('root')).render(
  <StrictMode>
    <BrowserRouter>
      <ConfigProvider>
        <AuthProvider>
          <InboxProvider>
            <AlertsProvider>
              <CartProvider>
                <CompareProvider>
                  <App />
                </CompareProvider>
              </CartProvider>
            </AlertsProvider>
          </InboxProvider>
        </AuthProvider>
      </ConfigProvider>
    </BrowserRouter>
  </StrictMode>,
)
