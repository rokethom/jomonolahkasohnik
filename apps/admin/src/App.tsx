import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import type { FormEvent, ReactNode } from 'react'
import Echo from 'laravel-echo'
import Pusher from 'pusher-js'
import './App.css'

declare global {
  interface Window {
    Pusher?: typeof Pusher
  }
}

type Role = 'admin' | 'gm' | 'hrd' | 'manager' | 'spv' | 'operator' | 'eksekutor' | 'driver' | 'customer'
type View = 'dashboard' | 'orders' | 'request-orders' | 'users' | 'drivers' | 'settings' | 'pricing' | 'branches' | 'geofence' | 'locations' | 'reports' | 'chats' | 'internal-chat' | 'manual-order'
type AdminHistoryState = {
  jojoAdminView?: View
}
type BeforeInstallPromptEvent = Event & {
  prompt: () => Promise<void>
  userChoice: Promise<{ outcome: 'accepted' | 'dismissed'; platform: string }>
}

type User = {
  id: number
  username: string
  name: string
  email: string
  phone: string | null
  role: Role
  branch_id: number | null
  branch: string | null
  branch_area?: string | null
  is_active: boolean
  is_suspended: boolean
  driver_state?: 'online' | 'offline'
  driver_bansos_amount?: number | null
  driver_bpjs_jht_enabled?: boolean
  is_ladies_driver?: boolean
  vehicle_seat_rows?: number | null
}
type DriverRow = User & {
  driver_id: number | null
  driver_status: 'active' | 'inactive' | 'suspended' | 'suspended_unpaid'
  google_bound?: boolean
  google_email?: string | null
  last_login_at?: string | null
  last_login_ip?: string | null
  last_login_device?: string | null
  auth_failed_attempts?: number
  auth_locked_until?: string | null
  auth_suspended_at?: string | null
  suspended_until: string | null
  suspension_reason: string | null
  oper_handle_count: number
  vehicle_type?: 'motor' | 'mobil' | string | null
  vehicle_seat_rows?: number | null
  is_ladies_driver?: boolean
  allowed_service_types?: string[]
  performance?: {
    rating_average: number
    ratings_count: number
    rating_score?: number
    rating_confidence?: number
    completed_orders_count: number
    today_completed_orders_count?: number
    month_completed_orders_count?: number
    cancelled_orders_count: number
    today_cancelled_orders_count?: number
    month_cancelled_orders_count?: number
    suspensions_count: number
    oper_handle_requests_count: number
    unpaid_deposits_count: number
    completed_revenue: number
    today_revenue?: number
    month_revenue?: number
    last_completed_at?: string | null
    online_score: number
  }
  suspensions: { id: number; reason: string; duration: number; start_at: string | null; end_at: string | null; status: string }[]
}

type Order = {
  id: number
  code: string
  customer: string | null
  driver: string | null
  service: string
  service_code?: string | null
  source?: string | null
  status: string
  cancel_reason?: string | null
  branch: string | null
  branch_area?: string | null
  pickup_address?: string | null
  destination_address?: string | null
  distance_km?: number | string | null
  price: number
  service_charge: number
  extra_charge: number
  total: number
  payment_method?: 'cash' | 'transfer' | 'qris' | string | null
  payment_label?: string | null
  payment_meta?: Record<string, unknown> | null
  preferred_vehicle_type?: 'motor' | 'mobil' | string | null
  required_vehicle_seat_rows?: 2 | 3 | number | null
  driver_preference?: 'general' | 'ladies' | string | null
  notes?: string | null
  raw_text?: string | null
  pricing_breakdown?: Record<string, unknown> | null
  waiting_seconds?: number
  sla_status?: 'normal' | 'warning' | 'critical' | 'assigned' | string
  suggested_drivers?: DriverCandidate[]
  customer_preferences?: CustomerPreference
  created_at: string | null
  updated_at?: string | null
}
type DriverCandidate = { id: number; name: string; phone?: string | null; vehicle_type?: string | null; vehicle_seat_rows?: number | null; is_ladies_driver?: boolean; branch?: string | null; branch_area?: string | null; rating_average?: number; is_favorite?: boolean }
type CustomerPreference = { favorite_driver?: { id: number; name: string } | null; blocked_drivers?: string[]; notes?: string | null }
type ManualOrderPayload = {
  service_type: string
  branch_id?: number | null
  pickup_address: string
  pickup_lat?: number
  pickup_lng?: number
  destination_address: string
  destination_lat?: number
  destination_lng?: number
  stops?: number
  notes?: string
  points?: Array<{ label?: string; address: string }>
  items?: Array<{ name: string; quantity?: number; price?: number; notes?: string }>
  payment_method?: 'cash' | 'transfer' | 'qris'
}
type ManualOrderPreview = {
  intent: 'order_preview' | 'service_selected' | 'fallback_form' | 'service_menu' | string
  selected_service?: string | null
  service_type?: string | null
  reply?: string | null
  message?: string | null
  quote?: {
    distance?: number
    distance_km?: number
    tarif?: number
    price?: number
    base_tarif_before_night?: number
    night_tariff_charge?: number
    night_tariff_percent?: number
    service_fee?: number
    service_charge?: number
    extra_charge?: number
    final_price?: number
    total_price?: number
  } | null
  order_payload?: ManualOrderPayload | null
  parsed?: Record<string, unknown> | null
}

type Branch = { id: number; name: string; area: string | null; latitude: string; longitude: string; radius_km?: string | number | null; geofence_areas_count?: number }
type ServiceRow = { id: number; name: string; code: string }
type PriceSetting = { id: number; name: string; branch_id: number | null; min_km: string; max_km: string | null; price: number | null; is_formula: boolean; per_km_rate: number | null; subtract_value: number | null; branch?: Branch | null }
type Geofence = { id: number; name: string; branch?: Branch | null; center_latitude: string; center_longitude: string; radius_meters: number; is_active: boolean }
type LocationLog = { id: number; user: string | null; branch: string | null; latitude: number; longitude: number; is_valid: boolean; is_suspicious: boolean; reason: string | null; created_at: string | null }
type Chat = { id: number; order_id?: number | null; order_code: string | null; type?: string; customer: string | null; driver: string | null; operator: string | null; branch?: string | null; status: string; sla_status?: string | null; latest_message?: string | null; last_message?: string | null; unread_count?: number; last_customer_message_at?: string | null; updated_at: string | null }
type AdminChatMessage = { id: number; chat_id: number; sender_id: number | null; sender_type: string; sender_name?: string | null; message: string; image_url?: string | null; audio_url?: string | null; audio_duration?: number | null; created_at?: string | null }
type ChatDetail = { chat: Chat; messages: AdminChatMessage[]; cancel_request?: { id: number; status: string; reason: string; image_url?: string | null } | null }
type InternalChatRoom = { id: number; name: string; type: 'global' | 'branch' | 'private' | string; branch_id?: number | null; branch?: string | null; branch_area?: string | null; participants_count?: number; participants?: Array<{ id: number; name: string; role: Role | string }>; last_message?: string | null; last_sender?: string | null; unread_count?: number; updated_at?: string | null }
type InternalChatMessage = { id: number; room_id: number; sender_id: number | null; sender_name: string; sender_role?: Role | string | null; message: string; metadata?: Record<string, unknown> | null; created_at?: string | null }
type InternalChatDetail = { room: InternalChatRoom; messages: InternalChatMessage[] }
type AuditLog = { id: number; user: string; role: Role | null; action: string; subject_type: string; subject_id: number | null; subject_label: string | null; metadata?: Record<string, unknown> | null; created_at: string | null }
type OperatorPerformance = { id: number; name: string; role: Role; branch: string | null; branch_area?: string | null; handled_chats_count: number; active_chats_count: number; rating_average: number; rating_score?: number; rating_confidence?: number; ratings_count: number; late_response_count: number }
type Stats = { total_users: number; total_drivers: number; active_orders: number; suspended_drivers: number }
type SystemSettings = {
  multi_order_enabled: boolean
  max_multi_order: number
  order_close_enabled?: boolean
  order_close_start?: string
  order_close_end?: string
  order_close_message?: string
  night_tariff_enabled?: boolean
  night_tariff_rules?: NightTariffRule[]
  feedback_templates?: {
    driver_accepted?: string
    order_auto_cancelled?: string
    order_cancelled?: string
  }
}
type NightTariffRule = { area?: string | null; start: string; end: string; percent: number }
type DepositReportRow = {
  driver: string
  area: string
  orders_count: number
  base_service_omset: number
  base_service_deposit: number
  previous_bill: number
  bpjs_jht: number
  bpjs: number
  previous_cashback_reward: number
  bill_before_bansos: number
  bansos: number
  total_bill: number
  paid_amount: number
  remaining_bill: number
  paid_at?: string | null
  next_cashback: number
}
type Permissions = {
  backend_access: boolean
  names: string[]
  assignable_roles: Role[]
  can_manage_policy: boolean
  can_manage_users: boolean
  can_suspend_drivers: boolean
  can_unsuspend_drivers?: boolean
  can_manage_driver_auth?: boolean
  can_manage_system_settings: boolean
  can_edit_order_price: boolean
  can_create_manual_order: boolean
  can_view_report?: boolean
  can_export_report?: boolean
  can_monitor_live_order?: boolean
  can_monitor_live_chat?: boolean
  can_use_internal_chat?: boolean
  can_approve_cancel_order?: boolean
  can_reject_cancel_order?: boolean
  can_assign_driver?: boolean
}
type Bootstrap = {
  me: User
  permissions: Permissions
  system_settings: SystemSettings
  stats: Stats
  users: User[]
  drivers: DriverRow[]
  operator_performance?: OperatorPerformance[]
  orders: Order[]
  branches: Branch[]
  services: ServiceRow[]
  price_settings: PriceSetting[]
  geofences: Geofence[]
  location_logs: LocationLog[]
  chats: Chat[]
  audit_logs: AuditLog[]
}

function resolveApiBase() {
  const configured = import.meta.env.VITE_API_BASE_URL ?? import.meta.env.VITE_API_URL ?? 'http://127.0.0.1:8000/api'
  const isPublicHost = !['localhost', '127.0.0.1', '::1'].includes(window.location.hostname)
  const pointsToLocalhost = /^https?:\/\/(localhost|127\.0\.0\.1)(:\d+)?\/api\/?$/i.test(configured)

  return isPublicHost && pointsToLocalhost ? 'https://api.situapps.tech/api' : configured
}

const API_BASE = resolveApiBase()
const APP_BASE = API_BASE.replace(/\/api$/, '')

const roleLabels: Record<Role, string> = {
  admin: 'Admin',
  gm: 'GM',
  hrd: 'HRD',
  manager: 'Manager',
  spv: 'SPV',
  operator: 'Operator',
  eksekutor: 'Eksekutor',
  driver: 'Driver',
  customer: 'Customer',
}

const roleColors: Record<Role, string> = {
  admin: 'role-danger',
  gm: 'role-gold',
  hrd: 'role-purple',
  manager: 'role-orange',
  spv: 'role-blue',
  operator: 'role-cyan',
  eksekutor: 'role-blue',
  driver: 'role-green',
  customer: 'role-muted',
}

type MenuItem = { id: View; label: string; icon: string }
type MenuGroup = { id: string; label: string; icon: string; items: MenuItem[] }

const menuGroups: MenuGroup[] = [
  {
    id: 'overview',
    label: 'Overview',
    icon: 'grid',
    items: [
      { id: 'dashboard', label: 'Dashboard', icon: 'grid' },
      { id: 'reports', label: 'Reports', icon: 'chart' },
    ],
  },
  {
    id: 'operations',
    label: 'Operations',
    icon: 'bag',
    items: [
      { id: 'orders', label: 'Order Operations', icon: 'bag' },
      { id: 'request-orders', label: 'Request Order', icon: 'receipt' },
      { id: 'chats', label: 'Chat Monitor', icon: 'chat' },
      { id: 'internal-chat', label: 'Internal Chat', icon: 'chat' },
      { id: 'manual-order', label: 'Manual Order', icon: 'plus' },
    ],
  },
  {
    id: 'management',
    label: 'Management',
    icon: 'users',
    items: [
      { id: 'users', label: 'Users', icon: 'users' },
      { id: 'drivers', label: 'Driver Management', icon: 'truck' },
      { id: 'branches', label: 'Branches', icon: 'building' },
    ],
  },
  {
    id: 'area',
    label: 'Area & System',
    icon: 'map',
    items: [
      { id: 'geofence', label: 'Geofence', icon: 'map' },
      { id: 'locations', label: 'Location Logs', icon: 'pin' },
      { id: 'pricing', label: 'Pricing & Policy', icon: 'cash' },
      { id: 'settings', label: 'System Settings', icon: 'settings' },
    ],
  },
]

const allMenus = menuGroups.flatMap((group) => group.items)
const adminViews = allMenus.map((item) => item.id)

function adminViewFromHistoryState(state: unknown) {
  const maybeState = state as AdminHistoryState | null
  const value = maybeState?.jojoAdminView
  return value && adminViews.includes(value) ? value : null
}

function allowedViewsFor(role: Role, permissions: Permissions): View[] {
  if (role === 'admin' || role === 'gm') return allMenus.map((item) => item.id)

  const views = new Set<View>(['dashboard'])
  if (permissions.can_monitor_live_order) {
    views.add('orders')
    views.add('request-orders')
  }
  if (permissions.can_assign_driver) views.add('drivers')
  if (permissions.can_manage_users) views.add('users')
  if (permissions.can_suspend_drivers || permissions.can_unsuspend_drivers) views.add('drivers')
  if (permissions.can_edit_order_price || permissions.can_manage_policy) views.add('pricing')
  if (permissions.can_view_report) views.add('reports')
  if (permissions.can_monitor_live_chat) views.add('chats')
  if (permissions.can_use_internal_chat) views.add('internal-chat')
  if (permissions.can_create_manual_order) views.add('manual-order')
  if (['manager', 'spv', 'operator'].includes(role)) views.add('locations')

  return allMenus.map((item) => item.id).filter((id) => views.has(id))
}

function App() {
  const [token, setToken] = useState(() => localStorage.getItem('admin_token') || localStorage.getItem('token') || '')
  const [data, setData] = useState<Bootstrap | null>(null)
  const [view, setView] = useState<View>('dashboard')
  const [query, setQuery] = useState('')
  const [roleFilter, setRoleFilter] = useState<Role | 'all'>('all')
  const [isUserFormOpen, setUserFormOpen] = useState(false)
  const [isLoading, setLoading] = useState(false)
  const [error, setError] = useState('')
  const [darkMode, setDarkMode] = useState(() => localStorage.getItem('admin_theme') === 'dark')
  const [isMobileNavOpen, setMobileNavOpen] = useState(false)
  const [openMenuGroups, setOpenMenuGroups] = useState<Record<string, boolean>>({
    overview: true,
    operations: true,
    management: true,
    area: false,
  })
  const [lastSyncedAt, setLastSyncedAt] = useState<Date | null>(null)
  const isRefreshingRef = useRef(false)
  const isBrowserBackRef = useRef(false)

  const clearAuthSession = useCallback(() => {
    localStorage.removeItem('admin_token')
    localStorage.removeItem('token')
    setToken('')
    setData(null)
    setError('')
    setLoading(false)
    isRefreshingRef.current = false
  }, [])

  const api = useMemo(() => makeApi(token, clearAuthSession), [clearAuthSession, token])

  const load = useCallback(async (silent = false) => {
    if (!token) return
    if (isRefreshingRef.current) return
    isRefreshingRef.current = true
    if (!silent) setLoading(true)
    setError('')
    try {
      setData(await api<Bootstrap>('/admin/bootstrap'))
      setLastSyncedAt(new Date())
    } catch (error) {
      if (isAuthError(error)) return
      const message = error instanceof Error ? error.message : 'Failed to load admin data'
      setError(message.includes('403') ? 'Akun ini tidak memiliki akses admin.' : message)
    } finally {
      if (!silent) setLoading(false)
      isRefreshingRef.current = false
    }
  }, [api, token])

  useEffect(() => {
    const timer = window.setTimeout(() => {
      void load()
    }, 0)

    return () => window.clearTimeout(timer)
  }, [load])

  useEffect(() => {
    if (!token) return

    const interval = window.setInterval(() => {
      void load(true)
    }, 5000)

    const refreshWhenVisible = () => {
      if (document.visibilityState === 'visible') void load(true)
    }

    document.addEventListener('visibilitychange', refreshWhenVisible)

    return () => {
      window.clearInterval(interval)
      document.removeEventListener('visibilitychange', refreshWhenVisible)
    }
  }, [load, token])

  useEffect(() => {
    if (!token) return

    const echo = makeEcho(token)
    const ordersChannel = echo.private('orders')
    ordersChannel.listen('.order.created', () => void load(true))
    ordersChannel.listen('.order.status.updated', () => void load(true))
    ordersChannel.listen('.driver.accepted', () => void load(true))

    return () => {
      echo.leave('orders')
    }
  }, [load, token])

  useEffect(() => {
    if (!token) return

    const initialView = adminViewFromHistoryState(window.history.state) ?? view
    const baseState = { ...(window.history.state as AdminHistoryState | null), jojoAdminView: initialView }
    window.history.replaceState(baseState, document.title, '/')
    window.history.pushState(baseState, document.title, '/')
    if (initialView !== view) setView(initialView)

    const handlePopState = (event: PopStateEvent) => {
      const nextView = adminViewFromHistoryState(event.state) ?? 'dashboard'
      isBrowserBackRef.current = true
      setView(nextView)
    }

    window.addEventListener('popstate', handlePopState)

    return () => {
      window.removeEventListener('popstate', handlePopState)
    }
  }, [token])

  useEffect(() => {
    if (!token) return

    if (isBrowserBackRef.current) {
      isBrowserBackRef.current = false
      return
    }

    if (adminViewFromHistoryState(window.history.state) === view) return

    window.history.pushState(
      { ...(window.history.state as AdminHistoryState | null), jojoAdminView: view },
      document.title,
      '/',
    )
  }, [token, view])

  useEffect(() => {
    const closeBackdropModal = (event: MouseEvent) => {
      const target = event.target as HTMLElement | null
      if (!target?.classList.contains('modal-backdrop')) return

      target.querySelector<HTMLButtonElement>('.modal-header .icon-button, .modal-actions .secondary-button')?.click()
    }

    document.addEventListener('mousedown', closeBackdropModal)

    return () => document.removeEventListener('mousedown', closeBackdropModal)
  }, [])

  if (!token) {
    return <LoginScreen onLogin={(nextToken) => {
      localStorage.setItem('admin_token', nextToken)
      localStorage.removeItem('token')
      setError('')
      setData(null)
      setToken(nextToken)
    }} />
  }

  if (!data) {
    return <div className="loading-screen">{isLoading ? 'Memuat data admin...' : error || 'Data belum tersedia'}</div>
  }

  const allowedViews = allowedViewsFor(data.me.role, data.permissions)
  const safeView = allowedViews.includes(view) ? view : 'dashboard'
  const visibleMenuGroups = menuGroups
    .map((group) => ({ ...group, items: group.items.filter((item) => allowedViews.includes(item.id)) }))
    .filter((group) => group.items.length > 0)
  const filteredUsers = data.users.filter((user) => {
    const text = `${user.username} ${user.name} ${user.email}`.toLowerCase()
    return text.includes(query.toLowerCase()) && (roleFilter === 'all' || user.role === roleFilter)
  })

  const logout = async () => {
    try {
      await api('/auth/logout', { method: 'POST' })
    } finally {
      sessionStorage.clear()
      clearAuthSession()
    }
  }

  const refresh = async () => {
    await load(true)
  }

  const toggleDarkMode = () => {
    setDarkMode((current) => {
      const next = !current
      localStorage.setItem('admin_theme', next ? 'dark' : 'light')
      return next
    })
  }

  return (
    <div className={darkMode ? 'admin-shell dark' : 'admin-shell'}>
      <button className={isMobileNavOpen ? 'mobile-scrim open' : 'mobile-scrim'} type="button" aria-label="Close navigation" onClick={() => setMobileNavOpen(false)} />
      <aside className={isMobileNavOpen ? 'sidebar open' : 'sidebar'}>
        <div className="brand"><div className="brand-mark">J</div><div><strong>Jojo Admin</strong><span>Operations Dashboard</span></div></div>
        <nav className="nav-list" aria-label="Admin navigation">
          {visibleMenuGroups.map((group) => {
            const hasActiveItem = group.items.some((item) => item.id === safeView)
            const isOpen = openMenuGroups[group.id] || hasActiveItem

            return (
              <div className={hasActiveItem ? 'nav-group active' : 'nav-group'} key={group.id}>
                <button
                  type="button"
                  className="nav-group-toggle"
                  onClick={() => setOpenMenuGroups((current) => ({ ...current, [group.id]: !isOpen }))}
                  aria-expanded={isOpen}
                >
                  <span><Icon name={group.icon} />{group.label}</span>
                  <Icon name="chevron" />
                </button>
                {isOpen && (
                  <div className="nav-group-items">
                    {group.items.map((item) => (
                      <button key={item.id} type="button" className={safeView === item.id ? 'nav-item active' : 'nav-item'} onClick={() => { setView(item.id); setMobileNavOpen(false) }}>
                        <Icon name={item.icon} /><span>{item.label}</span>
                      </button>
                    ))}
                  </div>
                )}
              </div>
            )
          })}
        </nav>
        <div className="sidebar-card">
          <span>Authenticated as</span>
          <strong>{data.me.name}</strong>
          <RoleBadge role={data.me.role} />
          <div className="sidebar-actions">
            {data.permissions.can_manage_users && <button className="sidebar-action-button" type="button" onClick={() => { setUserFormOpen(true); setMobileNavOpen(false) }}><Icon name="plus" />New User</button>}
            <PwaInstallButton />
            <button className="sidebar-action-button danger" type="button" onClick={logout}><Icon name="logout" />Logout</button>
          </div>
        </div>
      </aside>

        <main className="main">
          <header className="topbar">
            <button className="mobile-menu-button" type="button" aria-label="Open navigation" onClick={() => setMobileNavOpen(true)}><Icon name="grid" />Menu</button>
            <div className="topbar-title"><h1>{titleFor(safeView)}</h1><p>{subtitleFor(data)}</p>{error && <p className="error-text">{error}</p>}</div>
            <div className="topbar-actions">
              <div className="search"><Icon name="search" /><input value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Cari data" /></div>
              <div className="auto-refresh-pill" title="Data admin tersinkron otomatis tiap 5 detik">
                <span />
                Auto refresh
                <small>{lastSyncedAt ? formatShortTime(lastSyncedAt.toISOString()) : 'sync'}</small>
              </div>
              <button className="theme-switch" type="button" onClick={toggleDarkMode} aria-label={darkMode ? 'Switch to light theme' : 'Switch to dark theme'} title={darkMode ? 'Light theme' : 'Dark theme'}>
                <span><Icon name={darkMode ? 'sun' : 'moon'} /></span>
              </button>
              <button
                className="profile-settings-button"
                type="button"
                aria-label="Profile settings"
                title="Profile settings"
                onClick={() => {
                  setQuery(data.me.username)
                  setView(allowedViews.includes('users') ? 'users' : 'dashboard')
                }}
              >
                <Icon name="settings" />
              </button>
            </div>
          </header>

        {safeView === 'dashboard' && <Dashboard data={data} api={api} onChanged={refresh} onNavigate={setView} onOpenOrder={(code) => { setQuery(code); setView('orders') }} />}
        {safeView === 'orders' && <OrdersTable orders={data.orders} auditLogs={data.audit_logs} searchQuery={query} permissions={data.permissions} api={api} onChanged={refresh} />}
        {safeView === 'request-orders' && <RequestOrdersPanel orders={data.orders} searchQuery={query} />}
        {safeView === 'users' && <UsersPanel users={filteredUsers} branches={data.branches} me={data.me} roleFilter={roleFilter} onRoleFilterChange={setRoleFilter} permissions={data.permissions} api={api} onChanged={refresh} />}
        {safeView === 'drivers' && <DriverManagementPanel drivers={data.drivers} services={data.services} permissions={data.permissions} api={api} onChanged={refresh} />}
        {safeView === 'settings' && <SystemSettingsPanel settings={data.system_settings} permissions={data.permissions} api={api} onChanged={refresh} />}
        {safeView === 'pricing' && <PricingPanel settings={data.price_settings} branches={data.branches} permissions={data.permissions} api={api} onChanged={refresh} />}
        {safeView === 'reports' && <ReportsPanel data={data} api={api} token={token} />}
        {safeView === 'chats' && <AdminChatPanel initialChats={data.chats} api={api} me={data.me} token={token} permissions={data.permissions} onOpenOrder={(code) => { setQuery(code); setView('orders') }} />}
        {safeView === 'internal-chat' && <InternalChatPanel api={api} me={data.me} branches={data.branches} onOpenOrder={(code) => { setQuery(code); setView('orders') }} />}
        {safeView === 'manual-order' && <ManualOrderPanel me={data.me} branches={data.branches} api={api} onChanged={refresh} />}
        {safeView === 'branches' && <BranchesPanel branches={data.branches} me={data.me} api={api} onChanged={refresh} />}
        {safeView === 'geofence' && <GeofencePanel geofences={data.geofences} />}
        {safeView === 'locations' && <LocationLogsPanel logs={data.location_logs} />}
      </main>

      {isUserFormOpen && <UserFormModal permissions={data.permissions} branches={data.branches} services={data.services} api={api} onClose={() => setUserFormOpen(false)} onCreated={async (password) => { alert(`Password sementara: ${password}`); await refresh(); setUserFormOpen(false) }} />}
    </div>
  )
}

