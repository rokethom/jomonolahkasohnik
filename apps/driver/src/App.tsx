import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import type { ReactNode } from 'react'
import { create } from 'zustand'
import axios from 'axios'
import Echo from 'laravel-echo'
import Pusher from 'pusher-js'
import { BarChart3, BriefcaseBusiness, Camera, ChevronLeft, Home, Image as ImageIcon, LogOut, MessageCircle, MessageCircleMore, PackageCheck, Paperclip, SendHorizontal, UserRound } from 'lucide-react'
import { setupDriverPush } from './push'

declare global {
  interface Window {
    Pusher?: typeof Pusher
    google?: {
      accounts: {
        id: {
          initialize: (options: { client_id: string; callback: (response: { credential?: string }) => void }) => void
          renderButton: (element: HTMLElement, options: Record<string, string | number | boolean>) => void
        }
      }
    }
  }
}

type View = 'login' | 'dashboard' | 'orders' | 'order-detail' | 'chat' | 'history' | 'request' | 'profile' | 'performance'
type DriverHistoryState = {
  jojoDriverView?: View
}

type OrderStatus = 'pending' | 'accepted' | 'on_delivery' | 'pending_cancel' | 'done' | 'cancelled'

type Driver = {
  id: number
  name: string
  username: string
  phone: string | null
  email?: string | null
  profile_photo_url?: string | null
  role: string
  is_ladies_driver?: boolean
  vehicle_type?: 'motor' | 'mobil' | string | null
  vehicle_seat_rows?: number | null
  is_available?: boolean
  can_receive_orders?: boolean
  deposit_status?: 'paid' | 'unpaid' | string | null
  availability_block_reason?: string | null
  status: 'active' | 'inactive' | 'suspended' | 'suspended_unpaid'
  suspended_until?: string | null
  suspension_reason?: string | null
  suspension_type?: string | null
  oper_handle_count: number
}

type Eligibility = {
  can_accept: boolean
  reason: string | null
  direction_match: boolean
  area_match?: boolean
  active_order_count: number
  max_order: number
}

type Order = {
  id: number
  code: string
  status: OrderStatus
  customer: string
  customerPhone: string | null
  driver?: string | null
  source?: string | null
  service: string
  distanceKm: number
  pickup: string
  pickupLat: number
  pickupLng: number
  destination: string
  destinationLat: number
  destinationLng: number
  directionBearing: number | null
  isMultiOrder: boolean
  price: number
  serviceFee: number
  extraCharge: number
  total: number
  notes: string | null
  detail: string | null
  paymentMethod?: string | null
  paymentLabel?: string | null
  preferredVehicleType?: string | null
  requiredVehicleSeatRows?: number | null
  driverPreference?: string | null
  operHandleStatus?: string | null
  operHandleDriver?: string | null
  operHandleReason?: string | null
  operHandleUpdatedAt?: string | null
  acceptedAt?: string | null
  updatedAt?: string | null
  eligibility?: Eligibility
}

type Toast = { id: number; message: string; tone: 'success' | 'warning' | 'danger' }
type ReplyTarget = {
  id: string
  text: string
}
type PublicSettings = {
  oauth?: {
    google_enabled: boolean
    google_client_id?: string | null
  }
  push?: {
    enabled: boolean
    vapid_key?: string | null
    firebase_config?: Record<string, string> | null
  }
}
type ApiState = { loading: boolean; error: string }
type BeforeInstallPromptEvent = Event & {
  prompt: () => Promise<void>
  userChoice: Promise<{ outcome: 'accepted' | 'dismissed'; platform: string }>
}

type DriverStore = {
  view: View
  token: string
  driver: Driver | null
  orders: Order[]
  branchAcceptedOrders: Order[]
  branchRequestOrders: Order[]
  branchOperHandleOrders: Order[]
  branchSuspendHistory: BranchSuspendHistory[]
  selectedOrderId: number | null
  chatTarget: 'order' | 'operator'
  isOnline: boolean
  multiOrderEnabled: boolean
  maxMultiOrder: number
  finance: DriverFinance | null
  performance: DriverPerformance | null
  toasts: Toast[]
  setView: (view: View) => void
  openOperatorChat: () => void
  setToken: (token: string) => void
  setBootstrap: (payload: BootstrapResponse) => void
  updateOrder: (order: Partial<ApiOrder> & { id: number }) => void
  setDriverState: (driver: Driver, finance?: DriverFinance | null) => void
  logout: () => void
  selectOrder: (orderId: number) => void
  setOnline: (online: boolean) => void
  toast: (message: string, tone?: Toast['tone']) => void
  dismissToast: (id: number) => void
}

type BootstrapResponse = {
  driver: Driver
  settings: { multi_order_enabled: boolean; max_multi_order: number }
  finance?: DriverFinance
  performance?: DriverPerformance
  orders: ApiOrder[]
  branch_accepted_orders?: ApiOrder[]
  branch_request_orders?: ApiOrder[]
  branch_oper_handle_orders?: ApiOrder[]
  branch_suspend_history?: BranchSuspendHistory[]
}

type DriverFinance = {
  total: number
  status: string
  due_date?: string | null
  paid_amount: number
  paid_at?: string | null
  remaining?: number
  period_label?: string
  current_period_deposit?: number
  previous_deposit?: {
    month?: number
    year?: number
    total: number
    paid_amount: number
    remaining: number
    status: string
    due_date?: string | null
    paid_at?: string | null
    period_label?: string
    current_period_deposit?: number
    breakdown?: Record<string, number>
  }
  breakdown?: Record<string, number>
}

type DriverPerformance = {
  rating: number
  ratings_count: number
  completed_orders_count?: number
  cancelled_orders_count?: number
  today_completed_orders_count?: number
  month_revenue?: number
  today_revenue?: number
  period_label?: string
  setoran?: DriverFinance
  suspend_history?: Array<{ id: number; type?: string; reason: string; status: string; start_at?: string; end_at?: string }>
  oper_handle?: Array<{ id: number; status: string; reason?: string | null; created_at?: string }>
}

type BranchSuspendHistory = {
  id: number
  driver?: string | null
  type?: string | null
  reason: string
  status: string
  start_at?: string | null
  end_at?: string | null
  updated_at?: string | null
}

type ApiOrder = {
  id: number
  code: string
  order_code?: string
  status: string
  customer: string
  customer_phone: string | null
  driver?: string | null
  source?: string | null
  service: string
  distance_km: number
  pickup: string
  pickup_lat: number
  pickup_lng: number
  destination: string
  destination_lat: number
  destination_lng: number
  direction_bearing: number | null
  is_multi_order: boolean
  price: number
  service_fee: number
  service_charge?: number
  extra_charge: number
  total: number
  total_price?: number
  notes: string | null
  detail: string | null
  payment_method?: string | null
  payment_label?: string | null
  preferred_vehicle_type?: string | null
  required_vehicle_seat_rows?: number | null
  driver_preference?: string | null
  oper_handle_status?: string | null
  oper_handle_driver?: string | null
  oper_handle_reason?: string | null
  oper_handle_updated_at?: string | null
  payment_meta?: Record<string, unknown> | null
  accepted_at?: string | null
  updated_at?: string | null
  eligibility?: Eligibility
}

type ChatConversation = {
  id: number
  order_id: number | null
  type: string
  status: string
}

type ChatMessage = {
  id: number
  sender_id: number | null
  sender_type: string
  message: string | null
  image_url?: string | null
  audio_url?: string | null
  created_at?: string
  sender?: { id: number; name: string } | null
}

function resolveApiBase() {
  const configured = import.meta.env.VITE_API_BASE_URL ?? import.meta.env.VITE_API_URL ?? 'http://127.0.0.1:8000/api'
  const isPublicHost = !['localhost', '127.0.0.1', '::1'].includes(window.location.hostname)
  const pointsToLocalhost = /^https?:\/\/(localhost|127\.0\.0\.1)(:\d+)?\/api\/?$/i.test(configured)

  if (isPublicHost && pointsToLocalhost) {
    return window.location.hostname.endsWith('aplikasijoker.my.id')
      ? 'https://aplikasijoker.my.id/api'
      : `${window.location.origin}/api`
  }

  return configured
}

const API_BASE = resolveApiBase()
const APP_BASE = API_BASE.replace(/\/api$/, '')
const pricePattern = /(\d+(?:[.,]\d+)?)\s*k\b/gi
const driverViews: View[] = ['login', 'dashboard', 'orders', 'order-detail', 'chat', 'history', 'request', 'profile', 'performance']

function viewFromHistoryState(state: unknown) {
  const maybeState = state as DriverHistoryState | null
  const value = maybeState?.jojoDriverView
  return value && driverViews.includes(value) ? value : null
}

function guardViewForSession(view: View, token?: string | null): View {
  if (!token) return 'login'
  return view === 'login' ? 'dashboard' : view
}

function viewFromNotificationTarget() {
  const params = new URLSearchParams(window.location.search)
  const open = params.get('open')
  const type = params.get('notification_type') ?? params.get('type')

  if (open === 'orders' || type === 'new_order' || type === 'dispatcher_broadcast_order') return 'orders' as View
  return null
}

const useDriverStore = create<DriverStore>((set, get) => ({
  view: localStorage.getItem('driver_token') ? 'dashboard' : 'login',
  token: localStorage.getItem('driver_token') || '',
  driver: null,
  orders: [],
  branchAcceptedOrders: [],
  branchRequestOrders: [],
  branchOperHandleOrders: [],
  branchSuspendHistory: [],
  selectedOrderId: null,
  chatTarget: 'order',
  isOnline: true,
  multiOrderEnabled: false,
  maxMultiOrder: 1,
  finance: null,
  performance: null,
  toasts: [],
  setView: (view) => set({ view, ...(view === 'chat' ? { chatTarget: 'order' as const } : {}) }),
  openOperatorChat: () => set({ selectedOrderId: null, chatTarget: 'operator', view: 'chat' }),
  setToken: (token) => {
    localStorage.setItem('driver_token', token)
    set({ token, view: 'dashboard' })
  },
  setBootstrap: (payload) => set({
    driver: payload.driver,
    orders: payload.orders.map(mapOrder),
    branchAcceptedOrders: (payload.branch_accepted_orders ?? []).map(mapOrder),
    branchRequestOrders: (payload.branch_request_orders ?? []).map(mapOrder),
    branchOperHandleOrders: (payload.branch_oper_handle_orders ?? []).map(mapOrder),
    branchSuspendHistory: payload.branch_suspend_history ?? [],
    multiOrderEnabled: payload.settings.multi_order_enabled,
    maxMultiOrder: payload.settings.max_multi_order,
    finance: payload.finance ?? null,
    performance: payload.performance ?? null,
    isOnline: Boolean(payload.driver.is_available),
  }),
  updateOrder: (order) => set((state) => ({
    orders: state.orders.map((item) => item.id === order.id ? { ...item, ...mapOrderPatch(order) } : item),
  })),
  setDriverState: (driver, finance) => set({
    driver,
    ...(finance !== undefined ? { finance } : {}),
    isOnline: Boolean(driver.is_available),
  }),
  logout: () => {
    resetDriverEcho()
    localStorage.removeItem('driver_token')
    set({ token: '', driver: null, orders: [], branchAcceptedOrders: [], branchRequestOrders: [], branchOperHandleOrders: [], branchSuspendHistory: [], selectedOrderId: null, chatTarget: 'order', view: 'login' })
  },
  selectOrder: (orderId) => set({ selectedOrderId: orderId, chatTarget: 'order', view: 'order-detail' }),
  setOnline: (online) => set({ isOnline: online }),
  toast: (message, tone = 'success') => {
    const id = Date.now()
    set((state) => ({ toasts: [...state.toasts, { id, message, tone }] }))
    window.setTimeout(() => get().dismissToast(id), 3200)
  },
  dismissToast: (id) => set((state) => ({ toasts: state.toasts.filter((toast) => toast.id !== id) })),
}))

