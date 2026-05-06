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

type Role = 'admin' | 'gm' | 'hrd' | 'manager' | 'spv' | 'operator' | 'driver' | 'customer'
type View = 'dashboard' | 'orders' | 'request-orders' | 'users' | 'drivers' | 'settings' | 'pricing' | 'branches' | 'geofence' | 'locations' | 'reports' | 'chats' | 'manual-order'
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
}
type DriverRow = User & {
  driver_id: number | null
  driver_status: 'active' | 'suspended' | 'suspended_unpaid'
  suspended_until: string | null
  suspension_reason: string | null
  oper_handle_count: number
  vehicle_type?: 'motor' | 'mobil' | string | null
  allowed_service_types?: string[]
  suspensions: { id: number; reason: string; duration: number; start_at: string | null; end_at: string | null; status: string }[]
}

type Order = {
  id: number
  code: string
  customer: string | null
  driver: string | null
  service: string
  source?: string | null
  status: string
  cancel_reason?: string | null
  branch: string | null
  price: number
  service_charge: number
  extra_charge: number
  total: number
  created_at: string | null
}

type Branch = { id: number; name: string; area: string | null; latitude: string; longitude: string; radius_km?: string | number | null; geofence_areas_count?: number }
type ServiceRow = { id: number; name: string; code: string }
type PriceSetting = { id: number; name: string; branch_id: number | null; min_km: string; max_km: string | null; price: number | null; is_formula: boolean; per_km_rate: number | null; subtract_value: number | null; branch?: Branch | null }
type Geofence = { id: number; name: string; branch?: Branch | null; center_latitude: string; center_longitude: string; radius_meters: number; is_active: boolean }
type LocationLog = { id: number; user: string | null; branch: string | null; latitude: number; longitude: number; is_valid: boolean; is_suspicious: boolean; reason: string | null; created_at: string | null }
type Chat = { id: number; order_id?: number | null; order_code: string | null; type?: string; customer: string | null; driver: string | null; operator: string | null; branch?: string | null; status: string; sla_status?: string | null; latest_message?: string | null; last_message?: string | null; unread_count?: number; last_customer_message_at?: string | null; updated_at: string | null }
type AdminChatMessage = { id: number; chat_id: number; sender_id: number | null; sender_type: string; sender_name?: string | null; message: string; image_url?: string | null; audio_url?: string | null; audio_duration?: number | null; created_at?: string | null }
type ChatDetail = { chat: Chat; messages: AdminChatMessage[]; cancel_request?: { id: number; status: string; reason: string; image_url?: string | null } | null }
type AuditLog = { id: number; user: string; role: Role | null; action: string; subject_type: string; subject_id: number | null; subject_label: string | null; created_at: string | null }
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
  can_manage_system_settings: boolean
  can_edit_order_price: boolean
  can_create_manual_order: boolean
  can_view_report?: boolean
  can_export_report?: boolean
  can_monitor_live_order?: boolean
  can_monitor_live_chat?: boolean
  can_approve_cancel_order?: boolean
  can_reject_cancel_order?: boolean
}
type Bootstrap = {
  me: User
  permissions: Permissions
  system_settings: SystemSettings
  stats: Stats
  users: User[]
  drivers: DriverRow[]
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

function allowedViewsFor(role: Role, permissions: Permissions): View[] {
  if (role === 'admin' || role === 'gm') return allMenus.map((item) => item.id)

  const views = new Set<View>(['dashboard'])
  if (permissions.can_monitor_live_order) {
    views.add('orders')
    views.add('request-orders')
  }
  if (permissions.can_manage_users) views.add('users')
  if (permissions.can_suspend_drivers || permissions.can_unsuspend_drivers) views.add('drivers')
  if (permissions.can_edit_order_price || permissions.can_manage_policy) views.add('pricing')
  if (permissions.can_view_report) views.add('reports')
  if (permissions.can_monitor_live_chat) views.add('chats')
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

  const api = useMemo(() => makeApi(token), [token])

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
      const message = error instanceof Error ? error.message : 'Failed to load admin data'
      setError(message.includes('403') ? 'Akun ini tidak memiliki akses admin.' : message)
      if (String(error).includes('401')) setToken('')
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

  if (!token) {
    return <LoginScreen onLogin={(nextToken) => {
      localStorage.setItem('admin_token', nextToken)
      setToken(nextToken)
    }} />
  }

  if (!data) {
    return <div className="loading-screen">{isLoading ? 'Loading real admin data...' : error || 'No data loaded'}</div>
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
      localStorage.removeItem('admin_token')
      localStorage.removeItem('token')
      sessionStorage.clear()
      setToken('')
      setData(null)
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
        </div>
      </aside>

        <main className="main">
          <header className="topbar">
            <button className="mobile-menu-button" type="button" aria-label="Open navigation" onClick={() => setMobileNavOpen(true)}><Icon name="grid" />Menu</button>
            <div className="topbar-title"><h1>{titleFor(safeView)}</h1><p>{subtitleFor(data)}</p>{error && <p className="error-text">{error}</p>}</div>
            <div className="topbar-actions">
              <div className="search"><Icon name="search" /><input value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Search real data" /></div>
              <PwaInstallButton />
              {data.permissions.can_manage_users && <button className="primary-button" type="button" onClick={() => setUserFormOpen(true)}><Icon name="plus" />New User</button>}
              <div className="auto-refresh-pill" title="Data admin tersinkron otomatis tiap 5 detik">
                <span />
                Auto refresh
                <small>{lastSyncedAt ? formatShortTime(lastSyncedAt.toISOString()) : 'sync'}</small>
              </div>
              <button className="theme-button" type="button" onClick={toggleDarkMode}><Icon name={darkMode ? 'sun' : 'moon'} />{darkMode ? 'Light' : 'Dark'}</button>
              <button className="logout-button" type="button" onClick={logout}><Icon name="logout" />Logout</button>
            </div>
          </header>

        {safeView === 'dashboard' && <Dashboard data={data} />}
        {safeView === 'orders' && <OrdersTable orders={data.orders} searchQuery={query} permissions={data.permissions} api={api} onChanged={refresh} />}
        {safeView === 'request-orders' && <RequestOrdersPanel orders={data.orders} searchQuery={query} />}
        {safeView === 'users' && <UsersPanel users={filteredUsers} branches={data.branches} me={data.me} roleFilter={roleFilter} onRoleFilterChange={setRoleFilter} permissions={data.permissions} api={api} onChanged={refresh} />}
        {safeView === 'drivers' && <DriverManagementPanel drivers={data.drivers} services={data.services} permissions={data.permissions} api={api} onChanged={refresh} />}
        {safeView === 'settings' && <SystemSettingsPanel settings={data.system_settings} permissions={data.permissions} api={api} onChanged={refresh} />}
        {safeView === 'pricing' && <PricingPanel settings={data.price_settings} branches={data.branches} permissions={data.permissions} api={api} onChanged={refresh} />}
        {safeView === 'reports' && <ReportsPanel data={data} api={api} token={token} />}
        {safeView === 'chats' && <AdminChatPanel initialChats={data.chats} api={api} me={data.me} token={token} permissions={data.permissions} onOpenOrder={(code) => { setQuery(code); setView('orders') }} />}
        {safeView === 'manual-order' && <ManualOrderPanel users={data.users} api={api} onChanged={refresh} />}
        {safeView === 'branches' && <BranchesPanel branches={data.branches} me={data.me} api={api} onChanged={refresh} />}
        {safeView === 'geofence' && <GeofencePanel geofences={data.geofences} />}
        {safeView === 'locations' && <LocationLogsPanel logs={data.location_logs} />}
      </main>

      {isUserFormOpen && <UserFormModal permissions={data.permissions} branches={data.branches} api={api} onClose={() => setUserFormOpen(false)} onCreated={async (password) => { alert(`Password sementara: ${password}`); await refresh(); setUserFormOpen(false) }} />}
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

function Dashboard({ data }: { data: Bootstrap }) {
  return (
    <div className="dashboard-grid">
      <section className="hero-panel">
        <div><h2>{dashboardHeadline(data.me.role)}</h2><p>{subtitleFor(data)}</p></div>
        <div className="hero-orbit"><span></span><strong>{data.stats.active_orders}</strong><small>active orders</small></div>
      </section>
      <StatsRow stats={[
        { label: 'Visible Users', value: data.stats.total_users, icon: 'users', tone: 'violet' },
        { label: 'Visible Drivers', value: data.stats.total_drivers, icon: 'truck', tone: 'green' },
        { label: 'Active Orders', value: data.stats.active_orders, icon: 'bag', tone: 'amber' },
        { label: 'Suspended Driver', value: data.stats.suspended_drivers, icon: 'shield', tone: 'red' },
      ]} />
      <RoleAccessPanel role={data.me.role} />
      <RecentActivity orders={data.orders} />
      <AuditHistory logs={data.audit_logs ?? []} />
    </div>
  )
}

function StatsRow({ stats }: { stats: { label: string; value: number; icon: string; tone: string }[] }) {
  return <section className="stats-row">{stats.map((stat) => <article className={`stat-card ${stat.tone}`} key={stat.label}><div className="stat-icon"><Icon name={stat.icon} /></div><span>{stat.label}</span><strong>{stat.value}</strong><div className="sparkline"><i></i><i></i><i></i><i></i><i></i></div></article>)}</section>
}

function RoleAccessPanel({ role }: { role: Role }) {
  return <section className="panel"><PanelHeader title={`${roleLabels[role]} access`} action="Role permissions" /><div className="access-grid">{roleModules(role).map((item) => <article className="access-card" key={item.title}><div className="activity-icon"><Icon name={item.icon} /></div><strong>{item.title}</strong><span>{item.description}</span></article>)}</div></section>
}

function RecentActivity({ orders }: { orders: Order[] }) {
  return <section className="panel activity-panel"><PanelHeader title="Recent order activity" action="Live orders" /><div className="activity-list">{orders.slice(0, 6).map((order) => <div className="activity-item order-activity-item" key={order.id}><div className="activity-icon"><Icon name="bag" /></div><div><strong>{order.code}</strong><span>{order.customer || '-'} booked {order.service}</span>{order.status === 'CANCELLED' && <em>{order.cancel_reason || 'Dibatalkan tanpa alasan tersimpan.'}</em>}</div><StatusBadge status={order.status} /></div>)}</div></section>
}

function AuditHistory({ logs }: { logs: AuditLog[] }) {
  return (
    <section className="panel audit-panel">
      <PanelHeader title="History perubahan data" action={`${logs.length} logs`} />
      <div className="activity-list">
        {logs.length === 0 && <EmptyPanel title="Belum ada log" copy="Aktivitas create/edit admin akan muncul di sini." />}
        {logs.map((log) => (
          <div className="activity-item audit-item" key={log.id}>
            <div className="activity-icon"><Icon name="receipt" /></div>
            <div><strong>{auditActionLabel(log.action)}</strong><span>{log.user} · {log.subject_type} {log.subject_label ?? `#${log.subject_id ?? '-'}`}</span></div>
            <span className="status muted">{formatShortDateTime(log.created_at)}</span>
          </div>
        ))}
      </div>
    </section>
  )
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
      <PanelHeader title="Driver Management" action={`${drivers.length} driver${drivers.length === 1 ? '' : 's'}`} />
      <div className="driver-table-shell">
        <table className="driver-table">
          <thead><tr><th>Driver</th><th>Phone</th><th>Kendaraan</th><th>Layanan</th><th>Status</th><th>Until</th><th>Oper</th><th>History</th><th>Actions</th></tr></thead>
          <tbody>
            {drivers.map((driver) => (
              <tr key={driver.id}>
                <td><strong>{driver.name}</strong><span>{driver.username}</span></td>
                <td><span className="driver-phone">{driver.phone || '-'}</span></td>
                <td><span className="status info">{driver.vehicle_type ?? 'motor'}</span></td>
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
                    {permissions.can_unsuspend_drivers && driver.driver_status !== 'active' && <button className="mini-button" type="button" onClick={() => void release(driver)}>Release</button>}
                  </div>
                </td>
              </tr>
            ))}
            {drivers.length === 0 && (
              <tr>
                <td colSpan={9}>
                  <EmptyPanel title="Belum ada driver" copy="Driver yang terlihat sesuai role akan muncul di sini." />
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
      {configDriver && <DriverConfigModal driver={configDriver} services={services} api={api} onClose={() => setConfigDriver(null)} onSaved={async () => { await onChanged(); setConfigDriver(null) }} />}
    </section>
  )
}

function DriverConfigModal({ driver, services, api, onClose, onSaved }: { driver: DriverRow; services: ServiceRow[]; api: ApiClient; onClose: () => void; onSaved: () => Promise<void> }) {
  const serviceOptions = services.map((service) => ({ label: service.name, value: serviceTypeFromService(service) }))
  const [vehicleType, setVehicleType] = useState<'motor' | 'mobil'>((driver.vehicle_type === 'mobil' ? 'mobil' : 'motor'))
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
            </div>
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

function OrdersTable({ orders, searchQuery, permissions, api, onChanged }: { orders: Order[]; searchQuery: string; permissions: Permissions; api: ApiClient; onChanged: () => Promise<void> }) {
  const [editingOrder, setEditingOrder] = useState<Order | null>(null)
  const latestOrders = useMemo(() => sortOrdersNewest(orders), [orders])
  const filteredOrders = latestOrders.filter((order) => orderMatchesSearch(order, searchQuery))
  return <section className="panel"><PanelHeader title="Order operations" action={`${filteredOrders.length}/${orders.length} orders`} />{permissions.can_edit_order_price && <div className="notice">Edit harga akan dikirim realtime ke customer dan driver.</div>}<div className="table-wrap"><table><thead><tr><th>Order</th><th>Customer</th><th>Driver</th><th>Service</th><th>Branch</th><th>Total</th><th>Status</th>{permissions.can_edit_order_price && <th>Action</th>}</tr></thead><tbody>{filteredOrders.map((order) => <tr key={order.id}><td><strong>{order.code}</strong><span>{formatShortDateTime(order.created_at)}</span></td><td>{order.customer || '-'}</td><td>{order.driver || '-'}</td><td>{order.service}</td><td>{order.branch || '-'}</td><td><strong>Rp {order.total.toLocaleString('id-ID')}</strong><span>Tarif Rp {order.price.toLocaleString('id-ID')} · Fee Rp {order.service_charge.toLocaleString('id-ID')}</span></td><td><StatusBadge status={order.status} /></td>{permissions.can_edit_order_price && <td><button className="mini-button" type="button" onClick={() => setEditingOrder(order)}>Edit harga</button></td>}</tr>)}</tbody></table>{filteredOrders.length === 0 && <EmptyPanel title="Order tidak ditemukan" copy="Coba cek kode order atau hapus filter pencarian." />}</div>{editingOrder && <OrderPriceModal order={editingOrder} api={api} onClose={() => setEditingOrder(null)} onSaved={async () => { await onChanged(); setEditingOrder(null) }} />}</section>
}

function RequestOrdersPanel({ orders, searchQuery }: { orders: Order[]; searchQuery: string }) {
  const requestOrders = useMemo(() => sortOrdersNewest(orders).filter((order) => order.source === 'driver_request'), [orders])
  const filteredOrders = requestOrders.filter((order) => orderMatchesSearch(order, searchQuery))

  return (
    <section className="panel">
      <PanelHeader title="Request Order" action={`Driver request terbaru · ${filteredOrders.length}/${requestOrders.length}`} />
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
        <div className="modal-header"><div><h2>Edit harga order</h2><p>{order.code} · {order.customer || 'Customer'}</p></div><button type="button" className="icon-button" onClick={onClose}><Icon name="close" /></button></div>
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
      <div className="pricing-list">{settings.map((rule) => <article className="pricing-card" key={rule.id}><div className="pricing-card-main"><strong>{rule.name}</strong><span>{rule.branch ? branchLabel(rule.branch) : 'Global'} · {rule.min_km} - {rule.max_km ?? 'unlimited'} km</span></div><span className={rule.is_formula ? 'status info' : 'status success'}>{rule.is_formula ? 'Formula' : 'Flat'}</span><em>{rule.is_formula ? `Rp ${(rule.per_km_rate ?? 0).toLocaleString('id-ID')}/km - ${rule.subtract_value ?? 0}` : `Rp ${(rule.price ?? 0).toLocaleString('id-ID')}`}</em>{permissions.can_manage_policy && <button className="mini-button reject" type="button" onClick={() => void destroy(rule)}>Delete</button>}</article>)}</div>
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
              <div><h2>{activeChat.customer || activeChat.driver || 'Chat'}</h2><p>{activeChat.order_code ?? activeChat.type} · Operator: {activeChat.operator ?? me.name}</p></div>
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

function ManualOrderPanel({ users, api, onChanged }: { users: User[]; api: ApiClient; onChanged: () => Promise<void> }) {
  const customers = users.filter((user) => user.role === 'customer')
  const submit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    const form = new FormData(event.currentTarget)
    await api('/admin/orders/manual', {
      method: 'POST',
      body: JSON.stringify({
        user_id: Number(form.get('user_id')),
        service_type: form.get('service_type'),
        pickup_address: form.get('pickup_address'),
        pickup_lat: Number(form.get('pickup_lat')),
        pickup_lng: Number(form.get('pickup_lng')),
        destination_address: form.get('destination_address'),
        destination_lat: Number(form.get('destination_lat')),
        destination_lng: Number(form.get('destination_lng')),
        price: Number(form.get('price')),
        service_charge: Number(form.get('service_charge') || 0),
        notes: form.get('notes'),
      }),
    })
    event.currentTarget.reset()
    await onChanged()
  }
  return <section className="panel manual-order-panel"><div className="section-head"><div><h2>Manual Order</h2><p>Buat order customer dari operator tanpa map. Koordinat tetap disimpan untuk matching driver.</p></div><span className="status info">{customers.length} customer</span></div><form className="manual-form" onSubmit={submit}><label>Customer<select name="user_id" required>{customers.map((user) => <option key={user.id} value={user.id}>{user.name}</option>)}</select></label><label>Service<input name="service_type" required defaultValue="ojek" /></label><label className="span-2">Pickup<input name="pickup_address" required placeholder="Alamat jemput" /></label><label className="span-2">Destination<input name="destination_address" required placeholder="Alamat tujuan" /></label><label>Pickup lat<input name="pickup_lat" required defaultValue="-6.2" /></label><label>Pickup lng<input name="pickup_lng" required defaultValue="106.8" /></label><label>Destination lat<input name="destination_lat" required defaultValue="-6.17" /></label><label>Destination lng<input name="destination_lng" required defaultValue="106.79" /></label><label>Price<input name="price" required defaultValue="20000" /></label><label>Service charge<input name="service_charge" defaultValue="0" /></label><label className="span-2">Catatan<textarea name="notes" placeholder="Catatan operator" /></label><button className="primary-button span-2" type="submit">Create manual order</button></form></section>
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
  return <section className="panel branches-panel"><div className="section-head"><div><h2>Branches</h2><p>Kelola cabang operasional, area, dan titik koordinat utama.</p></div>{canCreate && <button className="primary-button compact" onClick={() => setShowForm((value) => !value)} type="button"><Icon name="plus" />Add Cabang</button>}</div>{showForm && <form className="admin-inline-form branch-create-form" onSubmit={submit}><label>Nama cabang<input name="name" required placeholder="Situbondo" /></label><label>Area<input name="area" placeholder="Kota / wilayah" /></label><label>Latitude<input name="latitude" required type="number" step="0.00000001" placeholder="-7.706" /></label><label>Longitude<input name="longitude" required type="number" step="0.00000001" placeholder="114.009" /></label><label>Radius KM<input name="radius_km" required type="number" step="0.1" min="0.1" defaultValue="5" /></label><button className="primary-button" type="submit">Save Cabang</button></form>}<div className="branch-grid">{branches.map((branch) => <article className="branch-card" key={branch.id}><div className="branch-map"><span>{branch.name.slice(0, 2).toUpperCase()}</span></div><div className="branch-card-body"><strong>{branch.name}</strong><span className="branch-area-name">{branch.area || 'Area belum diisi'}</span><p>{branch.latitude}, {branch.longitude}</p><b>{branch.radius_km ?? 5} km radius · {branch.geofence_areas_count ?? 0} geofence areas</b></div></article>)}</div></section>
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

function UserFormModal({ permissions, branches, api, onClose, onCreated }: { permissions: Permissions; branches: Branch[]; api: ApiClient; onClose: () => void; onCreated: (password: string) => void }) {
  const [role, setRole] = useState<Role>(permissions.assignable_roles[0] ?? 'operator')
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
        } : {}),
      }),
    })
    onCreated(payload.temporary_password)
  }
  return <div className="modal-backdrop" role="presentation"><div className="modal" role="dialog" aria-modal="true"><div className="modal-header"><div><h2>Create user</h2><p>Assignable roles: {permissions.assignable_roles.map((item) => roleLabels[item]).join(', ')}</p></div><button type="button" className="icon-button" onClick={onClose}><Icon name="close" /></button></div><form className="user-form" onSubmit={submit}><fieldset><legend>Info User</legend><div className="form-grid"><label>Username<input name="username" required /></label><label>Name<input name="name" required /></label><label>Email<input name="email" type="email" required /></label><label>Phone<input name="phone" /></label></div></fieldset><fieldset><legend>Role & Branch</legend><div className="form-grid"><label>Role<select value={role} onChange={(event) => setRole(event.target.value as Role)}>{permissions.assignable_roles.map((item) => <option key={item} value={item}>{roleLabels[item]}</option>)}</select></label><label>Branch<select name="branch_id"><option value="">No branch</option>{branches.map((branch) => <option key={branch.id} value={branch.id}>{branchLabel(branch)}</option>)}</select></label><label className="toggle-row"><input name="is_active" type="checkbox" defaultChecked />Active</label>{role === 'driver' && <label>Bansos Driver<input name="driver_bansos_amount" type="number" min="0" placeholder="Kosong = otomatis area" /></label>}{role === 'driver' && <label className="toggle-row"><input name="driver_bpjs_jht_enabled" type="checkbox" defaultChecked />JHT BPJS</label>}</div></fieldset><div className="modal-actions"><button type="button" className="secondary-button" onClick={onClose}>Cancel</button><button className="primary-button" type="submit">Create real user</button></div></form></div></div>
}