function LoginScreen({ onLogin }: { onLogin: (token: string) => void }) {
  const [error, setError] = useState('')
  const submit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    setError('')
    const form = new FormData(event.currentTarget)
    try {
      const response = await fetch(`${API_BASE}/auth/login`, {
        method: 'POST',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
        body: JSON.stringify({ email: form.get('email'), password: form.get('password') }),
      })
      const payload = await response.json()
      if (!response.ok) throw new Error(payload.message || 'Login failed')
      onLogin(payload.token)
    } catch (error) {
      const message = error instanceof Error ? error.message : 'Login failed'
      setError(message === 'Unauthorized' || message.includes('401') ? 'Email atau password salah.' : message)
    }
  }

  return (
    <main className="login-screen">
      <form className="login-card" onSubmit={submit}>
        <div className="brand"><div className="brand-mark">J</div><div><strong>Jojo Admin</strong><span>Secure Login</span></div></div>
        <label>Email<input name="email" type="email" required placeholder="admin@jojo.test" /></label>
        <label>Password<input name="password" type="password" required /></label>
        {error && <div className="error-text">{error}</div>}
        <button className="primary-button" type="submit">Login</button>
        <PwaInstallButton />
      </form>
    </main>
  )
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

function Dashboard({ data, api, onChanged, onNavigate, onOpenOrder }: { data: Bootstrap; api: ApiClient; onChanged: () => Promise<void>; onNavigate: (view: View) => void; onOpenOrder: (code: string) => void }) {
  if (data.me.role === 'eksekutor') {
    return <EksekutorDashboard data={data} api={api} onChanged={onChanged} onNavigate={onNavigate} onOpenOrder={onOpenOrder} />
  }

  const activeOrders = data.orders.filter(isActiveOrderStatus).length || data.stats.active_orders
  const onlineDrivers = data.drivers.filter((driver) => driver.driver_state === 'online' && driver.driver_status === 'active').length
  const unassignedOrders = data.orders.filter((order) => isWaitingDriverStatus(order.status) && !order.driver).length
  const unansweredChats = data.chats.filter((chat) => Number(chat.unread_count ?? 0) > 0 || ['waiting', 'open'].includes(String(chat.status).toLowerCase())).length
  const nightTariffActive = isNightTariffCurrentlyActive(data.system_settings)
  const topDriver = topDriverToday(data.drivers, data.orders)

  return (
    <div className="dashboard-grid">
      <StatsRow stats={[
        { label: 'Active Order Realtime', value: activeOrders, icon: 'bag', tone: 'amber', action: 'Orders', onClick: () => onNavigate('orders') },
        { label: 'Online Driver', value: onlineDrivers, icon: 'truck', tone: 'green', action: 'Drivers', onClick: () => onNavigate('drivers') },
        { label: 'Belum Diambil', value: unassignedOrders, icon: 'receipt', tone: 'violet', action: 'Cari driver', onClick: () => onNavigate('orders') },
        { label: 'Chat Belum Dibalas', value: unansweredChats, icon: 'chat', tone: 'red', action: 'Buka chat', onClick: () => onNavigate('chats') },
      ]} />
      <section className="insight-grid">
        <button className={nightTariffActive ? 'insight-card active' : 'insight-card'} type="button" onClick={() => onNavigate('settings')}>
          <span>Tarif Malam</span>
          <strong>{nightTariffActive ? 'Aktif' : data.system_settings.night_tariff_enabled ? 'Standby' : 'Nonaktif'}</strong>
          <small>{nightTariffActive ? 'Rule sedang berjalan sekarang' : 'Cek jadwal di System Settings'}</small>
        </button>
        <article className="top-driver-card">
          <div>
            <span>Top Driver Hari Ini</span>
            <strong>{topDriver.name}</strong>
          </div>
          <div className="top-driver-metrics">
            <p><span>Order terbanyak</span><b>{topDriver.orders}</b></p>
            <p><span>Rating terbaik</span><b>{topDriver.rating}</b></p>
            <p><span>Cancel terendah</span><b>{topDriver.cancel}</b></p>
          </div>
        </article>
      </section>
      <section className="dashboard-live-grid">
        <LiveOrders orders={data.orders} onOpenOrder={onOpenOrder} onViewAll={() => onNavigate('orders')} />
        <LiveChatDashboard chats={data.chats} onNavigate={() => onNavigate('chats')} onOpenOrder={onOpenOrder} />
      </section>
      <DriverPerformanceSnapshot drivers={data.drivers} onOpenDrivers={() => onNavigate('drivers')} />
      <OperatorPerformanceSnapshot operators={data.operator_performance ?? []} onOpenChats={() => onNavigate('chats')} />
      <RecentActivity orders={data.orders} onOpenOrder={onOpenOrder} />
      <PriceEditActivity auditLogs={data.audit_logs} />
    </div>
  )
}

function StatsRow({ stats }: { stats: { label: string; value: number; icon: string; tone: string; action?: string; onClick?: () => void }[] }) {
  return <section className="stats-row">{stats.map((stat) => <article className={`stat-card ${stat.tone}`} key={stat.label} role={stat.onClick ? 'button' : undefined} tabIndex={stat.onClick ? 0 : undefined} onClick={stat.onClick} onKeyDown={(event) => { if (stat.onClick && (event.key === 'Enter' || event.key === ' ')) stat.onClick() }}><div className="stat-icon"><Icon name={stat.icon} /></div><span>{stat.label}</span><strong>{stat.value}</strong>{stat.action && <small className="stat-action">{stat.action}</small>}<div className="sparkline"><i></i><i></i><i></i><i></i><i></i></div></article>)}</section>
}

function EksekutorDashboard({ data, api, onChanged, onNavigate, onOpenOrder }: { data: Bootstrap; api: ApiClient; onChanged: () => Promise<void>; onNavigate: (view: View) => void; onOpenOrder: (code: string) => void }) {
  const [assignOrder, setAssignOrder] = useState<Order | null>(null)
  const [dispatchMessage, setDispatchMessage] = useState('')
  const dispatchOrders = data.orders.filter(isDispatchPendingOrder)
  const criticalOrders = dispatchOrders.filter((order) => order.sla_status === 'critical' || Number(order.waiting_seconds ?? 0) >= 600)
  const idleDrivers = data.drivers.filter((driver) => driver.driver_state === 'online' && driver.driver_status === 'active' && !data.orders.some((order) => order.driver === driver.name && isActiveOrderStatus(order)))
  const acceptedDrivers = data.orders.filter((order) => order.driver && isActiveOrderStatus(order)).length

  return (
    <div className="eksekutor-dashboard">
      {criticalOrders.length > 0 && <div className="critical-dispatch-alert" role="alert">Critical pending: {criticalOrders.length} order menunggu terlalu lama.</div>}
      <section className="dispatch-hero">
        <div>
          <span className="eyebrow">Tactical Dispatch Center</span>
          <h2>{data.me.branch_area || data.me.branch || 'Area Eksekutor'}</h2>
          <p>Realtime queue, idle driver recommendation, dan manual assign untuk area sendiri.</p>
        </div>
        <div className="dispatch-clock">
          <strong>{formatShortTime(new Date().toISOString())}</strong>
          <span>Realtime dispatch</span>
        </div>
      </section>

      <StatsRow stats={[
        { label: 'Pending Dispatch', value: dispatchOrders.filter((order) => order.status === 'CREATED').length, icon: 'receipt', tone: criticalOrders.length ? 'red' : 'amber', action: 'Assign now' },
        { label: 'Driver Idle', value: idleDrivers.length, icon: 'truck', tone: 'green', action: 'Area sendiri', onClick: () => onNavigate('drivers') },
        { label: 'Driver Accepted', value: acceptedDrivers, icon: 'bag', tone: 'violet', action: 'Live route', onClick: () => onNavigate('orders') },
        { label: 'Pending Order', value: dispatchOrders.length, icon: 'chat', tone: 'red', action: 'Queue' },
      ]} />

      <section className="dispatch-layout">
        <section className="panel live-dispatch-panel">
          <div className="section-head compact-head">
            <div>
              <h2>Live Order Queue</h2>
              <p>Order waiting driver, pending dispatch, dan pending order area.</p>
            </div>
            <span className="status warning">{dispatchOrders.length} queue</span>
          </div>
          <div className="dispatch-queue">
            {dispatchOrders.length === 0 && <EmptyPanel title="Queue kosong" copy="Order pending area akan muncul realtime di sini." />}
            {dispatchOrders.map((order) => (
              <article className={order.sla_status === 'critical' ? 'dispatch-order-row critical' : 'dispatch-order-row'} key={order.id}>
                <button className="order-code-link inline" type="button" onClick={() => onOpenOrder(order.code)}>{order.code}</button>
                <div>
                  <strong>{order.customer || 'Customer'}</strong>
                  <span>{order.branch_area || order.branch || '-'} Â· {statusDispatchLabel(order.status)} Â· waiting {formatWaitingTime(order.waiting_seconds)}</span>
                </div>
                <div className="suggested-driver">
                  <small>Suggested</small>
                  <b>{order.suggested_drivers?.[0]?.name ?? 'Belum ada idle driver'}</b>
                </div>
                <div className="dispatch-row-actions">
                  <button className="secondary-button compact" type="button" onClick={() => void broadcastOrderToDrivers(api, order, setDispatchMessage)}>Broadcast</button>
                  <button className="primary-button compact" type="button" onClick={() => setAssignOrder(order)}>Assign Driver</button>
                </div>
              </article>
            ))}
          </div>
        </section>

        <aside className="panel customer-preference-panel">
          <PanelHeader title="Customer Preference" action="Priority" />
          {dispatchOrders.slice(0, 4).map((order) => (
            <article className="customer-pref-card" key={order.id}>
              <strong>{order.customer || order.code}</strong>
              <span>Favorite: {order.customer_preferences?.favorite_driver?.name ?? '-'}</span>
              <span>Blocked: {(order.customer_preferences?.blocked_drivers ?? []).join(', ') || '-'}</span>
              <small>{order.customer_preferences?.notes || 'Belum ada catatan customer.'}</small>
            </article>
          ))}
          {dispatchOrders.length === 0 && <EmptyPanel title="Tidak ada preference" copy="Preference muncul saat ada order pending." />}
        </aside>
      </section>

      <div className="floating-dispatch-actions">
        <button type="button" onClick={() => onNavigate('manual-order')}><Icon name="plus" />Order</button>
        <button type="button" disabled={!dispatchOrders[0]} onClick={() => dispatchOrders[0] && setAssignOrder(dispatchOrders[0])}><Icon name="truck" />Assign Driver</button>
        <button type="button" disabled={!dispatchOrders[0]} onClick={() => dispatchOrders[0] && void broadcastOrderToDrivers(api, dispatchOrders[0], setDispatchMessage)}><Icon name="shield" />Broadcast Driver</button>
        <button type="button" onClick={() => onNavigate('chats')}><Icon name="chat" />Chat Customer</button>
      </div>
      {dispatchMessage && <div className="dispatch-toast">{dispatchMessage}</div>}

      {assignOrder && <AssignDriverModal order={assignOrder} api={api} onClose={() => setAssignOrder(null)} onAssigned={async () => { await onChanged(); setAssignOrder(null) }} />}
    </div>
  )
}

async function broadcastOrderToDrivers(api: ApiClient, order: Order, setMessage: (value: string) => void) {
  try {
    const payload = await api<{ message?: string }>(`/admin/orders/${order.id}/broadcast-drivers`, { method: 'POST' })
    setMessage(payload.message ?? 'Broadcast driver terkirim.')
  } catch (error) {
    setMessage(error instanceof Error ? error.message : 'Broadcast driver gagal.')
  } finally {
    window.setTimeout(() => setMessage(''), 2600)
  }
}