function App() {
  const { view, token, driver, orders, branchAcceptedOrders, branchRequestOrders, branchOperHandleOrders, branchSuspendHistory, selectedOrderId, chatTarget, toasts, setBootstrap, updateOrder, setView, toast, logout } = useDriverStore()
  const [apiState, setApiState] = useState<ApiState>({ loading: false, error: '' })
  const [publicSettings, setPublicSettings] = useState<PublicSettings | null>(null)
  const isBrowserBackRef = useRef(false)
  const selectedOrder = orders.find((order) => order.id === selectedOrderId) ?? null
  const chatOrder = chatTarget === 'operator' ? null : selectedOrder ?? orders.find((order) => order.status === 'accepted' || order.status === 'on_delivery') ?? null

  const api = useMemo(() => makeApi(token), [token])

  useEffect(() => {
    const initialView = guardViewForSession(viewFromNotificationTarget() ?? viewFromHistoryState(window.history.state) ?? view, useDriverStore.getState().token)
    window.history.replaceState(
      { ...(window.history.state as DriverHistoryState | null), jojoDriverView: initialView },
      document.title,
      '/',
    )
    if (initialView !== view) setView(initialView)

    const handlePopState = (event: PopStateEvent) => {
      const nextView = guardViewForSession(
        viewFromHistoryState(event.state) ?? 'dashboard',
        useDriverStore.getState().token,
      )

      isBrowserBackRef.current = true
      setView(nextView)
    }

    window.addEventListener('popstate', handlePopState)

    return () => {
      window.removeEventListener('popstate', handlePopState)
    }
  }, [])

  useEffect(() => {
    const nextView = guardViewForSession(view, token)
    if (nextView !== view) {
      setView(nextView)
      return
    }

    if (isBrowserBackRef.current) {
      isBrowserBackRef.current = false
      if (viewFromHistoryState(window.history.state) !== view) {
        window.history.replaceState(
          { ...(window.history.state as DriverHistoryState | null), jojoDriverView: view },
          document.title,
          '/',
        )
      }
      return
    }

    if (viewFromHistoryState(window.history.state) === view) return

    window.history.pushState(
      { ...(window.history.state as DriverHistoryState | null), jojoDriverView: view },
      document.title,
      '/',
    )
  }, [setView, token, view])

  const load = useCallback(async (silent = false) => {
    if (!token) return null
    if (!silent) setApiState({ loading: true, error: '' })
    let loaded = false
    try {
      const payload = await api<BootstrapResponse>('/driver/bootstrap')
      setBootstrap(payload)
      loaded = true
      return payload
    } catch (error) {
      const message = getErrorMessage(error, '')
      if (/401|403|unauthenticated|unauthorized/i.test(message)) {
        logout()
        toast('Silakan login sebagai driver', 'warning')
        return null
      }
      setApiState({ loading: false, error: getErrorMessage(error, 'Gagal memuat data driver') })
      return null
    } finally {
      if (!silent && loaded) setApiState({ loading: false, error: '' })
    }
  }, [api, logout, setBootstrap, toast, token])

  useEffect(() => {
    const timer = window.setTimeout(() => void load(), 0)
    return () => window.clearTimeout(timer)
  }, [load])

  useEffect(() => {
    void fetch(`${API_BASE}/settings/public`, { headers: { Accept: 'application/json' } })
      .then((response) => response.ok ? response.json() : null)
      .then((payload) => setPublicSettings(payload?.data ?? null))
      .catch(() => undefined)
  }, [])

  useEffect(() => {
    if (!token || !publicSettings) return

    void setupDriverPush(API_BASE, token, publicSettings)
      .then((result) => {
        if (result.status === 'error' && result.message) toast(result.message, 'danger')
      })
      .catch((error) => {
        toast(getErrorMessage(error, 'FCM gagal membuat device token.'), 'danger')
      })
  }, [publicSettings, toast, token])

  useEffect(() => {
    if (!token) return

    const refresh = () => void load(true)
    const interval = window.setInterval(refresh, 10000)
    const onVisible = () => {
      if (document.visibilityState === 'visible') refresh()
    }

    window.addEventListener('focus', refresh)
    document.addEventListener('visibilitychange', onVisible)

    return () => {
      window.clearInterval(interval)
      window.removeEventListener('focus', refresh)
      document.removeEventListener('visibilitychange', onVisible)
    }
  }, [load, token])

  useEffect(() => {
    if (!token || !driver || driver.role.toLowerCase() === 'admin') return

    const sendLocation = () => {
      if (!navigator.geolocation) return

      navigator.geolocation.getCurrentPosition(
        (position) => {
          void api('/user/location', {
            method: 'POST',
            body: JSON.stringify({
              lat: position.coords.latitude,
              lng: position.coords.longitude,
              accuracy: position.coords.accuracy,
              gps_timestamp: new Date(position.timestamp).toISOString(),
            }),
          }).catch(() => undefined)
        },
        () => undefined,
        { enableHighAccuracy: true, timeout: 9000, maximumAge: 60_000 },
      )
    }

    sendLocation()
    const interval = window.setInterval(sendLocation, 5 * 60_000)

    return () => window.clearInterval(interval)
  }, [api, driver, token])

  useEffect(() => {
    if (!token) return

    const channel = makeEcho(token).private('orders')
    channel.listen('.order.price.updated', (event: { order?: Partial<ApiOrder> & { id: number; code?: string } }) => {
      if (!event.order?.id) return
      updateOrder(event.order)
      toast(`Harga order ${event.order.code ?? event.order.order_code ?? ''} diperbarui admin`, 'warning')
    })
    channel.listen('.order.created', (event: { order?: ApiOrder }) => {
      if (!event.order?.id) return
      const state = useDriverStore.getState()
      if (!canReceiveRealtimeOrder(state.driver, state.finance, state.isOnline)) return
      void load(true).then((payload) => {
        const visibleOrder = payload?.orders.some((order) => order.id === event.order?.id)
        if (visibleOrder) toast(`Order baru ${event.order?.code ?? event.order?.order_code ?? ''} masuk`, 'success')
      })
    })
    channel.listen('.driver.accepted', () => {
      void load(true)
    })
    channel.listen('.order.status.updated', (event: { order?: Partial<ApiOrder> & { id: number; code?: string }; new_status?: string }) => {
      if (!event.order?.id) return
      const currentState = useDriverStore.getState()
      const knownOrder = currentState.orders.some((order) => order.id === event.order?.id)
      const newStatus = normalizeStatus(event.new_status ?? event.order.status ?? '')
      updateOrder(event.order)
      if (!knownOrder && newStatus === 'pending' && canReceiveRealtimeOrder(currentState.driver, currentState.finance, currentState.isOnline)) {
        void load(true).then((payload) => {
          const visibleOrder = payload?.orders.some((order) => order.id === event.order?.id)
          if (visibleOrder) toast(`Order ${event.order?.code ?? event.order?.order_code ?? ''} kembali terbuka untuk driver`, 'warning')
        })
        return
      }
      if (knownOrder && isDoneStatus(event.new_status ?? event.order.status)) {
        toast(`Order ${event.order.code ?? event.order.order_code ?? ''} selesai`, 'success')
      }
    })

    return () => {
      makeEcho(token).leave('orders')
    }
  }, [load, toast, token, updateOrder])

  const action = async (work: () => Promise<unknown>, success: string) => {
    try {
      await work()
      toast(success, 'success')
      await load()
    } catch (error) {
      toast(getErrorMessage(error, 'Action gagal'), 'danger')
    }
  }

  if (view === 'login') return <><LoginScreen publicSettings={publicSettings} onLoggedIn={() => void load()} /><ToastStack toasts={toasts} /></>
  if (apiState.loading && !driver) return <Shell><SkeletonPage /></Shell>
  if (apiState.error && !driver) return <Shell><ErrorState message={apiState.error} onRetry={load} /></Shell>
  if (!driver) return null

  return (
    <Shell>
      <ToastStack toasts={toasts} />
      {view === 'dashboard' && <Dashboard driver={driver} orders={orders} branchAcceptedOrders={branchAcceptedOrders} branchRequestOrders={branchRequestOrders} branchOperHandleOrders={branchOperHandleOrders} branchSuspendHistory={branchSuspendHistory} loading={apiState.loading} api={api} onAction={action} />}
      {view === 'orders' && <OrderList orders={orders} loading={apiState.loading} api={api} onAction={action} />}
      {view === 'order-detail' && selectedOrder && <OrderDetail order={selectedOrder} api={api} onAction={action} />}
      {view === 'chat' && <ChatScreen order={chatOrder} api={api} mode={chatTarget} />}
      {view === 'history' && <History orders={orders} loading={apiState.loading} />}
      {view === 'request' && <RequestOrder onCreated={async () => { await load() }} />}
      {view === 'profile' && <Profile driver={driver} api={api} onSaved={async () => { await load() }} />}
      {view === 'performance' && <PerformancePage />}
      <BottomNav active={view} onNavigate={setView} />
    </Shell>
  )
}

function Shell({ children }: { children: ReactNode }) {
  return <main className="app-shell">{children}</main>
}

function LoginScreen({ publicSettings, onLoggedIn }: { publicSettings: PublicSettings | null; onLoggedIn: () => void }) {
  const setToken = useDriverStore((state) => state.setToken)
  const toast = useDriverStore((state) => state.toast)
  const [loading, setLoading] = useState(false)
  const googleButtonRef = useRef<HTMLDivElement | null>(null)
  const googleClientId = import.meta.env.VITE_GOOGLE_CLIENT_ID ?? publicSettings?.oauth?.google_client_id ?? ''

  useEffect(() => {
    if (!googleClientId || !googleButtonRef.current) return

    const setupGoogleButton = () => {
      if (!window.google || !googleButtonRef.current) return

      window.google.accounts.id.initialize({
        client_id: googleClientId,
        callback: async ({ credential }) => {
          if (!credential) {
            toast('Google tidak mengembalikan token login.', 'danger')
            return
          }

          setLoading(true)
          try {
            const { data } = await axios.post(`${API_BASE}/driver/auth/google`, {
              token: credential,
              device_name: navigator.userAgent.slice(0, 100),
            })
            setToken(data.token)
            onLoggedIn()
          } catch (error) {
            toast(getLoginErrorMessage(error), 'danger')
          } finally {
            setLoading(false)
          }
        },
      })

      googleButtonRef.current.innerHTML = ''
      window.google.accounts.id.renderButton(googleButtonRef.current, {
        theme: 'outline',
        size: 'large',
        width: 320,
        text: 'signin_with',
        shape: 'rectangular',
      })
    }

    const existingScript = document.querySelector<HTMLScriptElement>('script[src="https://accounts.google.com/gsi/client"]')
    if (existingScript) {
      if (window.google) setupGoogleButton()
      else existingScript.addEventListener('load', setupGoogleButton, { once: true })
      return
    }

    const script = document.createElement('script')
    script.src = 'https://accounts.google.com/gsi/client'
    script.async = true
    script.defer = true
    script.addEventListener('load', setupGoogleButton, { once: true })
    document.head.appendChild(script)
  }, [googleClientId, onLoggedIn, setToken, toast])

  return (
    <main className="login-screen">
      <section className="login-hero">
        <div className="brand-mark">JO</div>
        <h1>Jojo Driver</h1>
        <p>Kelola order aktif, chat, dan perjalanan dari satu dashboard.</p>
        <PwaInstallButton />
      </section>
      <section className="panel login-card">
        {googleClientId ? (
          <>
            <div className="google-login-slot" ref={googleButtonRef} />
            {loading && <p className="login-hint">Memverifikasi akun Google...</p>}
          </>
        ) : (
          <p className="login-hint">Login Google driver belum dikonfigurasi.</p>
        )}
      </section>
    </main>
  )
}