type ApiClient = <T = unknown>(path: string, options?: RequestInit) => Promise<T>

function makeApi(token: string): ApiClient {
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

function dashboardHeadline(role: Role) {
  return {
    admin: 'Admin command center',
    gm: 'GM executive command center',
    hrd: 'HRD people, policy, and chat control',
    manager: 'Manager branch reporting cockpit',
    spv: 'SPV validation and monitoring desk',
    operator: 'Operator live order and chat desk',
    driver: 'Driver order workspace',
    customer: 'Customer order workspace',
  }[role]
}

function subtitleFor(data: Bootstrap) {
  return `${roleLabels[data.me.role]} dashboard`
}

function roleModules(role: Role) {
  const modules: Record<Role, { title: string; description: string; icon: string }[]> = {
    admin: [{ title: 'Full system access', description: 'All frontend modules and backend Filament.', icon: 'shield' }],
    gm: [{ title: 'Full executive access', description: 'All dashboard modules and backend Filament.', icon: 'shield' }],
    hrd: [{ title: 'Manage user', description: 'No Admin or GM CRUD.', icon: 'users' }, { title: 'Set policy', description: 'Tarif and operational rules.', icon: 'cash' }, { title: 'Monitor all chat', description: 'Driver and customer chat oversight.', icon: 'chat' }],
    manager: [{ title: 'Branch reports', description: 'Order and driver reports scoped to branch.', icon: 'chart' }, { title: 'Export reports', description: 'Transactions, orders, and drivers.', icon: 'receipt' }],
    spv: [{ title: 'Validate order', description: 'Order and transaction validation.', icon: 'receipt' }, { title: 'Realtime monitoring', description: 'Branch operations live.', icon: 'pin' }],
    operator: [{ title: 'Input manual order', description: 'Create real manual orders.', icon: 'plus' }, { title: 'Edit prices', description: 'Adjust incoming order price.', icon: 'cash' }, { title: 'Handle chat', description: 'Receive customer and driver chat.', icon: 'chat' }],
    driver: [{ title: 'Orders only', description: 'Driver order workspace.', icon: 'bag' }],
    customer: [{ title: 'Create order', description: 'Customer order access.', icon: 'bag' }],
  }
  return modules[role]
}

function titleFor(view: View) {
  return { dashboard: 'Admin Dashboard', orders: 'Order Operations', 'request-orders': 'Request Order', users: 'User Management', drivers: 'Driver Management', settings: 'System Settings', pricing: 'Pricing & Policy', branches: 'Branch Management', geofence: 'Geofence Areas', locations: 'Location Logs', reports: 'Reports', chats: 'Chat Monitor', 'manual-order': 'Manual Order' }[view]
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
  return `${order.code} ${order.customer ?? ''} ${order.driver ?? ''} ${order.service} ${order.branch ?? ''} ${order.status} ${order.source ?? ''} ${order.cancel_reason ?? ''}`
    .toLowerCase()
    .includes(searchQuery.toLowerCase())
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

function auditActionLabel(action: string) {
  return action
    .replaceAll('_', ' ')
    .replace(/\b\w/g, (letter) => letter.toUpperCase())
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