function AssignDriverModal({ order, api, onClose, onAssigned }: { order: Order; api: ApiClient; onClose: () => void; onAssigned: () => Promise<void> }) {
  const [driverId, setDriverId] = useState(order.suggested_drivers?.[0]?.id ? String(order.suggested_drivers[0].id) : '')
  const [reason, setReason] = useState('Manual assign eksekutor')
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const candidates = order.suggested_drivers ?? []

  const submit = async (event: FormEvent) => {
    event.preventDefault()
    if (!driverId) return
    setSaving(true)
    setError('')
    try {
      await api(`/admin/orders/${order.id}/assign-driver`, {
        method: 'POST',
        body: JSON.stringify({ driver_id: Number(driverId), reason }),
      })
      await onAssigned()
    } catch (error) {
      setError(error instanceof Error ? error.message : 'Assign driver gagal')
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="modal-backdrop" role="presentation">
      <div className="modal dispatch-assign-modal" role="dialog" aria-modal="true">
        <div className="modal-header">
          <div><h2>Assign Driver</h2><p>{order.code} Â· {order.customer || 'Customer'} Â· {order.branch_area || order.branch}</p></div>
          <button type="button" className="icon-button" onClick={onClose}><Icon name="close" /></button>
        </div>
        <form className="user-form" onSubmit={submit}>
          <label>Driver area online & idle
            <select value={driverId} onChange={(event) => setDriverId(event.target.value)} required>
              <option value="">Pilih driver</option>
              {candidates.map((driver) => <option key={driver.id} value={driver.id}>{driver.is_favorite ? 'Favorit - ' : ''}{driver.name} - {vehicleLabel(driver.vehicle_type)}{driver.vehicle_type === 'mobil' ? ` ${driver.vehicle_seat_rows ?? 2} baris` : ''}{driver.is_ladies_driver ? ' - Ladies' : ''} - rating {driver.rating_average ?? 0}</option>)}
            </select>
          </label>
          <label>Alasan assign<textarea value={reason} onChange={(event) => setReason(event.target.value)} /></label>
          {candidates.length === 0 && <div className="notice danger">Tidak ada driver online idle di area ini.</div>}
          {error && <div className="error-text">{error}</div>}
          <div className="modal-actions">
            <button type="button" className="secondary-button" onClick={onClose}>Batal</button>
            <button className="primary-button" disabled={saving || !driverId}>{saving ? 'Assigning...' : 'Assign Driver'}</button>
          </div>
        </form>
      </div>
    </div>
  )
}

function LiveOrders({ orders, onOpenOrder, onViewAll }: { orders: Order[]; onOpenOrder: (code: string) => void; onViewAll: () => void }) {
  const liveOrders = orders.filter(isActiveOrderStatus).slice(0, 6)
  return (
    <section className="panel live-panel">
      <PanelHeader title="Live Order" action={`${liveOrders.length} aktif`} />
      <div className="live-order-list">
        {liveOrders.length === 0 && <EmptyPanel title="Tidak ada live order" copy="Order aktif akan muncul otomatis di sini." />}
        {liveOrders.map((order) => (
          <button className="live-order-row" type="button" key={order.id} onClick={() => onOpenOrder(order.code)}>
            <span className="live-dot" />
            <div>
              <strong>{order.code}</strong>
              <small>{order.customer || '-'} - {order.service}</small>
              <em>{displayBranchValue(order.branch, order.branch_area)} Â· {shortOrderRoute(order)}</em>
            </div>
            <b>Rp {Number(order.total || 0).toLocaleString('id-ID')}</b>
            <StatusBadge status={order.status} />
          </button>
        ))}
      </div>
      <button className="secondary-button compact" type="button" onClick={onViewAll}>Lihat semua order</button>
    </section>
  )
}

function LiveChatDashboard({ chats, onNavigate, onOpenOrder }: { chats: Chat[]; onNavigate: () => void; onOpenOrder: (code: string) => void }) {
  const liveChats = chats.slice(0, 6)
  return (
    <section className="panel live-panel">
      <PanelHeader title="Live Chat" action={`${liveChats.length} room`} />
      <div className="dashboard-chat-list">
        {liveChats.length === 0 && <EmptyPanel title="Belum ada chat" copy="Chat customer/operator akan muncul di sini." />}
        {liveChats.map((chat) => (
          <button className="dashboard-chat-row" type="button" key={chat.id} onClick={onNavigate}>
            <div className="activity-icon"><Icon name="chat" /></div>
            <div>
              <strong>{chat.customer || chat.driver || 'Chat room'}</strong>
              <span>{chat.latest_message || chat.last_message || 'Belum ada pesan terbaru'}</span>
              {chat.order_code && <em onClick={(event) => { event.stopPropagation(); onOpenOrder(chat.order_code!) }}>{chat.order_code}</em>}
            </div>
            {Number(chat.unread_count ?? 0) > 0 && <b>{chat.unread_count}</b>}
          </button>
        ))}
      </div>
      <button className="secondary-button compact" type="button" onClick={onNavigate}>Buka live chat</button>
    </section>
  )
}

function RecentActivity({ orders, onOpenOrder }: { orders: Order[]; onOpenOrder: (code: string) => void }) {
  return <section className="panel activity-panel compact-activity"><PanelHeader title="Recent order activity" action="Ringkas" /><div className="activity-list">{orders.slice(0, 5).map((order) => <div className="activity-item order-activity-item compact" key={order.id}><div><button className="order-code-link inline" type="button" onClick={() => onOpenOrder(order.code)}>{order.code}</button><span>{order.customer || '-'} - {order.service}</span>{order.status === 'CANCELLED' && <em>{order.cancel_reason || 'Dibatalkan tanpa alasan tersimpan.'}</em>}</div><StatusBadge status={order.status} /></div>)}</div></section>
}

function PriceEditActivity({ auditLogs }: { auditLogs: AuditLog[] }) {
  const logs = auditLogs.filter((log) => log.action === 'updated_order_price').slice(0, 6)

  return (
    <section className="panel activity-panel compact-activity">
      <PanelHeader title="History edit harga" action={`${logs.length} log`} />
      <div className="activity-list">
        {logs.length === 0 && <EmptyPanel title="Belum ada edit harga" copy="Log operator yang mengubah harga akan tampil di sini." />}
        {logs.map((log) => (
          <div className="activity-item order-activity-item compact" key={log.id}>
            <div>
              <strong>{log.subject_label ?? 'Order'}</strong>
              <span>{priceLogSummary(log)} oleh {log.user}</span>
            </div>
            <small>{formatShortDateTime(log.created_at)}</small>
          </div>
        ))}
      </div>
    </section>
  )
}

function DriverPerformanceSnapshot({ drivers, onOpenDrivers }: { drivers: DriverRow[]; onOpenDrivers: () => void }) {
  const rows = driverPerformanceRows(drivers)
  const best = {
    rating: bestDriverFor(rows, 'rating_score', 'desc'),
    orders: bestDriverFor(rows, 'completed_orders_count', 'desc'),
    revenue: bestDriverFor(rows, 'completed_revenue', 'desc'),
    clean: bestDriverFor(rows, 'cancelled_orders_count', 'asc'),
  }

  return (
    <section className="panel driver-performance-snapshot">
      <div className="section-head compact-head">
        <div>
          <h2>Evaluasi Performa Driver</h2>
          <p>Ranking cepat untuk melihat driver terbaik dari area yang terlihat.</p>
        </div>
        <button className="secondary-button compact" type="button" onClick={onOpenDrivers}>Lihat detail</button>
      </div>
      <div className="performance-mini-grid">
        <PerformanceMiniCard label="Rating terpercaya" driver={best.rating} value={best.rating ? `${Number(best.rating.performance.rating_score ?? best.rating.performance.rating_average).toFixed(1)}/5` : '-'} />
        <PerformanceMiniCard label="Order terbanyak" driver={best.orders} value={String(best.orders?.performance.completed_orders_count ?? '-')} />
        <PerformanceMiniCard label="Cancel terendah" driver={best.clean} value={String(best.clean?.performance.cancelled_orders_count ?? '-')} />
        <PerformanceMiniCard label="Pendapatan terbaik" driver={best.revenue} value={best.revenue ? `Rp ${best.revenue.performance.completed_revenue.toLocaleString('id-ID')}` : '-'} />
      </div>
    </section>
  )
}

function PerformanceMiniCard({ label, driver, value }: { label: string; driver?: DriverPerformanceRow; value: string }) {
  return <article className="performance-mini-card"><span>{label}</span><strong>{value}</strong><small>{driver?.name ?? 'Belum ada data'}</small></article>
}

function OperatorPerformanceSnapshot({ operators, onOpenChats }: { operators: OperatorPerformance[]; onOpenChats: () => void }) {
  const bestRating = [...operators].sort((first, second) => Number(second.rating_score ?? second.rating_average) - Number(first.rating_score ?? first.rating_average))[0]
  const busiest = [...operators].sort((first, second) => second.handled_chats_count - first.handled_chats_count)[0]
  const active = [...operators].sort((first, second) => second.active_chats_count - first.active_chats_count)[0]
  const clean = [...operators].sort((first, second) => first.late_response_count - second.late_response_count)[0]

  return (
    <section className="panel driver-performance-snapshot">
      <div className="section-head compact-head">
        <div>
          <h2>Performa Operator</h2>
          <p>Rating dan beban layanan chat operator/eksekutor.</p>
        </div>
        <button className="secondary-button compact" type="button" onClick={onOpenChats}>Buka chat</button>
      </div>
      <div className="performance-mini-grid">
        <OperatorMiniCard label="Rating terpercaya" operator={bestRating} value={bestRating && Number(bestRating.rating_score ?? bestRating.rating_average) > 0 ? `${Number(bestRating.rating_score ?? bestRating.rating_average).toFixed(1)}/5` : '-'} />
        <OperatorMiniCard label="Chat dilayani" operator={busiest} value={String(busiest?.handled_chats_count ?? '-')} />
        <OperatorMiniCard label="Chat aktif" operator={active} value={String(active?.active_chats_count ?? '-')} />
        <OperatorMiniCard label="Respon paling rapi" operator={clean} value={clean ? `${clean.late_response_count} telat` : '-'} />
      </div>
    </section>
  )
}

function OperatorMiniCard({ label, operator, value }: { label: string; operator?: OperatorPerformance; value: string }) {
  return <article className="performance-mini-card"><span>{label}</span><strong>{value}</strong><small>{operator?.name ?? 'Belum ada data'}</small></article>
}

function isActiveOrderStatus(order: Order) {
  return ['CREATED', 'SEARCHING_DRIVER', 'DRIVER_ACCEPTED', 'DRIVER_ON_THE_WAY', 'ARRIVED_PICKUP', 'ON_GOING', 'pending', 'accepted', 'on_delivery'].includes(String(order.status))
}

function isWaitingDriverStatus(status: string) {
  return ['CREATED', 'SEARCHING_DRIVER', 'pending', 'created', 'searching_driver'].includes(String(status))
}

function isDispatchPendingOrder(order: Order) {
  return isWaitingDriverStatus(order.status) && !order.driver
}

function statusDispatchLabel(status: string) {
  const key = String(status).toLowerCase()
  if (key.includes('searching')) return 'waiting_driver'
  if (key.includes('created') || key === 'pending') return 'pending_dispatch'
  return key.replaceAll('_', ' ')
}

function formatWaitingTime(seconds?: number) {
  const total = Math.max(0, Number(seconds ?? 0))
  const minutes = Math.floor(total / 60)
  const rest = total % 60

  if (minutes >= 60) {
    const hours = Math.floor(minutes / 60)
    return `${hours}j ${minutes % 60}m`
  }

  return `${minutes}m ${String(rest).padStart(2, '0')}d`
}

function isNightTariffCurrentlyActive(settings: SystemSettings) {
  if (!settings.night_tariff_enabled) return false
  const now = new Date()
  const minute = now.getHours() * 60 + now.getMinutes()
  return (settings.night_tariff_rules ?? []).some((rule) => timeInRange(minute, timeToMinute(rule.start), timeToMinute(rule.end)))
}

function timeToMinute(value?: string) {
  const [hour, minute] = String(value ?? '00:00').split(':').map((item) => Number(item))
  return Math.max(0, Math.min(23, hour || 0)) * 60 + Math.max(0, Math.min(59, minute || 0))
}

function timeInRange(value: number, start: number, end: number) {
  return start <= end ? value >= start && value <= end : value >= start || value <= end
}

type DriverPerformanceRow = DriverRow & {
  performance: NonNullable<DriverRow['performance']>
}

function normalizedDriverPerformance(driver: DriverRow): DriverPerformanceRow {
  return {
    ...driver,
    performance: {
      rating_average: Number(driver.performance?.rating_average ?? 0),
      ratings_count: Number(driver.performance?.ratings_count ?? 0),
      rating_score: Number(driver.performance?.rating_score ?? driver.performance?.rating_average ?? 0),
      rating_confidence: Number(driver.performance?.rating_confidence ?? 0),
      completed_orders_count: Number(driver.performance?.completed_orders_count ?? 0),
      today_completed_orders_count: Number(driver.performance?.today_completed_orders_count ?? 0),
      month_completed_orders_count: Number(driver.performance?.month_completed_orders_count ?? 0),
      cancelled_orders_count: Number(driver.performance?.cancelled_orders_count ?? 0),
      today_cancelled_orders_count: Number(driver.performance?.today_cancelled_orders_count ?? 0),
      month_cancelled_orders_count: Number(driver.performance?.month_cancelled_orders_count ?? 0),
      suspensions_count: Number(driver.performance?.suspensions_count ?? driver.suspensions.length),
      oper_handle_requests_count: Number(driver.performance?.oper_handle_requests_count ?? driver.oper_handle_count ?? 0),
      unpaid_deposits_count: Number(driver.performance?.unpaid_deposits_count ?? 0),
      completed_revenue: Number(driver.performance?.completed_revenue ?? 0),
      today_revenue: Number(driver.performance?.today_revenue ?? 0),
      month_revenue: Number(driver.performance?.month_revenue ?? 0),
      last_completed_at: driver.performance?.last_completed_at ?? null,
      online_score: driver.driver_state === 'online' ? 1 : 0,
    },
  }
}

function driverPerformanceRows(drivers: DriverRow[], branchFilter = 'all') {
  return drivers
    .filter((driver) => branchFilter === 'all' || driverBranchKey(driver) === branchFilter)
    .map(normalizedDriverPerformance)
}

function bestDriverFor(rows: DriverPerformanceRow[], metric: keyof DriverPerformanceRow['performance'], direction: 'asc' | 'desc') {
  const sorted = [...rows]
    .filter((driver) => Number.isFinite(Number(driver.performance[metric])))
    .sort((first, second) => {
      const diff = Number(first.performance[metric]) - Number(second.performance[metric])
      return direction === 'asc' ? diff : -diff
    })

  return sorted[0]
}

function topDriverToday(drivers: DriverRow[], orders: Order[]) {
  const bestByPerformance = bestDriverFor(driverPerformanceRows(drivers), 'today_completed_orders_count', 'desc')
  if (bestByPerformance && Number(bestByPerformance.performance.today_completed_orders_count ?? 0) > 0) {
    return {
      name: bestByPerformance.name,
      orders: Number(bestByPerformance.performance.today_completed_orders_count ?? 0),
      rating: bestByPerformance.performance.rating_average > 0 ? bestByPerformance.performance.rating_average.toFixed(1) : '-',
      cancel: Number(bestByPerformance.performance.today_cancelled_orders_count ?? 0),
    }
  }

  const today = new Date().toDateString()
  const driverOrders = orders.filter((order) => order.driver && order.created_at && new Date(order.created_at).toDateString() === today)
  const counts = new Map<string, { name: string; orders: number; cancel: number }>()
  driverOrders.forEach((order) => {
    const name = order.driver || '-'
    const row = counts.get(name) ?? { name, orders: 0, cancel: 0 }
    row.orders += 1
    if (String(order.status).toUpperCase() === 'CANCELLED') row.cancel += 1
    counts.set(name, row)
  })
  const best = [...counts.values()].sort((a, b) => b.orders - a.orders || a.cancel - b.cancel)[0]
  return {
    name: best?.name ?? 'Belum ada data',
    orders: best?.orders ?? 0,
    rating: '-',
    cancel: best ? best.cancel : '-',
  }
}

function driverBranchLabel(driver: DriverRow) {
  return [driver.branch, driver.branch_area].filter(Boolean).join(' - ') || 'Tanpa cabang'
}

function driverBranchKey(driver: DriverRow) {
  return driverBranchLabel(driver).toLowerCase()
}

function UsersPanel({ users, branches, me, roleFilter, onRoleFilterChange, permissions, api, onChanged }: { users: User[]; branches: Branch[]; me: User; roleFilter: Role | 'all'; onRoleFilterChange: (role: Role | 'all') => void; permissions: Permissions; api: ApiClient; onChanged: () => Promise<void> }) {
  const [editingUser, setEditingUser] = useState<User | null>(null)
  const canEditUser = (user: User) => permissions.can_manage_users && user.id !== me.id && (['admin', 'gm'].includes(me.role) || !['admin', 'gm'].includes(user.role))
  const resetPassword = async (user: User) => {
    const payload = await api<{ temporary_password: string }>(`/admin/users/${user.id}/reset-password`, { method: 'POST' })
    alert(`Password baru ${user.username}: ${payload.temporary_password}`)
  }
  const resetToken = async (user: User) => {
    if (!confirm(`Reset token login ${user.username}? User harus login ulang setelah ini.`)) return
    await api(`/admin/users/${user.id}/reset-token`, { method: 'POST' })
    await onChanged()
  }
  const destroy = async (user: User) => {
    if (!confirm(`Delete ${user.username}?`)) return
    await api(`/admin/users/${user.id}`, { method: 'DELETE' })
    await onChanged()
  }
  return (
    <section className="panel">
      <PanelHeader title="User management" action={`${users.length} records`} />
      <div className="table-toolbar"><select value={roleFilter} onChange={(event) => onRoleFilterChange(event.target.value as Role | 'all')}><option value="all">All visible roles</option>{Object.entries(roleLabels).map(([key, label]) => <option value={key} key={key}>{label}</option>)}</select><span className="toolbar-hint">Admin/GM only can edit Admin & GM accounts.</span></div>
      <div className="table-wrap"><table><thead><tr><th>Username</th><th>Name</th><th>Role</th><th>Branch</th><th>Status</th><th>Actions</th></tr></thead><tbody>{users.map((user) => <tr key={user.id}><td><strong>{user.username}</strong><span>{user.email}</span></td><td>{user.name}</td><td><RoleBadge role={user.role} /></td><td>{userBranchLabel(user)}</td><td><span className={user.is_suspended ? 'status danger' : user.is_active ? 'status success' : 'status muted'}>{user.is_suspended ? 'Suspended' : user.is_active ? 'Active' : 'Inactive'}</span></td><td><div className="row-actions">{canEditUser(user) && <button className="mini-button" type="button" onClick={() => setEditingUser(user)}>Edit</button>}{canEditUser(user) && <button className="mini-button" type="button" onClick={() => void resetPassword(user)}>Reset Pass</button>}{canEditUser(user) && user.role === 'customer' && <button className="mini-button" type="button" onClick={() => void resetToken(user)}>Reset Token</button>}{canEditUser(user) && <button className="mini-button reject" type="button" onClick={() => void destroy(user)}>Delete</button>}{!canEditUser(user) && <span className="status muted">Locked</span>}</div></td></tr>)}</tbody></table></div>
      {editingUser && <UserEditModal user={editingUser} branches={branches} permissions={permissions} api={api} onClose={() => setEditingUser(null)} onSaved={async () => { await onChanged(); setEditingUser(null) }} />}
    </section>
  )
}

function DriverManagementPanel({ drivers, services, permissions, api, onChanged }: { drivers: DriverRow[]; services: ServiceRow[]; permissions: Permissions; api: ApiClient; onChanged: () => Promise<void> }) {
  const [configDriver, setConfigDriver] = useState<DriverRow | null>(null)
  const [authDriver, setAuthDriver] = useState<DriverRow | null>(null)
  const [branchFilter, setBranchFilter] = useState('all')
  const [performancePeriod, setPerformancePeriod] = useState<'today' | 'month' | 'all'>('month')
  const branchOptions = useMemo(() => {
    const unique = new Map<string, string>()
    drivers.forEach((driver) => unique.set(driverBranchKey(driver), driverBranchLabel(driver)))
    return [...unique.entries()].sort((first, second) => first[1].localeCompare(second[1]))
  }, [drivers])
  const filteredDrivers = useMemo(() => drivers.filter((driver) => branchFilter === 'all' || driverBranchKey(driver) === branchFilter), [branchFilter, drivers])
  const suspend = async (driver: DriverRow, duration: number, status: 'suspended' | 'suspended_unpaid') => {
    if (!driver.driver_id) return
    const reason = prompt('Alasan suspend', status === 'suspended_unpaid' ? 'Belum bayar setoran' : 'Suspend manual admin')
    if (!reason) return
    await api(`/admin/drivers/${driver.driver_id}/suspend`, {
      method: 'POST',
      body: JSON.stringify({ duration, status, reason }),
    })
    await onChanged()
  }

  const release = async (driver: DriverRow) => {
    if (!driver.driver_id || !confirm(`Release suspend ${driver.name}?`)) return
    await api(`/admin/drivers/${driver.driver_id}/release-suspend`, { method: 'POST' })
    await onChanged()
  }

  const resetToken = async (driver: DriverRow) => {
    if (!driver.driver_id || !confirm(`Reset token login ${driver.name}? Driver harus login ulang setelah ini.`)) return
    await api(`/admin/drivers/${driver.driver_id}/reset-token`, { method: 'POST' })
    await onChanged()
  }

  return (
    <section className="panel driver-management-panel">
      <PanelHeader title="Driver Management" action={`${filteredDrivers.length}/${drivers.length} driver`} />
      <div className="driver-filter-bar">
        <label>
          Cabang / Area
          <select value={branchFilter} onChange={(event) => setBranchFilter(event.target.value)}>
            <option value="all">Semua cabang / area</option>
            {branchOptions.map(([key, label]) => <option key={key} value={key}>{label}</option>)}
          </select>
        </label>
        <label>
          Periode Performa
          <select value={performancePeriod} onChange={(event) => setPerformancePeriod(event.target.value as 'today' | 'month' | 'all')}>
            <option value="today">Hari ini</option>
            <option value="month">Bulan ini</option>
            <option value="all">Semua waktu</option>
          </select>
        </label>
        <span className="toolbar-hint">Ranking driver berubah mengikuti cabang/area dan periode yang dipilih.</span>
      </div>
      <DriverPerformanceBoard drivers={filteredDrivers} period={performancePeriod} />
      <div className="driver-table-shell">
        <table className="driver-table">
          <thead><tr><th>Driver</th><th>Phone</th><th>Kendaraan</th><th>Layanan</th><th>Status</th><th>Until</th><th>Oper</th><th>History</th><th>Actions</th></tr></thead>
          <tbody>
            {filteredDrivers.map((driver) => (
              <tr key={driver.id}>
                <td><strong>{driver.name}</strong><span>{driver.username}</span><span>{driver.google_email ?? driver.email}</span></td>
                <td><span className="driver-phone">{driver.phone || '-'}</span></td>
                <td><span className="status info">{vehicleLabel(driver.vehicle_type)}{driver.vehicle_type === 'mobil' ? ` ${driver.vehicle_seat_rows ?? 2} baris` : ''}</span>{driver.is_ladies_driver && <span className="status ladies-status">Ladies</span>}</td>
                <td><span className="driver-phone">{driver.allowed_service_types?.length ? driver.allowed_service_types.join(', ') : 'Semua layanan'}</span></td>
                <td><span className={driver.driver_status === 'active' ? 'status success' : driver.driver_status === 'suspended_unpaid' ? 'status warning' : 'status danger'}>{driver.driver_status.replace('_', ' ')}</span></td>
                <td>{driver.suspended_until || '-'}</td>
                <td>{driver.oper_handle_count}</td>
                <td><div className="driver-history">{driver.suspensions.slice(0, 2).map((item) => <span key={item.id}>{item.duration}h - {item.reason}</span>)}{driver.suspensions.length === 0 && <span>-</span>}</div></td>
                <td>
                  <div className="row-actions">
                    {permissions.can_suspend_drivers && <button className="mini-button reject" type="button" disabled={!driver.driver_id} onClick={() => void suspend(driver, 1, 'suspended')}>1h</button>}
                    {permissions.can_suspend_drivers && <button className="mini-button reject" type="button" disabled={!driver.driver_id} onClick={() => void suspend(driver, 12, 'suspended')}>12h</button>}
                    {permissions.can_suspend_drivers && <button className="mini-button reject" type="button" disabled={!driver.driver_id} onClick={() => void suspend(driver, 168, 'suspended_unpaid')}>Unpaid</button>}
                    {permissions.can_suspend_drivers && <button className="mini-button" type="button" disabled={!driver.driver_id} onClick={() => setConfigDriver(driver)}>Config</button>}
                    {permissions.can_suspend_drivers && <button className="mini-button" type="button" disabled={!driver.driver_id} onClick={() => void resetToken(driver)}>Reset Token</button>}
                    {permissions.can_manage_driver_auth && <button className="mini-button" type="button" disabled={!driver.driver_id} onClick={() => setAuthDriver(driver)}>Google Auth</button>}
                    {permissions.can_unsuspend_drivers && driver.driver_status !== 'active' && <button className="mini-button" type="button" onClick={() => void release(driver)}>Release</button>}
                  </div>
                </td>
              </tr>
            ))}
            {filteredDrivers.length === 0 && (
              <tr>
                <td colSpan={9}>
                  <EmptyPanel title="Belum ada driver" copy="Driver yang terlihat sesuai filter cabang akan muncul di sini." />
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
      {configDriver && <DriverConfigModal driver={configDriver} services={services} api={api} onClose={() => setConfigDriver(null)} onSaved={async () => { await onChanged(); setConfigDriver(null) }} />}
      {authDriver && <DriverGoogleAuthModal driver={authDriver} api={api} onClose={() => setAuthDriver(null)} onSaved={async () => { await onChanged(); setAuthDriver(null) }} />}
    </section>
  )
}

function DriverGoogleAuthModal({ driver, api, onClose, onSaved }: { driver: DriverRow; api: ApiClient; onClose: () => void; onSaved: () => Promise<void> }) {
  const [email, setEmail] = useState(driver.google_email ?? driver.email)
  const [saving, setSaving] = useState(false)
  const action = async (message: string, work: () => Promise<unknown>) => {
    if (!driver.driver_id || !confirm(message)) return
    setSaving(true)
    try {
      await work()
      await onSaved()
    } finally {
      setSaving(false)
    }
  }

  const saveEmail = async (event: FormEvent) => {
    event.preventDefault()
    if (!driver.driver_id) return
    setSaving(true)
    try {
      await api(`/admin/drivers/${driver.driver_id}/google-auth`, {
        method: 'PATCH',
        body: JSON.stringify({ email }),
      })
      await onSaved()
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="modal-backdrop" role="presentation">
      <div className="modal driver-auth-modal" role="dialog" aria-modal="true">
        <div className="modal-header">
          <div><h2>Google Login Driver</h2><p>{driver.name} - {driver.username}</p></div>
          <button type="button" className="icon-button" onClick={onClose}><Icon name="close" /></button>
        </div>
        <div className="driver-auth-grid">
          <StatusInfo label="Bind" value={driver.google_bound ? 'Terhubung' : 'Belum bind'} tone={driver.google_bound ? 'success' : 'warning'} />
          <StatusInfo label="Auth" value={driver.auth_suspended_at ? 'Suspended' : driver.auth_locked_until ? 'Locked' : 'Normal'} tone={driver.auth_suspended_at || driver.auth_locked_until ? 'danger' : 'success'} />
          <StatusInfo label="Failed" value={String(driver.auth_failed_attempts ?? 0)} tone={(driver.auth_failed_attempts ?? 0) > 0 ? 'warning' : 'muted'} />
          <StatusInfo label="Last login" value={driver.last_login_at ?? '-'} tone="muted" />
        </div>
        <div className="driver-auth-meta">
          <span>Email login: {driver.google_email ?? driver.email}</span>
          <span>Device: {driver.last_login_device ?? '-'}</span>
          <span>IP: {driver.last_login_ip ?? '-'}</span>
          <span>Locked until: {driver.auth_locked_until ?? '-'}</span>
        </div>
        <form className="user-form" onSubmit={saveEmail}>
          <fieldset>
            <legend>Email Google Driver</legend>
            <label>Email<input type="email" value={email} onChange={(event) => setEmail(event.target.value)} required /></label>
          </fieldset>
          <div className="modal-actions"><button type="button" className="secondary-button" onClick={onClose}>Cancel</button><button className="primary-button" disabled={saving} type="submit">{saving ? 'Saving...' : 'Save Email'}</button></div>
        </form>
        <div className="row-actions driver-auth-actions">
          <button className="mini-button" disabled={saving || !driver.driver_id} type="button" onClick={() => void action(`Reset Google bind ${driver.name}? Driver dapat login ulang dengan akun Google baru.`, () => api(`/admin/drivers/${driver.driver_id}/google-auth/reset-bind`, { method: 'POST' }))}>Reset Bind</button>
          <button className="mini-button" disabled={saving || !driver.driver_id} type="button" onClick={() => void action(`Revoke semua token aktif ${driver.name}?`, () => api(`/admin/drivers/${driver.driver_id}/reset-token`, { method: 'POST' }))}>Revoke Token</button>
          <button className="mini-button reject" disabled={saving || !driver.driver_id} type="button" onClick={() => void action(`Suspend auth Google ${driver.name}?`, () => api(`/admin/drivers/${driver.driver_id}/google-auth/suspend`, { method: 'POST' }))}>Suspend Auth</button>
          <button className="mini-button approve" disabled={saving || !driver.driver_id} type="button" onClick={() => void action(`Unlock auth Google ${driver.name}?`, () => api(`/admin/drivers/${driver.driver_id}/google-auth/unlock`, { method: 'POST' }))}>Unlock Auth</button>
        </div>
      </div>
    </div>
  )
}

function StatusInfo({ label, value, tone }: { label: string; value: string; tone: 'success' | 'warning' | 'danger' | 'muted' }) {
  return <div className="auth-status-card"><span>{label}</span><strong className={`status ${tone}`}>{value}</strong></div>
}

function DriverConfigModal({ driver, services, api, onClose, onSaved }: { driver: DriverRow; services: ServiceRow[]; api: ApiClient; onClose: () => void; onSaved: () => Promise<void> }) {
  const serviceOptions = services.map((service) => ({ label: service.name, value: serviceTypeFromService(service) }))
  const [vehicleType, setVehicleType] = useState<'motor' | 'mobil'>((driver.vehicle_type === 'mobil' ? 'mobil' : 'motor'))
  const [vehicleSeatRows, setVehicleSeatRows] = useState<2 | 3>((driver.vehicle_seat_rows === 3 ? 3 : 2))
  const [isLadiesDriver, setIsLadiesDriver] = useState(Boolean(driver.is_ladies_driver))
  const [allowed, setAllowed] = useState<string[]>(driver.allowed_service_types ?? [])
  const [saving, setSaving] = useState(false)

  const toggle = (service: string) => {
    setAllowed((current) => current.includes(service) ? current.filter((item) => item !== service) : [...current, service])
  }

  const save = async () => {
    if (!driver.driver_id) return
    setSaving(true)
    try {
      await api(`/admin/drivers/${driver.driver_id}/config`, {
        method: 'PUT',
        body: JSON.stringify({
          vehicle_type: vehicleType,
          vehicle_seat_rows: vehicleType === 'mobil' ? vehicleSeatRows : null,
          is_ladies_driver: isLadiesDriver,
          allowed_service_types: allowed,
        }),
      })
      await onSaved()
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="modal-backdrop" role="presentation">
      <div className="modal" role="dialog" aria-modal="true">
        <div className="modal-header">
          <div><h2>Driver Config</h2><p>{driver.name} - layanan yang boleh diterima</p></div>
          <button type="button" className="icon-button" onClick={onClose}><Icon name="close" /></button>
        </div>
        <div className="user-form">
          <fieldset>
            <legend>Kendaraan</legend>
            <div className="form-grid">
              <label>Vehicle Type<select value={vehicleType} onChange={(event) => setVehicleType(event.target.value as 'motor' | 'mobil')}><option value="motor">Driver sepeda motor</option><option value="mobil">Driver mobil</option></select></label>
              {vehicleType === 'mobil' && <label>Kapasitas Mobil<select value={vehicleSeatRows} onChange={(event) => setVehicleSeatRows(Number(event.target.value) as 2 | 3)}><option value={2}>2 baris - citycar/default</option><option value={3}>3 baris - MPV/keluarga</option></select></label>}
            </div>
            <label className="driver-ladies-card">
              <input type="checkbox" checked={isLadiesDriver} onChange={(event) => setIsLadiesDriver(event.target.checked)} />
              <span>
                <strong>Driver Ladies</strong>
                <small>Aktifkan agar driver dapat menerima order Ojek Ladies sesuai cabang/area.</small>
              </span>
              <b>{isLadiesDriver ? 'Aktif' : 'Nonaktif'}</b>
            </label>
          </fieldset>
          <fieldset>
            <legend>Pilihan Layanan</legend>
            <div className="service-check-grid">
              {serviceOptions.map((service) => <label className="toggle-row" key={service.value}><input type="checkbox" checked={allowed.includes(service.value)} onChange={() => toggle(service.value)} />{service.label}</label>)}
            </div>
            <div className="notice">Jika tidak ada layanan yang dicentang, driver dianggap bisa menerima semua layanan di area/cabangnya.</div>
          </fieldset>
        </div>
        <div className="modal-actions"><button type="button" className="secondary-button" onClick={onClose}>Cancel</button><button className="primary-button" type="button" disabled={saving} onClick={() => void save()}>{saving ? 'Saving...' : 'Save Config'}</button></div>
      </div>
    </div>
  )
}

function SystemSettingsPanel({ settings, permissions, api, onChanged }: { settings: SystemSettings; permissions: Permissions; api: ApiClient; onChanged: () => Promise<void> }) {
  const [multiOrderEnabled, setMultiOrderEnabled] = useState(settings.multi_order_enabled)
  const [maxMultiOrder, setMaxMultiOrder] = useState(settings.max_multi_order)
  const [orderCloseEnabled, setOrderCloseEnabled] = useState(settings.order_close_enabled ?? true)
  const [orderCloseStart, setOrderCloseStart] = useState(settings.order_close_start ?? '01:00')
  const [orderCloseEnd, setOrderCloseEnd] = useState(settings.order_close_end ?? '05:00')
  const [orderCloseMessage, setOrderCloseMessage] = useState(settings.order_close_message ?? 'Maaf, sistem order sedang tutup. Order dibuka kembali pukul {end}.')
  const [nightTariffEnabled, setNightTariffEnabled] = useState(settings.night_tariff_enabled ?? true)
  const [nightTariffRules, setNightTariffRules] = useState<NightTariffRule[]>(settings.night_tariff_rules ?? defaultNightTariffRules())
  const [feedbackTemplates, setFeedbackTemplates] = useState({
    driver_accepted: settings.feedback_templates?.driver_accepted ?? '',
    order_auto_cancelled: settings.feedback_templates?.order_auto_cancelled ?? '',
    order_cancelled: settings.feedback_templates?.order_cancelled ?? '',
  })
  const [saving, setSaving] = useState(false)

  useEffect(() => {
    setMultiOrderEnabled(settings.multi_order_enabled)
    setMaxMultiOrder(settings.max_multi_order)
    setOrderCloseEnabled(settings.order_close_enabled ?? true)
    setOrderCloseStart(settings.order_close_start ?? '01:00')
    setOrderCloseEnd(settings.order_close_end ?? '05:00')
    setOrderCloseMessage(settings.order_close_message ?? 'Maaf, sistem order sedang tutup. Order dibuka kembali pukul {end}.')
    setNightTariffEnabled(settings.night_tariff_enabled ?? true)
    setNightTariffRules(settings.night_tariff_rules ?? defaultNightTariffRules())
    setFeedbackTemplates({
      driver_accepted: settings.feedback_templates?.driver_accepted ?? '',
      order_auto_cancelled: settings.feedback_templates?.order_auto_cancelled ?? '',
      order_cancelled: settings.feedback_templates?.order_cancelled ?? '',
    })
  }, [settings])

  const save = async () => {
    if (!permissions.can_manage_system_settings) return
    setSaving(true)
    try {
      await api('/admin/system-settings', {
        method: 'PATCH',
        body: JSON.stringify({
          multi_order_enabled: multiOrderEnabled,
          max_multi_order: maxMultiOrder,
          order_close_enabled: orderCloseEnabled,
          order_close_start: orderCloseStart,
          order_close_end: orderCloseEnd,
          order_close_message: orderCloseMessage,
          night_tariff_enabled: nightTariffEnabled,
          night_tariff_rules: nightTariffRules,
          feedback_templates: feedbackTemplates,
        }),
      })
      await onChanged()
    } finally {
      setSaving(false)
    }
  }

  return (
    <section className="panel">
      <PanelHeader title="System Settings" action={settings.multi_order_enabled ? 'Multi order aktif' : 'Multi order nonaktif'} />
      <div className={settings.multi_order_enabled ? 'settings-status active' : 'settings-status'}>
        <strong>{settings.multi_order_enabled ? 'Aktif' : 'Nonaktif'}</strong>
        <span>Driver {settings.multi_order_enabled ? `bisa menerima hingga ${settings.max_multi_order} order searah.` : 'hanya bisa menerima satu order aktif.'}</span>
      </div>
      <div className="settings-grid">
        <label className="admin-toggle-row">
          <input
            type="checkbox"
            checked={multiOrderEnabled}
            disabled={!permissions.can_manage_system_settings}
            onChange={(event) => setMultiOrderEnabled(event.target.checked)}
          />
          <span>Enable Multi Order</span>
        </label>
        <label>
          Max Order
          <input
            type="number"
            min={1}
            max={3}
            value={maxMultiOrder}
            disabled={!permissions.can_manage_system_settings}
            onChange={(event) => setMaxMultiOrder(Math.max(1, Math.min(3, Number(event.target.value))))}
          />
        </label>
      </div>
      <div className="feedback-cms">
        <div className="section-head">
          <div>
            <h2>Jam Operasional Order</h2>
            <p>Sistem akan menolak order customer pada rentang close dan menampilkan popup informasi.</p>
          </div>
          <span className="status info">CMS</span>
        </div>
        <div className="settings-grid">
          <label className="admin-toggle-row">
            <input type="checkbox" checked={orderCloseEnabled} disabled={!permissions.can_manage_system_settings} onChange={(event) => setOrderCloseEnabled(event.target.checked)} />
            <span>Aktifkan close order otomatis</span>
          </label>
          <label>Jam close<input type="time" value={orderCloseStart} disabled={!permissions.can_manage_system_settings} onChange={(event) => setOrderCloseStart(event.target.value)} /></label>
          <label>Jam buka<input type="time" value={orderCloseEnd} disabled={!permissions.can_manage_system_settings} onChange={(event) => setOrderCloseEnd(event.target.value)} /></label>
          <label className="span-2">Pesan popup<textarea value={orderCloseMessage} disabled={!permissions.can_manage_system_settings} onChange={(event) => setOrderCloseMessage(event.target.value)} /></label>
        </div>
      </div>
      <div className="feedback-cms">
        <div className="section-head">
          <div>
            <h2>Tarif Jam Malam</h2>
            <p>Tambahan dihitung dari tarif dasar. Area kosong berlaku global; isi area untuk override seperti BWS.</p>
          </div>
          <span className="status info">CMS</span>
        </div>
        <label className="admin-toggle-row">
          <input type="checkbox" checked={nightTariffEnabled} disabled={!permissions.can_manage_system_settings} onChange={(event) => setNightTariffEnabled(event.target.checked)} />
          <span>Aktifkan tarif jam malam</span>
        </label>
        <div className="night-rule-list">
          {nightTariffRules.map((rule, index) => (
            <div className="night-rule-row" key={`${rule.area ?? 'global'}-${rule.start}-${index}`}>
              <label>Area<input value={rule.area ?? ''} disabled={!permissions.can_manage_system_settings} placeholder="Kosong = global" onChange={(event) => setNightTariffRules((rows) => updateNightRule(rows, index, { area: event.target.value }))} /></label>
              <label>Mulai<input type="time" value={rule.start} disabled={!permissions.can_manage_system_settings} onChange={(event) => setNightTariffRules((rows) => updateNightRule(rows, index, { start: event.target.value }))} /></label>
              <label>Selesai<input type="time" value={rule.end} disabled={!permissions.can_manage_system_settings} onChange={(event) => setNightTariffRules((rows) => updateNightRule(rows, index, { end: event.target.value }))} /></label>
              <label>Persen<input type="number" min={0} max={300} value={rule.percent} disabled={!permissions.can_manage_system_settings} onChange={(event) => setNightTariffRules((rows) => updateNightRule(rows, index, { percent: Number(event.target.value) }))} /></label>
              {permissions.can_manage_system_settings && <button className="mini-button reject" type="button" onClick={() => setNightTariffRules((rows) => rows.filter((_, rowIndex) => rowIndex !== index))}>Delete</button>}
            </div>
          ))}
        </div>
        {permissions.can_manage_system_settings && <button className="secondary-button" type="button" onClick={() => setNightTariffRules((rows) => [...rows, { area: '', start: '22:00', end: '00:00', percent: 30 }])}>Tambah Rule Tarif Malam</button>}
      </div>
      <div className="feedback-cms">
        <div className="section-head">
          <div>
            <h2>Feedback Customer</h2>
            <p>Template ucapan realtime untuk customer. Placeholder: {'{order_code}'}, {'{service}'}, {'{driver_name}'}, {'{customer_name}'}, {'{reason}'}.</p>
          </div>
          <span className="status info">CMS</span>
        </div>
        <div className="feedback-template-grid">
          <label>
            Order diterima driver
            <textarea
              value={feedbackTemplates.driver_accepted}
              disabled={!permissions.can_manage_system_settings}
              onChange={(event) => setFeedbackTemplates((current) => ({ ...current, driver_accepted: event.target.value }))}
            />
          </label>
          <label>
            Auto-cancel batas waktu
            <textarea
              value={feedbackTemplates.order_auto_cancelled}
              disabled={!permissions.can_manage_system_settings}
              onChange={(event) => setFeedbackTemplates((current) => ({ ...current, order_auto_cancelled: event.target.value }))}
            />
          </label>
          <label>
            Order cancel manual
            <textarea
              value={feedbackTemplates.order_cancelled}
              disabled={!permissions.can_manage_system_settings}
              onChange={(event) => setFeedbackTemplates((current) => ({ ...current, order_cancelled: event.target.value }))}
            />
          </label>
        </div>
      </div>
      {!permissions.can_manage_system_settings && <div className="notice">Role Anda tidak bisa mengubah system settings.</div>}
      {permissions.can_manage_system_settings && <button className="primary-button" type="button" disabled={saving} onClick={() => void save()}>{saving ? 'Saving...' : 'Save Settings'}</button>}
    </section>
  )
}

function OrdersTable({ orders, auditLogs, searchQuery, permissions, api, onChanged }: { orders: Order[]; auditLogs: AuditLog[]; searchQuery: string; permissions: Permissions; api: ApiClient; onChanged: () => Promise<void> }) {
  const [editingOrder, setEditingOrder] = useState<Order | null>(null)
  const [selectedOrderId, setSelectedOrderId] = useState<number | null>(null)
  const latestOrders = useMemo(() => sortOrdersNewest(orders), [orders])
  const filteredOrders = latestOrders.filter((order) => orderMatchesSearch(order, searchQuery))
  const selectedOrder = filteredOrders.find((order) => order.id === selectedOrderId) ?? filteredOrders[0] ?? null
  const priceLogs = auditLogs.filter((log) => log.action === 'updated_order_price').slice(0, 5)
  void priceLogs.map(priceLogSummary)
  return (
    <section className="panel order-operations-panel">
      <PanelHeader title="Order operations" action={`${filteredOrders.length}/${orders.length} orders`} />
      {permissions.can_edit_order_price && <div className="notice">Edit harga akan dikirim realtime ke customer dan driver.</div>}
      <div className="order-operations-layout">
        <div className="table-wrap order-table-wrap">
          <table>
            <thead><tr><th>Order</th><th>Customer</th><th>Driver</th><th>Service</th><th>Branch</th><th>Total</th><th>Status</th><th>Detail</th>{permissions.can_edit_order_price && <th>Action</th>}</tr></thead>
            <tbody>
              {filteredOrders.map((order) => (
                <tr className={selectedOrder?.id === order.id ? 'selected-row' : ''} key={order.id}>
                  <td><strong>{order.code}</strong><span>{formatShortDateTime(order.created_at)}</span></td>
                  <td>{order.customer || '-'}</td>
                  <td>{order.driver || '-'}</td>
                  <td>{order.service}</td>
                  <td>{displayBranchValue(order.branch, order.branch_area)}</td>
                  <td><strong>Rp {order.total.toLocaleString('id-ID')}</strong><span>Tarif Rp {order.price.toLocaleString('id-ID')} · Fee Rp {order.service_charge.toLocaleString('id-ID')}</span></td>
                  <td><StatusBadge status={order.status} /></td>
                  <td><button className="mini-button" type="button" onClick={() => setSelectedOrderId(order.id)}>Lihat</button></td>
                  {permissions.can_edit_order_price && <td><button className="mini-button" type="button" onClick={() => setEditingOrder(order)}>Edit harga</button></td>}
                </tr>
              ))}
            </tbody>
          </table>
          {filteredOrders.length === 0 && <EmptyPanel title="Order tidak ditemukan" copy="Coba cek kode order atau hapus filter pencarian." />}
        </div>
        <OrderDetailPanel order={selectedOrder} permissions={permissions} onEditPrice={permissions.can_edit_order_price && selectedOrder ? () => setEditingOrder(selectedOrder) : undefined} />
      </div>
      {editingOrder && <OrderPriceModal order={editingOrder} api={api} onClose={() => setEditingOrder(null)} onSaved={async () => { await onChanged(); setEditingOrder(null) }} />}
    </section>
  )
}

function OrderDetailPanel({ order, permissions, onEditPrice }: { order: Order | null; permissions: Permissions; onEditPrice?: () => void }) {
  if (!order) {
    return (
      <aside className="order-detail-panel empty-detail">
        <EmptyPanel title="Pilih order" copy="Klik Lihat pada tabel untuk membuka detail operasional order." />
      </aside>
    )
  }

  const payment = order.payment_label || paymentLabel(order.payment_method)
  const detailText = order.raw_text || order.notes || ''

  return (
    <aside className="order-detail-panel">
      <div className="order-detail-hero">
        <div>
          <span>Detail order</span>
          <strong>{order.code}</strong>
          <small>{formatShortDateTime(order.created_at)} · update {formatShortDateTime(order.updated_at)}</small>
        </div>
        <StatusBadge status={order.status} />
      </div>
      <div className="order-detail-money">
        <span>Total</span>
        <strong>Rp {order.total.toLocaleString('id-ID')}</strong>
        <small>Tarif Rp {order.price.toLocaleString('id-ID')} · Fee Rp {order.service_charge.toLocaleString('id-ID')} · Extra Rp {order.extra_charge.toLocaleString('id-ID')}</small>
      </div>
      <div className="order-detail-grid">
        <DetailItem label="Customer" value={order.customer || '-'} />
        <DetailItem label="Driver" value={order.driver || 'Belum diambil'} />
        <DetailItem label="Layanan" value={`${order.service_code ? `${order.service_code} · ` : ''}${order.service}`} />
        <DetailItem label="Cabang / Area" value={displayBranchValue(order.branch, order.branch_area)} />
        <DetailItem label="Pembayaran" value={payment} />
        <DetailItem label="Kendaraan" value={vehicleLabel(order.preferred_vehicle_type)} />
        {order.preferred_vehicle_type === 'mobil' && <DetailItem label="Seat Mobil" value={`${order.required_vehicle_seat_rows ?? 2} baris`} />}
        <DetailItem label="Preferensi" value={driverPreferenceLabel(order.driver_preference)} />
        <DetailItem label="Jarak" value={formatDistance(order.distance_km)} />
        <DetailItem label="SLA" value={`${order.sla_status ?? 'normal'} · ${formatWaitingTime(order.waiting_seconds)}`} />
        <DetailItem label="Source" value={order.source || 'app'} />
      </div>
      <div className="order-route-card">
        <div><span>Jemput / Pembelian</span><p>{order.pickup_address || '-'}</p></div>
        <div><span>Tujuan / Antar</span><p>{order.destination_address || '-'}</p></div>
      </div>
      {order.cancel_reason && <div className="notice danger">Cancel reason: {order.cancel_reason}</div>}
      {detailText && <div className="order-raw-note"><span>Catatan / raw order</span><p>{detailText}</p></div>}
      <div className="order-detail-actions">
        {permissions.can_edit_order_price && onEditPrice && <button className="primary-button compact" type="button" onClick={onEditPrice}>Edit harga</button>}
      </div>
    </aside>
  )
}

function DetailItem({ label, value }: { label: string; value: ReactNode }) {
  return <div className="detail-item"><span>{label}</span><strong>{value}</strong></div>
}

function RequestOrdersPanel({ orders, searchQuery }: { orders: Order[]; searchQuery: string }) {
  const requestOrders = useMemo(() => sortOrdersNewest(orders).filter((order) => order.source === 'driver_request'), [orders])
  const filteredOrders = requestOrders.filter((order) => orderMatchesSearch(order, searchQuery))

  return (
    <section className="panel">
      <PanelHeader title="Request Order" action={`Driver request terbaru Â· ${filteredOrders.length}/${requestOrders.length}`} />
      <div className="notice">Menu ini menampilkan order yang dibuat dari request driver. Data otomatis refresh dan diurutkan dari yang paling baru.</div>
      <div className="table-wrap">
        <table>
          <thead>
            <tr><th>Order</th><th>Driver</th><th>Service</th><th>Total</th><th>Status</th><th>Waktu</th></tr>
          </thead>
          <tbody>
            {filteredOrders.map((order) => (
              <tr key={order.id}>
                <td><strong>{order.code}</strong><span>{order.customer || '-'}</span></td>
                <td>{order.driver || '-'}</td>
                <td>{order.service}</td>
                <td><strong>Rp {order.total.toLocaleString('id-ID')}</strong></td>
                <td><StatusBadge status={order.status} /></td>
                <td>{formatShortDateTime(order.created_at)}</td>
              </tr>
            ))}
          </tbody>
        </table>
        {filteredOrders.length === 0 && <EmptyPanel title="Request order belum ada" copy="Order dari request driver akan tampil di sini." />}
      </div>
    </section>
  )
}

function OrderPriceModal({ order, api, onClose, onSaved }: { order: Order; api: ApiClient; onClose: () => void; onSaved: () => Promise<void> }) {
  const [price, setPrice] = useState(order.price)
  const [serviceCharge, setServiceCharge] = useState(order.service_charge)
  const [saving, setSaving] = useState(false)
  const total = Math.max(0, price) + Math.max(0, serviceCharge) + Math.max(0, order.extra_charge)
  const submit = async (event: FormEvent) => {
    event.preventDefault()
    setSaving(true)
    try {
      await api(`/admin/orders/${order.id}/price`, {
        method: 'PATCH',
        body: JSON.stringify({ price: Math.max(0, price), service_charge: Math.max(0, serviceCharge) }),
      })
      await onSaved()
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="modal-backdrop" role="presentation">
      <div className="modal price-edit-modal" role="dialog" aria-modal="true">
        <div className="modal-header"><div><h2>Edit harga order</h2><p>{order.code} Â· {order.customer || 'Customer'}</p></div><button type="button" className="icon-button" onClick={onClose}><Icon name="close" /></button></div>
        <form className="user-form" onSubmit={submit}>
          <fieldset>
            <legend>Harga</legend>
            <div className="form-grid">
              <label>Tarif dasar<input type="number" min={0} step={1000} value={price} onChange={(event) => setPrice(Number(event.target.value))} required /></label>
              <label>Service fee<input type="number" min={0} step={1000} value={serviceCharge} onChange={(event) => setServiceCharge(Number(event.target.value))} required /></label>
            </div>
          </fieldset>
          <div className="price-preview"><span>Total baru</span><strong>Rp {total.toLocaleString('id-ID')}</strong><small>Termasuk tambahan Rp {order.extra_charge.toLocaleString('id-ID')}. Dikirim realtime ke customer dan driver setelah disimpan.</small></div>
          <div className="modal-actions"><button type="button" className="secondary-button" onClick={onClose}>Cancel</button><button className="primary-button" type="submit" disabled={saving}>{saving ? 'Mengirim...' : 'Simpan & broadcast'}</button></div>
        </form>
      </div>
    </div>
  )
}

function PricingPanel({ settings, branches, permissions, api, onChanged }: { settings: PriceSetting[]; branches: Branch[]; permissions: Permissions; api: ApiClient; onChanged: () => Promise<void> }) {
  const [showForm, setShowForm] = useState(false)
  const [isFormula, setFormula] = useState(false)
  const create = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    const form = new FormData(event.currentTarget)
    await api('/admin/price-settings', {
      method: 'POST',
      body: JSON.stringify({
        name: form.get('name'),
        branch_id: Number(form.get('branch_id')) || null,
        min_km: Number(form.get('min_km') || 0),
        max_km: form.get('max_km') ? Number(form.get('max_km')) : null,
        price: isFormula ? null : Number(form.get('price') || 0),
        is_formula: isFormula,
        per_km_rate: isFormula ? Number(form.get('per_km_rate') || 0) : null,
        subtract_value: Number(form.get('subtract_value') || 0),
      }),
    })
    event.currentTarget.reset()
    setShowForm(false)
    await onChanged()
  }
  const destroy = async (setting: PriceSetting) => {
    if (!confirm(`Delete ${setting.name}?`)) return
    await api(`/admin/price-settings/${setting.id}`, { method: 'DELETE' })
    await onChanged()
  }
  const formulaCount = settings.filter((rule) => rule.is_formula).length
  return (
    <section className="panel pricing-panel">
      <div className="section-head">
        <div><h2>Pricing & Policy</h2><p>{settings.length} aturan tarif aktif, {formulaCount} memakai formula jarak.</p></div>
        {permissions.can_manage_policy && <button className="primary-button compact" type="button" onClick={() => setShowForm((value) => !value)}><Icon name="plus" />Policy</button>}
      </div>
      {showForm && (
        <form className="admin-inline-form pricing-create-form" onSubmit={create}>
          <label>Nama policy<input name="name" required placeholder="Belanja 0-3 km" /></label>
          <label>Cabang<select name="branch_id"><option value="">Global</option>{branches.map((branch) => <option key={branch.id} value={branch.id}>{branchLabel(branch)}</option>)}</select></label>
          <label>Min KM<input name="min_km" type="number" min="0" step="0.1" defaultValue="0" /></label>
          <label>Max KM<input name="max_km" type="number" min="0" step="0.1" placeholder="Kosong = unlimited" /></label>
          <label className="toggle-row inline-toggle"><input type="checkbox" checked={isFormula} onChange={(event) => setFormula(event.target.checked)} />Formula tarif</label>
          {!isFormula && <label>Flat price<input name="price" type="number" min="0" defaultValue="10000" /></label>}
          {isFormula && <label>Rate / KM<input name="per_km_rate" type="number" min="0" defaultValue="3000" /></label>}
          <label>Subtract<input name="subtract_value" type="number" min="0" defaultValue="0" /></label>
          <button className="primary-button" type="submit">Save Policy</button>
        </form>
      )}
      <div className="pricing-list">{settings.map((rule) => <article className="pricing-card" key={rule.id}><div className="pricing-card-main"><strong>{rule.name}</strong><span>{rule.branch ? branchLabel(rule.branch) : 'Global'} Â· {rule.min_km} - {rule.max_km ?? 'unlimited'} km</span></div><span className={rule.is_formula ? 'status info' : 'status success'}>{rule.is_formula ? 'Formula' : 'Flat'}</span><em>{rule.is_formula ? `Rp ${(rule.per_km_rate ?? 0).toLocaleString('id-ID')}/km - ${rule.subtract_value ?? 0}` : `Rp ${(rule.price ?? 0).toLocaleString('id-ID')}`}</em>{permissions.can_manage_policy && <button className="mini-button reject" type="button" onClick={() => void destroy(rule)}>Delete</button>}</article>)}</div>
    </section>
  )
}

function ReportsPanel({ data, api, token }: { data: Bootstrap; api: ApiClient; token: string }) {
  const now = new Date()
  const [month, setMonth] = useState(now.getMonth() + 1)
  const [year, setYear] = useState(now.getFullYear())
  const [depositRows, setDepositRows] = useState<DepositReportRow[]>([])
  const [loadingDeposits, setLoadingDeposits] = useState(false)
  const completed = data.orders.filter((order) => /completed|done/i.test(order.status)).length

  const loadDeposits = useCallback(async () => {
    setLoadingDeposits(true)
    try {
      const payload = await api<{ data: { rows: DepositReportRow[] } }>(`/admin/reports/driver-deposits?month=${month}&year=${year}`)
      setDepositRows(payload.data.rows)
    } finally {
      setLoadingDeposits(false)
    }
  }, [api, month, year])

  useEffect(() => {
    void loadDeposits()
  }, [loadDeposits])

  const exportExcel = async () => {
    const response = await fetch(`${API_BASE}/admin/reports/driver-deposits/export?month=${month}&year=${year}`, {
      headers: {
        Accept: 'application/vnd.ms-excel',
        Authorization: `Bearer ${token}`,
      },
    })
    if (!response.ok) throw new Error('Export report gagal')
    const blob = await response.blob()
    const url = URL.createObjectURL(blob)
    const anchor = document.createElement('a')
    anchor.href = url
    anchor.download = `rekap-setoran-driver-${year}-${String(month).padStart(2, '0')}.xls`
    anchor.click()
    URL.revokeObjectURL(url)
  }

  return (
    <div className="reports-stack">
      <section className="panel reports-panel">
        <div className="section-head"><div><h2>Reports</h2><p>Ringkasan operasional berdasarkan data yang bisa diakses role kamu.</p></div></div>
        <div className="report-grid"><ReportCard title="Orders" value={String(data.orders.length)} meta={`${completed} selesai`} tone="order" /><ReportCard title="Drivers" value={String(data.users.filter((user) => user.role === 'driver').length)} meta="visible drivers" tone="driver" /><ReportCard title="Suspicious GPS" value={String(data.location_logs.filter((log) => log.is_suspicious).length)} meta="needs review" tone="risk" /></div>
      </section>

      <section className="panel deposit-report-panel">
        <div className="section-head">
          <div>
            <h2>Rekap Setoran Driver</h2>
            <p>Format mengikuti report setoran bulanan dan export Excel.</p>
          </div>
          <div className="deposit-report-actions">
            <select value={month} onChange={(event) => setMonth(Number(event.target.value))}>
              {Array.from({ length: 12 }, (_, index) => index + 1).map((item) => <option key={item} value={item}>{monthName(item)}</option>)}
            </select>
            <input type="number" value={year} min={2020} max={2100} onChange={(event) => setYear(Number(event.target.value))} />
            {data.permissions.can_export_report && <button className="secondary-button compact" type="button" onClick={() => void exportExcel()}>Export Excel</button>}
          </div>
        </div>
        <div className="deposit-report-wrap">
          <table className="deposit-report-table">
            <thead>
              <tr>
                {depositReportHeaders(month).map((header, index) => <th key={header} className={index === 8 ? 'orange-head' : [11, 13].includes(index) ? 'yellow-head' : ''}>{header}</th>)}
              </tr>
            </thead>
            <tbody>
              {depositRows.map((row) => (
                <tr key={`${row.driver}-${row.area}`}>
                  <td>{row.driver}</td>
                  <td>{row.area}</td>
                  <td className="num">{row.orders_count}</td>
                  <td className="num">{formatNumber(row.base_service_omset)}</td>
                  <td className="num">{formatNumber(row.base_service_deposit)}</td>
                  <td className="num">{formatNumber(row.previous_bill)}</td>
                  <td className="num">{formatNumber(row.bpjs_jht)}</td>
                  <td className="num">{formatNumber(row.bpjs)}</td>
                  <td className="num">{formatNumber(row.previous_cashback_reward)}</td>
                  <td className="num">{formatNumber(row.bill_before_bansos)}</td>
                  <td className="num">{formatNumber(row.bansos)}</td>
                  <td className="num yellow-cell">{formatNumber(row.total_bill)}</td>
                  <td className="num">{formatNumber(row.paid_amount)}</td>
                  <td className="num yellow-cell">{formatNumber(row.remaining_bill)}</td>
                  <td>{row.paid_at ?? ''}</td>
                  <td className="num">{formatNumber(row.next_cashback)}</td>
                </tr>
              ))}
            </tbody>
          </table>
          {loadingDeposits && <EmptyPanel title="Memuat report setoran" copy="Data sedang diambil dari backend." />}
          {!loadingDeposits && depositRows.length === 0 && <EmptyPanel title="Belum ada data setoran" copy="Report akan tampil setelah ada driver/deposit bulan ini." />}
        </div>
      </section>
    </div>
  )
}

function ReportCard({ title, value, meta, tone }: { title: string; value: string; meta?: string; tone: string }) {
  return <article className={`report-card ${tone}`}><span>{title}</span><strong>{value}</strong>{meta && <small>{meta}</small>}</article>
}

function AdminChatPanel({ initialChats, api, me, token, permissions, onOpenOrder }: { initialChats: Chat[]; api: ApiClient; me: User; token: string; permissions: Permissions; onOpenOrder: (code: string) => void }) {
  const [chats, setChats] = useState<Chat[]>(initialChats)
  const [activeId, setActiveId] = useState<number | null>(initialChats[0]?.id ?? null)
  const [detail, setDetail] = useState<ChatDetail | null>(null)
  const [message, setMessage] = useState('')
  const [chatQuery, setChatQuery] = useState('')
  const [statusFilter, setStatusFilter] = useState<'all' | 'waiting' | 'active' | 'closed'>('all')
  const [isSending, setSending] = useState(false)
  const [chatError, setChatError] = useState('')
  const [isBotTyping, setBotTyping] = useState(false)
  const messagesRef = useRef<HTMLDivElement | null>(null)

  const activeChat = detail?.chat ?? chats.find((chat) => chat.id === activeId) ?? null
  const filteredChats = chats.filter((chat) => {
    const haystack = `${chat.customer ?? ''} ${chat.driver ?? ''} ${chat.operator ?? ''} ${chat.order_code ?? ''} ${chat.last_message ?? chat.latest_message ?? ''}`.toLowerCase()
    return haystack.includes(chatQuery.toLowerCase()) && (statusFilter === 'all' || chat.status === statusFilter)
  })

  const loadChats = useCallback(async () => {
    try {
      const payload = await api<{ data: { data: Chat[] } }>('/admin/chats')
      setChats(payload.data.data)
      if (!activeId && payload.data.data[0]) setActiveId(payload.data.data[0].id)
    } catch (error) {
      setChatError(error instanceof Error ? error.message : 'Gagal memuat chat')
    }
  }, [activeId, api])

  const loadDetail = useCallback(async (id: number) => {
    setChatError('')
    try {
      const payload = await api<{ data: ChatDetail }>(`/admin/chat/${id}`)
      setDetail(payload.data)
    } catch (error) {
      setChatError(error instanceof Error ? error.message : 'Gagal memuat detail chat')
    }
  }, [api])

  useEffect(() => {
    const timer = window.setTimeout(() => void loadChats(), 0)
    return () => window.clearTimeout(timer)
  }, [loadChats])

  useEffect(() => {
    if (!activeId) return
    setDetail(null)
    const timer = window.setTimeout(() => void loadDetail(activeId), 0)
    return () => window.clearTimeout(timer)
  }, [activeId, loadDetail])

  useEffect(() => {
    if (!activeId) return
    const echo = makeEcho(token)
    const channel = echo.private(`chat.${activeId}`)
    channel.listen('.message.sent', (event: { message: AdminChatMessage }) => {
      setDetail((current) => current ? { ...current, messages: current.messages.some((item) => item.id === event.message.id) ? current.messages : [...current.messages, event.message] } : current)
      setChats((rows) => rows.map((chat) => chat.id === activeId ? { ...chat, last_message: event.message.message, updated_at: event.message.created_at ?? chat.updated_at } : chat))
      setBotTyping(event.message.sender_type === 'customer')
      window.setTimeout(() => setBotTyping(false), 900)
    })
    channel.listen('.typing', () => setBotTyping(true))
    return () => {
      echo.leave(`chat.${activeId}`)
    }
  }, [activeId, token])

  useEffect(() => {
    messagesRef.current?.scrollTo({ top: messagesRef.current.scrollHeight, behavior: 'smooth' })
  }, [detail?.messages.length, isBotTyping])

  const send = async () => {
    if (!activeId || !message.trim() || isSending || activeChat?.status === 'closed') return
    setSending(true)
    setChatError('')
    try {
      const payload = await api<{ data: AdminChatMessage }>('/admin/send-message', {
        method: 'POST',
        body: JSON.stringify({ chat_id: activeId, message }),
      })
      setDetail((current) => current ? {
        ...current,
        chat: { ...current.chat, status: current.chat.status === 'waiting' ? 'active' : current.chat.status, operator: current.chat.operator ?? me.name, last_message: payload.data.message, updated_at: payload.data.created_at ?? current.chat.updated_at },
        messages: current.messages.some((item) => item.id === payload.data.id) ? current.messages : [...current.messages, payload.data],
      } : current)
      setChats((rows) => rows.map((chat) => chat.id === activeId ? { ...chat, status: chat.status === 'waiting' ? 'active' : chat.status, operator: chat.operator ?? me.name, last_message: payload.data.message, updated_at: payload.data.created_at ?? chat.updated_at } : chat))
      setMessage('')
    } catch (error) {
      setChatError(error instanceof Error ? error.message : 'Pesan gagal dikirim')
    } finally {
      setSending(false)
    }
  }

  const closeChat = async () => {
    if (!activeId || activeChat?.status === 'closed' || !confirm('Tutup percakapan ini?')) return
    setChatError('')
    try {
      await api(`/admin/chat/${activeId}/close`, { method: 'POST' })
      await loadDetail(activeId)
      await loadChats()
    } catch (error) {
      setChatError(error instanceof Error ? error.message : 'Gagal menutup chat')
    }
  }

  const decideCancel = async (action: 'approve' | 'reject') => {
    const cancelId = detail?.cancel_request?.id
    if (!cancelId) return
    await api(`/admin/chat/cancel-requests/${cancelId}/${action}`, { method: 'POST' })
    await loadDetail(activeId!)
  }

  return (
    <section className="admin-chat-shell">
      <aside className="admin-chat-list panel">
        <PanelHeader title="Live Chat" action={`${filteredChats.length}/${chats.length} synced`} />
        <div className="admin-chat-filters">
          <input value={chatQuery} onChange={(event) => setChatQuery(event.target.value)} placeholder="Cari nama, order, pesan..." />
          <select value={statusFilter} onChange={(event) => setStatusFilter(event.target.value as typeof statusFilter)}>
            <option value="all">Semua status</option>
            <option value="waiting">Waiting</option>
            <option value="active">Active</option>
            <option value="closed">Closed</option>
          </select>
        </div>
        <div className="admin-chat-scroll">
        {filteredChats.map((chat) => (
          <button key={chat.id} className={activeId === chat.id ? 'admin-chat-item active' : 'admin-chat-item'} onClick={() => setActiveId(chat.id)}>
            <div><strong>{chat.customer || chat.driver || 'Unknown user'}</strong><span>{chat.type?.replace('_', ' ') ?? 'chat'}</span></div>
            <p>{chat.last_message ?? chat.latest_message ?? 'Belum ada pesan'}</p>
            <footer><SlaBadge chat={chat} />{Boolean(chat.unread_count) && <b>{chat.unread_count}</b>}<small>{formatShortTime(chat.updated_at)}</small></footer>
          </button>
        ))}
        {filteredChats.length === 0 && <EmptyPanel title="Chat kosong" copy="Tidak ada percakapan sesuai filter." />}
        </div>
      </aside>

      <main className="admin-chat-room panel">
        {!activeChat && <EmptyPanel title="Pilih chat" copy="Conversation akan tampil di sini." />}
        {activeChat && (
          <>
            <header className="admin-chat-room-head">
              <div><h2>{activeChat.customer || activeChat.driver || 'Chat'}</h2><p>{activeChat.order_code ?? activeChat.type} Â· Operator: {activeChat.operator ?? me.name}</p></div>
              <div className="admin-chat-actions"><SlaBadge chat={activeChat} /><ChatStatusBadge status={activeChat.status} /><button className="mini-button reject" disabled={activeChat.status === 'closed'} onClick={closeChat}>Close Chat</button></div>
            </header>
            {activeChat.order_code && <button className="order-code-link order-code-row" onClick={() => onOpenOrder(activeChat.order_code!)}>Buka order {activeChat.order_code}</button>}
            {chatError && <div className="chat-error">{chatError}</div>}
            {detail?.cancel_request && (
              <div className="cancel-approval">
                <strong>Cancel request pending</strong><span>{detail.cancel_request.reason}</span>
                {permissions.can_approve_cancel_order && <button className="mini-button" onClick={() => void decideCancel('approve')}>Approve Cancel</button>}
                {permissions.can_reject_cancel_order && <button className="mini-button reject" onClick={() => void decideCancel('reject')}>Reject Cancel</button>}
              </div>
            )}
            <div className="admin-chat-messages" ref={messagesRef}>
              {detail?.messages.length === 0 && <EmptyPanel title="Belum ada pesan" copy="Balas untuk mengambil alih percakapan ini." />}
              {detail?.messages.map((item) => (
                <article key={item.id} className={item.sender_id === me.id ? 'admin-bubble mine' : item.sender_type === 'bot' ? 'admin-bubble bot' : 'admin-bubble'}>
                  <span>{item.sender_name ?? senderLabel(item.sender_type)} <small>{formatShortTime(item.created_at)}</small></span>
                  {item.message && <p>{renderOrderCodeLinks(item.message, onOpenOrder)}</p>}
                  {item.image_url && <img src={assetUrl(item.image_url)} alt="Chat attachment" />}
                  {item.audio_url && <div className="admin-voice"><audio controls src={assetUrl(item.audio_url)} /><small>{item.audio_duration ?? 0}s</small></div>}
                  {(item.message ?? '').includes('Transkripsi:') && <em>Transcription available</em>}
                </article>
              ))}
              {isBotTyping && <div className="bot-typing">Customer sedang mengetik...</div>}
            </div>
            <form className="admin-chat-composer" onSubmit={(event) => { event.preventDefault(); void send() }}>
              <textarea value={message} disabled={activeChat.status === 'closed'} onChange={(event) => setMessage(event.target.value)} placeholder={activeChat.status === 'closed' ? 'Chat sudah ditutup' : 'Balas sebagai operator...'} onKeyDown={(event) => { if (event.key === 'Enter' && !event.shiftKey) { event.preventDefault(); void send() } }} />
              <button className="primary-button" disabled={isSending || activeChat.status === 'closed' || !message.trim()} type="submit">{isSending ? 'Sending...' : 'Send'}</button>
            </form>
          </>
        )}
      </main>
    </section>
  )
}

function SlaBadge({ chat }: { chat: Chat }) {
  const late = chat.sla_status === 'late'
  const waiting = chat.sla_status === 'waiting'
  const label = late ? 'LATE' : waiting ? 'COUNTDOWN' : 'ON TIME'
  return <span className={late ? 'sla-badge late' : waiting ? 'sla-badge waiting' : 'sla-badge ok'}>{label}</span>
}

function ChatStatusBadge({ status }: { status: string }) {
  const tone = status === 'closed' ? 'muted' : status === 'waiting' ? 'warning' : 'success'
  return <span className={`status ${tone}`}>{status}</span>
}

function InternalChatPanel({ api, me, branches, onOpenOrder }: { api: ApiClient; me: User; branches: Branch[]; onOpenOrder: (code: string) => void }) {
  const [rooms, setRooms] = useState<InternalChatRoom[]>([])
  const [activeId, setActiveId] = useState<number | null>(null)
  const [detail, setDetail] = useState<InternalChatDetail | null>(null)
  const [message, setMessage] = useState('')
  const [query, setQuery] = useState('')
  const [roomType, setRoomType] = useState<'branch' | 'global' | 'private'>('branch')
  const [roomName, setRoomName] = useState('')
  const [roomBranchId, setRoomBranchId] = useState(() => String(me.branch_id ?? branches[0]?.id ?? ''))
  const [isCreating, setCreating] = useState(false)
  const [isSending, setSending] = useState(false)
  const [error, setError] = useState('')
  const messagesRef = useRef<HTMLDivElement | null>(null)

  const filteredRooms = rooms.filter((room) => `${room.name} ${room.branch ?? ''} ${room.last_message ?? ''}`.toLowerCase().includes(query.toLowerCase()))
  const activeRoom = detail?.room ?? rooms.find((room) => room.id === activeId) ?? null

  const loadRooms = useCallback(async () => {
    try {
      const payload = await api<{ data: InternalChatRoom[] }>('/admin/internal-chat/rooms')
      setRooms(payload.data)
      setActiveId((current) => current ?? payload.data[0]?.id ?? null)
      setError('')
    } catch (error) {
      setError(error instanceof Error ? error.message : 'Gagal memuat internal chat')
    }
  }, [api])

  const loadMessages = useCallback(async (roomId: number) => {
    try {
      const payload = await api<InternalChatDetail>(`/admin/internal-chat/rooms/${roomId}/messages`)
      setDetail(payload)
      setRooms((rows) => rows.map((room) => room.id === payload.room.id ? payload.room : room))
      setError('')
    } catch (error) {
      setError(error instanceof Error ? error.message : 'Gagal memuat pesan internal')
    }
  }, [api])

  useEffect(() => {
    void loadRooms()
    const timer = window.setInterval(() => void loadRooms(), 6000)
    return () => window.clearInterval(timer)
  }, [loadRooms])

  useEffect(() => {
    if (!activeId) return
    setDetail(null)
    void loadMessages(activeId)
    const timer = window.setInterval(() => void loadMessages(activeId), 3500)
    return () => window.clearInterval(timer)
  }, [activeId, loadMessages])

  useEffect(() => {
    messagesRef.current?.scrollTo({ top: messagesRef.current.scrollHeight, behavior: 'smooth' })
  }, [detail?.messages.length])

  const createRoom = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    if (!roomName.trim() || isCreating) return
    setCreating(true)
    try {
      const payload = await api<{ data: InternalChatRoom }>('/admin/internal-chat/rooms', {
        method: 'POST',
        body: JSON.stringify({
          name: roomName.trim(),
          type: roomType,
          branch_id: roomType === 'global' ? null : Number(roomBranchId) || me.branch_id,
        }),
      })
      setRooms((rows) => [payload.data, ...rows.filter((room) => room.id !== payload.data.id)])
      setActiveId(payload.data.id)
      setRoomName('')
      setError('')
    } catch (error) {
      setError(error instanceof Error ? error.message : 'Gagal membuat room')
    } finally {
      setCreating(false)
    }
  }

  const send = async () => {
    if (!activeId || !message.trim() || isSending) return
    setSending(true)
    try {
      const payload = await api<{ data: InternalChatMessage }>(`/admin/internal-chat/rooms/${activeId}/messages`, {
        method: 'POST',
        body: JSON.stringify({ message }),
      })
      setDetail((current) => current ? { ...current, messages: current.messages.some((item) => item.id === payload.data.id) ? current.messages : [...current.messages, payload.data] } : current)
      setRooms((rows) => rows.map((room) => room.id === activeId ? { ...room, last_message: payload.data.message, last_sender: payload.data.sender_name, updated_at: payload.data.created_at } : room))
      setMessage('')
      setError('')
    } catch (error) {
      setError(error instanceof Error ? error.message : 'Pesan internal gagal dikirim')
    } finally {
      setSending(false)
    }
  }

  return (
    <section className="admin-chat-shell internal-chat-shell">
      <aside className="admin-chat-list panel">
        <PanelHeader title="Internal Chat" action={`${filteredRooms.length}/${rooms.length} room`} />
        <div className="admin-chat-filters internal-chat-filters">
          <input value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Cari room/pesan..." />
          <select value={roomType} onChange={(event) => setRoomType(event.target.value as typeof roomType)}>
            <option value="branch">Area</option>
            {['admin', 'gm'].includes(me.role) && <option value="global">Global</option>}
            <option value="private">Private</option>
          </select>
        </div>
        <form className="internal-room-form" onSubmit={createRoom}>
          <input value={roomName} onChange={(event) => setRoomName(event.target.value)} placeholder="Room baru, contoh: Dispatch ASB" />
          {roomType !== 'global' && (
            <select value={roomBranchId} disabled={!['admin', 'gm'].includes(me.role)} onChange={(event) => setRoomBranchId(event.target.value)}>
              {branches.map((branch) => <option key={branch.id} value={branch.id}>{branchLabel(branch)}</option>)}
            </select>
          )}
          <button className="mini-button" type="submit" disabled={isCreating || !roomName.trim()}>Buat</button>
        </form>
        <div className="admin-chat-scroll">
          {filteredRooms.map((room) => (
            <button key={room.id} className={activeId === room.id ? 'admin-chat-item active' : 'admin-chat-item'} onClick={() => setActiveId(room.id)}>
              <div><strong>{room.name}</strong><span>{room.type}{room.branch ? ` - ${room.branch}` : ''}</span></div>
              <p>{room.last_sender ? `${room.last_sender}: ` : ''}{room.last_message ?? 'Belum ada pesan internal'}</p>
              <footer><small>{room.participants_count ?? 0} user</small>{Boolean(room.unread_count) && <b>{room.unread_count}</b>}<small>{formatShortTime(room.updated_at)}</small></footer>
            </button>
          ))}
          {filteredRooms.length === 0 && <EmptyPanel title="Belum ada room" copy="Buat room area atau tunggu room default tersinkron." />}
        </div>
      </aside>
      <main className="admin-chat-room panel">
        {!activeRoom && <EmptyPanel title="Pilih room" copy="Koordinasi internal akan tampil di sini." />}
        {activeRoom && (
          <>
            <header className="admin-chat-room-head">
              <div>
                <h2>{activeRoom.name}</h2>
                <p>{activeRoom.type} {activeRoom.branch ? `- ${activeRoom.branch}` : ''} · {activeRoom.participants_count ?? 0} peserta</p>
              </div>
              <span className="status success">Internal</span>
            </header>
            {error && <div className="chat-error">{error}</div>}
            <div className="admin-chat-messages" ref={messagesRef}>
              {detail?.messages.length === 0 && <EmptyPanel title="Belum ada pesan" copy="Mulai koordinasi dengan tim di room ini." />}
              {detail?.messages.map((item) => (
                <article key={item.id} className={item.sender_id === me.id ? 'admin-bubble mine' : 'admin-bubble'}>
                  <span>{item.sender_name} <small>{roleLabels[(item.sender_role as Role) || 'operator'] ?? item.sender_role} · {formatShortTime(item.created_at)}</small></span>
                  <p>{renderOrderCodeLinks(item.message, onOpenOrder)}</p>
                </article>
              ))}
            </div>
            <form className="admin-chat-composer" onSubmit={(event) => { event.preventDefault(); void send() }}>
              <textarea value={message} onChange={(event) => setMessage(event.target.value)} placeholder="Tulis pesan internal, mention order, atau koordinasi driver..." onKeyDown={(event) => { if (event.key === 'Enter' && !event.shiftKey) { event.preventDefault(); void send() } }} />
              <button className="primary-button" disabled={isSending || !message.trim()} type="submit">{isSending ? 'Sending...' : 'Send'}</button>
            </form>
          </>
        )}
      </main>
    </section>
  )
}

function renderOrderCodeLinks(text: string, onOpenOrder: (code: string) => void) {
  const pattern = /\b[A-Z]{2,}(?:-[A-Z0-9]+)+\b/g
  const parts: ReactNode[] = []
  let lastIndex = 0

  for (const match of text.matchAll(pattern)) {
    const code = match[0]
    const index = match.index ?? 0
    if (index > lastIndex) parts.push(text.slice(lastIndex, index))
    parts.push(<button key={`${code}-${index}`} className="order-code-link inline" onClick={() => onOpenOrder(code)}>{code}</button>)
    lastIndex = index + code.length
  }

  if (lastIndex < text.length) parts.push(text.slice(lastIndex))
  return parts.length ? parts : text
}

function EmptyPanel({ title, copy }: { title: string; copy: string }) {
  return <div className="empty-panel"><h2>{title}</h2><p>{copy}</p></div>
}

function ManualOrderPanel({ me, branches, api, onChanged }: { me: User; branches: Branch[]; api: ApiClient; onChanged: () => Promise<void> }) {
  const ownBranch = branches.find((branch) => branch.id === me.branch_id) ?? null
  const [branchId, setBranchId] = useState(() => String(ownBranch?.id ?? branches[0]?.id ?? ''))
  const selectedBranch = branches.find((branch) => String(branch.id) === branchId) ?? null
  const branchHint = selectedBranch ? branchLabel(selectedBranch) : 'Pilih cabang'
  const [rawText, setRawText] = useState('')
  const [paymentMethod, setPaymentMethod] = useState<'cash' | 'transfer' | 'qris'>('cash')
  const [preview, setPreview] = useState<ManualOrderPreview | null>(null)
  const [priceOverride, setPriceOverride] = useState('')
  const [serviceFeeOverride, setServiceFeeOverride] = useState('')
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')
  const canSubmitPreview = Boolean(preview?.order_payload)

  const parsedCustomer = previewCustomer(preview)

  const previewTextOrder = async () => {
    if (!rawText.trim()) return
    setLoading(true)
    setError('')
    try {
      const response = await api<{ data: ManualOrderPreview }>('/admin/orders/manual/preview', {
        method: 'POST',
        body: JSON.stringify({ raw_text: rawText.trim(), branch_id: Number(branchId) || null }),
      })
      setPreview(response.data)
      const parsedBranchId = branchIdFromManualPreview(response.data, branches)
      if (parsedBranchId) setBranchId(String(parsedBranchId))
      const quote = response.data.quote
      setPriceOverride(String(quote?.price ?? quote?.tarif ?? ''))
      setServiceFeeOverride(String(quote?.service_fee ?? quote?.service_charge ?? ''))
    } catch (error) {
      setPreview(null)
      setError(error instanceof Error ? error.message : 'Parser order gagal')
    } finally {
      setLoading(false)
    }
  }

  const submitParsedOrder = async () => {
    if (!preview?.order_payload) return
    setLoading(true)
    setError('')
    try {
      await api('/admin/orders/manual', {
        method: 'POST',
        body: JSON.stringify({
          parsed_customer: parsedCustomer,
          order_payload: {
            ...preview.order_payload,
            branch_id: Number(branchId) || preview.order_payload.branch_id || null,
            payment_method: paymentMethod,
            notes: [preview.order_payload.notes, rawText.trim()].filter(Boolean).join('\n'),
          },
          branch_id: Number(branchId) || null,
          price_override: priceOverride !== '' ? Number(priceOverride) : undefined,
          service_charge_override: serviceFeeOverride !== '' ? Number(serviceFeeOverride) : undefined,
        }),
      })
      setRawText('')
      setPreview(null)
      setPriceOverride('')
      setServiceFeeOverride('')
      await onChanged()
      window.alert('Order manual berhasil dibuat.')
    } catch (error) {
      setError(error instanceof Error ? error.message : 'Order gagal dibuat')
    } finally {
      setLoading(false)
    }
  }

  const addPoint = () => {
    if (!preview?.order_payload) return
    const label = `Titik ${(preview.order_payload.points?.length ?? 0) + 1}`
    setPreview({
      ...preview,
      order_payload: {
        ...preview.order_payload,
        points: [...(preview.order_payload.points ?? []), { label, address: '' }],
        stops: Math.max(1, 2 + (preview.order_payload.points?.length ?? 0)),
      },
    })
  }

  const updatePoint = (index: number, address: string) => {
    if (!preview?.order_payload) return
    const points = [...(preview.order_payload.points ?? [])]
    points[index] = { ...points[index], address }
    setPreview({ ...preview, order_payload: { ...preview.order_payload, points } })
  }

  return (
    <section className="panel manual-order-panel">
      <div className="section-head">
        <div>
          <h2>Manual Order</h2>
          <p>Paste chat customer. AI parser membaca nama, nomor, alamat, layanan, dan detail order secara otomatis.</p>
        </div>
        <span className="status info">{branchHint}</span>
      </div>

      <div className="manual-parser-layout">
        <div className="manual-ai-composer manual-workspace-card">
          <div className="manual-card-title">
            <strong>Paste order</strong>
            <span>Branch pilihan</span>
          </div>
          <label className="manual-branch-selector">
            Cabang / Area
            <select value={branchId} onChange={(event) => { setBranchId(event.target.value); setPreview(null) }}>
              <option value="">Pilih cabang</option>
              {branches.map((branch) => <option key={branch.id} value={branch.id}>{branchLabel(branch)}</option>)}
            </select>
          </label>
          <label className="manual-textarea-label">
            Teks order dari customer
            <textarea value={rawText} onChange={(event) => { setRawText(event.target.value); setPreview(null) }} placeholder={"Contoh:\nNama: Pak Budi\nNo HP: 08123456789\nOjek dari Perum ASB ke STB Kota, pembayaran cash.\nCatatan: minta driver A jika ada."} />
          </label>
          {error && <div className="manual-order-error">{error}</div>}
          <div className="manual-ai-actions">
            <button className="secondary-button" type="button" disabled={loading || !rawText.trim()} onClick={() => void previewTextOrder()}>
              {loading ? 'Membaca order...' : 'Preview AI parser'}
            </button>
          </div>
        </div>

        <ManualOrderPreviewCard
          preview={preview}
          customer={parsedCustomer}
          paymentMethod={paymentMethod}
          priceOverride={priceOverride}
          serviceFeeOverride={serviceFeeOverride}
          loading={loading}
          canSubmit={canSubmitPreview}
          onPaymentChange={setPaymentMethod}
          onPriceChange={setPriceOverride}
          onServiceFeeChange={setServiceFeeOverride}
          onAddPoint={addPoint}
          onPointChange={updatePoint}
          onDestinationChange={(address) => {
            if (!preview?.order_payload) return
            setPreview({ ...preview, order_payload: { ...preview.order_payload, destination_address: address } })
          }}
          onSubmit={() => void submitParsedOrder()}
        />
      </div>
    </section>
  )
}

type DriverPerformancePeriod = 'today' | 'month' | 'all'

function performanceMetricKey(period: DriverPerformancePeriod, metric: 'orders' | 'cancel' | 'revenue'): keyof DriverPerformanceRow['performance'] {
  if (period === 'today') {
    return metric === 'orders' ? 'today_completed_orders_count' : metric === 'cancel' ? 'today_cancelled_orders_count' : 'today_revenue'
  }

  if (period === 'month') {
    return metric === 'orders' ? 'month_completed_orders_count' : metric === 'cancel' ? 'month_cancelled_orders_count' : 'month_revenue'
  }

  return metric === 'orders' ? 'completed_orders_count' : metric === 'cancel' ? 'cancelled_orders_count' : 'completed_revenue'
}

function periodLabel(period: DriverPerformancePeriod) {
  return period === 'today' ? 'hari ini' : period === 'month' ? 'bulan ini' : 'semua waktu'
}

function DriverPerformanceBoard({ drivers, period }: { drivers: DriverRow[]; period: DriverPerformancePeriod }) {
  const rows = driverPerformanceRows(drivers)
  const orderMetric = performanceMetricKey(period, 'orders')
  const cancelMetric = performanceMetricKey(period, 'cancel')
  const revenueMetric = performanceMetricKey(period, 'revenue')
  const cards = [
    { label: 'Rating terpercaya', tone: 'rating', driver: bestDriverFor(rows, 'rating_score', 'desc'), value: (row?: DriverPerformanceRow) => row && Number(row.performance.rating_score ?? row.performance.rating_average) > 0 ? `${Number(row.performance.rating_score ?? row.performance.rating_average).toFixed(1)}/5` : '-', meta: 'Bayesian score' },
    { label: 'Terima order terbanyak', tone: 'orders', driver: bestDriverFor(rows, orderMetric, 'desc'), value: (row?: DriverPerformanceRow) => String(row?.performance[orderMetric] ?? '-'), meta: periodLabel(period) },
    { label: 'Tidak telat bayar', tone: 'clean', driver: bestDriverFor(rows, 'unpaid_deposits_count', 'asc'), value: (row?: DriverPerformanceRow) => row ? `${row.performance.unpaid_deposits_count} unpaid` : '-', meta: 'setoran' },
    { label: 'Suspend terbanyak', tone: 'risk', driver: bestDriverFor(rows, 'suspensions_count', 'desc'), value: (row?: DriverPerformanceRow) => String(row?.performance.suspensions_count ?? '-'), meta: 'evaluasi disiplin' },
    { label: 'Cancel terbanyak', tone: 'risk', driver: bestDriverFor(rows, cancelMetric, 'desc'), value: (row?: DriverPerformanceRow) => String(row?.performance[cancelMetric] ?? '-'), meta: periodLabel(period) },
    { label: 'Oper handle terbanyak', tone: 'ops', driver: bestDriverFor(rows, 'oper_handle_requests_count', 'desc'), value: (row?: DriverPerformanceRow) => String(row?.performance.oper_handle_requests_count ?? '-'), meta: 'oper handle' },
    { label: 'Pendapatan terbaik', tone: 'revenue', driver: bestDriverFor(rows, revenueMetric, 'desc'), value: (row?: DriverPerformanceRow) => row ? `Rp ${Number(row.performance[revenueMetric] ?? 0).toLocaleString('id-ID')}` : '-', meta: periodLabel(period) },
    { label: 'Online terbaik', tone: 'online', driver: bestDriverFor(rows, 'online_score', 'desc'), value: (row?: DriverPerformanceRow) => row?.driver_state === 'online' ? 'Online' : 'Offline', meta: 'status sekarang' },
  ]

  return (
    <section className="driver-performance-board">
      {cards.map((card) => (
        <article className={`driver-performance-card ${card.tone}`} key={card.label}>
          <span>{card.label}</span>
          <strong>{card.value(card.driver)}</strong>
          <small>{card.driver?.name ?? 'Belum ada data'}</small>
          <em>{card.driver ? driverBranchLabel(card.driver) : card.meta}</em>
        </article>
      ))}
    </section>
  )
}

function ManualOrderPreviewCard({
  preview,
  customer,
  paymentMethod,
  priceOverride,
  serviceFeeOverride,
  loading,
  canSubmit,
  onPaymentChange,
  onPriceChange,
  onServiceFeeChange,
  onAddPoint,
  onPointChange,
  onDestinationChange,
  onSubmit,
}: {
  preview: ManualOrderPreview | null
  customer: { name?: string; phone?: string; address?: string }
  paymentMethod: 'cash' | 'transfer' | 'qris'
  priceOverride: string
  serviceFeeOverride: string
  loading: boolean
  canSubmit: boolean
  onPaymentChange: (value: 'cash' | 'transfer' | 'qris') => void
  onPriceChange: (value: string) => void
  onServiceFeeChange: (value: string) => void
  onAddPoint: () => void
  onPointChange: (index: number, value: string) => void
  onDestinationChange: (value: string) => void
  onSubmit: () => void
}) {
  if (!preview) {
    return (
      <aside className="manual-preview-card empty">
        <Icon name="plus" />
        <strong>Preview order akan muncul di sini</strong>
        <p>AI akan membaca nama customer, telepon, pickup, tujuan, catatan, tarif, dan total dari teks yang ditempel.</p>
      </aside>
    )
  }

  const payload = preview.order_payload
  const quote = preview.quote
  const basePrice = Number(quote?.base_tarif_before_night ?? quote?.price ?? quote?.tarif ?? 0)
  const nightCharge = Number(quote?.night_tariff_charge ?? 0)
  const price = priceOverride !== '' ? Number(priceOverride) : Number(quote?.price ?? quote?.tarif ?? 0)
  const serviceFee = serviceFeeOverride !== '' ? Number(serviceFeeOverride) : Number(quote?.service_fee ?? quote?.service_charge ?? 0)
  const extraCharge = Number(quote?.extra_charge ?? 0)
  const total = price + serviceFee + extraCharge
  const distance = quote?.distance_km ?? quote?.distance

  return (
    <aside className={payload ? 'manual-preview-card ready' : 'manual-preview-card warning'}>
      <span className="status info">{preview.intent}</span>
      <strong>{manualDisplayValue(preview.selected_service ?? preview.service_type ?? payload?.service_type) ?? 'Order belum terbaca'}</strong>
      <p>{sanitizeManualPreviewText(preview.reply ?? preview.message, preview) ?? 'Lengkapi teks order agar sistem bisa membuat preview.'}</p>
      {payload && (
        <div className="manual-preview-detail">
          <div><span>Customer</span><b>{customer.name || 'Belum terbaca'}{customer.phone ? ` - ${customer.phone}` : ''}</b></div>
          <div><span>Pickup</span><b>{payload.pickup_address}</b></div>
          <label className="manual-inline-editor"><span>Tujuan</span><input value={payload.destination_address} onChange={(event) => onDestinationChange(event.target.value)} /></label>
          {(payload.points ?? []).map((point, index) => (
            <label className="manual-inline-editor" key={`${point.label}-${index}`}><span>{point.label ?? `Titik ${index + 1}`}</span><input value={point.address} onChange={(event) => onPointChange(index, event.target.value)} placeholder="Alamat titik tambahan" /></label>
          ))}
          <div><span>Jarak</span><b>{distance ? `${Number(distance).toFixed(2)} km` : '-'}</b></div>
          <label className="manual-inline-editor"><span>Pembayaran</span><select value={paymentMethod} onChange={(event) => onPaymentChange(event.target.value as 'cash' | 'transfer' | 'qris')}><option value="cash">Pembayaran Cash</option><option value="transfer">Pembayaran Transfer</option><option value="qris">Pembayaran QRIS</option></select></label>
          <div><span>Tarif dasar</span><b>Rp {basePrice.toLocaleString('id-ID')}</b></div>
          {nightCharge > 0 && <div><span>Tarif malam {quote?.night_tariff_percent ? `${quote.night_tariff_percent}%` : ''}</span><b>Rp {nightCharge.toLocaleString('id-ID')}</b></div>}
          <label className="manual-inline-editor price-editor"><span>Edit harga final</span><input type="number" min="0" value={priceOverride} onChange={(event) => onPriceChange(event.target.value)} /></label>
          <label className="manual-inline-editor"><span>Service fee</span><input type="number" min="0" value={serviceFeeOverride} onChange={(event) => onServiceFeeChange(event.target.value)} /></label>
        </div>
      )}
      {payload && (
        <>
          <div className="manual-preview-total"><span>Total estimasi</span><strong>Rp {Number(total).toLocaleString('id-ID')}</strong></div>
          <div className="manual-preview-actions sticky-actions">
            <button className="secondary-button compact" type="button" onClick={onAddPoint}>Tambah titik</button>
            <button className="primary-button compact" type="button" disabled={loading || !canSubmit} onClick={onSubmit}>{loading ? 'Mengirim...' : 'Kirim Order'}</button>
          </div>
        </>
      )}
    </aside>
  )
}

function previewCustomer(preview: ManualOrderPreview | null) {
  const parsed = preview?.parsed ?? {}
  const customer = (parsed.customer && typeof parsed.customer === 'object' ? parsed.customer : {}) as Record<string, unknown>
  return {
    name: stringValue(customer.name ?? parsed.name),
    phone: stringValue(customer.phone ?? parsed.phone),
    address: stringValue(customer.address ?? parsed.address),
  }
}

function stringValue(value: unknown) {
  return typeof value === 'string' && value.trim() !== '' ? value.trim() : undefined
}

function branchIdFromManualPreview(preview: ManualOrderPreview, branches: Branch[]) {
  const direct = preview.order_payload?.branch_id
  if (direct) return direct
  const parsed = preview.parsed ?? {}
  const hint = manualDisplayValue(parsed.area ?? parsed.branch ?? parsed.cabang ?? parsed.kode_pelanggan)
  if (!hint) return null
  const key = hint.toLowerCase()
  return branches.find((branch) => {
    const values = [branch.name, branch.area, branchLabel(branch)].filter(Boolean).map((value) => String(value).toLowerCase())
    return values.some((value) => key.includes(value) || value.includes(key))
  })?.id ?? null
}

function sanitizeManualPreviewText(value: unknown, preview: ManualOrderPreview) {
  const text = stringValue(value)
  if (!text) return undefined
  if (!text.includes('[object Object]')) return text
  const parsed = preview.parsed ?? {}
  const area = manualDisplayValue(parsed.area ?? parsed.branch ?? parsed.cabang ?? parsed.kode_pelanggan) ?? 'Area belum terbaca'
  return text.replace(/\[object Object\]/g, area)
}

function manualDisplayValue(value: unknown) {
  if (typeof value === 'string' && value.trim()) return value.trim()
  if (value && typeof value === 'object') {
    const item = value as Record<string, unknown>
    return stringValue(item.name ?? item.label ?? item.title ?? item.code)
  }

  return undefined
}
function BranchesPanel({ branches, me, api, onChanged }: { branches: Branch[]; me: User; api: ApiClient; onChanged: () => Promise<void> }) {
  const [showForm, setShowForm] = useState(false)
  const canCreate = ['admin', 'gm'].includes(me.role)
  const submit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    const form = new FormData(event.currentTarget)
    await api('/admin/branches', {
      method: 'POST',
      body: JSON.stringify({
        name: form.get('name'),
        area: form.get('area'),
        latitude: Number(form.get('latitude')),
        longitude: Number(form.get('longitude')),
        radius_km: Number(form.get('radius_km') || 5),
      }),
    })
    event.currentTarget.reset()
    setShowForm(false)
    await onChanged()
  }
  return <section className="panel branches-panel"><div className="section-head"><div><h2>Branches</h2><p>Kelola cabang operasional, area, dan titik koordinat utama.</p></div>{canCreate && <button className="primary-button compact" onClick={() => setShowForm((value) => !value)} type="button"><Icon name="plus" />Add Cabang</button>}</div>{showForm && <form className="admin-inline-form branch-create-form" onSubmit={submit}><label>Nama cabang<input name="name" required placeholder="Situbondo" /></label><label>Area<input name="area" placeholder="Kota / wilayah" /></label><label>Latitude<input name="latitude" required type="number" step="0.00000001" placeholder="-7.706" /></label><label>Longitude<input name="longitude" required type="number" step="0.00000001" placeholder="114.009" /></label><label>Radius KM<input name="radius_km" required type="number" step="0.1" min="0.1" defaultValue="5" /></label><button className="primary-button" type="submit">Save Cabang</button></form>}<div className="branch-grid">{branches.map((branch) => <article className="branch-card" key={branch.id}><div className="branch-map"><span>{branch.name.slice(0, 2).toUpperCase()}</span></div><div className="branch-card-body"><strong>{branch.name}</strong><span className="branch-area-name">{branch.area || 'Area belum diisi'}</span><p>{branch.latitude}, {branch.longitude}</p><b>{branch.radius_km ?? 5} km radius Â· {branch.geofence_areas_count ?? 0} geofence areas</b></div></article>)}</div></section>
}

function GeofencePanel({ geofences }: { geofences: Geofence[] }) {
  return <section className="panel"><PanelHeader title="Geofence areas" action={`${geofences.length} areas`} /><div className="activity-list">{geofences.map((area) => <AreaRow key={area.id} name={area.name} branch={area.branch ? branchLabel(area.branch) : '-'} radius={`${area.radius_meters} m`} active={area.is_active} />)}</div></section>
}

function LocationLogsPanel({ logs }: { logs: LocationLog[] }) {
  return <section className="panel"><PanelHeader title="Location logs" action={`${logs.length} logs`} /><div className="activity-list">{logs.map((log) => <div className="activity-item" key={log.id}><div className={log.is_suspicious ? 'activity-icon danger' : 'activity-icon'}><Icon name="pin" /></div><div><strong>{log.user || '-'}</strong><span>{log.branch || '-'} - {log.latitude}, {log.longitude}</span></div><span className={log.is_suspicious ? 'status danger' : log.is_valid ? 'status success' : 'status muted'}>{log.is_suspicious ? 'Suspicious' : log.is_valid ? 'Valid' : 'Invalid'}</span></div>)}</div></section>
}

function UserEditModal({ user, branches, permissions, api, onClose, onSaved }: { user: User; branches: Branch[]; permissions: Permissions; api: ApiClient; onClose: () => void; onSaved: () => void }) {
  const [role, setRole] = useState<Role>(user.role)
  const [saving, setSaving] = useState(false)
  const submit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    setSaving(true)
    const form = new FormData(event.currentTarget)
    try {
      await api(`/admin/users/${user.id}`, {
        method: 'PUT',
        body: JSON.stringify({
          username: form.get('username'),
          name: form.get('name'),
          email: form.get('email'),
          phone: form.get('phone'),
        role,
        branch_id: Number(form.get('branch_id')) || null,
        is_active: form.get('is_active') === 'on',
        is_suspended: form.get('is_suspended') === 'on',
        suspension_reason: form.get('suspension_reason') || null,
        ...(role === 'driver' ? {
          driver_bansos_amount: form.get('driver_bansos_amount') === '' ? null : Number(form.get('driver_bansos_amount')),
          driver_bpjs_jht_enabled: form.get('driver_bpjs_jht_enabled') === 'on',
        } : {}),
      }),
      })
      onSaved()
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="modal-backdrop" role="presentation">
      <div className="modal" role="dialog" aria-modal="true">
        <div className="modal-header"><div><h2>Edit user</h2><p>{user.email}</p></div><button type="button" className="icon-button" onClick={onClose}><Icon name="close" /></button></div>
        <form className="user-form" onSubmit={submit}>
          <fieldset><legend>Account</legend><div className="form-grid"><label>Username<input name="username" required defaultValue={user.username} /></label><label>Name<input name="name" required defaultValue={user.name} /></label><label>Email<input name="email" type="email" required defaultValue={user.email} /></label><label>Phone<input name="phone" defaultValue={user.phone ?? ''} /></label></div></fieldset>
          <fieldset><legend>Access</legend><div className="form-grid"><label>Role<select value={role} onChange={(event) => setRole(event.target.value as Role)}>{permissions.assignable_roles.map((item) => <option key={item} value={item}>{roleLabels[item]}</option>)}</select></label><label>Branch<select name="branch_id" defaultValue={user.branch_id ?? ''}><option value="">No branch</option>{branches.map((branch) => <option key={branch.id} value={branch.id}>{branchLabel(branch)}</option>)}</select></label><label className="toggle-row"><input name="is_active" type="checkbox" defaultChecked={user.is_active} />Active</label><label className="toggle-row"><input name="is_suspended" type="checkbox" defaultChecked={user.is_suspended} />Suspended</label>{role === 'driver' && <label>Bansos Driver<input name="driver_bansos_amount" type="number" min="0" placeholder="Kosong = otomatis area" defaultValue={user.driver_bansos_amount ?? ''} /></label>}{role === 'driver' && <label className="toggle-row"><input name="driver_bpjs_jht_enabled" type="checkbox" defaultChecked={user.driver_bpjs_jht_enabled ?? true} />JHT BPJS</label>}<label className="span-2">Suspension reason<textarea name="suspension_reason" defaultValue="" placeholder="Optional reason" /></label></div></fieldset>
          <div className="modal-actions"><button type="button" className="secondary-button" onClick={onClose}>Cancel</button><button className="primary-button" type="submit" disabled={saving}>{saving ? 'Saving...' : 'Save user'}</button></div>
        </form>
      </div>
    </div>
  )
}

function UserFormModal({ permissions, branches, services, api, onClose, onCreated }: { permissions: Permissions; branches: Branch[]; services: ServiceRow[]; api: ApiClient; onClose: () => void; onCreated: (password: string) => void }) {
  const [role, setRole] = useState<Role>(permissions.assignable_roles[0] ?? 'operator')
  const [vehicleType, setVehicleType] = useState<'motor' | 'mobil'>('motor')
  const [allowedServices, setAllowedServices] = useState<string[]>([])
  const toggleService = (code: string) => setAllowedServices((current) => current.includes(code) ? current.filter((item) => item !== code) : [...current, code])
  const submit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    const form = new FormData(event.currentTarget)
    const payload = await api<{ temporary_password: string }>('/admin/users', {
      method: 'POST',
      body: JSON.stringify({
        username: form.get('username'),
        name: form.get('name'),
        email: form.get('email'),
        phone: form.get('phone'),
        role,
        branch_id: Number(form.get('branch_id')) || null,
        is_active: form.get('is_active') === 'on',
        is_suspended: false,
        ...(role === 'driver' ? {
          driver_bansos_amount: form.get('driver_bansos_amount') === '' ? null : Number(form.get('driver_bansos_amount')),
          driver_bpjs_jht_enabled: form.get('driver_bpjs_jht_enabled') === 'on',
          vehicle_type: vehicleType,
          vehicle_seat_rows: vehicleType === 'mobil' ? Number(form.get('vehicle_seat_rows') || 2) : null,
          is_ladies_driver: form.get('is_ladies_driver') === 'on',
          allowed_service_types: allowedServices,
        } : {}),
      }),
    })
    onCreated(payload.temporary_password)
  }
  return <div className="modal-backdrop" role="presentation"><div className="modal" role="dialog" aria-modal="true"><div className="modal-header"><div><h2>Create user</h2><p>Assignable roles: {permissions.assignable_roles.map((item) => roleLabels[item]).join(', ')}</p></div><button type="button" className="icon-button" onClick={onClose}><Icon name="close" /></button></div><form className="user-form" onSubmit={submit}><fieldset><legend>Info User</legend><div className="form-grid"><label>Username<input name="username" required /></label><label>Name<input name="name" required /></label><label>Email<input name="email" type="email" required /></label><label>Phone<input name="phone" /></label></div></fieldset><fieldset><legend>Role & Branch</legend><div className="form-grid"><label>Role<select value={role} onChange={(event) => setRole(event.target.value as Role)}>{permissions.assignable_roles.map((item) => <option key={item} value={item}>{roleLabels[item]}</option>)}</select></label><label>Branch<select name="branch_id"><option value="">No branch</option>{branches.map((branch) => <option key={branch.id} value={branch.id}>{branchLabel(branch)}</option>)}</select></label><label className="toggle-row"><input name="is_active" type="checkbox" defaultChecked />Active</label>{role === 'driver' && <label>Tipe kendaraan<select name="vehicle_type" value={vehicleType} onChange={(event) => setVehicleType(event.target.value as 'motor' | 'mobil')}><option value="motor">Motor</option><option value="mobil">Mobil</option></select></label>}{role === 'driver' && vehicleType === 'mobil' && <label>Kapasitas mobil<select name="vehicle_seat_rows" defaultValue="2"><option value="2">2 baris - citycar/default</option><option value="3">3 baris - MPV/keluarga</option></select></label>}{role === 'driver' && <label>Bansos Driver<input name="driver_bansos_amount" type="number" min="0" placeholder="Kosong = otomatis area" /></label>}{role === 'driver' && <label className="toggle-row"><input name="driver_bpjs_jht_enabled" type="checkbox" defaultChecked />JHT BPJS</label>}{role === 'driver' && <label className="toggle-row driver-ladies-toggle"><input name="is_ladies_driver" type="checkbox" />Driver Ladies</label>}</div>{role === 'driver' && <div className="service-config-pills"><strong>Config layanan driver</strong><span>Kosongkan jika driver boleh menerima semua layanan.</span>{services.map((service) => <label key={service.id} className="toggle-row service-pill"><input type="checkbox" checked={allowedServices.includes(service.code)} onChange={() => toggleService(service.code)} />{service.name}</label>)}</div>}</fieldset><div className="modal-actions"><button type="button" className="secondary-button" onClick={onClose}>Cancel</button><button className="primary-button" type="submit">Create real user</button></div></form></div></div>
}

type ApiClient = <T = unknown>(path: string, options?: RequestInit) => Promise<T>

class AuthExpiredError extends Error {
  constructor(message = 'Sesi login berakhir. Silakan login ulang.') {
    super(message)
    this.name = 'AuthExpiredError'
  }
}

function isAuthError(error: unknown) {
  return error instanceof AuthExpiredError
}

function makeApi(token: string, onUnauthorized?: () => void): ApiClient {
  return async <T,>(path: string, options: RequestInit = {}) => {
    const response = await fetch(`${API_BASE}${path}`, {
      ...options,
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        Authorization: `Bearer ${token}`,
        ...(options.headers ?? {}),
      },
    })
    const payload = await response.json().catch(() => ({}))
    if (response.status === 401 || response.status === 419) {
      onUnauthorized?.()
      throw new AuthExpiredError(typeof payload.message === 'string' ? payload.message : undefined)
    }
    if (!response.ok) throw new Error(payload.message || `HTTP ${response.status}`)
    return payload as T
  }
}

let adminEcho: Echo<'reverb'> | null = null

function makeEcho(token: string) {
  if (adminEcho) return adminEcho

  window.Pusher = Pusher
  adminEcho = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY ?? 'local',
    wsHost: import.meta.env.VITE_REVERB_HOST ?? window.location.hostname,
    wsPort: Number(import.meta.env.VITE_REVERB_PORT ?? 8080),
    wssPort: Number(import.meta.env.VITE_REVERB_PORT ?? 8080),
    forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'http') === 'https',
    enabledTransports: ['ws', 'wss'],
    authEndpoint: `${APP_BASE}/broadcasting/auth`,
    auth: {
      headers: {
        Authorization: `Bearer ${token}`,
        Accept: 'application/json',
      },
    },
  })

  return adminEcho
}