function Dashboard({ driver, orders, branchAcceptedOrders, branchRequestOrders, branchOperHandleOrders, branchSuspendHistory, loading, api, onAction }: { driver: Driver; orders: Order[]; branchAcceptedOrders: Order[]; branchRequestOrders: Order[]; branchOperHandleOrders: Order[]; branchSuspendHistory: BranchSuspendHistory[]; loading: boolean; api: ApiClient; onAction: (work: () => Promise<unknown>, success: string) => Promise<void> }) {
  const { isOnline, setDriverState, setView, maxMultiOrder, finance, performance, toast } = useDriverStore()
  const [financeOpen, setFinanceOpen] = useState(false)
  const [financeMode, setFinanceMode] = useState<'billing' | 'running'>('billing')
  const [availabilitySaving, setAvailabilitySaving] = useState(false)
  const activeOrders = orders.filter(isActiveOrder)
  const canReceiveOrders = canReceiveRealtimeOrder(driver, finance, isOnline)
  const pendingOrders = canReceiveOrders ? orders.filter((order) => order.status === 'pending') : []
  const acceptedTotal = orders.filter((order) => order.status !== 'pending').length
  const previousDeposit = finance?.previous_deposit
  const previousTotal = Number(previousDeposit?.total ?? 0)
  const previousRemaining = Number(previousDeposit?.remaining ?? 0)
  const currentPeriodDeposit = Number(finance?.current_period_deposit ?? finance?.breakdown?.setoran_hingga_hari_ini ?? 0)
  const currentRunningTotal = Number(finance?.total ?? 0)
  const previousPeriodLabel = previousDeposit?.period_label ?? 'bulan lalu'
  const currentPeriodLabel = finance?.period_label ?? 'bulan ini'
  const availabilityCopy = driver.availability_block_reason
    ?? (canReceiveOrders ? 'Order baru dan request order aktif saat tersedia.' : 'OFF: order baru dan request order nonaktif.')

  const updateAvailability = async (online: boolean) => {
    if (availabilitySaving) return
    setAvailabilitySaving(true)
    try {
      const payload = await api<{ message: string; driver: Driver; finance?: DriverFinance }>('/driver/availability', {
        method: 'POST',
        body: JSON.stringify({ online }),
      })
      setDriverState(payload.driver, payload.finance ?? finance)
      toast(payload.message, online ? 'success' : 'warning')
    } catch (error) {
      toast(getErrorMessage(error, 'Gagal mengubah status driver'), 'danger')
    } finally {
      setAvailabilitySaving(false)
    }
  }

  return (
    <section className="page dashboard">
      <header className="top-card">
        <div>
          <span className="eyebrow">Driver Dashboard</span>
          <h1>Halo, {driver.name}</h1>
          <p>{driver.status === 'active' ? 'Siap ambil order hari ini' : suspendReasonText(driver)}</p>
          <PwaInstallButton />
        </div>
        <div className="top-card-brand">
          <img src="/logo.png" alt="Jojo Driver" />
          <StatusPill driver={driver} />
        </div>
      </header>

      {driver.status !== 'active' && <SuspendBanner driver={driver} />}

      <section className="online-card panel">
        <div>
          <strong>{isOnline ? 'Online' : 'Offline'}</strong>
          <span>{activeOrders.length}/{maxMultiOrder} order aktif · {driver.deposit_status ?? 'sync'}</span>
          <small>{availabilityCopy}</small>
        </div>
        <label className="switch"><input checked={isOnline} disabled={availabilitySaving} onChange={(event) => void updateAvailability(event.target.checked)} type="checkbox" /><span /></label>
      </section>

      <button className="month-income-card panel" type="button" onClick={() => setView('performance')}>
        <span>Total pendapatan bulan ini</span>
        <strong>Rp {formatMoney(performance?.month_revenue ?? 0)}</strong>
        <small>{performance?.period_label ?? 'Performa driver bulan ini'} - hari ini Rp {formatMoney(performance?.today_revenue ?? 0)}</small>
      </button>

      <section className="stats-grid">
        <button className="metric setoran-card" onClick={() => { setFinanceMode('billing'); setFinanceOpen(true) }}>
          <span>TAGIHAN BULAN {previousPeriodLabel.toUpperCase()}</span>
          <strong>Rp {formatMoney(previousRemaining)}</strong>
          <small>{previousDeposit?.status ?? 'paid'} - total Rp {formatMoney(previousTotal)}{previousRemaining <= 0 && previousDeposit?.paid_at ? ` - dibayar ${formatDepositPaidAt(previousDeposit.paid_at)}` : ''}</small>
        </button>
        <button className="metric setoran-card" onClick={() => { setFinanceMode('running'); setFinanceOpen(true) }}>
          <span>TOTAL BULAN INI BERJALAN</span>
          <strong>Rp {formatMoney(currentRunningTotal)}</strong>
          <small>{currentPeriodLabel} - dasar Rp {formatMoney(currentPeriodDeposit)}{previousRemaining > 0 ? ` + sisa ${previousPeriodLabel}` : ''}</small>
        </button>
        <Metric label="Order diterima" value={acceptedTotal} />
        <Metric label="Order aktif" value={activeOrders.length} />
      </section>

      <section className="quick-grid">
        <button className="primary-button" disabled={!isOnline || driver.status !== 'active'} onClick={() => setView('request')}>Request Order</button>
        <button className="secondary-button" onClick={() => setView('history')}>Riwayat</button>
      </section>

      {activeOrders.length > 0 && <ActiveOrderRoute orders={activeOrders} max={maxMultiOrder} />}

      <section>
        <SectionTitle title="List Order" action={loading ? 'Sync' : `${pendingOrders.length} order`} />
        {loading && <SkeletonCards />}
        {!loading && pendingOrders.length === 0 && <EmptyState title="Belum ada order" copy="Order baru akan tampil di sini." />}
        {pendingOrders.slice(0, 3).map((order) => <OrderCard key={order.id} order={order} api={api} onAction={onAction} />)}
      </section>
      <BranchAcceptedFeed orders={branchAcceptedOrders} requestOrders={branchRequestOrders} operHandleOrders={branchOperHandleOrders} suspendHistory={branchSuspendHistory} />
      {financeOpen && finance && <SetoranModal finance={finance} mode={financeMode} onClose={() => setFinanceOpen(false)} />}
    </section>
  )
}

function BranchAcceptedFeed({
  orders,
  requestOrders,
  operHandleOrders,
  suspendHistory,
}: {
  orders: Order[]
  requestOrders: Order[]
  operHandleOrders: Order[]
  suspendHistory: BranchSuspendHistory[]
}) {
  const [openPanel, setOpenPanel] = useState<'accepted' | 'request' | 'oper' | 'suspend' | null>(null)
  const visible = orders.filter((order) => order.driver && order.source !== 'driver_request').slice(0, 6)
  const requestVisible = requestOrders.slice(0, 8)
  const operVisible = operHandleOrders.filter((order) => order.operHandleStatus).slice(0, 6)
  const suspendVisible = suspendHistory.slice(0, 6)
  const ladiesCount = visible.filter((order) => order.driverPreference === 'ladies').length
  const togglePanel = (panel: 'accepted' | 'request' | 'oper' | 'suspend') => setOpenPanel((current) => current === panel ? null : panel)

  return (
    <section className="branch-feed panel">
      <div className="branch-feed-head">
        <div>
          <span>Monitor area</span>
          <h2>Order diterima area</h2>
        </div>
        <strong>{visible.length + requestVisible.length + operVisible.length + suspendVisible.length} catatan</strong>
      </div>

      <div className="branch-monitor-grid">
        <button className={`branch-monitor-card ${openPanel === 'accepted' ? 'active' : ''}`} type="button" onClick={() => togglePanel('accepted')}>
          <span>Order diterima</span>
          <strong>{visible.length}</strong>
          <small>{ladiesCount > 0 ? `${ladiesCount} Ladies` : 'Area cabang'}</small>
        </button>
        <button className={`branch-monitor-card request ${openPanel === 'request' ? 'active' : ''}`} type="button" onClick={() => togglePanel('request')}>
          <span>Request order</span>
          <strong>{requestVisible.length}</strong>
          <small>Driver cabang</small>
        </button>
        <button className={`branch-monitor-card oper ${openPanel === 'oper' ? 'active' : ''}`} type="button" onClick={() => togglePanel('oper')}>
          <span>Oper handle</span>
          <strong>{operVisible.length}</strong>
          <small>Area cabang</small>
        </button>
        <button className={`branch-monitor-card suspend ${openPanel === 'suspend' ? 'active' : ''}`} type="button" onClick={() => togglePanel('suspend')}>
          <span>History suspend</span>
          <strong>{suspendVisible.length}</strong>
          <small>Driver cabang</small>
        </button>
      </div>

      {openPanel === 'accepted' && (
        <div className="branch-detail-list">
          {visible.length === 0 && <p className="note">Belum ada order area yang diterima driver.</p>}
          {visible.map((order) => (
            <article className="branch-accepted-card" key={order.id}>
              <div className="branch-accepted-icon">{driverInitial(order.driver)}</div>
              <div className="branch-accepted-main">
                <strong>{order.code}</strong>
                <span>
                  {order.driver} menerima order {order.service}
                  {order.driverPreference === 'ladies' && <em className="ladies-chip">Ladies</em>}
                </span>
                <small>{shortAddress(order.pickup)} menuju {shortAddress(order.destination)}</small>
              </div>
              <time>{formatHistoryTime(order.updatedAt ?? order.acceptedAt)}</time>
            </article>
          ))}
        </div>
      )}

      {openPanel === 'request' && (
        <div className="branch-detail-list">
          {requestVisible.length === 0 && <p className="note">Belum ada request order driver di cabang kamu.</p>}
          {requestVisible.map((order) => (
            <article className="branch-accepted-card request-order-card" key={`request-${order.id}`}>
              <div className="branch-accepted-icon request">{driverInitial(order.driver ?? order.customer)}</div>
              <div className="branch-accepted-main">
                <strong>{order.code}</strong>
                <span>{order.driver || order.customer || 'Driver'} request order {order.service}</span>
                <small>Rp {formatMoney(order.total)} - {shortAddress(order.pickup)} menuju {shortAddress(order.destination)}</small>
              </div>
              <time>{formatHistoryTime(order.updatedAt ?? order.acceptedAt)}</time>
            </article>
          ))}
        </div>
      )}

      {openPanel === 'oper' && (
        <div className="branch-detail-list">
          {operVisible.length === 0 && <p className="note">Belum ada oper handle area.</p>}
          {operVisible.map((order) => (
            <article className={`branch-accepted-card oper-handle ${order.operHandleStatus === 'approved' ? 'approved' : ''}`} key={`oper-${order.id}-${order.operHandleStatus}`}>
              <div className="branch-accepted-icon oper">{driverInitial(order.operHandleDriver ?? order.driver)}</div>
              <div className="branch-accepted-main">
                <strong>{order.code}</strong>
                <span>
                  {operHandleStatusText(order)}
                  <em className="oper-handle-chip">{operHandleStatusLabel(order.operHandleStatus)}</em>
                </span>
                {order.operHandleReason && <small>Alasan: {order.operHandleReason}</small>}
              </div>
              <time>{formatHistoryTime(order.operHandleUpdatedAt ?? order.updatedAt)}</time>
            </article>
          ))}
        </div>
      )}

      {openPanel === 'suspend' && (
        <div className="branch-detail-list">
          {suspendVisible.length === 0 && <p className="note">Belum ada history suspend.</p>}
          {suspendVisible.map((item) => (
            <article className="branch-accepted-card suspend-history-card" key={`suspend-${item.id}`}>
              <div className="branch-accepted-icon suspend">{driverInitial(item.type ?? 'S')}</div>
              <div className="branch-accepted-main">
                <strong>{item.driver ?? 'Driver'} - {item.type ?? 'Suspend'}</strong>
                <span>{item.reason || 'Tidak ada alasan suspend.'}</span>
                <small>Status: {item.status}</small>
              </div>
              <time>{formatHistoryTime(item.end_at ?? item.start_at)}</time>
            </article>
          ))}
        </div>
      )}
    </section>
  )
}

function SetoranModal({ finance, mode, onClose }: { finance: DriverFinance; mode: 'billing' | 'running'; onClose: () => void }) {
  const billing = mode === 'billing' ? billingDepositFor(finance) : finance
  const rows = setoranBreakdownRows(billing.breakdown, billing.period_label, mode)
  const remaining = Math.max(0, billing.total - billing.paid_amount)
  return (
    <Modal title={mode === 'billing' ? 'Detail Setoran' : 'Detail Tagihan Berjalan'} onClose={onClose}>
      <div className="deposit-summary">
        <span>
          <small>Jatuh tempo</small>
          <strong>{formatDepositDueDate(billing.due_date)}</strong>
        </span>
        <span className={`deposit-status ${String(billing.status ?? '').toLowerCase()}`}>
          <small>Status</small>
          <strong>{billing.status ?? '-'}</strong>
        </span>
      </div>
      <div className="setoran-breakdown">
        {rows.map(([label, value]) => <PriceRow key={label} label={label} value={value} />)}
        <div className="total-row"><span>{mode === 'billing' ? `Total tagihan ${billing.period_label ?? 'bulan sebelumnya'}` : 'Total tagihan berjalan'}</span><strong>Rp {formatMoney(billing.total)}</strong></div>
        <PaidAmountRow value={billing.paid_amount} paidAt={billing.paid_at} />
        <div className="total-row"><span>Sisa tagihan</span><strong>Rp {formatMoney(remaining)}</strong></div>
      </div>
    </Modal>
  )
}

function billingDepositFor(finance: DriverFinance): DriverFinance {
  if (!finance.previous_deposit) return finance

  return {
    ...finance,
    total: finance.previous_deposit.total,
    status: finance.previous_deposit.status,
    due_date: finance.previous_deposit.due_date,
    paid_amount: finance.previous_deposit.paid_amount,
    paid_at: finance.previous_deposit.paid_at,
    remaining: finance.previous_deposit.remaining,
    period_label: finance.previous_deposit.period_label,
    current_period_deposit: finance.previous_deposit.current_period_deposit,
    breakdown: finance.previous_deposit.breakdown,
  }
}

function PaidAmountRow({ value, paidAt }: { value: number; paidAt?: string | null }) {
  return (
    <div className="price-row paid-amount-row">
      <span>
        Sudah dibayar
        <small>{paidAt ? `Tanggal bayar: ${formatDepositPaidAt(paidAt)}` : 'Belum ada tanggal pembayaran'}</small>
      </span>
      <strong>Rp {formatMoney(value)}</strong>
    </div>
  )
}

function OrderList({ orders, loading, api, onAction }: { orders: Order[]; loading: boolean; api: ApiClient; onAction: (work: () => Promise<unknown>, success: string) => Promise<void> }) {
  const { driver, finance, isOnline } = useDriverStore()
  const canReceiveOrders = canReceiveRealtimeOrder(driver, finance, isOnline)
  const visibleOrders = useMemo(
    () => orders.filter((order) => isActiveOrder(order) || (canReceiveOrders && order.status === 'pending')).sort(sortNewestOrderFirst),
    [canReceiveOrders, orders],
  )

  return (
    <section className="page">
      <PageTitle title="Order List" subtitle="Order aktif dan terbaru untuk driver." />
      {!canReceiveOrders && <div className="notice-card warning">Status OFF atau rule setoran/suspend aktif. Order baru tidak ditampilkan.</div>}
      {loading && <SkeletonCards />}
      {!loading && visibleOrders.length === 0 && <EmptyState title="Kosong" copy="Belum ada order aktif atau order baru." />}
      {visibleOrders.map((order) => <OrderCard key={order.id} order={order} api={api} onAction={onAction} />)}
    </section>
  )
}