function RoleBadge({ role }: { role: Role }) {
  return <span className={`role-badge ${roleColors[role]}`}>{roleLabels[role]}</span>
}

function StatusBadge({ status }: { status: string }) {
  const tone = status === 'COMPLETED' ? 'success' : status === 'CANCELLED' ? 'danger' : status === 'SEARCHING_DRIVER' ? 'warning' : 'info'
  return <span className={`status ${tone}`}>{status}</span>
}

function defaultNightTariffRules(): NightTariffRule[] {
  return [
    { area: 'bws', start: '21:30', end: '00:00', percent: 30 },
    { area: 'bondowoso', start: '21:30', end: '00:00', percent: 30 },
    { area: '', start: '22:00', end: '00:00', percent: 30 },
    { area: '', start: '00:01', end: '04:00', percent: 50 },
    { area: '', start: '04:01', end: '06:00', percent: 30 },
  ]
}

function updateNightRule(rows: NightTariffRule[], index: number, patch: Partial<NightTariffRule>) {
  return rows.map((row, rowIndex) => rowIndex === index ? { ...row, ...patch } : row)
}

function serviceTypeFromService(service: ServiceRow) {
  return {
    OJ: 'ojek',
    KR: 'kurir',
    DO: 'delivery',
    BL: 'belanja',
    GO: 'gift_order',
    JM: 'joker_mobil',
    TV: 'travel',
  }[service.code.toUpperCase()] ?? service.code.toLowerCase()
}

function AreaRow({ name, branch, radius, active }: { name: string; branch: string; radius: string; active: boolean }) {
  return <div className="activity-item"><div className="activity-icon"><Icon name="map" /></div><div><strong>{name}</strong><span>{branch} - {radius}</span></div><span className={active ? 'status success' : 'status muted'}>{active ? 'Active' : 'Off'}</span></div>
}

function PanelHeader({ title, action }: { title: string; action: string }) {
  return <div className="panel-header"><h2>{title}</h2><span>{action}</span></div>
}

function subtitleFor(data: Bootstrap) {
  return `${roleLabels[data.me.role]} dashboard`
}

function titleFor(view: View) {
  return { dashboard: 'Admin Dashboard', orders: 'Order Operations', 'request-orders': 'Request Order', users: 'User Management', drivers: 'Driver Management', settings: 'System Settings', pricing: 'Pricing & Policy', branches: 'Branch Management', geofence: 'Geofence Areas', locations: 'Location Logs', reports: 'Reports', chats: 'Chat Monitor', 'internal-chat': 'Internal Chat', 'manual-order': 'Manual Order' }[view]
}

function senderLabel(sender: string) {
  return sender === 'customer' ? 'Customer' : sender === 'operator' ? 'Operator' : sender === 'driver' ? 'Driver' : sender === 'bot' ? 'JOJOBOT' : sender
}