function OrderCard({ order, api, onAction }: { order: Order; api: ApiClient; onAction: (work: () => Promise<unknown>, success: string) => Promise<void> }) {
  const { selectOrder } = useDriverStore()
  const hasActiveOrders = (order.eligibility?.active_order_count ?? 0) > 0
  const directionMatch = order.eligibility?.direction_match ?? true
  const route = routeInfoFor(order)

  return (
    <article className="order-card panel fade-in">
      <button className="card-hit" onClick={() => selectOrder(order.id)} aria-label={`Buka ${order.code}`} />
      <header>
        <div><strong>{order.customer}</strong><span>{shortAddress(route.pickupAddress)}</span></div>
        <b>Rp {formatMoney(order.total)}</b>
      </header>
      <div className="order-meta">
        <span className="badge"><PackageCheck size={13} />{order.service}</span>
        {order.preferredVehicleType && (
          <span className="direction-badge vehicle">
            {driverVehicleLabel(order)}
          </span>
        )}
        <span>{statusLabel(order.status)}</span>
        {order.driverPreference === 'ladies' && <span className="direction-badge ladies">LADIES</span>}
        {order.eligibility?.area_match === false && <span className="direction-badge mismatch">LUAR AREA</span>}
        {hasActiveOrders && <span className={directionMatch ? 'direction-badge match' : 'direction-badge mismatch'}>{directionMatch ? 'SEARAH' : 'TIDAK SEARAH'}</span>}
      </div>
      <div className="route-strip"><span>{shortAddress(route.pickupAddress)}</span><b>→</b><span>{shortAddress(route.destinationAddress)}</span></div>
      <textarea value={order.detail || order.notes || ''} readOnly placeholder="Tidak ada detail" />
      {order.eligibility?.can_accept === false && <p className="eligibility-note">{eligibilityReason(order.eligibility.reason)}</p>}
      <footer>
        <button className="secondary-button" onClick={(event) => { event.stopPropagation(); selectOrder(order.id) }}>Detail</button>
        <button className="primary-button" disabled={order.eligibility?.can_accept === false || order.status !== 'pending'} onClick={(event) => { event.stopPropagation(); void onAction(() => api(`/orders/${order.id}/accept`, { method: 'POST' }), 'Order diterima') }}>Terima Order</button>
      </footer>
    </article>
  )
}

function OrderDetail({ order, api, onAction }: { order: Order; api: ApiClient; onAction: (work: () => Promise<unknown>, success: string) => Promise<void> }) {
  const { setView } = useDriverStore()
  const [adjustOpen, setAdjustOpen] = useState(false)
  const [operOpen, setOperOpen] = useState(false)
  const [cancelOpen, setCancelOpen] = useState(false)
  const [now, setNow] = useState(() => Date.now())
  const isAccepted = order.status === 'accepted'
  const canFinish = order.status === 'accepted' || order.status === 'on_delivery'
  const isOperHandlePending = order.operHandleStatus === 'pending'
  const acceptedAt = order.acceptedAt ? new Date(order.acceptedAt).getTime() : now
  const finishAt = acceptedAt + 5 * 60_000
  const finishWait = Math.max(0, finishAt - now)
  const finishDisabled = finishWait > 0 || isOperHandlePending
  const directionMatch = order.eligibility?.direction_match ?? true
  const route = routeInfoFor(order)

  useEffect(() => {
    if (!canFinish || finishWait <= 0) return
    const timer = window.setInterval(() => setNow(Date.now()), 1000)
    return () => window.clearInterval(timer)
  }, [canFinish, finishWait])

  return (
    <section className="page detail-page">
      <button className="back-button" aria-label="Kembali" onClick={() => setView('orders')}><ChevronLeft size={22} /></button>
      <header className="detail-hero panel">
        <div><span className="eyebrow">{order.code}</span><h1>{order.customer}</h1><p>{order.customerPhone || '-'}</p></div>
        <div className="detail-hero-meta">
          <span className="badge">{order.service}</span>
          <strong>Rp {formatMoney(order.total)}</strong>
          <small>{statusLabel(order.status)}</small>
        </div>
      </header>

      <section className="detail-summary-grid">
        <InfoTile label="Status" value={statusLabel(order.status)} />
        <InfoTile label="Multi Order" value={order.isMultiOrder ? 'Aktif' : 'Tidak'} />
        <InfoTile label="Pembayaran" value={order.paymentLabel ?? driverPaymentLabel(order.paymentMethod)} />
        <InfoTile label="Driver" value={order.driverPreference === 'ladies' ? 'Ladies' : 'Umum'} />
        <InfoTile label="Kendaraan" value={driverVehicleLabel(order)} />
      </section>

      <section className={directionMatch ? 'direction-panel match' : 'direction-panel mismatch'}>
        <strong>{directionMatch ? 'SEARAH' : 'TIDAK SEARAH'}</strong>
        <span>{directionMatch ? 'Order ini mengikuti arah perjalanan aktif.' : 'Order ini tidak searah dengan perjalanan Anda.'}</span>
      </section>

      <section className="panel route-panel">
        <SectionTitle title="Lokasi" />
        <MapRow label={route.pickupLabel} address={route.pickupAddress} lat={order.pickupLat} lng={order.pickupLng} />
        <MapRow label={route.destinationLabel} address={route.destinationAddress} lat={order.destinationLat} lng={order.destinationLng} />
      </section>

      <section className="panel">
        <SectionTitle title="Catatan" action="Customer" />
        <p className="note">{order.notes || 'Tidak ada catatan'}</p>
        {order.detail && <p className="detail-copy">{order.detail}</p>}
      </section>

      <section className="panel">
        <SectionTitle title="Breakdown Harga" action="Total" />
        <PriceRow label="Tarif" value={order.price} />
        <PriceRow label="Service fee" value={order.serviceFee} />
        <PriceRow label="Tambahan jasa" value={order.extraCharge} />
        <div className="total-row"><span>Total</span><strong>Rp {formatMoney(order.total)}</strong></div>
      </section>

      <div className="action-stack detail-actions">
        {order.status === 'pending' && (
          <>
            {order.eligibility?.can_accept === false && <p className="eligibility-note">{eligibilityReason(order.eligibility.reason)}</p>}
            <button className="primary-button" disabled={order.eligibility?.can_accept === false} onClick={() => onAction(() => api(`/orders/${order.id}/accept`, { method: 'POST' }), 'Order diterima')}>Terima Order</button>
          </>
        )}
        {isAccepted && (
          <>
            <button className="secondary-button" onClick={() => setView('chat')}>Chat Customer</button>
            <button className="secondary-button" onClick={() => setAdjustOpen(true)}>Tambah Service Charge</button>
            <button className="danger-button" disabled={isOperHandlePending} onClick={() => setOperOpen(true)}>Oper Handle</button>
            <button className="ghost-button" onClick={() => setCancelOpen(true)}>Request Cancel ke CS</button>
          </>
        )}
        {canFinish && (
          <button
            className="finish-button"
            disabled={finishDisabled}
            onClick={() => onAction(() => api(`/orders/${order.id}/complete`, { method: 'POST' }), 'Order selesai')}
          >
            {isOperHandlePending ? 'Menunggu approval oper handle' : finishWait > 0 ? `Selesai aktif dalam ${formatRemaining(finishWait)}` : 'Selesai'}
          </button>
        )}
      </div>

      {adjustOpen && <AdjustmentModal order={order} onClose={() => setAdjustOpen(false)} onSubmit={(amount, reason) => onAction(() => api(`/orders/${order.id}/adjustments`, { method: 'POST', body: JSON.stringify({ amount, reason }) }), 'Adjustment terkirim').then(() => setAdjustOpen(false))} />}
      {operOpen && <OperHandleModal order={order} onClose={() => setOperOpen(false)} onConfirm={(reason) => onAction(() => api(`/orders/${order.id}/oper-handle`, { method: 'POST', body: JSON.stringify({ reason }) }), 'Oper handle dikirim ke Operator/SPV').then(() => setOperOpen(false))} />}
      {cancelOpen && <CancelRequestModal onClose={() => setCancelOpen(false)} onConfirm={(reason) => onAction(() => api(`/orders/${order.id}/cancel-request`, { method: 'POST', body: JSON.stringify({ reason }) }), 'Request cancel dikirim ke Operator/SPV').then(() => setCancelOpen(false))} />}
    </section>
  )
}

function AdjustmentModal({ order, onClose, onSubmit }: { order: Order; onClose: () => void; onSubmit: (amount: number, reason: string) => void }) {
  const [amount, setAmount] = useState('2000')
  const [reason, setReason] = useState('')
  const numericAmount = Number(amount)
  const canSubmit = Number.isFinite(numericAmount) && numericAmount >= 1000 && reason.trim().length >= 5

  return (
    <Modal title="Tambah Service Charge" onClose={onClose}>
      <p className="modal-copy">Tambahan jasa akan menambah total {order.code}. Alasan wajib ditulis karena terlihat di laporan admin dan menjadi catatan untuk customer.</p>
      <label>
        Nominal
        <input
          type="number"
          value={amount}
          min={1000}
          step={1000}
          inputMode="numeric"
          placeholder="Contoh 2000"
          onChange={(event) => setAmount(event.target.value.replace(/[^\d]/g, ''))}
        />
      </label>
      <label>
        Alasan
        <textarea
          value={reason}
          onChange={(event) => setReason(event.target.value)}
          placeholder="Contoh: parkir tambahan / titik jemput berubah / belanja perlu kantong ekstra"
          required
        />
      </label>
      {amount === '' && <p className="modal-copy warning">Nominal belum diisi. Masukkan minimal Rp 1.000.</p>}
      <button className="primary-button" disabled={!canSubmit} onClick={() => onSubmit(numericAmount, reason.trim())}>Kirim Adjustment</button>
    </Modal>
  )
}

function OperHandleModal({ order, onClose, onConfirm }: { order: Order; onClose: () => void; onConfirm: (reason: string) => void }) {
  const [reason, setReason] = useState('')

  return (
    <Modal title="Oper Handle" onClose={onClose}>
      <p className="modal-copy">Order {order.code} akan masuk approval Operator/SPV. Setelah disetujui, order dilepas untuk dicari driver lain dan akun driver akan terkena suspend sesuai aturan.</p>
      <label>Alasan<textarea value={reason} onChange={(event) => setReason(event.target.value)} placeholder="Contoh: kendaraan bermasalah / kondisi darurat" required /></label>
      <div className="modal-actions">
        <button className="secondary-button" onClick={onClose}>Batal</button>
        <button className="danger-button" disabled={!reason.trim()} onClick={() => onConfirm(reason.trim())}>Kirim Approval</button>
      </div>
    </Modal>
  )
}

function CancelRequestModal({ onClose, onConfirm }: { onClose: () => void; onConfirm: (reason: string) => void }) {
  const [reason, setReason] = useState('')
  return <Modal title="Request Cancel" onClose={onClose}><p className="modal-copy">Cancel order harus menunggu approval Operator/SPV melalui chat monitor.</p><label>Alasan<textarea value={reason} onChange={(event) => setReason(event.target.value)} required /></label><div className="modal-actions"><button className="secondary-button" onClick={onClose}>Batal</button><button className="danger-button" disabled={!reason.trim()} onClick={() => onConfirm(reason.trim())}>Kirim</button></div></Modal>
}

function InfoTile({ label, value }: { label: string; value: string }) {
  return (
    <div className="info-tile panel">
      <span>{label}</span>
      <strong>{value}</strong>
    </div>
  )
}