function sortOrdersNewest(orders: Order[]) {
  return [...orders].sort((first, second) => {
    const firstTime = first.created_at ? new Date(first.created_at).getTime() : 0
    const secondTime = second.created_at ? new Date(second.created_at).getTime() : 0

    return secondTime - firstTime || second.id - first.id
  })
}

function orderMatchesSearch(order: Order, searchQuery: string) {
  return `${order.code} ${order.customer ?? ''} ${order.driver ?? ''} ${order.service} ${displayBranchValue(order.branch, order.branch_area)} ${order.status} ${order.source ?? ''} ${order.cancel_reason ?? ''}`
    .toLowerCase()
    .includes(searchQuery.toLowerCase())
}

function paymentLabel(method?: string | null) {
  if (method === 'transfer') return 'Transfer'
  if (method === 'qris') return 'QRIS'
  if (method === 'cash') return 'Cash'
  return '-'
}

function vehicleLabel(vehicle?: string | null) {
  if (vehicle === 'mobil') return 'Mobil'
  if (vehicle === 'motor') return 'Motor'
  return '-'
}

function driverPreferenceLabel(preference?: string | null) {
  if (preference === 'ladies') return 'Ladies'
  return 'Umum'
}

function formatDistance(value?: number | string | null) {
  const distance = Number(value ?? 0)

  return distance > 0 ? `${distance.toLocaleString('id-ID', { maximumFractionDigits: 2 })} km` : '-'
}

function shortOrderRoute(order: Order) {
  const pickup = stringValue(order.pickup_address) || 'pickup'
  const destination = stringValue(order.destination_address) || 'tujuan'

  return `${pickup.slice(0, 24)} ? ${destination.slice(0, 24)}`
}

function displayBranchValue(branch: unknown, area?: string | null) {
  if (typeof branch === 'string' && branch.trim()) return [branch, area].filter(Boolean).join(' - ')
  if (branch && typeof branch === 'object') {
    const value = branch as { name?: unknown; area?: unknown }
    return [stringValue(value.name), stringValue(value.area) ?? area].filter(Boolean).join(' - ') || '-'
  }

  return area || '-'
}

function priceLogSummary(log: AuditLog) {
  const metadata = log.metadata ?? {}
  const after = metadata.after && typeof metadata.after === 'object' ? metadata.after as Record<string, unknown> : {}
  const total = Number(after.total ?? 0)

  return total > 0 ? `Total baru Rp ${total.toLocaleString('id-ID')}` : 'Harga diedit'
}

function monthName(month: number) {
  return new Intl.DateTimeFormat('id-ID', { month: 'long' }).format(new Date(2026, month - 1, 1))
}

function depositReportHeaders(month: number) {
  const label = monthName(month).toUpperCase()

  return [
    'DRIVER',
    'AREA',
    'JML ORDER',
    'Omset Dari Jasa Dasar',
    'Setoran 20% dari Jasa Dasar',
    'Tagihan Bln Lalu',
    'JHT BPJSTK',
    'Premi BPJSTK',
    'Reward Cashback Bulan Lalu',
    'Total Tagihan',
    'Bansos Area',
    `Total Tagihan ${label}`,
    'Terbayar',
    'Sisa Tagihan',
    'Tgl Bayar',
    'cashback 10% utk Bulan Depan',
  ]
}