function ChatScreen({ order, api, mode }: { order: Order | null; api: ApiClient; mode: 'order' | 'operator' }) {
  const { setView, driver, toast } = useDriverStore()
  const orderId = order?.id ?? null
  const [conversation, setConversation] = useState<ChatConversation | null>(null)
  const [messages, setMessages] = useState<ChatMessage[]>([])
  const [text, setText] = useState('')
  const [loading, setLoading] = useState(false)
  const [sending, setSending] = useState(false)
  const [error, setError] = useState('')
  const [attachmentOpen, setAttachmentOpen] = useState(false)
  const [replyTarget, setReplyTarget] = useState<ReplyTarget | null>(null)
  const [draftImage, setDraftImage] = useState<{ file: File; url: string } | null>(null)
  const [previewImage, setPreviewImage] = useState<string | null>(null)
  const listRef = useRef<HTMLDivElement | null>(null)
  const textareaRef = useRef<HTMLTextAreaElement | null>(null)
  const galleryInputRef = useRef<HTMLInputElement | null>(null)
  const cameraInputRef = useRef<HTMLInputElement | null>(null)

  const loadChat = useCallback(async (silent = false) => {
    if (mode === 'order' && !orderId) return
    if (!silent) setLoading(true)
    if (!silent) setError('')
    try {
      const started = mode === 'operator'
        ? await api<{ data: ChatConversation }>('/chats/operator', { method: 'POST' })
        : await api<{ data: ChatConversation }>(`/orders/${orderId}/chat`, {
            method: 'POST',
            body: JSON.stringify({ type: 'customer_driver' }),
          })
      setConversation(started.data)
      const response = await api<{ data: { data: ChatMessage[] } }>(`/chats/${started.data.id}/messages?per_page=50`)
      setMessages((current) => mergeChatMessages(current, [...response.data.data].reverse()))
    } catch (err) {
      if (!silent) setError(getErrorMessage(err, mode === 'operator' ? 'Chat operator belum tersedia' : 'Chat belum tersedia untuk order ini'))
    } finally {
      if (!silent) setLoading(false)
    }
  }, [api, mode, orderId])

  useEffect(() => {
    setConversation(null)
    setMessages([])
    setError('')
    if (mode === 'order' && !orderId) return

    void loadChat()
  }, [loadChat, mode, orderId])

  useEffect(() => {
    if (mode === 'order' && !orderId) return
    const interval = window.setInterval(() => void loadChat(true), 8000)
    return () => window.clearInterval(interval)
  }, [loadChat, mode, orderId])

  useEffect(() => {
    const channelName = mode === 'operator' && driver?.id ? `chat.operator.${driver.id}` : orderId ? `chat.order.${orderId}` : null
    if (!channelName) return
    const channel = makeEcho(useDriverStore.getState().token).private(channelName)
    channel.listen('.message.sent', (event: { message?: ChatMessage }) => {
      const incoming = event.message
      if (!incoming) return
      setMessages((current) => current.some((message) => String(message.id) === String(incoming.id)) ? current : [...current, incoming])
    })

    return () => {
      makeEcho(useDriverStore.getState().token).leave(channelName)
    }
  }, [driver?.id, mode, orderId])

  useEffect(() => {
    listRef.current?.scrollTo({ top: listRef.current.scrollHeight, behavior: 'smooth' })
  }, [messages.length, loading, error])

  const sendMessage = async (event?: React.FormEvent) => {
    event?.preventDefault()
    if (!conversation || !text.trim()) return
    const messageText = withReplyPrefix(text.trim(), replyTarget)
    setText('')
    setReplyTarget(null)
    setAttachmentOpen(false)
    setSending(true)
    try {
      const response = await api<{ data: ChatMessage }>(`/chats/${conversation.id}/messages`, {
        method: 'POST',
        body: JSON.stringify({ message: messageText }),
      })
      setMessages((current) => current.some((message) => String(message.id) === String(response.data.id)) ? current : [...current, response.data])
    } catch (err) {
      setText(messageText)
      toast(getErrorMessage(err, 'Gagal kirim pesan'), 'danger')
    } finally {
      setSending(false)
    }
  }

  const sendImage = async (file?: File | null, caption = '') => {
    if (!conversation || !file) return
    setAttachmentOpen(false)
    setSending(true)
    try {
      const form = new FormData()
      form.append('image', file)
      form.append('message', withReplyPrefix(caption || file.name, replyTarget))
      const response = await api<{ data: ChatMessage }>(`/chats/${conversation.id}/messages`, {
        method: 'POST',
        body: form,
      })
      setMessages((current) => current.some((message) => String(message.id) === String(response.data.id)) ? current : [...current, response.data])
    } catch (err) {
      toast(getErrorMessage(err, 'Gagal kirim gambar'), 'danger')
    } finally {
      setSending(false)
    }
  }

  const shareContact = async () => {
    if (!conversation || !driver) return
    const phone = driver.phone || 'Nomor belum tersedia'
    setAttachmentOpen(false)
    setText('')
    setSending(true)
    try {
      const response = await api<{ data: ChatMessage }>(`/chats/${conversation.id}/messages`, {
        method: 'POST',
        body: JSON.stringify({ message: withReplyPrefix(`Kontak driver:\n${driver.name}\n${phone}`, replyTarget) }),
      })
      setReplyTarget(null)
      setMessages((current) => current.some((message) => String(message.id) === String(response.data.id)) ? current : [...current, response.data])
    } catch (err) {
      toast(getErrorMessage(err, 'Gagal kirim kontak'), 'danger')
    } finally {
      setSending(false)
    }
  }

  return (
    <section className="page chat-page">
      <button className="back-button" aria-label="Kembali" onClick={() => setView(mode === 'operator' ? 'profile' : order ? 'order-detail' : 'dashboard')}><ChevronLeft size={22} /></button>
      <PageTitle title={mode === 'operator' ? 'Chat Operator' : 'Chat'} subtitle={mode === 'operator' ? 'CS / Operator' : order?.customer ?? 'Customer'} />
      {mode === 'order' && !order && <EmptyState title="Belum ada order aktif" copy="Chat customer muncul setelah order diterima." />}
      {(mode === 'operator' || order) && (
        <div className="driver-chat panel">
          {loading && messages.length === 0 && <div className="chat-loading">Memuat chat...</div>}
          {!loading && error && <EmptyState title="Chat belum bisa dibuka" copy={error} />}
          {(!loading || messages.length > 0) && !error && (
            <>
              <div className="chat-list driver-chat-list" ref={listRef}>
                {messages.length === 0 && <div className="chat-loading">Belum ada pesan. Mulai percakapan dengan {mode === 'operator' ? 'operator' : 'customer'}.</div>}
                {messages.map((message) => {
                  const mine = message.sender_id === driver?.id || message.sender_type === 'driver'
                  return (
                    <article key={message.id} className={`bubble ${mine ? 'mine' : ''}`}>
                      <p>{redactMapText(message.message) || (message.image_url ? 'Foto terkirim' : 'Pesan media')}</p>
                      {message.image_url && <button className="chat-image-button" type="button" onClick={() => setPreviewImage(assetUrl(message.image_url!))}><img className="chat-media" src={assetUrl(message.image_url)} alt="Lampiran chat" /></button>}
                      {message.audio_url && <audio controls src={assetUrl(message.audio_url)} />}
                      <button className="bubble-reply" type="button" onClick={() => setReplyTarget({ id: String(message.id), text: message.message || (message.image_url ? 'Foto' : 'Pesan') })}>Balas</button>
                      <span>{formatChatTime(message.created_at)}</span>
                    </article>
                  )
                })}
              </div>
              <form className="composer driver-composer" onSubmit={sendMessage}>
                {attachmentOpen && (
                  <AttachmentPanel
                    onGallery={() => galleryInputRef.current?.click()}
                    onCamera={() => cameraInputRef.current?.click()}
                    onContact={() => void shareContact()}
                  />
                )}
                <input ref={galleryInputRef} type="file" accept="image/*" hidden onChange={(event) => {
                  const file = event.target.files?.[0]
                  if (file) setDraftImage({ file, url: URL.createObjectURL(file) })
                  event.currentTarget.value = ''
                }} />
                <input ref={cameraInputRef} type="file" accept="image/*" capture="environment" hidden onChange={(event) => {
                  const file = event.target.files?.[0]
                  if (file) setDraftImage({ file, url: URL.createObjectURL(file) })
                  event.currentTarget.value = ''
                }} />
                {replyTarget && <div className="reply-preview"><span>Balas</span><strong>{replyTarget.text}</strong><button type="button" onClick={() => setReplyTarget(null)}>x</button></div>}
                <button className="clip-button" disabled={sending || !conversation} type="button" onClick={() => setAttachmentOpen((open) => !open)} aria-label="Lampiran">
                  <Paperclip size={20} />
                </button>
                <textarea
                  ref={textareaRef}
                  value={text}
                  rows={1}
                  onChange={(event) => {
                    setText(event.target.value)
                    autoResizeTextarea(event.currentTarget)
                  }}
                  onKeyDown={(event) => {
                    if (event.key === 'Enter' && (event.ctrlKey || event.metaKey)) {
                      event.preventDefault()
                      void sendMessage()
                    }
                  }}
                  placeholder={`Tulis pesan ke ${mode === 'operator' ? 'operator' : 'customer'}`}
                />
                <button className="primary-button send-button" disabled={sending || !text.trim()} type="submit" aria-label="Kirim pesan">
                  <SendHorizontal size={21} />
                </button>
              </form>
              {draftImage && (
                <ImageEditorModal
                  imageUrl={draftImage.url}
                  onClose={() => {
                    URL.revokeObjectURL(draftImage.url)
                    setDraftImage(null)
                  }}
                  onSend={async (file, caption) => {
                    await sendImage(file, caption)
                    URL.revokeObjectURL(draftImage.url)
                    setDraftImage(null)
                  }}
                />
              )}
              {previewImage && <ImagePreviewModal imageUrl={previewImage} onClose={() => setPreviewImage(null)} />}
            </>
          )}
        </div>
      )}
    </section>
  )
}

function AttachmentPanel({ onGallery, onCamera, onContact }: { onGallery: () => void; onCamera: () => void; onContact: () => void }) {
  return (
    <div className="attachment-panel">
      <button type="button" onClick={onGallery}><span className="gallery"><ImageIcon size={26} /></span><b>Galeri</b></button>
      <button type="button" onClick={onCamera}><span className="camera"><Camera size={26} /></span><b>Kamera</b></button>
      <button type="button" onClick={onContact}><span className="contact"><UserRound size={26} /></span><b>Kontak</b></button>
    </div>
  )
}

function ImageEditorModal({ imageUrl, onClose, onSend }: { imageUrl: string; onClose: () => void; onSend: (file: File, caption: string) => void | Promise<void> }) {
  const canvasRef = useRef<HTMLCanvasElement | null>(null)
  const drawingRef = useRef(false)
  const [caption, setCaption] = useState('')

  useEffect(() => {
    const canvas = canvasRef.current
    if (!canvas) return
    const context = canvas.getContext('2d')
    const image = new Image()
    image.onload = () => {
      const scale = Math.min(1, 900 / image.width)
      canvas.width = Math.max(1, Math.round(image.width * scale))
      canvas.height = Math.max(1, Math.round(image.height * scale))
      context?.drawImage(image, 0, 0, canvas.width, canvas.height)
    }
    image.src = imageUrl
  }, [imageUrl])

  const draw = (event: React.PointerEvent<HTMLCanvasElement>) => {
    const canvas = canvasRef.current
    const context = canvas?.getContext('2d')
    if (!canvas || !context || !drawingRef.current) return
    const rect = canvas.getBoundingClientRect()
    context.lineWidth = 7
    context.lineCap = 'round'
    context.strokeStyle = '#ef1f1f'
    context.lineTo((event.clientX - rect.left) * (canvas.width / rect.width), (event.clientY - rect.top) * (canvas.height / rect.height))
    context.stroke()
  }

  const send = async () => {
    const canvas = canvasRef.current
    if (!canvas) return
    const blob = await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.9))
    if (!blob) return
    await onSend(new File([blob], `jojo-chat-${Date.now()}.jpg`, { type: 'image/jpeg' }), caption.trim())
  }

  return (
    <div className="image-editor-backdrop">
      <div className="image-editor">
        <div className="image-editor-toolbar">
          <button type="button" onClick={onClose}>x</button>
          <strong>Edit gambar</strong>
          <button type="button" onClick={() => void send()}><SendHorizontal size={22} /></button>
        </div>
        <canvas
          ref={canvasRef}
          onPointerDown={(event) => {
            drawingRef.current = true
            const context = event.currentTarget.getContext('2d')
            const rect = event.currentTarget.getBoundingClientRect()
            context?.beginPath()
            context?.moveTo((event.clientX - rect.left) * (event.currentTarget.width / rect.width), (event.clientY - rect.top) * (event.currentTarget.height / rect.height))
          }}
          onPointerMove={draw}
          onPointerUp={() => { drawingRef.current = false }}
          onPointerLeave={() => { drawingRef.current = false }}
        />
        <input value={caption} onChange={(event) => setCaption(event.target.value)} placeholder="Tambah keterangan..." />
      </div>
    </div>
  )
}

function ImagePreviewModal({ imageUrl, onClose }: { imageUrl: string; onClose: () => void }) {
  return (
    <div className="image-editor-backdrop" onClick={onClose}>
      <div className="image-preview" onClick={(event) => event.stopPropagation()}>
        <button type="button" onClick={onClose}>x</button>
        <img src={imageUrl} alt="Preview lampiran" />
      </div>
    </div>
  )
}

function Profile({ driver, api, onSaved }: { driver: Driver; api: ApiClient; onSaved: () => Promise<void> }) {
  const toast = useDriverStore((state) => state.toast)
  const setView = useDriverStore((state) => state.setView)
  const openOperatorChat = useDriverStore((state) => state.openOperatorChat)
  const logout = useDriverStore((state) => state.logout)
  const [form, setForm] = useState({ name: driver.name, username: driver.username, password: '' })
  const [photo, setPhoto] = useState<File | null>(null)
  const [photoPreview, setPhotoPreview] = useState<string | null>(null)
  const submit = async (event: React.FormEvent) => {
    event.preventDefault()
    try {
      if (photo) {
        const resizedPhoto = await resizeImageFile(photo, {
          maxWidth: 900,
          maxHeight: 900,
          quality: 0.82,
          fileNamePrefix: 'driver-profile',
        })
        const payload = new FormData()
        payload.append('name', form.name)
        payload.append('username', form.username)
        if (form.password) payload.append('password', form.password)
        payload.append('profile_photo', resizedPhoto)
        await api('/driver/profile', { method: 'POST', body: payload })
      } else {
        await api('/driver/profile', { method: 'PUT', body: JSON.stringify(form) })
      }
      if (photoPreview) URL.revokeObjectURL(photoPreview)
      setPhoto(null)
      setPhotoPreview(null)
      toast('Profile berhasil diperbarui', 'success')
      await onSaved()
    } catch (error) {
      toast(getErrorMessage(error, 'Profile gagal diperbarui'), 'danger')
    }
  }

  const handleLogout = async () => {
    try {
      await api('/auth/logout', { method: 'POST' })
    } catch {
      // Tetap keluar lokal jika token server sudah kedaluwarsa atau jaringan putus.
    } finally {
      logout()
    }
  }

  return (
    <section className="page">
      <PageTitle title="Profile" subtitle="Kelola data akun driver." />
      <button className="panel profile-menu-button" type="button" onClick={() => setView('performance')}>
        <BarChart3 size={20} />
        <div><strong>Performa</strong><span>Rating, setoran, suspend history, dan oper handle</span></div>
      </button>
      <button className="panel profile-menu-button operator-chat-button" type="button" onClick={openOperatorChat}>
        <MessageCircleMore size={20} />
        <div><strong>Chat CS / Operator</strong><span>Hubungi operator untuk bantuan akun, suspend, atau order.</span></div>
      </button>
      {driver.status !== 'active' && (
        <section className="panel profile-suspend-card">
          <strong>{driver.status === 'suspended_unpaid' ? 'Suspend setoran' : 'Status suspend'}</strong>
          <span>{suspendReasonText(driver)}</span>
          <small>{driver.suspended_until ? `Sampai ${formatHistoryTime(driver.suspended_until)}` : 'Menunggu release admin'}</small>
        </section>
      )}
      <form className="panel profile-form" onSubmit={submit}>
        <div className="driver-profile-photo">
          {photoPreview || driver.profile_photo_url ? <img src={photoPreview ?? cmsAssetUrl(driver.profile_photo_url ?? '')} alt="Foto driver" /> : <UserRound size={36} />}
          <label>
            <Camera size={18} />
            Ganti Foto
            <input type="file" accept="image/*" hidden onChange={(event) => {
              const file = event.target.files?.[0] ?? null
              if (photoPreview) URL.revokeObjectURL(photoPreview)
              setPhoto(file)
              setPhotoPreview(file ? URL.createObjectURL(file) : null)
            }} />
          </label>
        </div>
        <label>Nama<input value={form.name} onChange={(event) => setForm({ ...form, name: event.target.value })} required /></label>
        <label>Username<input value={form.username} onChange={(event) => setForm({ ...form, username: event.target.value })} required /></label>
        <label>Telephone<input value={driver.phone ?? ''} readOnly /><small>Perubahan nomor melalui Manager/SPV.</small></label>
        <label>Password Baru<input type="password" value={form.password} minLength={8} onChange={(event) => setForm({ ...form, password: event.target.value })} /></label>
        <button className="primary-button" type="submit">Update Profile</button>
      </form>
      <button className="danger-button full logout-button" type="button" onClick={() => void handleLogout()}>
        <LogOut size={18} />
        Logout
      </button>
    </section>
  )
}

function RequestOrder({ onCreated }: { onCreated: () => Promise<void> }) {
  const [request, setRequest] = useState('')
  const [loading, setLoading] = useState(false)
  const { token, toast, driver, isOnline, branchRequestOrders } = useDriverStore()
  const canCreateRequest = Boolean(isOnline && driver?.is_available && driver?.status === 'active')
  const parsedPrices = parseRequestPrices(request)
  const ownRequestOrders = branchRequestOrders
    .filter((order) => order.driver === driver?.name || order.customer === driver?.name)
    .slice(0, 8)
  const areaRequestOrders = branchRequestOrders
    .filter((order) => order.driver !== driver?.name && order.customer !== driver?.name)
    .slice(0, 12)
  const submit = async () => {
    if (!canCreateRequest) {
      toast('Status driver OFF atau tidak aktif. Aktifkan ON terlebih dahulu untuk request order.', 'warning')
      return
    }
    setLoading(true)
    try {
      await makeApi(token)('/driver/request-order', { method: 'POST', body: JSON.stringify({ raw_text: request }) })
      toast('Request order berhasil dibuat', 'success')
      setRequest('')
      await onCreated()
    } catch (error) {
      toast(getErrorMessage(error, 'Gagal kirim request'), 'danger')
    } finally {
      setLoading(false)
    }
  }
  return (
    <section className="page request-page">
      <PageTitle title="Request Order" subtitle="Paste format request, history area tampil seperti grup driver cabang." />
      <div className="panel request-compose-panel">
        {!canCreateRequest && <div className="notice-card warning">Status OFF atau akun tidak aktif. Request order tidak bisa dikirim.</div>}
        <textarea className="request-box" placeholder={"DO\npiscok dawuhan\nke Ayani\njasa 10k D 7k"} value={request} onChange={(event) => setRequest(event.target.value)} />
        <div className="preview-price request-price-preview">
          <span>Jasa diterima: <strong>{parsedPrices.acceptedPrice ? `Rp ${formatMoney(parsedPrices.acceptedPrice)}` : 'Belum terdeteksi'}</strong></span>
          <span>Dasar setoran: <strong>{parsedPrices.depositBase ? `Rp ${formatMoney(parsedPrices.depositBase)}` : 'Belum terdeteksi'}</strong></span>
        </div>
        <button className="primary-button full" disabled={loading || !canCreateRequest || !parsedPrices.acceptedPrice} onClick={submit}>{loading ? 'Mengirim...' : 'Kirim Request'}</button>
      </div>

      <section className="panel request-history-panel">
        <SectionTitle title="Request kamu" action={`${ownRequestOrders.length}`} />
        {ownRequestOrders.length === 0 && <p className="note">Request yang kamu kirim akan tampil di sini.</p>}
        {ownRequestOrders.map((order) => <RequestHistoryRow key={order.id} order={order} />)}
      </section>

      <section className="panel request-history-panel">
        <SectionTitle title="Request driver area" action={`${areaRequestOrders.length}`} />
        {areaRequestOrders.length === 0 && <p className="note">Belum ada request dari driver lain di cabang kamu.</p>}
        {areaRequestOrders.map((order) => <RequestHistoryRow key={order.id} order={order} />)}
      </section>
    </section>
  )
}

function History({ orders, loading }: { orders: Order[]; loading: boolean }) {
  const selectOrder = useDriverStore((state) => state.selectOrder)
  const driver = useDriverStore((state) => state.driver)
  const branchRequestOrders = useDriverStore((state) => state.branchRequestOrders)
  const [selectedMonth, setSelectedMonth] = useState(() => monthKey(new Date().toISOString()))
  const historyOrders = orders.filter((order) => order.status === 'done' || order.status === 'cancelled' || Boolean(order.operHandleStatus))
  const monthOptions = useMemo(() => {
    const keys = new Set(historyOrders.map((order) => monthKey(historyOrderTime(order))).filter(Boolean))
    recentMonthKeys(12).forEach((key) => keys.add(key))

    return [...keys].sort().reverse()
  }, [historyOrders])
  const visibleOrders = historyOrders.filter((order) => monthKey(historyOrderTime(order)) === selectedMonth)
  const driverIncome = visibleOrders
    .filter((order) => order.status === 'done')
    .reduce((sum, order) => sum + Math.max(order.total ?? 0, 0), 0)
  const visibleRequestOrders = branchRequestOrders.filter((order) => monthKey(order.updatedAt ?? order.acceptedAt) === selectedMonth)
  const ownRequestOrders = visibleRequestOrders.filter((order) => order.driver === driver?.name || order.customer === driver?.name)

  return (
    <section className="page history-page">
      <PageTitle title="Riwayat" subtitle={`Order ${formatMonthLabel(selectedMonth)}.`} />
      <section className="panel history-filter-panel">
        <label>
          Bulan
          <select value={selectedMonth} onChange={(event) => setSelectedMonth(event.target.value)}>
            {monthOptions.map((key) => <option key={key} value={key}>{formatMonthLabel(key)}</option>)}
          </select>
        </label>
        <div>
          <span>Total pendapatan kamu</span>
          <strong>Rp {formatMoney(driverIncome)}</strong>
          <small>{formatMonthLabel(selectedMonth)} sampai hari ini</small>
        </div>
      </section>
      {loading && <SkeletonCards />}
      {!loading && historyOrders.length === 0 && <EmptyState title="Belum ada riwayat" copy="Order selesai, batal, dan oper handle akan tampil di sini." />}
      {!loading && historyOrders.length > 0 && visibleOrders.length === 0 && <EmptyState title="Tidak ada order" copy="Tidak ada riwayat pada bulan ini." />}
      <div className="history-list">
        {visibleOrders.map((order) => {
          const route = routeInfoFor(order)
          const isOperHandleHistory = Boolean(order.operHandleStatus)

          return (
          <button className="history-row panel" key={order.id} onClick={() => selectOrder(order.id)} type="button">
            <div className="history-main">
              <div>
                <strong>{order.code}</strong>
                <span className={`history-status ${order.status}`}>{statusLabel(order.status)}</span>
                {isOperHandleHistory && <span className={`history-status oper-handle ${order.operHandleStatus}`}>Oper {operHandleStatusLabel(order.operHandleStatus)}</span>}
              </div>
              <p>{shortAddress(route.pickupAddress)} <span>→</span> {shortAddress(route.destinationAddress)}</p>
              {order.operHandleReason && <em className="history-oper-reason">Alasan: {order.operHandleReason}</em>}
              <small>{formatHistoryTime(historyOrderTime(order))}</small>
            </div>
            <div className="history-total">
              <b>Rp {formatMoney(order.total)}</b>
              {isOperHandleHistory && <span>Riwayat oper handle</span>}
            </div>
          </button>
        )})}
      </div>
      <section className="panel request-history-panel">
        <SectionTitle title="Request order kamu" action={`${ownRequestOrders.length}`} />
        {ownRequestOrders.length === 0 && <p className="note">Belum ada request order kamu pada bulan ini.</p>}
        {ownRequestOrders.map((order) => <RequestHistoryRow key={order.id} order={order} />)}
      </section>
      <section className="panel request-history-panel">
        <SectionTitle title="Request order area" action={`${visibleRequestOrders.length}`} />
        {visibleRequestOrders.length === 0 && <p className="note">Belum ada request order area pada bulan ini.</p>}
        {visibleRequestOrders.map((order) => <RequestHistoryRow key={order.id} order={order} />)}
      </section>
    </section>
  )
}

function RequestHistoryRow({ order }: { order: Order }) {
  return (
    <div className="branch-feed-row">
      <strong>{order.code}</strong>
      <span>{order.driver || order.customer || 'Driver'} · {statusLabel(order.status)}</span>
      <small>Rp {formatMoney(order.total)} · {formatHistoryTime(order.updatedAt ?? order.acceptedAt)}</small>
    </div>
  )
}