function formatNumber(value: number) {
  return value === 0 ? '-' : value.toLocaleString('en-US')
}

function formatShortTime(value?: string | null) {
  if (!value) return ''
  return new Intl.DateTimeFormat('id-ID', { hour: '2-digit', minute: '2-digit' }).format(new Date(value))
}

function formatShortDateTime(value?: string | null) {
  if (!value) return '-'
  return new Intl.DateTimeFormat('id-ID', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }).format(new Date(value))
}

function branchLabel(branch: Branch) {
  return [branch.name, branch.area].filter(Boolean).join(' - ')
}

function userBranchLabel(user: User) {
  if (!user.branch) return '-'
  return [user.branch, user.branch_area].filter(Boolean).join(' - ')
}

function assetUrl(path: string) {
  return path.startsWith('http') ? path : `${APP_BASE}${path}`
}

function Icon({ name }: { name: string }) {
  const icons: Record<string, string> = {
    grid: 'M4 4h7v7H4V4Zm9 0h7v7h-7V4ZM4 13h7v7H4v-7Zm9 0h7v7h-7v-7Z',
    bag: 'M7 8V7a5 5 0 0 1 10 0v1h2l1 12H4L5 8h2Zm2 0h6V7a3 3 0 0 0-6 0v1Z',
    users: 'M8 11a4 4 0 1 1 0-8 4 4 0 0 1 0 8Zm8 1a3.5 3.5 0 1 1 0-7 3.5 3.5 0 0 1 0 7ZM2 20a6 6 0 0 1 12 0H2Zm12.5 0a7.5 7.5 0 0 0-2-5.1A5 5 0 0 1 22 20h-7.5Z',
    cash: 'M3 6h18v12H3V6Zm3 3a3 3 0 0 1-3 3v3a3 3 0 0 1 3 3h12a3 3 0 0 1 3-3v-3a3 3 0 0 1-3-3H6Zm6 6a3 3 0 1 1 0-6 3 3 0 0 1 0 6Z',
    building: 'M4 21V3h10v18H4Zm12 0V9h4v12h-4ZM7 6v2h2V6H7Zm0 4v2h2v-2H7Zm0 4v2h2v-2H7Zm4-8v2h2V6h-2Zm0 4v2h2v-2h-2Zm0 4v2h2v-2h-2Z',
    map: 'M9 18 3 21V6l6-3 6 3 6-3v15l-6 3-6-3Zm1-12v10l4 2V8l-4-2Z',
    pin: 'M12 22s7-5.2 7-12a7 7 0 1 0-14 0c0 6.8 7 12 7 12Zm0-9a3 3 0 1 1 0-6 3 3 0 0 1 0 6Z',
    search: 'M10 4a6 6 0 1 1-3.5 10.9l-3.2 3.2 1.4 1.4 3.2-3.2A6 6 0 0 1 10 4Zm0 2a4 4 0 1 0 0 8 4 4 0 0 0 0-8Z',
    plus: 'M11 4h2v7h7v2h-7v7h-2v-7H4v-2h7V4Z',
    truck: 'M3 6h11v9H3V6Zm12 3h3l3 4v2h-2a3 3 0 0 0-6 0h-2V9h4ZM7 20a3 3 0 1 1 0-6 3 3 0 0 1 0 6Zm10 0a3 3 0 1 1 0-6 3 3 0 0 1 0 6Z',
    shield: 'M12 2 20 5v6c0 5-3.4 9-8 11-4.6-2-8-6-8-11V5l8-3Z',
    close: 'm6.4 5 12.6 12.6-1.4 1.4L5 6.4 6.4 5Zm12.6 1.4L6.4 19 5 17.6 17.6 5 19 6.4Z',
    chevron: 'M7.4 8.6 12 13.2l4.6-4.6L18 10l-6 6-6-6 1.4-1.4Z',
    chart: 'M4 19h16v2H2V3h2v16Zm3-2V9h3v8H7Zm5 0V5h3v12h-3Zm5 0v-6h3v6h-3Z',
    chat: 'M4 4h16v11H7l-5 5V4h2Zm3 4v2h10V8H7Zm0 4v2h7v-2H7Z',
    receipt: 'M6 2h12v20l-3-2-3 2-3-2-3 2V2Zm3 5v2h6V7H9Zm0 4v2h6v-2H9Zm0 4v2h4v-2H9Z',
    settings: 'M19.4 13.5a7.8 7.8 0 0 0 .1-1.5 7.8 7.8 0 0 0-.1-1.5l2-1.5-2-3.5-2.4 1a7.2 7.2 0 0 0-2.6-1.5L14 2h-4l-.4 2.5A7.2 7.2 0 0 0 7 6L4.6 5 2.6 8.5l2 1.5a7.8 7.8 0 0 0-.1 1.5c0 .5 0 1 .1 1.5l-2 1.5 2 3.5 2.4-1a7.2 7.2 0 0 0 2.6 1.5L10 22h4l.4-2.5A7.2 7.2 0 0 0 17 18l2.4 1 2-3.5-2-1.5ZM12 15.5a3.5 3.5 0 1 1 0-7 3.5 3.5 0 0 1 0 7Z',
    moon: 'M21 14.8A8.5 8.5 0 0 1 9.2 3a7 7 0 1 0 11.8 11.8Z',
    sun: 'M12 7a5 5 0 1 1 0 10 5 5 0 0 1 0-10Zm0-5h2v3h-2V2Zm0 17h2v3h-2v-3ZM2 12h3v2H2v-2Zm17 0h3v2h-3v-2ZM4.2 5.6l1.4-1.4 2.1 2.1-1.4 1.4-2.1-2.1Zm12.1 12.1 1.4-1.4 2.1 2.1-1.4 1.4-2.1-2.1Zm2.1-13.5 1.4 1.4-2.1 2.1-1.4-1.4 2.1-2.1ZM6.3 16.3l1.4 1.4-2.1 2.1-1.4-1.4 2.1-2.1Z',
    logout: 'M5 3h8v2H7v14h6v2H5V3Zm11.6 5.4L21.2 13l-4.6 4.6-1.4-1.4 2.2-2.2H10v-2h7.4l-2.2-2.2 1.4-1.4Z',
  }
  return <svg viewBox="0 0 24 24" aria-hidden="true"><path d={icons[name]} /></svg>
}

export default App