function PerformancePage() {
  const performance = useDriverStore((state) => state.performance)
  return (
    <section className="page performance-page">
      <PageTitle title="Performa" subtitle={`Evaluasi bulan ${performance?.period_label ?? 'ini'}.`} />
      <section className="driver-performance-hero panel">
        <div>
          <span>Rating customer</span>
          <strong>{Number(performance?.rating ?? 0).toFixed(1)}/5</strong>
          <small>{performance?.ratings_count ?? 0} rating masuk</small>
        </div>
        <div>
          <span>Pendapatan bulan ini</span>
          <strong>Rp {formatMoney(performance?.month_revenue ?? 0)}</strong>
          <small>Hari ini Rp {formatMoney(performance?.today_revenue ?? 0)}</small>
        </div>
      </section>
      <section className="stats-grid">
        <Metric label="Order selesai bulan ini" value={String(performance?.completed_orders_count ?? 0)} />
        <Metric label="Order selesai hari ini" value={String(performance?.today_completed_orders_count ?? 0)} />
        <Metric label="Cancel bulan ini" value={String(performance?.cancelled_orders_count ?? 0)} />
        <Metric label="Jumlah Rating" value={String(performance?.ratings_count ?? 0)} />
      </section>
      <section className="panel performance-panel">
        <SectionTitle title="Setoran" action={`Rp ${formatMoney(performance?.setoran?.total ?? 0)}`} />
        <p className="note">Status: {performance?.setoran?.status ?? '-'}</p>
      </section>
      <section className="panel performance-panel">
        <SectionTitle title="Suspend History" action={`${performance?.suspend_history?.length ?? 0}`} />
        {(performance?.suspend_history ?? []).map((item) => <p key={item.id} className="note">{item.type ?? 'suspend'} - {item.reason} - {item.status}</p>)}
        {(performance?.suspend_history ?? []).length === 0 && <p className="note">Belum ada suspend.</p>}
      </section>
      <section className="panel performance-panel">
        <SectionTitle title="Oper Handle" action={`${performance?.oper_handle?.length ?? 0}`} />
        {(performance?.oper_handle ?? []).map((item) => <p key={item.id} className="note">{item.status} - {item.reason ?? '-'}</p>)}
        {(performance?.oper_handle ?? []).length === 0 && <p className="note">Belum ada oper handle.</p>}
      </section>
    </section>
  )
}
function ActiveOrderRoute({ orders, max }: { orders: Order[]; max: number }) {
  const selectOrder = useDriverStore((state) => state.selectOrder)
  const sortedOrders = [...orders].sort((a, b) => (a.directionBearing ?? 0) - (b.directionBearing ?? 0))

  return (
    <section className="panel active-route">
      <SectionTitle title="Order Aktif" action={`${orders.length}/${max}`} />
      {sortedOrders.map((order, index) => {
        const route = routeInfoFor(order)

        return (
        <button key={order.id} className="active-route-row" type="button" onClick={() => selectOrder(order.id)}>
          <b>{index + 1}</b>
          <div>
            <strong>{order.code} {order.isMultiOrder && <span>Multi</span>}</strong>
            <p>{shortAddress(route.pickupAddress)} → {shortAddress(route.destinationAddress)}</p>
          </div>
          <em>{Math.round(order.directionBearing ?? 0)}°</em>
        </button>
      )})}
    </section>
  )
}

function SuspendBanner({ driver }: { driver: Driver }) {
  const remaining = useCountdown(driver.suspended_until)
  return <section className="suspend-banner"><strong>{driver.status === 'suspended_unpaid' ? 'Suspend belum bayar setoran' : 'Akun disuspend'}</strong><span>{remaining ? `Sisa waktu ${remaining}` : 'Menunggu release admin'}</span><p>{suspendReasonText(driver)}</p><small>Anda masih bisa chat operator untuk bantuan.</small></section>
}

function BottomNav({ active, onNavigate }: { active: View; onNavigate: (view: View) => void }) {
  const items: { view: View; label: string; icon: ReactNode }[] = [
    { view: 'dashboard', label: 'Home', icon: <Home size={18} /> },
    { view: 'orders', label: 'Order', icon: <BriefcaseBusiness size={18} /> },
    { view: 'chat', label: 'Chat', icon: <MessageCircle size={18} /> },
    { view: 'profile', label: 'Profile', icon: <UserRound size={18} /> },
  ]
  return <nav className="bottom-nav">{items.map((item) => <button key={item.view} className={active === item.view ? 'active' : ''} onClick={() => onNavigate(item.view)}><span>{item.icon}</span>{item.label}</button>)}</nav>
}

function Modal({ title, onClose, children }: { title: string; onClose: () => void; children: ReactNode }) {
  return <div className="modal-backdrop" onClick={onClose}><section className="modal-card" onClick={(event) => event.stopPropagation()}><header><h2>{title}</h2><button aria-label="Tutup popup" onClick={onClose}>×</button></header>{children}</section></div>
}

function PwaInstallButton() {
  const [installEvent, setInstallEvent] = useState<BeforeInstallPromptEvent | null>(null)
  const [installed, setInstalled] = useState(() => window.matchMedia('(display-mode: standalone)').matches)

  useEffect(() => {
    const onPrompt = (event: Event) => {
      event.preventDefault()
      setInstallEvent(event as BeforeInstallPromptEvent)
    }
    const onInstalled = () => {
      setInstalled(true)
      setInstallEvent(null)
    }

    window.addEventListener('beforeinstallprompt', onPrompt)
    window.addEventListener('appinstalled', onInstalled)

    return () => {
      window.removeEventListener('beforeinstallprompt', onPrompt)
      window.removeEventListener('appinstalled', onInstalled)
    }
  }, [])

  if (installed || !installEvent) return null

  return (
    <button
      className="pwa-install-button"
      type="button"
      onClick={async () => {
        await installEvent.prompt()
        const choice = await installEvent.userChoice
        if (choice.outcome === 'accepted') setInstalled(true)
        setInstallEvent(null)
      }}
    >
      <img src="/logo.png" alt="" />
      <span>Install Aplikasi</span>
    </button>
  )
}

function ToastStack({ toasts }: { toasts: Toast[] }) {
  return <div className="toast-stack">{toasts.map((toast) => <div key={toast.id} className={`toast ${toast.tone}`}>{toast.message}</div>)}</div>
}

function Metric({ label, value }: { label: string; value: string | number }) { return <article className="metric panel"><span>{label}</span><strong>{value}</strong></article> }
function SectionTitle({ title, action }: { title: string; action?: string }) { return <div className="section-title"><h2>{title}</h2>{action && <span>{action}</span>}</div> }
function PageTitle({ title, subtitle }: { title: string; subtitle: string }) { return <header className="page-title"><h1>{title}</h1><p>{subtitle}</p></header> }
function StatusPill({ driver }: { driver: Driver }) { return <span className={`status-pill ${driver.status}`}>{driver.status.replace('_', ' ')}</span> }
function MapRow({ label, address }: { label: string; address: string; lat: number; lng: number }) {
  return (
    <div className="map-row">
      <span>{label}</span>
      <strong>{address}</strong>
    </div>
  )
}
function PriceRow({ label, value }: { label: string; value: number }) { return <div className="price-row"><span>{label}</span><strong>Rp {formatMoney(value)}</strong></div> }
function mergeChatMessages(current: ChatMessage[], incoming: ChatMessage[]) {
  const byId = new Map(current.map((message) => [String(message.id), message]))
  incoming.forEach((message) => byId.set(String(message.id), message))

  return [...byId.values()].sort((a, b) => new Date(a.created_at ?? '').getTime() - new Date(b.created_at ?? '').getTime())
}

function SkeletonPage() { return <section className="page"><SkeletonCards /><SkeletonCards /></section> }
function SkeletonCards() { return <div className="skeleton-list">{[1, 2, 3].map((item) => <div className="skeleton-card" key={item} />)}</div> }
function ErrorState({ message, onRetry }: { message: string; onRetry: () => void }) { return <section className="page"><EmptyState title="Gagal memuat" copy={message} /><button className="primary-button" onClick={onRetry}>Coba Lagi</button></section> }
function EmptyState({ title, copy }: { title: string; copy: string }) { return <div className="empty-state"><strong>{title}</strong><p>{copy}</p></div> }

function useCountdown(until?: string | null) {
  const [now, setNow] = useState(() => Date.now())
  useEffect(() => { const timer = window.setInterval(() => setNow(Date.now()), 1000); return () => window.clearInterval(timer) }, [])
  return useMemo(() => {
    if (!until) return ''
    const diff = new Date(until).getTime() - now
    if (diff <= 0) return '00:00'
    const hours = Math.floor(diff / 3_600_000)
    const minutes = Math.floor((diff % 3_600_000) / 60_000)
    const seconds = Math.floor((diff % 60_000) / 1000)
    return `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`
  }, [now, until])
}

type ApiClient = <T = unknown>(path: string, options?: RequestInit) => Promise<T>
function makeApi(token: string): ApiClient {
  return async <T,>(path: string, options: RequestInit = {}) => {
    const isFormData = options.body instanceof FormData
    const response = await fetch(`${API_BASE}${path}`, {
      ...options,
      headers: {
        Accept: 'application/json',
        ...(!isFormData ? { 'Content-Type': 'application/json' } : {}),
        Authorization: `Bearer ${token}`,
        ...(options.headers ?? {}),
      },
    })
    const payload = await response.json().catch(() => ({}))
    if (!response.ok) throw new Error(payload.message || `HTTP ${response.status}`)
    return payload as T
  }
}

function mapOrder(order: ApiOrder): Order {
  return {
    id: order.id,
    code: order.code,
    status: normalizeStatus(order.status),
    customer: order.customer,
    customerPhone: order.customer_phone,
    driver: order.driver ?? null,
    source: order.source ?? null,
    service: order.service,
    distanceKm: order.distance_km,
    pickup: order.pickup,
    pickupLat: order.pickup_lat,
    pickupLng: order.pickup_lng,
    destination: order.destination,
    destinationLat: order.destination_lat,
    destinationLng: order.destination_lng,
    directionBearing: order.direction_bearing,
    isMultiOrder: order.is_multi_order,
    price: order.price,
    serviceFee: order.service_fee,
    extraCharge: order.extra_charge,
    total: order.total,
    notes: order.notes,
    detail: order.detail,
    paymentMethod: order.payment_method ?? null,
    paymentLabel: order.payment_label ?? null,
    preferredVehicleType: order.preferred_vehicle_type ?? null,
    requiredVehicleSeatRows: order.required_vehicle_seat_rows ?? null,
    driverPreference: order.driver_preference ?? null,
    operHandleStatus: order.oper_handle_status ?? null,
    operHandleDriver: order.oper_handle_driver ?? null,
    operHandleReason: order.oper_handle_reason ?? null,
    operHandleUpdatedAt: order.oper_handle_updated_at ?? null,
    acceptedAt: order.accepted_at ?? order.updated_at ?? null,
    updatedAt: order.updated_at ?? null,
    eligibility: order.eligibility,
  }
}

function mapOrderPatch(order: Partial<ApiOrder> & { id: number }): Partial<Order> {
  return {
    id: order.id,
    ...(order.code || order.order_code ? { code: order.code ?? order.order_code } : {}),
    ...(order.status ? { status: normalizeStatus(order.status) } : {}),
    ...(order.price !== undefined ? { price: order.price } : {}),
    ...(order.service_fee !== undefined || order.service_charge !== undefined ? { serviceFee: order.service_fee ?? order.service_charge ?? 0 } : {}),
    ...(order.extra_charge !== undefined ? { extraCharge: order.extra_charge } : {}),
    ...(order.total !== undefined || order.total_price !== undefined ? { total: order.total ?? order.total_price ?? 0 } : {}),
    ...(order.driver !== undefined ? { driver: order.driver } : {}),
    ...(order.source !== undefined ? { source: order.source } : {}),
    ...(order.preferred_vehicle_type !== undefined ? { preferredVehicleType: order.preferred_vehicle_type } : {}),
    ...(order.required_vehicle_seat_rows !== undefined ? { requiredVehicleSeatRows: order.required_vehicle_seat_rows } : {}),
    ...(order.driver_preference !== undefined ? { driverPreference: order.driver_preference } : {}),
    ...(order.oper_handle_status !== undefined ? { operHandleStatus: order.oper_handle_status } : {}),
    ...(order.oper_handle_driver !== undefined ? { operHandleDriver: order.oper_handle_driver } : {}),
    ...(order.oper_handle_reason !== undefined ? { operHandleReason: order.oper_handle_reason } : {}),
    ...(order.oper_handle_updated_at !== undefined ? { operHandleUpdatedAt: order.oper_handle_updated_at } : {}),
  }
}

function cmsAssetUrl(path: string) {
  if (!/^https?:\/\//i.test(path)) return assetUrl(path)

  return normalizeRemoteAsset(path)
}

function normalizeRemoteAsset(path: string) {
  try {
    const url = new URL(path)
    if (['localhost', '127.0.0.1'].includes(url.hostname) || url.pathname.startsWith('/storage/') || url.pathname.startsWith('/api/media/')) {
      return `${APP_BASE}${url.pathname}${url.search}${url.hash}`
    }
  } catch {
    return path
  }

  return path
}

type ResizeImageOptions = {
  maxWidth: number
  maxHeight: number
  quality: number
  fileNamePrefix: string
}

async function resizeImageFile(file: File, options: ResizeImageOptions): Promise<File> {
  if (!file.type.startsWith('image/') || typeof document === 'undefined') return file

  const bitmap = await createImageBitmap(file).catch(() => null)
  if (!bitmap) return file

  const ratio = Math.min(options.maxWidth / bitmap.width, options.maxHeight / bitmap.height, 1)
  const width = Math.max(1, Math.round(bitmap.width * ratio))
  const height = Math.max(1, Math.round(bitmap.height * ratio))
  const canvas = document.createElement('canvas')
  canvas.width = width
  canvas.height = height
  const context = canvas.getContext('2d')
  if (!context) return file

  context.drawImage(bitmap, 0, 0, width, height)
  bitmap.close?.()

  const blob = await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, 'image/jpeg', options.quality))
  if (!blob || blob.size >= file.size) return file

  return new File([blob], `${options.fileNamePrefix}-${Date.now()}.jpg`, { type: 'image/jpeg' })
}

let driverEcho: Echo<'reverb'> | null = null
let driverEchoToken = ''

function isLocalRealtimeHost(host?: string) {
  return !host || ['localhost', '127.0.0.1', '::1'].includes(host)
}

function resolveRealtimeConfig() {
  const configuredHost = import.meta.env.VITE_REVERB_HOST
  const browserHost = window.location.hostname
  const isPublicHost = !isLocalRealtimeHost(browserHost)
  const host = isPublicHost && isLocalRealtimeHost(configuredHost) ? browserHost : (configuredHost || browserHost)
  const scheme = isPublicHost && isLocalRealtimeHost(configuredHost)
    ? window.location.protocol.replace(':', '')
    : (import.meta.env.VITE_REVERB_SCHEME ?? window.location.protocol.replace(':', '') ?? 'http')
  const port = isPublicHost && isLocalRealtimeHost(configuredHost) && scheme === 'https'
    ? 443
    : Number(import.meta.env.VITE_REVERB_PORT ?? (scheme === 'https' ? 443 : 8080))

  return { host, scheme, port }
}

function makeEcho(token: string) {
  if (driverEcho && driverEchoToken === token) return driverEcho

  if (driverEcho) {
    driverEcho.disconnect()
    driverEcho = null
  }

  window.Pusher = Pusher
  driverEchoToken = token
  const realtime = resolveRealtimeConfig()
  driverEcho = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY ?? 'local',
    wsHost: realtime.host,
    wsPort: realtime.port,
    wssPort: realtime.port,
    forceTLS: realtime.scheme === 'https',
    enabledTransports: ['ws', 'wss'],
    authEndpoint: `${APP_BASE}/broadcasting/auth`,
    auth: {
      headers: {
        Authorization: `Bearer ${token}`,
        Accept: 'application/json',
      },
    },
  })

  return driverEcho
}

function resetDriverEcho() {
  driverEcho?.disconnect()
  driverEcho = null
  driverEchoToken = ''
}

function normalizeStatus(status: string): OrderStatus {
  const key = status.toLowerCase()
  if (key === 'driver_accepted' || key === 'accepted' || key === 'assigned') return 'accepted'
  if (key === 'on_going' || key === 'on_delivery' || key === 'driver_on_the_way' || key === 'arrived_pickup') return 'on_delivery'
  if (key === 'pending_cancel') return 'pending_cancel'
  if (key === 'completed' || key === 'done') return 'done'
  if (key === 'cancelled') return 'cancelled'
  return 'pending'
}

function isDoneStatus(status?: string) {
  return normalizeStatus(String(status ?? '')) === 'done'
}

function parseRequestPrices(text: string) {
  const prices = [...text.matchAll(pricePattern)].map((match) => Math.round(Number(match[1].replace(',', '.')) * 1000))

  return {
    acceptedPrice: prices[0] ?? null,
    depositBase: prices.length > 0 ? prices[prices.length - 1] : null,
  }
}
function driverInitial(name?: string | null) { return (name || 'D').trim().slice(0, 1).toUpperCase() || 'D' }
function isActiveOrder(order: Order) { return order.status === 'accepted' || order.status === 'on_delivery' || order.status === 'pending_cancel' }
function operHandleStatusLabel(status?: string | null) {
  const key = String(status ?? '').toLowerCase()
  if (key === 'approved') return 'Approved'
  if (key === 'rejected') return 'Rejected'
  if (key === 'pending') return 'Menunggu'
  return key || 'Oper handle'
}
function operHandleStatusText(order: Order) {
  const driver = order.operHandleDriver ?? order.driver ?? 'Driver'
  if (order.operHandleStatus === 'approved') return `${driver} oper handle, order dibuka lagi`
  if (order.operHandleStatus === 'rejected') return `${driver} oper handle ditolak`
  return `${driver} mengajukan oper handle`
}
function canReceiveRealtimeOrder(driver: Driver | null, finance: DriverFinance | null, isOnline: boolean) {
  if (!isOnline) return false
  return Boolean(driver?.can_receive_orders ?? (driver?.status === 'active' && (driver?.deposit_status ?? finance?.status ?? 'paid') === 'paid'))
}
function sortNewestOrderFirst(a: Order, b: Order) {
  const timeA = new Date(a.updatedAt ?? a.acceptedAt ?? 0).getTime()
  const timeB = new Date(b.updatedAt ?? b.acceptedAt ?? 0).getTime()

  if (timeA !== timeB) return timeB - timeA

  return b.id - a.id
}
function historyOrderTime(order: Order) {
  return order.operHandleUpdatedAt ?? order.updatedAt ?? order.acceptedAt
}
function suspendReasonText(driver: Driver) {
  if (driver.status === 'suspended_unpaid') {
    return driver.suspension_reason || 'Reason: setoran driver belum lunas.'
  }

  if (driver.suspension_reason) {
    return `Reason: ${driver.suspension_reason}`
  }

  if (driver.suspension_type) {
    return `Reason: ${driver.suspension_type.replaceAll('_', ' ')}`
  }

  return 'Reason: belum ada alasan suspend dari admin.'
}
function formatMoney(value: number) { return value.toLocaleString('id-ID') }
function driverPaymentLabel(method?: string | null) {
  if (method === 'transfer') return 'Transfer'
  if (method === 'qris') return 'QRIS'
  return 'Cash'
}
function driverVehicleLabel(order: Pick<Order, 'preferredVehicleType' | 'requiredVehicleSeatRows'>) {
  if (order.preferredVehicleType === 'mobil') {
    return `Mobil ${order.requiredVehicleSeatRows === 3 ? 3 : 2} baris`
  }

  if (order.preferredVehicleType === 'motor') {
    return 'Motor'
  }

  return 'Sesuai layanan'
}
function formatRemaining(ms: number) {
  const totalSeconds = Math.ceil(ms / 1000)
  const minutes = Math.floor(totalSeconds / 60)
  const seconds = totalSeconds % 60
  return `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`
}
function shortAddress(address: string) { return address.length > 28 ? `${address.slice(0, 28)}...` : address }
function statusLabel(status: OrderStatus) { return { pending: 'Menunggu', accepted: 'Accepted', on_delivery: 'Antar', pending_cancel: 'Menunggu Cancel', done: 'Selesai', cancelled: 'Batal' }[status] }
function setoranBreakdownRows(breakdown?: Record<string, number>, periodLabel = 'bulan sebelumnya', mode: 'billing' | 'running' = 'billing') {
  if (!breakdown) return []

  const setoranHinggaHariIni = breakdown.setoran_hingga_hari_ini ?? ((breakdown.handle_hari_15 ?? 0) + (breakdown.handle_hari_30 ?? 0))
  const cashbackBulanSebelumnya = breakdown.cashback_bulan_sebelumnya ?? 0
  const tagihanBulanSebelumnya = breakdown.tagihan_bulan_sebelumnya ?? 0
  const rows: Array<[string, number]> = [[mode === 'running' ? `Pendapatan sampai hari ini (${periodLabel})` : `Tagihan pada bulan ${periodLabel}`, setoranHinggaHariIni]]

  rows.push(['Cashback bulan sebelumnya', cashbackBulanSebelumnya > 0 ? -cashbackBulanSebelumnya : 0])
  if (tagihanBulanSebelumnya > 0) rows.push(['Sisa tagihan bulan sebelumnya', tagihanBulanSebelumnya])
  if (breakdown.bansos !== undefined) rows.push(['Bansos', breakdown.bansos])
  if (breakdown.bpjs !== undefined) rows.push(['Premi BPJS Ketenagakerjaan', breakdown.bpjs])
  if (breakdown.bpjs_jht !== undefined) rows.push(['JHT BPJS Ketenagakerjaan', breakdown.bpjs_jht])

  return rows
}
function routeInfoFor(order: Order) {
  const service = order.service.toLowerCase()
  const isPurchase = ['do', 'delivery', 'belanja', 'gift', 'gift_order'].some((keyword) => service.includes(keyword))
  const purchaseAddress = detailValue(order.detail, ['lokasi pembelian', 'alamat pembelian', 'pembelian'])
  const deliveryAddress = detailValue(order.detail, ['alamat antar', 'alamat tujuan', 'tujuan'])

  return {
    pickupLabel: isPurchase ? 'Pembelian' : 'Pickup',
    destinationLabel: isPurchase ? 'Alamat Antar' : 'Tujuan',
    pickupAddress: isPurchase ? purchaseAddress || order.pickup : order.pickup,
    destinationAddress: isPurchase ? deliveryAddress || order.destination : order.destination,
  }
}
function detailValue(detail: string | null, labels: string[]) {
  if (!detail) return ''

  const lines = detail.split(/\r?\n/)
  for (const line of lines) {
    const [rawLabel, ...rest] = line.split(':')
    const label = rawLabel.trim().toLowerCase()
    if (labels.includes(label)) return rest.join(':').trim()
  }

  return ''
}
function eligibilityReason(reason?: string | null) {
  return {
    'di luar area driver': 'Order ini di luar area/cabang driver Anda.',
    'tidak searah': 'Order ini tidak searah dengan perjalanan aktif.',
    'layanan tidak aktif untuk driver': 'Layanan ini belum aktif untuk akun driver Anda.',
    'multi order nonaktif': 'Multi order sedang nonaktif.',
    'maksimal order aktif tercapai': 'Batas order aktif sudah tercapai.',
    'driver off': 'Status driver OFF. Aktifkan ON jika setoran sudah paid.',
    'driver tidak aktif': 'Akun driver sedang tidak aktif/suspend.',
  }[String(reason ?? '')] ?? 'Order belum bisa diterima saat ini.'
}
function formatHistoryTime(value?: string | null) {
  return value ? new Date(value).toLocaleString('id-ID', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) : 'Waktu belum tersedia'
}

function formatDepositDueDate(value?: string | null) {
  if (!value) return '-'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return value.split('T')[0] || value

  return new Intl.DateTimeFormat('id-ID', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
  }).format(date)
}

function formatDepositPaidAt(value?: string | null) {
  if (!value) return '-'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return value.split('T')[0] || value

  return new Intl.DateTimeFormat('id-ID', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  }).format(date)
}

function monthKey(value?: string | null) {
  if (!value) return ''
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return ''

  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`
}
function recentMonthKeys(count: number) {
  const now = new Date()

  return Array.from({ length: count }, (_, index) => {
    const date = new Date(now.getFullYear(), now.getMonth() - index, 1)

    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`
  })
}
function formatMonthLabel(key: string) {
  const [year, month] = key.split('-').map(Number)
  if (!year || !month) return 'Periode tidak diketahui'

  return new Intl.DateTimeFormat('id-ID', { month: 'long', year: 'numeric' }).format(new Date(year, month - 1, 1))
}
function getErrorMessage(error: unknown, fallback: string) {
  if (axios.isAxiosError(error)) {
    const data = error.response?.data as { message?: string; errors?: Record<string, string[]> } | undefined
    const firstError = data?.errors ? Object.values(data.errors).flat()[0] : undefined
    return firstError ?? data?.message ?? (error.response?.status ? `HTTP ${error.response.status}` : error.message) ?? fallback
  }

  return error instanceof Error ? error.message : fallback
}

function getLoginErrorMessage(error: unknown) {
  if (axios.isAxiosError(error)) {
    const message = getErrorMessage(error, 'Login driver gagal')
    if (message === 'Email tidak terdaftar sebagai driver') return 'Akun Google ini belum terdaftar sebagai driver'
    if (message === 'Akun Google tidak sesuai dengan driver ini') return 'Akun Google berbeda. Hubungi admin.'
    if (error.response?.status === 423) return 'Login terkunci sementara. Coba lagi 15 menit atau hubungi admin.'
    return message
  }

  const message = getErrorMessage(error, 'Login driver gagal')
  return /401|unauthorized/i.test(message) ? 'Login Google gagal. Silakan coba lagi.' : message
}
function assetUrl(path: string) {
  if (!path) return ''
  if (/^https?:\/\//i.test(path)) return normalizeRemoteAsset(path)

  const cleanPath = path.startsWith('/') ? path : `/${path}`
  return `${APP_BASE}${cleanPath}`
}
function formatChatTime(value?: string) { return value ? new Date(value).toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' }) : '' }
function redactMapText(message?: string | null) {
  if (!message) return ''

  return message
    .replace(/https?:\/\/(?:www\.)?(?:google\.com\/maps|maps\.google\.com|maps\.app\.goo\.gl)\S*/giu, '[lokasi disembunyikan]')
    .replace(/(-?\d{1,2}(?:\.\d+)?)\s*,\s*(-?\d{1,3}(?:\.\d+)?)/g, '[koordinat disembunyikan]')
}

function autoResizeTextarea(textarea: HTMLTextAreaElement) {
  textarea.style.height = 'auto'
  textarea.style.height = `${Math.min(textarea.scrollHeight, 132)}px`
}

function withReplyPrefix(text: string, reply?: ReplyTarget | null) {
  if (!reply) return text

  return `Membalas:\n> ${String(reply.text).replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 90)}\n\n${text}`
}

export default App

