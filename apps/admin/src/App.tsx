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
type View = 'dashboard' | 'orders' | 'request-orders' | 'users' | 'drivers' | 'settings' | 'pricing' | 'ring-pricing' | 'branches' | 'geofence' | 'locations' | 'reports' | 'chats' | 'internal-chat' | 'sticky-notes' | 'manual-order'
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
  address?: string | null
  profile_photo_url?: string | null
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
  registration_location?: UserLocationPoint | null
  current_location?: UserLocationPoint | null
  latest_gps?: UserGpsPoint | null
  location_changed?: boolean
  location_distance_meters?: number | null
  location_risk?: 'normal' | 'changed' | 'moved_far' | 'suspicious' | 'mock_location' | string
}
type UserLocationPoint = {
  lat: number
  lng: number
  address?: string | null
  accuracy?: number | null
  branch?: string | null
  status?: string | null
  updated_at?: string | null
  maps_url?: string | null
}
type UserGpsPoint = UserLocationPoint & {
  is_suspicious?: boolean
  is_mock_location?: boolean
  reason?: string | null
  created_at?: string | null
}
type DriverRow = User & {
  driver_id: number | null
  driver_status: 'active' | 'inactive' | 'suspended' | 'suspended_unpaid'
  deposit_status?: 'paid' | 'unpaid' | string | null
  deposit_total?: number
  deposit_paid_amount?: number
  deposit_remaining?: number
  deposit_paid_at?: string | null
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
  vehicle_types?: string[]
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
  driver_user_id?: number | null
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
  oper_handle?: OperHandle | null
  created_at: string | null
  updated_at?: string | null
}
type OperHandle = {
  id: number
  order_id: number
  order_code: string | null
  order_status?: string | null
  customer?: string | null
  driver?: string | null
  driver_phone?: string | null
  branch?: string | null
  branch_area?: string | null
  service?: string | null
  total?: number | null
  reason?: string | null
  status: string
  requested_by?: string | null
  operator_approved_at?: string | null
  spv_approved_at?: string | null
  created_at?: string | null
  updated_at?: string | null
}
type DriverCandidate = { id: number; name: string; phone?: string | null; vehicle_type?: string | null; vehicle_types?: string[]; vehicle_seat_rows?: number | null; is_ladies_driver?: boolean; branch?: string | null; branch_area?: string | null; rating_average?: number; is_favorite?: boolean }
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
  service_payload?: Record<string, unknown> | null
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

type Branch = { id: number; name: string; area: string | null; latitude: string; longitude: string; radius_km?: string | number | null; geofence_areas_count?: number; geofence_areas?: Array<{ id: number; name: string }> }
type ServiceRow = { id: number; name: string; code: string; outside_area_only?: boolean }
type PriceSetting = { id: number; name: string; branch_id: number | null; min_km: string; max_km: string | null; price: number | null; is_formula: boolean; per_km_rate: number | null; subtract_value: number | null; branch?: Branch | null }
type RingPricingRule = { id: number; branch_id: number | null; branch?: Pick<Branch, 'id' | 'name' | 'area'> | null; service_type?: string | null; name: string; pickup_area: string; destination_area: string; pickup_aliases?: string[]; destination_aliases?: string[]; ring: string; price: number; is_bidirectional: boolean; source: string; is_active: boolean; created_at?: string | null; updated_at?: string | null }
type RingPricingSuggestion = { id: number; branch_id: number | null; branch?: Pick<Branch, 'id' | 'name' | 'area'> | null; service_type?: string | null; pickup_area: string; destination_area: string; ring?: string | null; suggested_price: number; previous_price?: number | null; occurrence_count: number; sample_order_ids?: number[]; last_order_code?: string | null; last_edited_by?: string | null; status: string; created_at?: string | null; updated_at?: string | null }
type Geofence = { id: number; name: string; branch?: Branch | null; center_latitude: string; center_longitude: string; radius_meters: number; is_active: boolean }
type LocationLog = { id: number; user: string | null; branch: string | null; latitude: number; longitude: number; accuracy?: number | null; provider?: string | null; is_mock_location?: boolean; is_valid: boolean; is_suspicious: boolean; reason: string | null; maps_url?: string | null; created_at: string | null }
type Chat = { id: number; order_id?: number | null; order_code: string | null; type?: string; customer: string | null; driver: string | null; operator: string | null; branch?: string | null; status: string; sla_status?: string | null; latest_message?: string | null; last_message?: string | null; unread_count?: number; last_customer_message_at?: string | null; first_operator_response_at?: string | null; rating_requested_at?: string | null; closed_at?: string | null; updated_at: string | null }
type AdminChatMessage = { id: number; chat_id: number; sender_id: number | null; sender_type: string; sender_name?: string | null; message: string; image_url?: string | null; audio_url?: string | null; audio_duration?: number | null; file_url?: string | null; file_name?: string | null; file_mime?: string | null; file_size?: number | null; created_at?: string | null }
type ChatDetail = { chat: Chat; messages: AdminChatMessage[]; cancel_request?: { id: number; status: string; reason: string; image_url?: string | null } | null }
type InternalChatRoom = { id: number; name: string; type: 'global' | 'branch' | 'private' | string; branch_id?: number | null; branch?: string | null; branch_area?: string | null; participants_count?: number; participants?: Array<{ id: number; name: string; role: Role | string }>; last_message?: string | null; last_sender?: string | null; unread_count?: number; updated_at?: string | null }
type InternalChatAttachment = { source?: string | null; name?: string | null; mime?: string | null; size?: number | null; url?: string | null; path?: string | null }
type InternalChatMetadata = { attachment?: InternalChatAttachment | null; mentioned_user_ids?: number[]; order_ids?: number[]; order_codes?: string[] }
type InternalChatMessage = { id: number; room_id: number; sender_id: number | null; sender_name: string; sender_role?: Role | string | null; message: string; metadata?: InternalChatMetadata | null; created_at?: string | null }
type InternalChatDetail = { room: InternalChatRoom; messages: InternalChatMessage[] }
type InternalNoteStatus = 'open' | 'in_progress' | 'done' | 'archived'
type InternalNotePriority = 'low' | 'normal' | 'high' | 'urgent'
type InternalNoteUser = { id: number; name: string; role: Role | string }
type InternalNoteReply = { id: number; note_id: number; author: InternalNoteUser | null; body: string; created_at?: string | null }
type InternalNote = {
  id: number
  title: string
  body: string
  category: string
  priority: InternalNotePriority
  status: InternalNoteStatus
  author: InternalNoteUser | null
  assigned_to: InternalNoteUser | null
  branch: { id: number; name: string; area?: string | null } | null
  replies_count: number
  latest_reply?: InternalNoteReply | null
  last_activity_at?: string | null
  created_at?: string | null
  updated_at?: string | null
}
type InternalNotesResponse = {
  data: InternalNote[]
  summary: { open: number; in_progress: number; done: number; urgent: number; assigned_to_me: number }
  options?: { statuses: InternalNoteStatus[]; priorities: InternalNotePriority[]; categories: string[] }
}
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
  can_manage_ring_pricing?: boolean
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
  can_use_internal_notes?: boolean
  can_approve_cancel_order?: boolean
  can_reject_cancel_order?: boolean
  can_approve_oper_handle?: boolean
  can_assign_driver?: boolean
  allowed_views?: View[]
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
  oper_handles?: OperHandle[]
  branches: Branch[]
  services: ServiceRow[]
  price_settings: PriceSetting[]
  ring_pricing_rules?: RingPricingRule[]
  ring_pricing_suggestions?: RingPricingSuggestion[]
  geofences: Geofence[]
  location_logs: LocationLog[]
  chats: Chat[]
  audit_logs: AuditLog[]
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
const DEFAULT_ADMIN_NOTIFICATION_SOUND = '/notifadmin.mpeg'
const ADMIN_SOUND_DB = 'jojo-admin-settings'
const ADMIN_SOUND_STORE = 'notification-sound'
const ADMIN_SOUND_KEY = 'custom'

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
      { id: 'sticky-notes', label: 'Sticky Notes', icon: 'note' },
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
      { id: 'ring-pricing', label: 'Master Ring', icon: 'cash' },
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

  if (Array.isArray(permissions.allowed_views) && permissions.allowed_views.length > 0) {
    const allowed = allMenus.map((item) => item.id).filter((id) => permissions.allowed_views?.includes(id))
    return allowed.includes('dashboard') ? allowed : ['dashboard', ...allowed]
  }

  const views = new Set<View>(['dashboard'])
  if (permissions.can_monitor_live_order) {
    views.add('orders')
    views.add('request-orders')
  }
  if (permissions.can_assign_driver) views.add('drivers')
  if (permissions.can_manage_users) views.add('users')
  if (permissions.can_suspend_drivers || permissions.can_unsuspend_drivers) views.add('drivers')
  if (permissions.can_edit_order_price || permissions.can_manage_policy) views.add('pricing')
  if (permissions.can_manage_ring_pricing) views.add('ring-pricing')
  if (permissions.can_view_report) views.add('reports')
  if (permissions.can_monitor_live_chat) views.add('chats')
  if (permissions.can_use_internal_chat) views.add('internal-chat')
  if (permissions.can_use_internal_notes) views.add('sticky-notes')
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
  const [isProfileOpen, setProfileOpen] = useState(false)
  const [notificationSound, setNotificationSound] = useState(() => localStorage.getItem('admin_notification_sound') ?? 'default')
  const [chatDriverTargetId, setChatDriverTargetId] = useState<number | null>(null)
  const clearChatDriverTarget = useCallback(() => setChatDriverTargetId(null), [])
  const [openMenuGroups, setOpenMenuGroups] = useState<Record<string, boolean>>({
    overview: true,
    operations: true,
    management: true,
    area: false,
  })
  const [adminNotice, setAdminNotice] = useState('')
  const [lastSyncedAt, setLastSyncedAt] = useState<Date | null>(null)
  const isRefreshingRef = useRef(false)
  const isBrowserBackRef = useRef(false)
  const lastOperHandlePendingRef = useRef<number | null>(null)

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
      const payload = await api<Bootstrap>('/admin/bootstrap')
      const pendingOperHandles = (payload.oper_handles ?? []).filter((item) => item.status === 'pending').length

      if (silent && lastOperHandlePendingRef.current !== null && pendingOperHandles > lastOperHandlePendingRef.current) {
        setAdminNotice(`${pendingOperHandles - lastOperHandlePendingRef.current} pengajuan oper handle baru menunggu approval.`)
        window.setTimeout(() => setAdminNotice(''), 4200)
      }

      lastOperHandlePendingRef.current = pendingOperHandles
      setData(payload)
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

    const refreshWhenVisible = () => {
      if (document.visibilityState === 'visible') void load(true)
    }

    document.addEventListener('visibilitychange', refreshWhenVisible)

    return () => {
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
    if (!token || !data?.me || ['admin', 'gm'].includes(data.me.role)) return

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
  }, [api, data?.me, token])

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

  const allowedViews = data ? allowedViewsFor(data.me.role, data.permissions) : []
  const safeView = data ? (allowedViews.includes(view) ? view : (allowedViews[0] ?? 'dashboard')) : view
  const updateInfo = useBuildUpdate('admin')

  useEffect(() => {
    if (safeView === 'dashboard' && query !== '') {
      setQuery('')
    }
  }, [query, safeView])

  if (!token) {
    return (
      <>
        <LoginScreen onLogin={(nextToken) => {
          localStorage.setItem('admin_token', nextToken)
          localStorage.removeItem('token')
          setError('')
          setData(null)
          setToken(nextToken)
        }} />
        <AppUpdateNotice update={updateInfo} />
      </>
    )
  }

  if (!data) {
    return (
      <>
        <div className="loading-screen">{isLoading ? 'Memuat data admin...' : error || 'Data belum tersedia'}</div>
        <AppUpdateNotice update={updateInfo} />
      </>
    )
  }

  const visibleMenuGroups = menuGroups
    .map((group) => ({ ...group, items: group.items.filter((item) => allowedViews.includes(item.id)) }))
    .filter((group) => group.items.length > 0)
  const filteredUsers = data.users.filter((user) => {
    const text = `${user.username} ${user.name} ${user.email}`.toLowerCase()
    return text.includes(query.toLowerCase()) && (roleFilter === 'all' || user.role === roleFilter)
  })
  const pendingOperHandles = (data.oper_handles ?? []).filter((item) => item.status === 'pending')

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
          <div className="sidebar-profile-line">
            <div className="admin-avatar">
              {data.me.profile_photo_url ? <img src={assetUrl(data.me.profile_photo_url)} alt={data.me.name} /> : <span>{data.me.name.slice(0, 1).toUpperCase()}</span>}
            </div>
            <div>
              <span>Authenticated as</span>
              <strong>{data.me.name}</strong>
            </div>
          </div>
          <RoleBadge role={data.me.role} />
          <div className="sidebar-actions">
            <button className="sidebar-action-button" type="button" onClick={() => { setProfileOpen(true); setMobileNavOpen(false) }}><Icon name="settings" />Profile & Setting</button>
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
              {pendingOperHandles.length > 0 && (
                <button className="oper-handle-topbar-alert" type="button" onClick={() => { setView('orders'); setQuery('') }}>
                  <Icon name="shield" />
                  <span>{pendingOperHandles.length} oper handle</span>
                </button>
              )}
              <div className="search"><Icon name="search" /><input value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Cari data" /></div>
              <div className="auto-refresh-pill" title="Data admin tersinkron otomatis tiap 5 detik">
                <span />
                Auto refresh
                <small>{lastSyncedAt ? formatShortTime(lastSyncedAt.toISOString()) : 'sync'}</small>
              </div>
              <button className="theme-switch" type="button" onClick={toggleDarkMode} aria-label={darkMode ? 'Switch to light theme' : 'Switch to dark theme'} title={darkMode ? 'Light theme' : 'Dark theme'}>
                <span><Icon name={darkMode ? 'sun' : 'moon'} /></span>
              </button>
            </div>
          </header>

        {adminNotice && <div className="dispatch-toast oper-handle-toast">{adminNotice}</div>}
        {safeView === 'dashboard' && <Dashboard data={data} api={api} onChanged={refresh} onNavigate={setView} onOpenOrder={(code) => { setQuery(code); setView('orders') }} />}
        {safeView === 'orders' && <OrdersTable orders={data.orders} operHandles={data.oper_handles ?? []} auditLogs={data.audit_logs} searchQuery={query} permissions={data.permissions} api={api} onChanged={refresh} onOpenDriverChat={(driverUserId) => { setChatDriverTargetId(driverUserId); setView('chats') }} />}
        {safeView === 'request-orders' && <RequestOrdersPanel orders={data.orders} searchQuery={query} />}
        {safeView === 'users' && <UsersPanel users={filteredUsers} branches={data.branches} me={data.me} roleFilter={roleFilter} onRoleFilterChange={setRoleFilter} permissions={data.permissions} api={api} onChanged={refresh} />}
        {safeView === 'drivers' && <DriverManagementPanel drivers={data.drivers} services={data.services} permissions={data.permissions} api={api} onChanged={refresh} />}
        {safeView === 'settings' && <SystemSettingsPanel settings={data.system_settings} permissions={data.permissions} api={api} onChanged={refresh} />}
        {(safeView === 'pricing' || safeView === 'ring-pricing') && <PricingPanel mode={safeView === 'ring-pricing' ? 'ring' : 'all'} settings={data.price_settings} ringRules={data.ring_pricing_rules ?? []} ringSuggestions={data.ring_pricing_suggestions ?? []} branches={data.branches} services={data.services} permissions={data.permissions} api={api} onChanged={refresh} />}
        {safeView === 'reports' && <ReportsPanel data={data} api={api} token={token} />}
        {safeView === 'chats' && <AdminChatPanel initialChats={data.chats} api={api} me={data.me} token={token} permissions={data.permissions} notificationSound={notificationSound} targetDriverUserId={chatDriverTargetId} onTargetDriverHandled={clearChatDriverTarget} onOpenOrder={(code) => { setQuery(code); setView('orders') }} />}
        {safeView === 'internal-chat' && <InternalChatPanel api={api} me={data.me} branches={data.branches} users={data.users} orders={data.orders} onOpenOrder={(code) => { setQuery(code); setView('orders') }} />}
        {safeView === 'sticky-notes' && <StickyNotesPanel api={api} me={data.me} users={data.users} branches={data.branches} />}
        {safeView === 'manual-order' && <ManualOrderPanel me={data.me} branches={data.branches} api={api} onChanged={refresh} />}
        {safeView === 'branches' && <BranchesPanel branches={data.branches} me={data.me} api={api} onChanged={refresh} />}
        {safeView === 'geofence' && <GeofencePanel geofences={data.geofences} />}
        {safeView === 'locations' && <LocationLogsPanel logs={data.location_logs} branches={data.branches} canViewMaps={data.me.role === 'admin'} />}
      </main>

      <AppUpdateNotice update={updateInfo} />
      {isUserFormOpen && <UserFormModal permissions={data.permissions} branches={data.branches} services={data.services} api={api} onClose={() => setUserFormOpen(false)} onCreated={async (password) => { alert(`Password sementara: ${password}`); await refresh(); setUserFormOpen(false) }} />}
      {isProfileOpen && <AdminProfileModal me={data.me} api={api} darkMode={darkMode} notificationSound={notificationSound} onDarkModeChange={toggleDarkMode} onNotificationSoundChange={(value) => { localStorage.setItem('admin_notification_sound', value); setNotificationSound(value); if (value !== 'off') playAdminNotificationSound(value) }} onClose={() => setProfileOpen(false)} onSaved={refresh} />}
    </div>
  )
}

type BuildInfo = {
  app: string
  sha: string
  full_sha?: string
  built_at?: string
}

function useBuildUpdate(appName: string) {
  const [update, setUpdate] = useState<BuildInfo | null>(null)
  const currentVersionRef = useRef<BuildInfo | null>(null)

  useEffect(() => {
    let active = true

    const check = async () => {
      try {
        const response = await fetch(`/version.json?t=${Date.now()}`, { cache: 'no-store' })
        if (!response.ok) return
        const latest = await response.json() as BuildInfo
        if (!active || latest.app !== appName || !latest.sha) return
        if (!currentVersionRef.current) {
          currentVersionRef.current = latest
          return
        }
        if (latest.sha === currentVersionRef.current.sha) return

        setUpdate(latest)
      } catch {
        // Keep admin workflows alive even if version polling fails.
      }
    }

    void check()
    const interval = window.setInterval(check, 45000)

    return () => {
      active = false
      window.clearInterval(interval)
    }
  }, [appName])

  return update
}

function AppUpdateNotice({ update }: { update: BuildInfo | null }) {
  if (!update) return null

  return (
    <div className="app-update-notice">
      <div>
        <strong>Update admin tersedia</strong>
        <span>Versi terbaru telah tersedia.</span>
      </div>
      <button type="button" onClick={() => window.location.reload()}>Refresh</button>
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
  const pendingOperHandles = (data.oper_handles ?? []).filter((item) => item.status === 'pending').length
  const nightTariffActive = isNightTariffCurrentlyActive(data.system_settings)
  const topDriver = topDriverToday(data.drivers, data.orders)

  return (
    <div className="dashboard-grid">
      <StatsRow stats={[
        { label: 'Active Order Realtime', value: activeOrders, icon: 'bag', tone: 'amber', action: 'Orders', onClick: () => onNavigate('orders') },
        { label: 'Online Driver', value: onlineDrivers, icon: 'truck', tone: 'green', action: 'Drivers', onClick: () => onNavigate('drivers') },
        { label: 'Belum Diambil', value: unassignedOrders, icon: 'receipt', tone: 'violet', action: 'Cari driver', onClick: () => onNavigate('orders') },
        pendingOperHandles > 0
          ? { label: 'Oper Handle Pending', value: pendingOperHandles, icon: 'shield', tone: 'red', action: 'Approval', onClick: () => onNavigate('orders') }
          : { label: 'Chat Belum Dibalas', value: unansweredChats, icon: 'chat', tone: 'red', action: 'Buka chat', onClick: () => onNavigate('chats') },
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
              {candidates.map((driver) => <option key={driver.id} value={driver.id}>{driver.is_favorite ? 'Favorit - ' : ''}{driver.name} - {driverVehicleLabel(driver)}{driver.is_ladies_driver ? ' - Ladies' : ''} - rating {driver.rating_average ?? 0}</option>)}
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

function canEditOrderPrice(order: Order | null) {
  if (!order) return false

  return !['COMPLETED', 'CANCELLED', 'DONE', 'CANCELED'].includes(String(order.status).toUpperCase())
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
      <div className="table-wrap user-table-wrap"><table><thead><tr><th>User</th><th>Name</th><th>Role</th><th>Branch</th><th>Lokasi</th><th>Status</th><th>Actions</th></tr></thead><tbody>{users.map((user) => <tr key={user.id}><td><div className="user-identity-cell"><UserAvatar user={user} /><div><strong>{user.username}</strong><span>{user.email}</span></div></div></td><td>{user.name}</td><td><RoleBadge role={user.role} /></td><td>{userBranchLabel(user)}</td><td><UserLocationSummary user={user} canViewMaps={me.role === 'admin'} /></td><td><span className={user.is_suspended ? 'status danger' : user.is_active ? 'status success' : 'status muted'}>{user.is_suspended ? 'Suspended' : user.is_active ? 'Active' : 'Inactive'}</span></td><td><div className="row-actions">{canEditUser(user) && <button className="mini-button" type="button" onClick={() => setEditingUser(user)}>Edit</button>}{canEditUser(user) && <button className="mini-button" type="button" onClick={() => void resetPassword(user)}>Reset Pass</button>}{canEditUser(user) && user.role === 'customer' && <button className="mini-button" type="button" onClick={() => void resetToken(user)}>Reset Token</button>}{canEditUser(user) && <button className="mini-button reject" type="button" onClick={() => void destroy(user)}>Delete</button>}{!canEditUser(user) && <span className="status muted">Locked</span>}</div></td></tr>)}</tbody></table></div>
      {editingUser && <UserEditModal user={editingUser} branches={branches} permissions={permissions} api={api} onClose={() => setEditingUser(null)} onSaved={async () => { await onChanged(); setEditingUser(null) }} />}
    </section>
  )
}

function UserAvatar({ user }: { user: Pick<User, 'name' | 'username' | 'profile_photo_url'> }) {
  const [failed, setFailed] = useState(false)
  const image = user.profile_photo_url ? assetUrl(user.profile_photo_url) : ''
  const initial = (user.name || user.username || 'U').trim().slice(0, 1).toUpperCase()

  return (
    <span className="user-avatar">
      {image && !failed ? <img src={image} alt={user.name || user.username} onError={() => setFailed(true)} /> : initial}
    </span>
  )
}

function UserLocationSummary({ user, canViewMaps }: { user: User; canViewMaps: boolean }) {
  const registration = user.registration_location
  const latest = user.latest_gps ?? user.current_location
  const risk = user.location_risk ?? 'normal'

  if (!registration && !latest) {
    return <span className="status muted">No GPS</span>
  }

  return (
    <div className="user-location-summary">
      {canViewMaps && (
        <div className="user-location-links">
          {registration?.maps_url && <a href={registration.maps_url} target="_blank" rel="noreferrer">Daftar</a>}
          {latest?.maps_url && <a href={latest.maps_url} target="_blank" rel="noreferrer">GPS terbaru</a>}
        </div>
      )}
      <span className={`location-risk ${locationRiskTone(risk)}`}>{locationRiskLabel(risk)}</span>
      <small>{formatLocationDistance(user.location_distance_meters)}{latest?.branch ? ` - ${latest.branch}` : ''}</small>
      {user.latest_gps?.reason && <em>{user.latest_gps.reason}</em>}
    </div>
  )
}

function DriverManagementPanel({ drivers, services, permissions, api, onChanged }: { drivers: DriverRow[]; services: ServiceRow[]; permissions: Permissions; api: ApiClient; onChanged: () => Promise<void> }) {
  const [configDriver, setConfigDriver] = useState<DriverRow | null>(null)
  const [authDriver, setAuthDriver] = useState<DriverRow | null>(null)
  const [branchFilter, setBranchFilter] = useState('all')
  const [performancePeriod, setPerformancePeriod] = useState<'today' | 'month' | 'all'>('month')
  const [selectedDriverId, setSelectedDriverId] = useState<number | null>(null)
  const detailRef = useRef<HTMLElement | null>(null)
  const branchOptions = useMemo(() => {
    const unique = new Map<string, string>()
    drivers.forEach((driver) => unique.set(driverBranchKey(driver), driverBranchLabel(driver)))
    return [...unique.entries()].sort((first, second) => first[1].localeCompare(second[1]))
  }, [drivers])
  const filteredDrivers = useMemo(() => drivers.filter((driver) => branchFilter === 'all' || driverBranchKey(driver) === branchFilter), [branchFilter, drivers])
  const selectedDriver = useMemo(
    () => filteredDrivers.find((driver) => driver.id === selectedDriverId) ?? filteredDrivers[0] ?? null,
    [filteredDrivers, selectedDriverId],
  )

  useEffect(() => {
    if (filteredDrivers.length === 0) {
      if (selectedDriverId !== null) setSelectedDriverId(null)
      return
    }

    if (!filteredDrivers.some((driver) => driver.id === selectedDriverId)) {
      setSelectedDriverId(filteredDrivers[0].id)
    }
  }, [filteredDrivers, selectedDriverId])

  const selectDriver = (driver: DriverRow) => {
    setSelectedDriverId(driver.id)
    window.setTimeout(() => detailRef.current?.scrollIntoView({ behavior: 'smooth', block: 'nearest' }), 0)
  }

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

  const markDeposit = async (driver: DriverRow, status: 'paid' | 'unpaid', full = false) => {
    if (!driver.driver_id) return
    const label = status === 'paid' ? (full ? 'LUNAS' : 'BAYAR SETORAN') : 'UNPAID'
    const reason = status === 'unpaid' ? prompt(`Alasan setoran ${driver.name} dibuat unpaid`, 'Belum bayar setoran') : null
    if (status === 'unpaid' && !reason) return
    const amount = status === 'paid' && !full
      ? Number(prompt(`Nominal dibayar ${driver.name}\nSisa tagihan: Rp ${Number(driver.deposit_remaining ?? 0).toLocaleString('id-ID')}`, String(driver.deposit_remaining ?? 0)) ?? 0)
      : null
    const paymentAmount = amount ?? 0
    if (status === 'paid' && !full && (!Number.isFinite(paymentAmount) || paymentAmount <= 0)) return
    if (!confirm(`Tandai setoran ${driver.name} sebagai ${label}?`)) return

    try {
      const response = await api<{ message?: string }>(`/admin/drivers/${driver.driver_id}/deposit/${status}`, {
        method: 'POST',
        body: JSON.stringify(status === 'unpaid' ? { reason } : full ? { full: true } : { full: false, amount: paymentAmount }),
      })
      alert(response.message ?? `Setoran ${driver.name} berhasil diperbarui.`)
      await onChanged()
    } catch (error) {
      alert(error instanceof Error ? error.message : `Setoran ${driver.name} gagal diperbarui.`)
    }
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
      <div className="driver-management-layout">
        <div className="driver-table-shell">
          <div className="driver-table-hint">Klik baris driver untuk membuka detail dan aksi.</div>
          <table className="driver-table">
            <thead><tr><th>Driver</th><th>Phone</th><th>Kendaraan</th><th>Layanan</th><th>Status</th><th>Setoran</th><th>Oper</th></tr></thead>
            <tbody>
              {filteredDrivers.map((driver) => (
                <tr
                  key={driver.id}
                  className={selectedDriver?.id === driver.id ? 'selected-row' : ''}
                  onClick={() => selectDriver(driver)}
                  onKeyDown={(event) => {
                    if (event.key === 'Enter' || event.key === ' ') {
                      event.preventDefault()
                      selectDriver(driver)
                    }
                  }}
                  tabIndex={0}
                >
                  <td><div className="user-identity-cell driver-identity-cell"><UserAvatar user={driver} /><div><strong>{driver.name}</strong><span>{driver.username}</span><span>{driver.google_email ?? driver.email}</span></div></div></td>
                  <td><span className="driver-phone">{driver.phone || '-'}</span></td>
                  <td><span className="status info">{driverVehicleLabel(driver)}</span>{driver.is_ladies_driver && <span className="status ladies-status">Ladies</span>}</td>
                  <td><span className="driver-service-list">{driver.allowed_service_types?.length ? driver.allowed_service_types.join(', ') : 'Semua layanan'}</span></td>
                  <td><span className={driver.driver_status === 'active' ? 'status success' : driver.driver_status === 'suspended_unpaid' ? 'status warning' : 'status danger'}>{driver.driver_status.replace('_', ' ')}</span></td>
                  <td>
                    <span className={driver.deposit_status === 'paid' ? 'status success' : 'status warning'}>{driver.deposit_status === 'paid' && Number(driver.deposit_remaining ?? 0) > 0 ? 'paid parsial' : driver.deposit_status ?? 'sync'}</span>
                    <span className="driver-phone">Sisa Rp {Number(driver.deposit_remaining ?? 0).toLocaleString('id-ID')}</span>
                  </td>
                  <td><strong>{driver.oper_handle_count}</strong></td>
                </tr>
              ))}
              {filteredDrivers.length === 0 && (
                <tr>
                  <td colSpan={7}>
                    <EmptyPanel title="Belum ada driver" copy="Driver yang terlihat sesuai filter cabang akan muncul di sini." />
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
        <section className="driver-detail-panel" ref={detailRef}>
          {!selectedDriver && <EmptyPanel title="Pilih driver" copy="Detail driver dan tombol aksi akan tampil di sini." />}
          {selectedDriver && (
            <>
              <div className="driver-detail-hero">
                <UserAvatar user={selectedDriver} />
                <div>
                  <span>Driver terpilih</span>
                  <strong>{selectedDriver.name}</strong>
                  <small>{selectedDriver.username} - {driverBranchLabel(selectedDriver)}</small>
                </div>
              </div>
              <div className="driver-detail-statuses">
                <span className={selectedDriver.driver_status === 'active' ? 'status success' : selectedDriver.driver_status === 'suspended_unpaid' ? 'status warning' : 'status danger'}>{selectedDriver.driver_status.replace('_', ' ')}</span>
                <span className={selectedDriver.deposit_status === 'paid' ? 'status success' : 'status warning'}>{selectedDriver.deposit_status ?? 'sync'}</span>
                <span className="status info">{driverVehicleLabel(selectedDriver)}</span>
                {selectedDriver.is_ladies_driver && <span className="status ladies-status">Ladies</span>}
              </div>
              <div className="driver-detail-grid">
                <div><span>Telepon</span><strong>{selectedDriver.phone || '-'}</strong></div>
                <div><span>Email</span><strong>{selectedDriver.google_email ?? selectedDriver.email ?? '-'}</strong></div>
                <div><span>Setoran</span><strong>Rp {Number(selectedDriver.deposit_remaining ?? 0).toLocaleString('id-ID')}</strong></div>
                <div><span>Oper handle</span><strong>{selectedDriver.oper_handle_count}</strong></div>
                <div><span>Suspend until</span><strong>{selectedDriver.suspended_until || '-'}</strong></div>
                <div><span>Layanan</span><strong>{selectedDriver.allowed_service_types?.length ? selectedDriver.allowed_service_types.join(', ') : 'Semua layanan'}</strong></div>
              </div>
              <div className="driver-detail-history">
                <div className="section-title"><h2>History suspend</h2><span>{selectedDriver.suspensions.length}</span></div>
                <div className="driver-history-list">
                  {selectedDriver.suspensions.slice(0, 5).map((item) => (
                    <article key={item.id}>
                      <strong>{item.duration} jam</strong>
                      <span>{item.reason}</span>
                    </article>
                  ))}
                  {selectedDriver.suspensions.length === 0 && <p>Belum ada history suspend.</p>}
                </div>
              </div>
              <div className="driver-detail-actions">
                {permissions.can_suspend_drivers && (
                  <div className="driver-action-group danger">
                    <span>Suspend</span>
                    <div>
                      <button className="mini-button reject" type="button" disabled={!selectedDriver.driver_id} onClick={() => void suspend(selectedDriver, 1, 'suspended')}>1h</button>
                      <button className="mini-button reject" type="button" disabled={!selectedDriver.driver_id} onClick={() => void suspend(selectedDriver, 12, 'suspended')}>12h</button>
                      <button className="mini-button reject" type="button" disabled={!selectedDriver.driver_id || selectedDriver.deposit_status === 'unpaid'} onClick={() => void markDeposit(selectedDriver, 'unpaid')}>Unpaid</button>
                    </div>
                  </div>
                )}
                {permissions.can_suspend_drivers && (
                  <div className="driver-action-group">
                    <span>Setoran</span>
                    <div>
                      <button className="mini-button" type="button" disabled={!selectedDriver.driver_id || Number(selectedDriver.deposit_remaining ?? 0) <= 0} onClick={() => void markDeposit(selectedDriver, 'paid')}>Bayar</button>
                      <button className="mini-button approve" type="button" disabled={!selectedDriver.driver_id || Number(selectedDriver.deposit_remaining ?? 0) <= 0} onClick={() => void markDeposit(selectedDriver, 'paid', true)}>Lunas</button>
                    </div>
                  </div>
                )}
                <div className="driver-action-group">
                  <span>Akun</span>
                  <div>
                    {permissions.can_suspend_drivers && <button className="mini-button" type="button" disabled={!selectedDriver.driver_id} onClick={() => setConfigDriver(selectedDriver)}>Config</button>}
                    {permissions.can_suspend_drivers && <button className="mini-button" type="button" disabled={!selectedDriver.driver_id} onClick={() => void resetToken(selectedDriver)}>Reset Token</button>}
                    {permissions.can_manage_driver_auth && <button className="mini-button" type="button" disabled={!selectedDriver.driver_id} onClick={() => setAuthDriver(selectedDriver)}>Google Auth</button>}
                    {permissions.can_unsuspend_drivers && selectedDriver.driver_status !== 'active' && <button className="mini-button approve" type="button" onClick={() => void release(selectedDriver)}>Release</button>}
                  </div>
                </div>
              </div>
            </>
          )}
        </section>
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
  const [vehicleTypes, setVehicleTypes] = useState<string[]>(normalizedDriverVehicleTypes(driver))
  const [vehicleSeatRows, setVehicleSeatRows] = useState<2 | 3>((driver.vehicle_seat_rows === 3 ? 3 : 2))
  const [isLadiesDriver, setIsLadiesDriver] = useState(Boolean(driver.is_ladies_driver))
  const [allowed, setAllowed] = useState<string[]>(driver.allowed_service_types ?? [])
  const [saving, setSaving] = useState(false)

  const toggleVehicleType = (type: 'motor' | 'mobil') => {
    setVehicleTypes((current) => {
      const next = current.includes(type) ? current.filter((item) => item !== type) : [...current, type]

      return next.length > 0 ? next : [type]
    })
  }

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
          vehicle_types: vehicleTypes,
          vehicle_type: vehicleTypes[0] ?? 'motor',
          vehicle_seat_rows: vehicleTypes.includes('mobil') ? vehicleSeatRows : null,
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
            <div className="service-check-grid">
              <label className="toggle-row"><input type="checkbox" checked={vehicleTypes.includes('motor')} onChange={() => toggleVehicleType('motor')} />Driver sepeda motor</label>
              <label className="toggle-row"><input type="checkbox" checked={vehicleTypes.includes('mobil')} onChange={() => toggleVehicleType('mobil')} />Driver mobil</label>
            </div>
            <div className="notice">Centang keduanya jika driver bisa menerima order motor dan mobil.</div>
            {vehicleTypes.includes('mobil') && <div className="form-grid"><label>Kapasitas Mobil<select value={vehicleSeatRows} onChange={(event) => setVehicleSeatRows(Number(event.target.value) as 2 | 3)}><option value={2}>2 baris - citycar/default</option><option value={3}>3 baris - MPV/keluarga</option></select></label></div>}
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

function OrdersTable({ orders, operHandles, auditLogs, searchQuery, permissions, api, onChanged, onOpenDriverChat }: { orders: Order[]; operHandles: OperHandle[]; auditLogs: AuditLog[]; searchQuery: string; permissions: Permissions; api: ApiClient; onChanged: () => Promise<void>; onOpenDriverChat: (driverUserId: number) => void }) {
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
      {permissions.can_edit_order_price && <div className="notice">Edit harga hanya aktif saat order berjalan, lalu dikirim realtime ke customer dan driver.</div>}
      <OperHandleQueue
        operHandles={operHandles}
        api={api}
        permissions={permissions}
        onChanged={onChanged}
        onSelectOrder={(orderId) => setSelectedOrderId(orderId)}
      />
      <div className="order-operations-layout">
        <div className="table-wrap order-table-wrap">
          <table>
            <thead><tr><th>Order</th><th>Customer</th><th>Driver</th><th>Service</th><th>Branch</th><th>Total</th><th>Status</th>{permissions.can_edit_order_price && <th>Action</th>}</tr></thead>
            <tbody>
              {filteredOrders.map((order) => (
                <tr
                  className={selectedOrder?.id === order.id ? 'selected-row' : ''}
                  key={order.id}
                  onClick={() => setSelectedOrderId(order.id)}
                  tabIndex={0}
                  onKeyDown={(event) => {
                    if (event.key === 'Enter' || event.key === ' ') {
                      event.preventDefault()
                      setSelectedOrderId(order.id)
                    }
                  }}
                >
                  <td><strong>{order.code}</strong><span>{formatShortDateTime(order.created_at)}</span></td>
                  <td>{order.customer || '-'}</td>
                  <td>{order.driver_user_id && order.driver ? <button className="inline-action-link" type="button" onClick={(event) => { event.stopPropagation(); onOpenDriverChat(order.driver_user_id!) }}>{order.driver}</button> : order.driver || '-'}</td>
                  <td>{order.service}</td>
                  <td>{displayBranchValue(order.branch, order.branch_area)}</td>
                  <td><strong>Rp {order.total.toLocaleString('id-ID')}</strong><span>Tarif Rp {order.price.toLocaleString('id-ID')} · Fee Rp {order.service_charge.toLocaleString('id-ID')}</span></td>
                  <td><StatusBadge status={order.status} /></td>
                  {permissions.can_edit_order_price && (
                    <td>
                      {canEditOrderPrice(order)
                        ? <button className="mini-button" type="button" onClick={(event) => { event.stopPropagation(); setEditingOrder(order) }}>Edit harga</button>
                        : <span className="muted-action">Terkunci</span>}
                    </td>
                  )}
                </tr>
              ))}
            </tbody>
          </table>
          {filteredOrders.length === 0 && <EmptyPanel title="Order tidak ditemukan" copy="Coba cek kode order atau hapus filter pencarian." />}
        </div>
        <OrderDetailPanel order={selectedOrder} permissions={permissions} onOpenDriverChat={onOpenDriverChat} onEditPrice={permissions.can_edit_order_price && selectedOrder && canEditOrderPrice(selectedOrder) ? () => setEditingOrder(selectedOrder) : undefined} />
      </div>
      {editingOrder && <OrderPriceModal order={editingOrder} api={api} onClose={() => setEditingOrder(null)} onSaved={async () => { await onChanged(); setEditingOrder(null) }} />}
    </section>
  )
}

function OperHandleQueue({ operHandles, api, permissions, onChanged, onSelectOrder }: { operHandles: OperHandle[]; api: ApiClient; permissions: Permissions; onChanged: () => Promise<void>; onSelectOrder: (orderId: number) => void }) {
  const [savingId, setSavingId] = useState<number | null>(null)
  const [message, setMessage] = useState('')
  const pending = operHandles.filter((item) => item.status === 'pending')
  const recent = pending.length > 0 ? pending : operHandles.slice(0, 3)

  const approve = async (item: OperHandle) => {
    setSavingId(item.id)
    setMessage('')
    try {
      const payload = await api<{ message?: string }>(`/admin/oper-handles/${item.id}/approve`, { method: 'POST' })
      setMessage(payload.message ?? 'Approval oper handle tersimpan.')
      await onChanged()
    } catch (error) {
      setMessage(error instanceof Error ? error.message : 'Approval oper handle gagal.')
    } finally {
      setSavingId(null)
      window.setTimeout(() => setMessage(''), 3600)
    }
  }

  if (operHandles.length === 0) return null

  return (
    <section className={pending.length > 0 ? 'oper-handle-admin-panel pending' : 'oper-handle-admin-panel'}>
      <div className="oper-handle-admin-head">
        <div>
          <span>Oper handle</span>
          <strong>{pending.length > 0 ? `${pending.length} menunggu approval` : 'Tidak ada pending'}</strong>
        </div>
        <span className={pending.length > 0 ? 'status danger' : 'status success'}>{pending.length > 0 ? 'Action needed' : 'Clear'}</span>
      </div>
      <div className="oper-handle-admin-list">
        {recent.map((item) => (
          <article className="oper-handle-admin-card" key={item.id}>
            <button className="order-code-link inline" type="button" onClick={() => onSelectOrder(item.order_id)}>{item.order_code ?? `#${item.order_id}`}</button>
            <div className="oper-handle-admin-copy">
              <strong>{item.driver || 'Driver'} mengajukan oper handle</strong>
              <span>{displayBranchValue(item.branch, item.branch_area)} · {item.service || '-'} · {formatShortDateTime(item.created_at)}</span>
              <p>{item.reason || 'Tidak ada alasan tertulis.'}</p>
            </div>
            <div className="oper-handle-approval-steps">
              <span className={item.operator_approved_at ? 'status success' : 'status warning'}>Operator {item.operator_approved_at ? 'OK' : 'pending'}</span>
              <span className={item.spv_approved_at ? 'status success' : 'status warning'}>SPV {item.spv_approved_at ? 'OK' : 'pending'}</span>
            </div>
            {permissions.can_approve_oper_handle && item.status === 'pending' && (
              <button className="mini-button approve" type="button" disabled={savingId === item.id} onClick={() => void approve(item)}>
                {savingId === item.id ? 'Menyimpan...' : 'Approve'}
              </button>
            )}
          </article>
        ))}
      </div>
      {message && <div className={message.toLowerCase().includes('gagal') || message.includes('HTTP') ? 'notice danger' : 'notice success'}>{message}</div>}
    </section>
  )
}

function OrderDetailPanel({ order, permissions, onEditPrice, onOpenDriverChat }: { order: Order | null; permissions: Permissions; onEditPrice?: () => void; onOpenDriverChat?: (driverUserId: number) => void }) {
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
        <DetailItem label="Driver" value={order.driver_user_id && order.driver && onOpenDriverChat ? <button className="inline-action-link detail-link" type="button" onClick={() => onOpenDriverChat(order.driver_user_id!)}>{order.driver}</button> : order.driver || 'Belum diambil'} />
        <DetailItem label="Layanan" value={`${order.service_code ? `${order.service_code} · ` : ''}${order.service}`} />
        <DetailItem label="Cabang / Area" value={displayBranchValue(order.branch, order.branch_area)} />
        <DetailItem label="Pembayaran" value={payment} />
        <DetailItem label="Kendaraan" value={vehicleLabel(order.preferred_vehicle_type)} />
        {order.preferred_vehicle_type === 'mobil' && <DetailItem label="Seat Mobil" value={`${order.required_vehicle_seat_rows ?? 2} baris`} />}
        <DetailItem label="Preferensi" value={driverPreferenceLabel(order.driver_preference)} />
        <DetailItem label="SLA" value={`${order.sla_status ?? 'normal'} · ${formatWaitingTime(order.waiting_seconds)}`} />
        <DetailItem label="Source" value={order.source || 'app'} />
      </div>
      <div className="order-route-card">
        <div><span>Jemput / Pembelian</span><p>{order.pickup_address || '-'}</p></div>
        <div><span>Tujuan / Antar</span><p>{order.destination_address || '-'}</p></div>
      </div>
      {order.oper_handle && (
        <div className={order.oper_handle.status === 'pending' ? 'order-oper-handle-card pending' : 'order-oper-handle-card'}>
          <div>
            <span>Oper handle</span>
            <strong>{operHandleStatusLabel(order.oper_handle)}</strong>
          </div>
          <p>{order.oper_handle.reason || 'Tidak ada alasan tertulis.'}</p>
          <small>Driver: {order.oper_handle.driver || order.driver || '-'} · Update {formatShortDateTime(order.oper_handle.updated_at)}</small>
        </div>
      )}
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
  const [error, setError] = useState('')
  const canEdit = canEditOrderPrice(order)
  const total = Math.max(0, price) + Math.max(0, serviceCharge) + Math.max(0, order.extra_charge)
  const submit = async (event: FormEvent) => {
    event.preventDefault()
    if (!canEdit) {
      setError('Harga hanya bisa diedit saat order masih berjalan.')
      return
    }
    setSaving(true)
    setError('')
    try {
      await api(`/admin/orders/${order.id}/price`, {
        method: 'PATCH',
        body: JSON.stringify({ price: Math.max(0, price), service_charge: Math.max(0, serviceCharge) }),
      })
      await onSaved()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Edit harga gagal.')
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="modal-backdrop" role="presentation">
      <div className="modal price-edit-modal" role="dialog" aria-modal="true">
        <div className="modal-header"><div><h2>Edit harga order</h2><p>{order.code} Â· {order.customer || 'Customer'}</p></div><button type="button" className="icon-button" onClick={onClose}><Icon name="close" /></button></div>
        <form className="user-form" onSubmit={submit}>
          {!canEdit && <div className="notice warning">Order sudah selesai atau batal. Harga tidak dapat diedit.</div>}
          {error && <div className="notice danger">{error}</div>}
          <fieldset>
            <legend>Harga</legend>
            <div className="form-grid">
              <label>Tarif dasar<input type="number" min={0} step={1000} value={price} onChange={(event) => setPrice(Number(event.target.value))} disabled={!canEdit} required /></label>
              <label>Service fee<input type="number" min={0} step={1000} value={serviceCharge} onChange={(event) => setServiceCharge(Number(event.target.value))} disabled={!canEdit} required /></label>
            </div>
          </fieldset>
          <div className="price-preview"><span>Total baru</span><strong>Rp {total.toLocaleString('id-ID')}</strong><small>Termasuk tambahan Rp {order.extra_charge.toLocaleString('id-ID')}. Dikirim realtime ke customer dan driver setelah disimpan.</small></div>
          <div className="modal-actions"><button type="button" className="secondary-button" onClick={onClose}>Cancel</button><button className="primary-button" type="submit" disabled={saving || !canEdit}>{saving ? 'Mengirim...' : 'Simpan & broadcast'}</button></div>
        </form>
      </div>
    </div>
  )
}

function PricingPanel({ mode = 'all', settings, ringRules, ringSuggestions, branches, services, permissions, api, onChanged }: { mode?: 'all' | 'ring'; settings: PriceSetting[]; ringRules: RingPricingRule[]; ringSuggestions: RingPricingSuggestion[]; branches: Branch[]; services: ServiceRow[]; permissions: Permissions; api: ApiClient; onChanged: () => Promise<void> }) {
  const [showForm, setShowForm] = useState(false)
  const [showRingForm, setShowRingForm] = useState(false)
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
  const createRing = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    const form = new FormData(event.currentTarget)
    await api('/admin/ring-pricing-rules', {
      method: 'POST',
      body: JSON.stringify({
        name: form.get('name'),
        branch_id: Number(form.get('branch_id')) || null,
        service_type: form.get('service_type') || null,
        pickup_area: form.get('pickup_area'),
        destination_area: form.get('destination_area'),
        pickup_aliases: aliasList(form.get('pickup_aliases')),
        destination_aliases: aliasList(form.get('destination_aliases')),
        ring: form.get('ring'),
        price: Number(form.get('price') || 0),
        is_bidirectional: form.get('is_bidirectional') === 'on',
        is_active: form.get('is_active') === 'on',
      }),
    })
    event.currentTarget.reset()
    setShowRingForm(false)
    await onChanged()
  }
  const destroyRing = async (rule: RingPricingRule) => {
    if (!confirm(`Delete master ring ${rule.name}?`)) return
    await api(`/admin/ring-pricing-rules/${rule.id}`, { method: 'DELETE' })
    await onChanged()
  }
  const approveSuggestion = async (suggestion: RingPricingSuggestion) => {
    await api(`/admin/ring-pricing-suggestions/${suggestion.id}/approve`, { method: 'POST' })
    await onChanged()
  }
  const rejectSuggestion = async (suggestion: RingPricingSuggestion) => {
    await api(`/admin/ring-pricing-suggestions/${suggestion.id}/reject`, { method: 'POST' })
    await onChanged()
  }
  const formulaCount = settings.filter((rule) => rule.is_formula).length
  const canManageRing = Boolean(permissions.can_manage_ring_pricing)
  const activeRingCount = ringRules.filter((rule) => rule.is_active).length
  const learnedRingCount = ringRules.filter((rule) => rule.source === 'learned').length
  return (
    <section className={`panel pricing-panel ${mode === 'ring' ? 'master-ring-panel' : ''}`}>
      <div className="section-head master-ring-head">
        <div><h2>{mode === 'ring' ? 'Master Ring Pricing' : 'Pricing & Policy'}</h2><p>Terhubung ke pricing order customer, manual order, dan AI parser melalui payload pickup/tujuan.</p></div>
        <div className="section-actions">
          {canManageRing && <button className="secondary-button compact" type="button" onClick={() => setShowRingForm((value) => !value)}><Icon name="plus" />{showRingForm ? 'Tutup Form' : 'Master Ring'}</button>}
          {mode === 'all' && permissions.can_manage_policy && <button className="primary-button compact" type="button" onClick={() => setShowForm((value) => !value)}><Icon name="plus" />Policy</button>}
        </div>
      </div>
      {mode === 'ring' && (
        <div className="master-ring-summary">
          <article><span>Total route</span><strong>{ringRules.length}</strong><small>{activeRingCount} aktif</small></article>
          <article><span>Suggestion</span><strong>{ringSuggestions.length}</strong><small>Dari koreksi harga</small></article>
          <article><span>Learned</span><strong>{learnedRingCount}</strong><small>Sudah jadi rule</small></article>
        </div>
      )}
      {showRingForm && canManageRing && (
        <form className="admin-inline-form pricing-create-form ring-create-form" onSubmit={createRing}>
          <div className="ring-form-title"><strong>Tambah Master Ring</strong><span>Simpan akan menutup form dan kembali ke list.</span></div>
          <label>Nama master<input name="name" required placeholder="Asembagus - Jangkar Ring 1" /></label>
          <label>Cabang<select name="branch_id"><option value="">Global</option>{branches.map((branch) => <option key={branch.id} value={branch.id}>{branchLabel(branch)}</option>)}</select></label>
          <label>Layanan<select name="service_type"><option value="">Semua layanan</option>{services.map((service) => <option key={service.id} value={service.code}>{service.name}</option>)}</select></label>
          <label>Ring<select name="ring" defaultValue="ring_1"><option value="ring_1">Ring 1</option><option value="ring_2">Ring 2</option><option value="ring_3">Ring 3</option></select></label>
          <label>Asal area<input name="pickup_area" required placeholder="Pasar Kampung Asembagus" /></label>
          <label>Tujuan area<input name="destination_area" required placeholder="Pelabuhan Jangkar" /></label>
          <label>Alias asal<input name="pickup_aliases" placeholder="pasar asembagus, kampung asembagus" /></label>
          <label>Alias tujuan<input name="destination_aliases" placeholder="p jangkar, pelabuhan jangkar" /></label>
          <label>Harga jasa<input name="price" type="number" min="0" step="1000" defaultValue="6000" required /></label>
          <label className="toggle-row inline-toggle"><input name="is_bidirectional" type="checkbox" defaultChecked />Dua arah</label>
          <label className="toggle-row inline-toggle"><input name="is_active" type="checkbox" defaultChecked />Aktif</label>
          <div className="ring-form-actions"><button className="secondary-button" type="button" onClick={() => setShowRingForm(false)}>Batal</button><button className="primary-button" type="submit">Simpan Master Ring</button></div>
        </form>
      )}
      {mode === 'all' && showForm && (
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
      <div className="pricing-subsection">
        <PanelHeader title="Master Ring Route" action={`${ringRules.length} rules`} />
        <div className="pricing-list ring-pricing-list">{ringRules.map((rule) => <article className="pricing-card ring-card" key={rule.id}><div className="pricing-card-main"><div className="ring-card-title"><strong>{rule.name}</strong><span className={rule.is_active ? 'status success' : 'status muted'}>{rule.is_active ? 'Aktif' : 'Nonaktif'}</span></div><span>{rule.branch ? branchLabel(rule.branch as Branch) : 'Global'} · {rule.service_type ?? 'semua layanan'} · {ringLabel(rule.ring)} · {rule.source}</span><small>{rule.pickup_area} → {rule.destination_area}{rule.is_bidirectional ? ' · dua arah' : ''}</small>{((rule.pickup_aliases?.length ?? 0) > 0 || (rule.destination_aliases?.length ?? 0) > 0) && <small className="ring-aliases">Alias: {[...(rule.pickup_aliases ?? []), ...(rule.destination_aliases ?? [])].slice(0, 5).join(', ')}</small>}</div><em>Rp {rule.price.toLocaleString('id-ID')}</em>{canManageRing && <button className="mini-button reject" type="button" onClick={() => void destroyRing(rule)}>Delete</button>}</article>)}</div>
        {ringRules.length === 0 && <EmptyPanel title="Master ring kosong" copy="Tambahkan route ring resmi agar harga tidak hanya mengandalkan jarak maps." />}
      </div>
      {canManageRing && ringSuggestions.length > 0 && (
        <div className="pricing-subsection">
          <PanelHeader title="Suggestion dari edit harga" action={`${ringSuggestions.length} pending`} />
          <div className="pricing-list ring-pricing-list">{ringSuggestions.map((suggestion) => <article className="pricing-card ring-card suggestion" key={suggestion.id}><div className="pricing-card-main"><div className="ring-card-title"><strong>{suggestion.pickup_area} → {suggestion.destination_area}</strong><span className="status warning">Learn</span></div><span>{suggestion.branch ? branchLabel(suggestion.branch as Branch) : 'Global'} · {suggestion.service_type ?? 'semua layanan'} · {ringLabel(suggestion.ring ?? '-')}</span><small>{suggestion.occurrence_count}x koreksi · terakhir {suggestion.last_order_code ?? '-'} oleh {suggestion.last_edited_by ?? '-'}</small></div><em>Rp {suggestion.suggested_price.toLocaleString('id-ID')}</em><div className="ring-card-actions"><button className="mini-button" type="button" onClick={() => void approveSuggestion(suggestion)}>Approve</button><button className="mini-button reject" type="button" onClick={() => void rejectSuggestion(suggestion)}>Reject</button></div></article>)}</div>
        </div>
      )}
      {mode === 'all' && <div className="pricing-subsection">
        <PanelHeader title="Tarif Jarak" action={`${formulaCount} formula`} />
      <div className="pricing-list">{settings.map((rule) => <article className="pricing-card" key={rule.id}><div className="pricing-card-main"><strong>{rule.name}</strong><span>{rule.branch ? branchLabel(rule.branch) : 'Global'} Â· {rule.min_km} - {rule.max_km ?? 'unlimited'} km</span></div><span className={rule.is_formula ? 'status info' : 'status success'}>{rule.is_formula ? 'Formula' : 'Flat'}</span><em>{rule.is_formula ? `Rp ${(rule.per_km_rate ?? 0).toLocaleString('id-ID')}/km - ${rule.subtract_value ?? 0}` : `Rp ${(rule.price ?? 0).toLocaleString('id-ID')}`}</em>{permissions.can_manage_policy && <button className="mini-button reject" type="button" onClick={() => void destroy(rule)}>Delete</button>}</article>)}</div>
      </div>}
    </section>
  )
}

function aliasList(value: FormDataEntryValue | null) {
  return String(value ?? '').split(',').map((item) => item.trim()).filter(Boolean)
}

function ringLabel(value: string) {
  return value.replace(/_/g, ' ').replace(/\bring\b/i, 'Ring').replace(/\b(\d)\b/, '$1')
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

function AdminProfileModal({
  me,
  api,
  darkMode,
  notificationSound,
  onDarkModeChange,
  onNotificationSoundChange,
  onClose,
  onSaved,
}: {
  me: User
  api: ApiClient
  darkMode: boolean
  notificationSound: string
  onDarkModeChange: () => void
  onNotificationSoundChange: (value: string) => void
  onClose: () => void
  onSaved: () => Promise<void>
}) {
  const [name, setName] = useState(me.name)
  const [phone, setPhone] = useState(me.phone ?? '')
  const [address, setAddress] = useState(me.address ?? '')
  const [photo, setPhoto] = useState<File | null>(null)
  const [photoPreview, setPhotoPreview] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const [hasCustomSound, setHasCustomSound] = useState(false)

  useEffect(() => {
    void hasCustomAdminNotificationSound().then(setHasCustomSound)
  }, [])

  const submit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    setSaving(true)
    setError('')

    try {
      const body = new FormData()
      body.append('name', name.trim())
      body.append('phone', phone.trim())
      body.append('address', address.trim())
      if (photo) body.append('profile_photo', photo)

      await api('/user/profile', { method: 'POST', body })
      if (photoPreview) URL.revokeObjectURL(photoPreview)
      setPhoto(null)
      setPhotoPreview(null)
      await onSaved()
      onClose()
    } catch (error) {
      setError(error instanceof Error ? error.message : 'Profile gagal disimpan')
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="modal-backdrop">
      <section className="modal admin-profile-modal">
        <header className="modal-header">
          <div><h2>Profile & Setting</h2><p>Kelola profile admin, foto, tema, dan suara notifikasi.</p></div>
          <button className="icon-button" type="button" onClick={onClose}>x</button>
        </header>
        <form className="user-form admin-profile-form" onSubmit={submit}>
          <div className="admin-profile-photo">
            <div className="admin-profile-avatar">
              {photoPreview || me.profile_photo_url ? <img src={photoPreview ?? assetUrl(me.profile_photo_url ?? '')} alt={me.name} /> : <span>{me.name.slice(0, 1).toUpperCase()}</span>}
            </div>
            <label className="secondary-button compact">
              Ganti Foto
              <input type="file" accept="image/*" hidden onChange={(event) => {
                const file = event.target.files?.[0] ?? null
                if (photoPreview) URL.revokeObjectURL(photoPreview)
                setPhoto(file)
                setPhotoPreview(file ? URL.createObjectURL(file) : null)
              }} />
            </label>
          </div>
          <div className="form-grid">
            <label>Nama<input value={name} onChange={(event) => setName(event.target.value)} required /></label>
            <label>Telepon<input value={phone} onChange={(event) => setPhone(event.target.value)} placeholder="Nomor aktif" /></label>
            <label className="span-2">Alamat<textarea value={address} onChange={(event) => setAddress(event.target.value)} placeholder="Alamat staff/admin" /></label>
            <label>Tema
              <button className="secondary-button" type="button" onClick={onDarkModeChange}>{darkMode ? 'Dark mode aktif' : 'Light mode aktif'}</button>
            </label>
            <label>Suara notifikasi
              <select value={notificationSound} onChange={(event) => onNotificationSoundChange(event.target.value)}>
                <option value="default">Default Admin</option>
                {hasCustomSound && <option value="custom">Custom perangkat ini</option>}
                <option value="ding">Ding</option>
                <option value="pop">Pop</option>
                <option value="soft">Soft</option>
                <option value="off">Nonaktif</option>
              </select>
            </label>
            <div className="span-2 admin-sound-actions">
              <button className="secondary-button" type="button" onClick={() => playAdminNotificationSound(notificationSound === 'off' ? 'default' : notificationSound)}>Tes Suara</button>
              <label className="secondary-button">
                Upload Custom
                <input
                  type="file"
                  accept="audio/*"
                  hidden
                  onChange={(event) => {
                    const file = event.target.files?.[0] ?? null
                    event.currentTarget.value = ''
                    if (!file) return
                    void saveCustomAdminNotificationSound(file)
                      .then(() => {
                        setHasCustomSound(true)
                        onNotificationSoundChange('custom')
                      })
                      .catch((error) => setError(error instanceof Error ? error.message : 'Gagal menyimpan audio custom'))
                  }}
                />
              </label>
              {hasCustomSound && <button className="secondary-button" type="button" onClick={() => void clearCustomAdminNotificationSound().then(() => { setHasCustomSound(false); onNotificationSoundChange('default') })}>Default</button>}
            </div>
          </div>
          {error && <div className="chat-error">{error}</div>}
          <div className="modal-actions">
            <button className="secondary-button" type="button" onClick={onClose}>Batal</button>
            <button className="primary-button" type="submit" disabled={saving || !name.trim()}>{saving ? 'Saving...' : 'Simpan Profile'}</button>
          </div>
        </form>
      </section>
    </div>
  )
}

function AdminChatPanel({ initialChats, api, me, token, permissions, notificationSound, targetDriverUserId, onTargetDriverHandled, onOpenOrder }: { initialChats: Chat[]; api: ApiClient; me: User; token: string; permissions: Permissions; notificationSound: string; targetDriverUserId: number | null; onTargetDriverHandled: () => void; onOpenOrder: (code: string) => void }) {
  const [chats, setChats] = useState<Chat[]>(initialChats)
  const [activeId, setActiveId] = useState<number | null>(initialChats[0]?.id ?? null)
  const [detail, setDetail] = useState<ChatDetail | null>(null)
  const [message, setMessage] = useState('')
  const [chatQuery, setChatQuery] = useState('')
  const [statusFilter, setStatusFilter] = useState<'all' | 'waiting' | 'active' | 'closed'>('all')
  const [attachmentOpen, setAttachmentOpen] = useState(false)
  const [attachmentFile, setAttachmentFile] = useState<{ file: File; source: 'gallery' | 'camera' | 'document' } | null>(null)
  const [isSending, setSending] = useState(false)
  const [chatError, setChatError] = useState('')
  const [isBotTyping, setBotTyping] = useState(false)
  const [closeConfirmOpen, setCloseConfirmOpen] = useState(false)
  const messagesRef = useRef<HTMLDivElement | null>(null)
  const galleryInputRef = useRef<HTMLInputElement | null>(null)
  const cameraInputRef = useRef<HTMLInputElement | null>(null)
  const documentInputRef = useRef<HTMLInputElement | null>(null)
  const chatSnapshotRef = useRef('')
  const rateLimitUntilRef = useRef(0)

  const activeChat = detail?.chat ?? chats.find((chat) => chat.id === activeId) ?? null
  const waitingQueue = useMemo(() => chats
    .filter((chat) => chat.status === 'waiting')
    .sort((first, second) => chatQueueTime(first) - chatQueueTime(second)), [chats])
  const filteredChats = chats.filter((chat) => {
    const haystack = `${chat.customer ?? ''} ${chat.driver ?? ''} ${chat.operator ?? ''} ${chat.order_code ?? ''} ${chat.last_message ?? chat.latest_message ?? ''}`.toLowerCase()
    return haystack.includes(chatQuery.toLowerCase()) && (statusFilter === 'all' || chat.status === statusFilter)
  }).sort((first, second) => chatSortScore(first, waitingQueue) - chatSortScore(second, waitingQueue))

  const loadChats = useCallback(async (notify = true) => {
    if (Date.now() < rateLimitUntilRef.current) return
    try {
      const payload = await api<{ data: { data: Chat[] } }>('/admin/chats')
      const snapshot = chatListSnapshot(payload.data.data)
      if (notify && chatSnapshotRef.current && chatSnapshotRef.current !== snapshot) {
        playAdminNotificationSound(notificationSound)
      }
      chatSnapshotRef.current = snapshot
      setChats(payload.data.data)
      if (!activeId && payload.data.data[0]) setActiveId(payload.data.data[0].id)
      setChatError((current) => current.includes('Terlalu banyak refresh') ? '' : current)
    } catch (error) {
      if (isRateLimitedError(error)) {
        rateLimitUntilRef.current = Date.now() + 15000
        setChatError('Terlalu banyak refresh live chat. Sistem jeda 15 detik lalu sync otomatis lagi.')
        return
      }
      setChatError(error instanceof Error ? error.message : 'Gagal memuat chat')
    }
  }, [activeId, api, notificationSound])

  const loadDetail = useCallback(async (id: number) => {
    if (Date.now() < rateLimitUntilRef.current) return
    setChatError('')
    try {
      const payload = await api<{ data: ChatDetail }>(`/admin/chat/${id}`)
      setDetail(payload.data)
    } catch (error) {
      if (isRateLimitedError(error)) {
        rateLimitUntilRef.current = Date.now() + 15000
        setChatError('Terlalu banyak refresh detail chat. Sistem jeda 15 detik lalu sync otomatis lagi.')
        return
      }
      setChatError(error instanceof Error ? error.message : 'Gagal memuat detail chat')
    }
  }, [api])

  useEffect(() => {
    if (!targetDriverUserId) return

    let active = true
    const openDriverChat = async () => {
      setChatError('')
      try {
        const payload = await api<{ data: Chat }>(`/admin/chat/drivers/${targetDriverUserId}`, { method: 'POST' })
        if (!active) return
        setChats((rows) => [payload.data, ...rows.filter((chat) => chat.id !== payload.data.id)])
        setActiveId(payload.data.id)
        await loadDetail(payload.data.id)
      } catch (error) {
        if (active) setChatError(error instanceof Error ? error.message : 'Gagal membuka chat driver')
      } finally {
        if (active) onTargetDriverHandled()
      }
    }

    void openDriverChat()

    return () => {
      active = false
    }
  }, [api, loadDetail, onTargetDriverHandled, targetDriverUserId])

  useEffect(() => {
    const snapshot = chatListSnapshot(initialChats)
    if (!chatSnapshotRef.current) chatSnapshotRef.current = snapshot
    setChats(initialChats)
    setActiveId((current) => current ?? initialChats[0]?.id ?? null)
  }, [initialChats])

  useEffect(() => {
    const timer = window.setTimeout(() => void loadChats(), 0)
    const interval = window.setInterval(() => void loadChats(), 5000)
    return () => {
      window.clearTimeout(timer)
      window.clearInterval(interval)
    }
  }, [loadChats])

  useEffect(() => {
    if (!activeId) return
    setDetail(null)
    const timer = window.setTimeout(() => void loadDetail(activeId), 0)
    const interval = window.setInterval(() => void loadDetail(activeId), 5000)
    return () => {
      window.clearTimeout(timer)
      window.clearInterval(interval)
    }
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
    if (!activeId || (!message.trim() && !attachmentFile) || isSending || activeChat?.status === 'closed') return
    setSending(true)
    setChatError('')
    try {
      const messageText = message.trim() || (attachmentFile ? `Lampiran ${attachmentLabel(attachmentFile.source)}: ${attachmentFile.file.name}` : '')
      const body = attachmentFile
        ? adminChatAttachmentBody(activeId, messageText, attachmentFile)
        : JSON.stringify({ chat_id: activeId, message: messageText })
      const payload = await api<{ data: AdminChatMessage }>('/admin/send-message', {
        method: 'POST',
        body,
      })
      setDetail((current) => current ? {
        ...current,
        chat: { ...current.chat, status: current.chat.status === 'waiting' ? 'active' : current.chat.status, operator: current.chat.operator ?? me.name, last_message: payload.data.message, updated_at: payload.data.created_at ?? current.chat.updated_at },
        messages: current.messages.some((item) => item.id === payload.data.id) ? current.messages : [...current.messages, payload.data],
      } : current)
      setChats((rows) => rows.map((chat) => chat.id === activeId ? { ...chat, status: chat.status === 'waiting' ? 'active' : chat.status, operator: chat.operator ?? me.name, last_message: payload.data.message, updated_at: payload.data.created_at ?? chat.updated_at } : chat))
      setMessage('')
      setAttachmentFile(null)
      setAttachmentOpen(false)
      setChatError(attachmentFile ? 'Lampiran berhasil dikirim.' : '')
      await loadChats(false)
    } catch (error) {
      if (isRateLimitedError(error)) {
        rateLimitUntilRef.current = Date.now() + 15000
        setChatError('Pesan belum terkirim karena terlalu banyak request. Tunggu sebentar lalu kirim ulang.')
        return
      }
      setChatError(error instanceof Error ? error.message : 'Pesan gagal dikirim')
    } finally {
      setSending(false)
    }
  }

  const closeChat = async () => {
    if (!activeId || activeChat?.status === 'closed') return
    setChatError('')
    try {
      await api(`/admin/chat/${activeId}/close`, { method: 'POST' })
      setCloseConfirmOpen(false)
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

  const attachFile = (source: 'gallery' | 'camera' | 'document', file?: File | null) => {
    if (!file) return
    setAttachmentFile({ file, source })
    setAttachmentOpen(false)
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
        {waitingQueue.length > 0 && (
          <div className="chat-waiting-queue">
            <strong>{waitingQueue.length} antrian waiting</strong>
            <span>Balas dari nomor #1 agar SLA aman.</span>
          </div>
        )}
        <div className="admin-chat-scroll">
        {filteredChats.map((chat) => (
          <button key={chat.id} className={activeId === chat.id ? 'admin-chat-item active' : 'admin-chat-item'} onClick={() => setActiveId(chat.id)}>
            <div><strong>{chat.customer || chat.driver || 'Unknown user'}</strong><span>{chat.type?.replace('_', ' ') ?? 'chat'}</span></div>
            <p>{chat.last_message ?? chat.latest_message ?? 'Belum ada pesan'}</p>
            <footer><QueueBadge chat={chat} queue={waitingQueue} /><SlaBadge chat={chat} /><ChatFeedbackBadge chat={chat} />{Boolean(chat.unread_count) && <b>{chat.unread_count}</b>}<small>{formatShortTime(chat.updated_at)}</small></footer>
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
              <div><h2>{activeChat.customer || activeChat.driver || 'Chat'}</h2><p>{activeChat.order_code ?? activeChat.type} · Ditangani: {activeChat.operator ?? me.name}</p></div>
              <div className="admin-chat-actions"><QueueBadge chat={activeChat} queue={waitingQueue} /><SlaBadge chat={activeChat} /><ChatFeedbackBadge chat={activeChat} /><ChatStatusBadge status={activeChat.status} /><button className="mini-button reject" disabled={activeChat.status === 'closed'} onClick={() => setCloseConfirmOpen(true)}>Close Chat</button></div>
            </header>
            {activeChat.order_code && <button className="order-code-link order-code-row" onClick={() => onOpenOrder(activeChat.order_code!)}>Buka order {activeChat.order_code}</button>}
            {chatError && <div className={`chat-error ${chatNoticeTone(chatError)}`}>{chatError}</div>}
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
                  <AdminChatFilePreview message={item} />
                  {(item.message ?? '').includes('Transkripsi:') && <em>Transcription available</em>}
                </article>
              ))}
              {isBotTyping && <div className="bot-typing">Customer sedang mengetik...</div>}
            </div>
            <form className="admin-chat-composer" onSubmit={(event) => { event.preventDefault(); void send() }}>
              <input ref={galleryInputRef} type="file" accept="image/*" hidden onChange={(event) => { attachFile('gallery', event.target.files?.[0]); event.currentTarget.value = '' }} />
              <input ref={cameraInputRef} type="file" accept="image/*" capture="environment" hidden onChange={(event) => { attachFile('camera', event.target.files?.[0]); event.currentTarget.value = '' }} />
              <input ref={documentInputRef} type="file" accept=".pdf,.doc,.docx,.xls,.xlsx,.txt" hidden onChange={(event) => { attachFile('document', event.target.files?.[0]); event.currentTarget.value = '' }} />
              <div className="internal-attachment-wrap admin-attachment-wrap">
                <button className="chat-clip-button" type="button" disabled={activeChat.status === 'closed'} onClick={() => setAttachmentOpen((open) => !open)} aria-label="Lampiran">
                  <Icon name="clip" />
                </button>
                {attachmentOpen && (
                  <div className="internal-attachment-menu admin-attachment-menu">
                    <button type="button" onClick={() => galleryInputRef.current?.click()}>Galeri</button>
                    <button type="button" onClick={() => cameraInputRef.current?.click()}>Kamera</button>
                    <button type="button" onClick={() => documentInputRef.current?.click()}>Dokumen</button>
                  </div>
                )}
              </div>
              <textarea value={message} disabled={activeChat.status === 'closed'} onChange={(event) => setMessage(event.target.value)} placeholder={activeChat.status === 'closed' ? 'Chat sudah ditutup' : attachmentFile ? 'Tambahkan keterangan lampiran...' : 'Balas sebagai operator...'} onKeyDown={(event) => { if (event.key === 'Enter' && !event.shiftKey) { event.preventDefault(); void send() } }} />
              <button className="primary-button" disabled={isSending || activeChat.status === 'closed' || (!message.trim() && !attachmentFile)} type="submit">{isSending ? 'Sending...' : 'Send'}</button>
              {attachmentFile && (
                <div className="internal-composer-hints admin-composer-hints">
                  <button type="button" className="attachment-chip" onClick={() => setAttachmentFile(null)}>{attachmentLabel(attachmentFile.source)}: {attachmentFile.file.name} x</button>
                </div>
              )}
            </form>
            {closeConfirmOpen && (
              <div className="chat-confirm-card" role="dialog" aria-modal="true" aria-label="Konfirmasi tutup chat">
                <div>
                  <strong>Tutup percakapan ini?</strong>
                  <span>Chat akan berubah status menjadi closed dan tidak bisa dibalas lagi.</span>
                </div>
                <div>
                  <button className="secondary-button compact" type="button" onClick={() => setCloseConfirmOpen(false)}>Batal</button>
                  <button className="mini-button reject" type="button" onClick={() => void closeChat()}>Tutup Chat</button>
                </div>
              </div>
            )}
          </>
        )}
      </main>
    </section>
  )
}

function SlaBadge({ chat }: { chat: Chat }) {
  const late = chat.sla_status === 'late'
  const waiting = chat.sla_status === 'waiting'
  const label = late ? 'SLA LATE' : waiting ? 'SLA WAITING' : 'SLA OK'
  return <span className={late ? 'sla-badge late' : waiting ? 'sla-badge waiting' : 'sla-badge ok'}>{label}</span>
}

function QueueBadge({ chat, queue }: { chat: Chat; queue: Chat[] }) {
  if (chat.status !== 'waiting') return null
  const index = queue.findIndex((item) => item.id === chat.id)

  return <span className="queue-badge">Antrian #{index >= 0 ? index + 1 : '-'}</span>
}

function ChatFeedbackBadge({ chat }: { chat: Chat }) {
  if (!chat.rating_requested_at) return null
  const autoClosed = chat.status === 'closed' && !chat.first_operator_response_at
  return (
    <span className={autoClosed ? 'feedback-badge closed' : 'feedback-badge'}>
      {autoClosed ? 'Auto closed 15m' : 'Feedback 10m'}
    </span>
  )
}

function ChatStatusBadge({ status }: { status: string }) {
  const tone = status === 'closed' ? 'muted' : status === 'waiting' ? 'warning' : 'success'
  return <span className={`status ${tone}`}>{status}</span>
}

function InternalChatPanel({ api, me, branches, users, orders, onOpenOrder }: { api: ApiClient; me: User; branches: Branch[]; users: User[]; orders: Order[]; onOpenOrder: (code: string) => void }) {
  const [rooms, setRooms] = useState<InternalChatRoom[]>([])
  const [activeId, setActiveId] = useState<number | null>(null)
  const [detail, setDetail] = useState<InternalChatDetail | null>(null)
  const [message, setMessage] = useState('')
  const [selectedOrderId, setSelectedOrderId] = useState('')
  const [attachmentOpen, setAttachmentOpen] = useState(false)
  const [attachmentFile, setAttachmentFile] = useState<{ file: File; source: 'gallery' | 'camera' | 'document' } | null>(null)
  const [query, setQuery] = useState('')
  const [roomType, setRoomType] = useState<'branch' | 'global' | 'private'>('branch')
  const [roomName, setRoomName] = useState('')
  const [roomBranchId, setRoomBranchId] = useState(() => String(me.branch_id ?? branches[0]?.id ?? ''))
  const [isCreating, setCreating] = useState(false)
  const [isSending, setSending] = useState(false)
  const [error, setError] = useState('')
  const messagesRef = useRef<HTMLDivElement | null>(null)
  const galleryInputRef = useRef<HTMLInputElement | null>(null)
  const cameraInputRef = useRef<HTMLInputElement | null>(null)
  const documentInputRef = useRef<HTMLInputElement | null>(null)

  const filteredRooms = rooms.filter((room) => `${room.name} ${room.branch ?? ''} ${room.last_message ?? ''}`.toLowerCase().includes(query.toLowerCase()))
  const activeRoom = detail?.room ?? rooms.find((room) => room.id === activeId) ?? null
  const managementUsers = users.filter((user) => ['admin', 'gm', 'hrd', 'manager', 'spv', 'operator', 'eksekutor'].includes(user.role))
  const visibleOrders = sortOrdersNewest(orders).slice(0, 40)
  const selectedOrder = visibleOrders.find((order) => String(order.id) === selectedOrderId) ?? orders.find((order) => String(order.id) === selectedOrderId) ?? null
  const mentionNeedle = lastMentionToken(message)
  const mentionSuggestions = mentionNeedle === null
    ? []
    : managementUsers
      .filter((user) => `${user.name} ${roleLabels[user.role] ?? user.role}`.toLowerCase().includes(mentionNeedle.toLowerCase()))
      .slice(0, 6)
  const orderSuggestions = mentionNeedle === null
    ? []
    : visibleOrders
      .filter((order) => `${order.code} ${order.customer ?? ''} ${order.driver ?? ''}`.toLowerCase().includes(mentionNeedle.toLowerCase()))
      .slice(0, 6)

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
    if (!activeId || (!message.trim() && !attachmentFile) || isSending) return
    setSending(true)
    try {
      const metadata: InternalChatMetadata = {
        mentioned_user_ids: mentionedUserIds(message, managementUsers),
        order_ids: selectedOrder ? [selectedOrder.id] : mentionedOrderIds(message, orders),
        order_codes: selectedOrder ? [selectedOrder.code] : mentionedOrderCodes(message, orders),
      }
      let body: BodyInit
      if (attachmentFile) {
        const form = new FormData()
        body = form
        form.append('message', message.trim() || `Lampiran ${attachmentLabel(attachmentFile.source)}: ${attachmentFile.file.name}`)
        form.append('attachment', attachmentFile.file)
        form.append('attachment_source', attachmentFile.source)
        form.append('metadata_json', JSON.stringify(metadata))
      } else {
        body = JSON.stringify({ message, metadata })
      }
      const payload = await api<{ data: InternalChatMessage }>(`/admin/internal-chat/rooms/${activeId}/messages`, {
        method: 'POST',
        body,
      })
      setDetail((current) => current ? { ...current, messages: current.messages.some((item) => item.id === payload.data.id) ? current.messages : [...current.messages, payload.data] } : current)
      setRooms((rows) => rows.map((room) => room.id === activeId ? { ...room, last_message: payload.data.message, last_sender: payload.data.sender_name, updated_at: payload.data.created_at } : room))
      setMessage('')
      setSelectedOrderId('')
      setAttachmentFile(null)
      setAttachmentOpen(false)
      setError('')
    } catch (error) {
      setError(error instanceof Error ? error.message : 'Pesan internal gagal dikirim')
    } finally {
      setSending(false)
    }
  }

  const appendText = (value: string) => setMessage((current) => `${current}${current && !/\s$/.test(current) ? ' ' : ''}${value} `)
  const attachFile = (source: 'gallery' | 'camera' | 'document', file?: File | null) => {
    if (!file) return
    setAttachmentFile({ file, source })
    setAttachmentOpen(false)
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
            <div className="internal-context-bar">
              <label>
                Order
                <select value={selectedOrderId} onChange={(event) => {
                  setSelectedOrderId(event.target.value)
                  const order = orders.find((item) => String(item.id) === event.target.value)
                  if (order) appendText(`@order:${order.code}`)
                }}>
                  <option value="">Tag order berjalan/selesai</option>
                  {visibleOrders.map((order) => <option key={order.id} value={order.id}>{order.code} - {order.status} - {order.customer ?? 'Customer'}</option>)}
                </select>
              </label>
              <div className="internal-quick-tags">
                {managementUsers.slice(0, 6).map((user) => (
                  <button key={user.id} type="button" onClick={() => appendText(`@${user.name.replace(/\s+/g, '_')}`)}>
                    @{user.name}
                  </button>
                ))}
              </div>
            </div>
            <div className="admin-chat-messages" ref={messagesRef}>
              {detail?.messages.length === 0 && <EmptyPanel title="Belum ada pesan" copy="Mulai koordinasi dengan tim di room ini." />}
              {detail?.messages.map((item) => (
                <article key={item.id} className={item.sender_id === me.id ? 'admin-bubble mine' : 'admin-bubble'}>
                  <span>{item.sender_name} <small>{roleLabels[(item.sender_role as Role) || 'operator'] ?? item.sender_role} · {formatShortTime(item.created_at)}</small></span>
                  {visibleInternalMessageText(item).trim() && <p>{renderOrderCodeLinks(visibleInternalMessageText(item), onOpenOrder)}</p>}
                  <InternalAttachmentPreview attachment={item.metadata?.attachment} />
                  {Boolean(item.metadata?.order_codes?.length) && (
                    <div className="internal-message-tags">
                      {item.metadata?.order_codes?.map((code) => <button key={code} type="button" onClick={() => onOpenOrder(code)}>Order {code}</button>)}
                    </div>
                  )}
                </article>
              ))}
            </div>
            <form className="admin-chat-composer" onSubmit={(event) => { event.preventDefault(); void send() }}>
              <input ref={galleryInputRef} type="file" accept="image/*" hidden onChange={(event) => { attachFile('gallery', event.target.files?.[0]); event.currentTarget.value = '' }} />
              <input ref={cameraInputRef} type="file" accept="image/*" capture="environment" hidden onChange={(event) => { attachFile('camera', event.target.files?.[0]); event.currentTarget.value = '' }} />
              <input ref={documentInputRef} type="file" accept=".pdf,.doc,.docx,.xls,.xlsx,.txt" hidden onChange={(event) => { attachFile('document', event.target.files?.[0]); event.currentTarget.value = '' }} />
              <div className="internal-attachment-wrap">
                <button className="chat-clip-button" type="button" onClick={() => setAttachmentOpen((open) => !open)} aria-label="Lampiran">
                  <Icon name="clip" />
                </button>
                {attachmentOpen && (
                  <div className="internal-attachment-menu">
                    <button type="button" onClick={() => galleryInputRef.current?.click()}>Galeri</button>
                    <button type="button" onClick={() => cameraInputRef.current?.click()}>Kamera</button>
                    <button type="button" onClick={() => documentInputRef.current?.click()}>Dokumen</button>
                  </div>
                )}
              </div>
              <textarea value={message} onChange={(event) => setMessage(event.target.value)} placeholder="Tulis pesan internal, mention order, atau koordinasi driver..." onKeyDown={(event) => { if (event.key === 'Enter' && !event.shiftKey) { event.preventDefault(); void send() } }} />
              <button className="primary-button" disabled={isSending || (!message.trim() && !attachmentFile)} type="submit">{isSending ? 'Sending...' : 'Send'}</button>
              {(mentionSuggestions.length > 0 || orderSuggestions.length > 0 || attachmentFile) && (
                <div className="internal-composer-hints">
                  {attachmentFile && <button type="button" className="attachment-chip" onClick={() => setAttachmentFile(null)}>{attachmentLabel(attachmentFile.source)}: {attachmentFile.file.name} x</button>}
                  {mentionSuggestions.map((user) => <button key={user.id} type="button" onClick={() => appendText(`@${user.name.replace(/\s+/g, '_')}`)}>@{user.name}</button>)}
                  {orderSuggestions.map((order) => <button key={order.id} type="button" onClick={() => { setSelectedOrderId(String(order.id)); appendText(`@order:${order.code}`) }}>@order:{order.code}</button>)}
                </div>
              )}
            </form>
          </>
        )}
      </main>
    </section>
  )
}

function InternalAttachmentPreview({ attachment }: { attachment?: InternalChatAttachment | null }) {
  if (!attachment?.url) return null

  const url = assetUrl(attachment.url)
  const isImage = String(attachment.mime ?? '').startsWith('image/')

  return (
    <a className="internal-attachment-preview" href={url} target="_blank" rel="noreferrer">
      {isImage ? <img src={url} alt={attachment.name ?? 'Lampiran internal'} /> : <Icon name="receipt" />}
      <span>
        <b>{attachment.name ?? 'Lampiran'}</b>
        <small>{attachmentLabel(attachment.source)}{attachment.size ? ` - ${formatFileSize(attachment.size)}` : ''}</small>
      </span>
    </a>
  )
}

function AdminChatFilePreview({ message }: { message: AdminChatMessage }) {
  if (!message.file_url) return null

  return (
    <a className="admin-file-attachment" href={assetUrl(message.file_url)} target="_blank" rel="noreferrer">
      <Icon name="clip" />
      <span>
        <b>{message.file_name ?? 'Dokumen chat'}</b>
        <small>{message.file_mime ?? 'file'}{message.file_size ? ` · ${formatFileSize(message.file_size)}` : ''}</small>
      </span>
    </a>
  )
}

function adminChatAttachmentBody(chatId: number, message: string, attachment: { file: File; source: 'gallery' | 'camera' | 'document' }) {
  const form = new FormData()
  form.append('chat_id', String(chatId))
  form.append('message', message)
  if (attachment.source === 'document') {
    form.append('file', attachment.file)
  } else {
    form.append('image', attachment.file)
  }

  return form
}

function visibleInternalMessageText(message: InternalChatMessage) {
  let text = message.message
  for (const code of message.metadata?.order_codes ?? []) {
    text = text
      .replaceAll(`@order:${code}`, '')
      .replaceAll(`@${code}`, '')
  }

  if (message.metadata?.attachment && text === `Lampiran ${attachmentLabel(message.metadata.attachment.source)}: ${message.metadata.attachment.name}`) {
    return ''
  }

  return text.replace(/\s+/g, ' ').trim()
}

function attachmentLabel(source?: string | null) {
  if (source === 'gallery') return 'Galeri'
  if (source === 'camera') return 'Kamera'
  return 'Dokumen'
}

function formatFileSize(size: number) {
  if (size >= 1024 * 1024) return `${(size / 1024 / 1024).toFixed(1)} MB`
  if (size >= 1024) return `${Math.round(size / 1024)} KB`
  return `${size} B`
}

function lastMentionToken(text: string) {
  const match = text.match(/(?:^|\s)@([^\s@]*)$/)
  return match ? match[1] : null
}

function mentionedUserIds(text: string, users: User[]) {
  const normalized = text.toLowerCase()
  return users
    .filter((user) => normalized.includes(`@${user.name.replace(/\s+/g, '_').toLowerCase()}`) || normalized.includes(`@${user.name.toLowerCase()}`))
    .map((user) => user.id)
}

function mentionedOrderIds(text: string, orders: Order[]) {
  const codes = mentionedOrderCodes(text, orders)
  return orders.filter((order) => codes.includes(order.code)).map((order) => order.id)
}

function mentionedOrderCodes(text: string, orders: Order[]) {
  const normalized = text.toLowerCase()
  return orders
    .filter((order) => normalized.includes(`@order:${order.code.toLowerCase()}`) || normalized.includes(`@${order.code.toLowerCase()}`))
    .map((order) => order.code)
}

function StickyNotesPanel({ api, me, users, branches }: { api: ApiClient; me: User; users: User[]; branches: Branch[] }) {
  const [notes, setNotes] = useState<InternalNote[]>([])
  const [summary, setSummary] = useState<InternalNotesResponse['summary']>({ open: 0, in_progress: 0, done: 0, urgent: 0, assigned_to_me: 0 })
  const [activeId, setActiveId] = useState<number | null>(null)
  const [statusFilter, setStatusFilter] = useState<InternalNoteStatus | 'all'>('all')
  const [query, setQuery] = useState('')
  const [title, setTitle] = useState('')
  const [body, setBody] = useState('')
  const [priority, setPriority] = useState<InternalNotePriority>('normal')
  const [category, setCategory] = useState('operasional')
  const [assignedToId, setAssignedToId] = useState('')
  const [branchId, setBranchId] = useState(() => String(me.branch_id ?? ''))
  const [reply, setReply] = useState('')
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')

  const staffUsers = users.filter((user) => !['driver', 'customer'].includes(user.role))
  const activeNote = notes.find((note) => note.id === activeId) ?? notes[0] ?? null
  const filteredNotes = notes.filter((note) => {
    const matchesStatus = statusFilter === 'all' || note.status === statusFilter
    const haystack = `${note.title} ${note.body} ${note.author?.name ?? ''} ${note.assigned_to?.name ?? ''} ${note.branch?.name ?? ''} ${note.branch?.area ?? ''}`.toLowerCase()
    return matchesStatus && haystack.includes(query.toLowerCase())
  })
  const boardStatuses: InternalNoteStatus[] = ['open', 'in_progress', 'done']

  const loadNotes = useCallback(async () => {
    try {
      const params = new URLSearchParams()
      if (statusFilter !== 'all') params.set('status', statusFilter)
      if (query.trim()) params.set('q', query.trim())
      const payload = await api<InternalNotesResponse>(`/admin/internal-notes${params.toString() ? `?${params}` : ''}`)
      setNotes(payload.data)
      setSummary(payload.summary)
      setActiveId((current) => current ?? payload.data[0]?.id ?? null)
      setError('')
    } catch (error) {
      setError(error instanceof Error ? error.message : 'Gagal memuat sticky notes')
    }
  }, [api, query, statusFilter])

  useEffect(() => {
    void loadNotes()
    const timer = window.setInterval(() => void loadNotes(), 10000)
    return () => window.clearInterval(timer)
  }, [loadNotes])

  const createNote = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    if (!title.trim() || !body.trim() || loading) return
    setLoading(true)
    try {
      const payload = await api<{ data: InternalNote }>('/admin/internal-notes', {
        method: 'POST',
        body: JSON.stringify({
          title: title.trim(),
          body: body.trim(),
          priority,
          category,
          assigned_to_id: assignedToId ? Number(assignedToId) : null,
          branch_id: branchId ? Number(branchId) : null,
        }),
      })
      setNotes((rows) => [payload.data, ...rows])
      setActiveId(payload.data.id)
      setTitle('')
      setBody('')
      setPriority('normal')
      setCategory('operasional')
      setAssignedToId('')
      setError('')
      void loadNotes()
    } catch (error) {
      setError(error instanceof Error ? error.message : 'Sticky note gagal dibuat')
    } finally {
      setLoading(false)
    }
  }

  const updateNote = async (note: InternalNote, updates: Partial<Pick<InternalNote, 'status' | 'priority' | 'category'>>) => {
    try {
      const payload = await api<{ data: InternalNote }>(`/admin/internal-notes/${note.id}`, {
        method: 'PATCH',
        body: JSON.stringify(updates),
      })
      setNotes((rows) => rows.map((item) => item.id === note.id ? payload.data : item))
      setError('')
    } catch (error) {
      setError(error instanceof Error ? error.message : 'Update sticky note gagal')
    }
  }

  const sendReply = async () => {
    if (!activeNote || !reply.trim()) return
    try {
      const payload = await api<{ data: InternalNoteReply }>(`/admin/internal-notes/${activeNote.id}/replies`, {
        method: 'POST',
        body: JSON.stringify({ body: reply.trim() }),
      })
      setNotes((rows) => rows.map((note) => note.id === activeNote.id ? { ...note, latest_reply: payload.data, replies_count: note.replies_count + 1, last_activity_at: payload.data.created_at } : note))
      setReply('')
      setError('')
    } catch (error) {
      setError(error instanceof Error ? error.message : 'Balasan note gagal dikirim')
    }
  }

  return (
    <section className="sticky-notes-shell">
      <aside className="panel sticky-note-compose">
        <PanelHeader title="Sticky Notes" action={`${notes.length} note`} />
        <div className="sticky-note-summary">
          <span><b>{summary.open}</b> Open</span>
          <span><b>{summary.in_progress}</b> Progress</span>
          <span><b>{summary.urgent}</b> Urgent</span>
          <span><b>{summary.assigned_to_me}</b> Untuk saya</span>
        </div>
        <form onSubmit={createNote}>
          <input value={title} onChange={(event) => setTitle(event.target.value)} placeholder="Judul singkat, contoh: Follow up QRIS" />
          <textarea value={body} onChange={(event) => setBody(event.target.value)} placeholder="Tulis masukan, bug, todo, atau hal yang perlu ditindaklanjuti..." />
          <div className="sticky-form-grid">
            <select value={category} onChange={(event) => setCategory(event.target.value)}>
              <option value="operasional">Operasional</option>
              <option value="bug">Bug</option>
              <option value="ide">Ide</option>
              <option value="follow_up">Follow up</option>
              <option value="customer">Customer</option>
              <option value="driver">Driver</option>
            </select>
            <select value={priority} onChange={(event) => setPriority(event.target.value as InternalNotePriority)}>
              <option value="low">Low</option>
              <option value="normal">Normal</option>
              <option value="high">High</option>
              <option value="urgent">Urgent</option>
            </select>
            <select value={assignedToId} onChange={(event) => setAssignedToId(event.target.value)}>
              <option value="">Assign nanti</option>
              {staffUsers.map((user) => <option key={user.id} value={user.id}>{user.name} - {roleLabels[user.role] ?? user.role}</option>)}
            </select>
            <select value={branchId} onChange={(event) => setBranchId(event.target.value)}>
              <option value="">Global</option>
              {branches.map((branch) => <option key={branch.id} value={branch.id}>{branchLabel(branch)}</option>)}
            </select>
          </div>
          <button className="primary-button" type="submit" disabled={loading || !title.trim() || !body.trim()}>{loading ? 'Menyimpan...' : '+ Tambah Note'}</button>
        </form>
        {error && <p className="error-text">{error}</p>}
      </aside>

      <main className="sticky-board">
        <div className="panel sticky-toolbar">
          <div className="search"><Icon name="search" /><input value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Cari note, user, area..." /></div>
          <select value={statusFilter} onChange={(event) => setStatusFilter(event.target.value as InternalNoteStatus | 'all')}>
            <option value="all">Semua status</option>
            <option value="open">Open</option>
            <option value="in_progress">In progress</option>
            <option value="done">Done</option>
            <option value="archived">Archived</option>
          </select>
        </div>
        <div className="sticky-columns">
          {boardStatuses.map((status) => (
            <section className="panel sticky-column" key={status}>
              <PanelHeader title={internalNoteStatusLabel(status)} action={`${filteredNotes.filter((note) => note.status === status).length}`} />
              <div className="sticky-card-list">
                {filteredNotes.filter((note) => note.status === status).map((note) => (
                  <button key={note.id} className={activeNote?.id === note.id ? `sticky-card active ${note.priority}` : `sticky-card ${note.priority}`} onClick={() => setActiveId(note.id)}>
                    <span className={`note-priority ${note.priority}`}>{internalNotePriorityLabel(note.priority)}</span>
                    <strong>{note.title}</strong>
                    <p>{note.body}</p>
                    <footer>
                      <small>{note.author?.name ?? 'System'}</small>
                      <small>{note.branch ? `${note.branch.name}${note.branch.area ? ` - ${note.branch.area}` : ''}` : 'Global'}</small>
                    </footer>
                    {note.latest_reply && <em>{note.latest_reply.author?.name ?? 'Tim'}: {note.latest_reply.body}</em>}
                  </button>
                ))}
                {filteredNotes.filter((note) => note.status === status).length === 0 && <EmptyPanel title="Kosong" copy="Tidak ada note di kolom ini." />}
              </div>
            </section>
          ))}
        </div>
      </main>

      <aside className="panel sticky-detail">
        {!activeNote && <EmptyPanel title="Pilih note" copy="Detail, reply, dan aksi status tampil di sini." />}
        {activeNote && (
          <>
            <div className="sticky-detail-head">
              <span className={`note-priority ${activeNote.priority}`}>{internalNotePriorityLabel(activeNote.priority)}</span>
              <h2>{activeNote.title}</h2>
              <p>{activeNote.author?.name ?? 'System'} · {formatShortDateTime(activeNote.created_at)}</p>
            </div>
            <p className="sticky-detail-body">{activeNote.body}</p>
            <div className="sticky-meta-grid">
              <span><small>Status</small><b>{internalNoteStatusLabel(activeNote.status)}</b></span>
              <span><small>Assign</small><b>{activeNote.assigned_to?.name ?? '-'}</b></span>
              <span><small>Area</small><b>{activeNote.branch ? `${activeNote.branch.name}${activeNote.branch.area ? ` - ${activeNote.branch.area}` : ''}` : 'Global'}</b></span>
              <span><small>Reply</small><b>{activeNote.replies_count}</b></span>
            </div>
            <div className="sticky-actions">
              <button className="mini-button" onClick={() => void updateNote(activeNote, { status: 'open' })}>Open</button>
              <button className="mini-button approve" onClick={() => void updateNote(activeNote, { status: 'in_progress' })}>Progress</button>
              <button className="mini-button reject" onClick={() => void updateNote(activeNote, { status: 'done' })}>Done</button>
            </div>
            {activeNote.latest_reply && (
              <div className="sticky-latest-reply">
                <small>Balasan terakhir</small>
                <p>{activeNote.latest_reply.body}</p>
                <span>{activeNote.latest_reply.author?.name ?? 'Tim'} · {formatShortDateTime(activeNote.latest_reply.created_at)}</span>
              </div>
            )}
            <form className="sticky-reply-form" onSubmit={(event) => { event.preventDefault(); void sendReply() }}>
              <textarea value={reply} onChange={(event) => setReply(event.target.value)} placeholder="Balas note atau tambahkan update progress..." />
              <button className="primary-button" type="submit" disabled={!reply.trim()}>Kirim Reply</button>
            </form>
          </>
        )}
      </aside>
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
  const [branchTouched, setBranchTouched] = useState(false)
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

  useEffect(() => {
    if (branchTouched || !me.branch_id) return
    const userBranch = branches.find((branch) => branch.id === me.branch_id)
    if (userBranch && branchId !== String(userBranch.id)) {
      setBranchId(String(userBranch.id))
    }
  }, [branchId, branchTouched, branches, me.branch_id])

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
      if (!branchId && parsedBranchId) setBranchId(String(parsedBranchId))
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
          raw_text: rawText.trim(),
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
            <select value={branchId} onChange={(event) => { setBranchTouched(true); setBranchId(event.target.value); setPreview(null) }}>
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
  const routeMeta = (payload?.service_payload && typeof payload.service_payload === 'object' ? payload.service_payload : {}) as Record<string, unknown>
  const geocodingStatus = typeof routeMeta.geocoding_status === 'string' ? routeMeta.geocoding_status : null
  const geocodingWarning = typeof routeMeta.geocoding_warning === 'string' ? routeMeta.geocoding_warning : null
  const pickupProvider = typeof routeMeta.pickup_geocoded_by === 'string' ? routeMeta.pickup_geocoded_by : null
  const destinationProvider = typeof routeMeta.destination_geocoded_by === 'string' ? routeMeta.destination_geocoded_by : null
  const serviceKey = String(payload?.service_type ?? preview.service_type ?? preview.selected_service ?? '').toLowerCase()
  const isCourierOrder = ['kurir', 'kr'].includes(serviceKey) || serviceKey.includes('kurir')
  const containsTart = /\b(?:kue\s*)?tart\b/i.test([
    payload?.notes,
    payload?.pickup_address,
    payload?.destination_address,
    JSON.stringify(payload?.items ?? []),
    JSON.stringify(payload?.service_payload ?? {}),
  ].filter(Boolean).join(' '))

  return (
    <aside className={payload ? 'manual-preview-card ready' : 'manual-preview-card warning'}>
      <span className="status info">{preview.intent}</span>
      <strong>{manualDisplayValue(preview.selected_service ?? preview.service_type ?? payload?.service_type) ?? 'Order belum terbaca'}</strong>
      <p>{sanitizeManualPreviewText(preview.reply ?? preview.message, preview) ?? 'Lengkapi teks order agar sistem bisa membuat preview.'}</p>
      {payload && (
        <div className="manual-preview-detail">
          <div><span>{isCourierOrder ? 'Customer / pengirim' : 'Customer'}</span><b>{customer.name || 'Belum terbaca'}{customer.phone ? ` - ${customer.phone}` : ''}</b></div>
          {customer.address && <div><span>Alamat customer</span><b>{customer.address}</b></div>}
          <div><span>{isCourierOrder ? 'Pickup / ambil barang' : 'Pickup'}</span><b>{payload.pickup_address}</b></div>
          <label className="manual-inline-editor"><span>{isCourierOrder ? 'Penerima / tujuan' : 'Tujuan'}</span><input value={payload.destination_address} onChange={(event) => onDestinationChange(event.target.value)} /></label>
          {containsTart && <div className="manual-route-status warning"><span>Rule kue tart</span><b>Submit akan membuat 2 order delivery: driver utama dan helper tanpa service fee.</b></div>}
          {(payload.points ?? []).map((point, index) => (
            <label className="manual-inline-editor" key={`${point.label}-${index}`}><span>{point.label ?? `Titik ${index + 1}`}</span><input value={point.address} onChange={(event) => onPointChange(index, event.target.value)} placeholder="Alamat titik tambahan" /></label>
          ))}
          {geocodingStatus && geocodingStatus !== 'base_fare' && (
            <div className={geocodingStatus === 'resolved' ? 'manual-route-status resolved' : 'manual-route-status warning'}>
              <span>Status maps</span>
              <b>{geocodingStatus === 'resolved' ? `Titik terbaca ${[pickupProvider, destinationProvider].filter(Boolean).join(' / ') || 'maps'}` : geocodingWarning ?? 'Koordinat fallback, cek titik maps sebelum kirim order.'}</b>
            </div>
          )}
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
  return <section className="panel branches-panel"><div className="section-head"><div><h2>Branches</h2><p>Kelola cabang operasional, area, dan titik koordinat utama.</p></div>{canCreate && <button className="primary-button compact" onClick={() => setShowForm((value) => !value)} type="button"><Icon name="plus" />Add Cabang</button>}</div>{showForm && <form className="admin-inline-form branch-create-form" onSubmit={submit}><label>Nama cabang<input name="name" required placeholder="Situbondo" /></label><label>Area<input name="area" placeholder="Kota / wilayah" /></label><label>Latitude<input name="latitude" required type="number" step="0.00000001" placeholder="-7.706" /></label><label>Longitude<input name="longitude" required type="number" step="0.00000001" placeholder="114.009" /></label><label>Radius KM<input name="radius_km" required type="number" step="0.1" min="0.1" defaultValue="5" /></label><button className="primary-button" type="submit">Save Cabang</button></form>}<div className="branch-grid">{branches.map((branch) => <article className="branch-card" key={branch.id}><div className="branch-map"><span>{branch.name.slice(0, 2).toUpperCase()}</span></div><div className="branch-card-body"><strong>{branch.name}</strong><span className="branch-area-name">{branch.area || 'Area belum diisi'}</span><p>Titik cabang disembunyikan di frontend</p><b>{branch.radius_km ?? 5} km radius - {branchGeofenceNames(branch)}</b></div></article>)}</div></section>
}

function GeofencePanel({ geofences }: { geofences: Geofence[] }) {
  return <section className="panel"><PanelHeader title="Geofence areas" action={`${geofences.length} areas`} /><div className="activity-list">{geofences.map((area) => <AreaRow key={area.id} name={area.name} branch={area.branch ? branchLabel(area.branch) : '-'} radius={`${area.radius_meters} m`} active={area.is_active} />)}</div></section>
}

function LocationLogsPanel({ logs, branches, canViewMaps }: { logs: LocationLog[]; branches: Branch[]; canViewMaps: boolean }) {
  const [branchFilter, setBranchFilter] = useState('all')
  const branchOptions = useMemo(() => {
    const values = new Map<string, string>()
    branches.forEach((branch) => values.set(branchLocationKey(branchLabel(branch)), branchLabel(branch)))
    logs.forEach((log) => {
      if (log.branch) values.set(branchLocationKey(log.branch), log.branch)
    })

    return [...values.entries()].sort((first, second) => first[1].localeCompare(second[1]))
  }, [branches, logs])
  const filteredLogs = logs.filter((log) => branchFilter === 'all' || branchLocationKey(log.branch || '') === branchFilter)

  return <section className="panel"><PanelHeader title="Location logs" action={`${filteredLogs.length}/${logs.length} logs`} /><div className="table-toolbar location-log-toolbar"><select value={branchFilter} onChange={(event) => setBranchFilter(event.target.value)}><option value="all">Semua branch</option>{branchOptions.map(([key, label]) => <option key={key} value={key}>{label}</option>)}</select><span className="toolbar-hint">Filter global untuk audit GPS per cabang.</span></div><div className="activity-list">{filteredLogs.map((log) => <div className="activity-item location-log-item" key={log.id}><div className={log.is_suspicious || log.is_mock_location ? 'activity-icon danger' : 'activity-icon'}><Icon name="pin" /></div><div><strong>{log.user || '-'}</strong><span>{canViewMaps ? `${log.branch || '-'} - ${log.latitude}, ${log.longitude}` : `${log.branch || '-'} - titik GPS disembunyikan`}</span><small>{[log.provider, log.accuracy ? `akurasi ${Math.round(log.accuracy)}m` : null, log.created_at ? formatShortDateTime(log.created_at) : null].filter(Boolean).join(' - ')}</small>{log.reason && <em>{log.reason}</em>}</div><div className="location-log-actions">{canViewMaps && log.maps_url && <a className="mini-button" href={log.maps_url} target="_blank" rel="noreferrer">Maps</a>}<span className={log.is_mock_location || log.is_suspicious ? 'status danger' : log.is_valid ? 'status success' : 'status muted'}>{log.is_mock_location ? 'GPS tidak valid' : log.is_suspicious ? 'Suspicious' : log.is_valid ? 'Valid' : 'Invalid'}</span></div></div>)}{filteredLogs.length === 0 && <EmptyPanel title="Log lokasi kosong" copy="Tidak ada GPS log untuk filter branch ini." />}</div></section>
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

function UserFormModal({ permissions, branches, services, api, onClose, onCreated }: { permissions: Permissions; branches: Branch[]; services: ServiceRow[]; api: ApiClient; onClose: () => void; onCreated: (password: string) => void | Promise<void> }) {
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
    await onCreated(payload.temporary_password)
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

function isRateLimitedError(error: unknown) {
  return error instanceof Error && (/429/.test(error.message) || /too many attempts/i.test(error.message))
}

function chatNoticeTone(message: string) {
  if (/berhasil/i.test(message)) return 'success'
  if (/terlalu banyak|jeda|tunggu/i.test(message)) return 'warning'
  return 'danger'
}

function makeApi(token: string, onUnauthorized?: () => void): ApiClient {
  return async <T,>(path: string, options: RequestInit = {}) => {
    const isFormData = options.body instanceof FormData
    const headers = {
      Accept: 'application/json',
      Authorization: `Bearer ${token}`,
      ...(!isFormData ? { 'Content-Type': 'application/json' } : {}),
      ...(options.headers ?? {}),
    }
    const response = await fetch(`${API_BASE}${path}`, {
      ...options,
      headers,
    })
    const payload = await response.json().catch(() => ({}))
    if (response.status === 401 || response.status === 419) {
      onUnauthorized?.()
      throw new AuthExpiredError(typeof payload.message === 'string' ? payload.message : undefined)
    }
    if (!response.ok) throw new Error(payload.message ? `HTTP ${response.status}: ${payload.message}` : `HTTP ${response.status}`)
    return payload as T
  }
}

let adminEcho: Echo<'reverb'> | null = null

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
  if (adminEcho) return adminEcho

  window.Pusher = Pusher
  const realtime = resolveRealtimeConfig()
  adminEcho = new Echo({
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
  return { dashboard: 'Admin Dashboard', orders: 'Order Operations', 'request-orders': 'Request Order', users: 'User Management', drivers: 'Driver Management', settings: 'System Settings', pricing: 'Pricing & Policy', 'ring-pricing': 'Master Ring', branches: 'Branch Management', geofence: 'Geofence Areas', locations: 'Location Logs', reports: 'Reports', chats: 'Chat Monitor', 'internal-chat': 'Internal Chat', 'sticky-notes': 'Sticky Notes', 'manual-order': 'Manual Order' }[view]
}

function internalNoteStatusLabel(status: InternalNoteStatus) {
  return status === 'open' ? 'Open' : status === 'in_progress' ? 'In progress' : status === 'done' ? 'Done' : 'Archived'
}

function internalNotePriorityLabel(priority: InternalNotePriority) {
  return priority === 'urgent' ? 'Urgent' : priority === 'high' ? 'High' : priority === 'low' ? 'Low' : 'Normal'
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
  return `${order.code} ${order.customer ?? ''} ${order.driver ?? ''} ${order.service} ${displayBranchValue(order.branch, order.branch_area)} ${order.status} ${order.source ?? ''} ${order.cancel_reason ?? ''} ${order.oper_handle?.status ?? ''} ${order.oper_handle?.reason ?? ''}`
    .toLowerCase()
    .includes(searchQuery.toLowerCase())
}

function operHandleStatusLabel(item: OperHandle) {
  if (item.status === 'approved') return 'Approved'
  if (item.operator_approved_at && !item.spv_approved_at) return 'Menunggu SPV'
  if (!item.operator_approved_at && item.spv_approved_at) return 'Menunggu Operator'
  if (item.status === 'pending') return 'Menunggu approval'
  return item.status
}

function chatQueueTime(chat: Chat) {
  return new Date(chat.last_customer_message_at ?? chat.updated_at ?? 0).getTime() || 0
}

function chatSortScore(chat: Chat, queue: Chat[]) {
  if (chat.status === 'waiting') {
    const index = queue.findIndex((item) => item.id === chat.id)
    return index >= 0 ? index : 0
  }

  if (chat.status === 'active') return 10_000 + (Date.now() - new Date(chat.updated_at ?? 0).getTime())
  if (chat.status === 'closed') return 20_000 + (Date.now() - new Date(chat.updated_at ?? 0).getTime())

  return 30_000
}

function chatListSnapshot(chats: Chat[]) {
  return chats
    .map((chat) => `${chat.id}:${chat.updated_at ?? ''}:${chat.last_message ?? chat.latest_message ?? ''}:${chat.unread_count ?? 0}:${chat.status}`)
    .join('|')
}

function openAdminSoundDb(): Promise<IDBDatabase> {
  return new Promise((resolve, reject) => {
    const request = indexedDB.open(ADMIN_SOUND_DB, 1)
    request.onupgradeneeded = () => {
      const db = request.result
      if (!db.objectStoreNames.contains(ADMIN_SOUND_STORE)) db.createObjectStore(ADMIN_SOUND_STORE)
    }
    request.onsuccess = () => resolve(request.result)
    request.onerror = () => reject(request.error ?? new Error('IndexedDB tidak tersedia'))
  })
}

async function adminSoundStore(mode: IDBTransactionMode, work: (store: IDBObjectStore) => IDBRequest) {
  const db = await openAdminSoundDb()
  return new Promise<unknown>((resolve, reject) => {
    const transaction = db.transaction(ADMIN_SOUND_STORE, mode)
    const request = work(transaction.objectStore(ADMIN_SOUND_STORE))
    request.onsuccess = () => resolve(request.result)
    request.onerror = () => reject(request.error ?? new Error('Gagal mengakses audio notifikasi'))
    transaction.oncomplete = () => db.close()
    transaction.onerror = () => {
      db.close()
      reject(transaction.error ?? new Error('Gagal menyimpan audio notifikasi'))
    }
  })
}

async function saveCustomAdminNotificationSound(file: File) {
  if (!file.type.startsWith('audio/')) throw new Error('File harus berupa audio.')
  if (file.size > 2 * 1024 * 1024) throw new Error('Ukuran audio maksimal 2 MB.')

  await adminSoundStore('readwrite', (store) => store.put(file, ADMIN_SOUND_KEY))
}

async function clearCustomAdminNotificationSound() {
  await adminSoundStore('readwrite', (store) => store.delete(ADMIN_SOUND_KEY))
}

async function hasCustomAdminNotificationSound() {
  try {
    const blob = await adminSoundStore('readonly', (store) => store.get(ADMIN_SOUND_KEY))

    return blob instanceof Blob
  } catch {
    return false
  }
}

async function adminNotificationSoundUrl() {
  try {
    const blob = await adminSoundStore('readonly', (store) => store.get(ADMIN_SOUND_KEY))
    if (blob instanceof Blob) return URL.createObjectURL(blob)
  } catch {
    // fallback ke audio default.
  }

  return DEFAULT_ADMIN_NOTIFICATION_SOUND
}

function playAudioUrl(url: string) {
  if (typeof Audio === 'undefined') return
  const audio = new Audio(url)
  audio.preload = 'auto'
  audio.volume = 1
  void audio.play().catch(() => undefined).finally(() => {
    if (url.startsWith('blob:')) window.setTimeout(() => URL.revokeObjectURL(url), 3000)
  })
}

function playAdminNotificationSound(sound: string) {
  if (sound === 'off' || typeof window === 'undefined') return

  if (sound === 'default') {
    playAudioUrl(DEFAULT_ADMIN_NOTIFICATION_SOUND)
    return
  }

  if (sound === 'custom') {
    void adminNotificationSoundUrl().then(playAudioUrl)
    return
  }

  const AudioContextClass = window.AudioContext || (window as typeof window & { webkitAudioContext?: typeof AudioContext }).webkitAudioContext
  if (!AudioContextClass) return

  try {
    const context = new AudioContextClass()
    const oscillator = context.createOscillator()
    const gain = context.createGain()
    const presets: Record<string, { frequency: number; duration: number; type: OscillatorType }> = {
      ding: { frequency: 880, duration: 0.16, type: 'sine' },
      pop: { frequency: 520, duration: 0.11, type: 'triangle' },
      soft: { frequency: 660, duration: 0.2, type: 'sine' },
    }
    const preset = presets[sound] ?? presets.ding

    oscillator.type = preset.type
    oscillator.frequency.value = preset.frequency
    gain.gain.setValueAtTime(0.0001, context.currentTime)
    gain.gain.exponentialRampToValueAtTime(0.12, context.currentTime + 0.02)
    gain.gain.exponentialRampToValueAtTime(0.0001, context.currentTime + preset.duration)
    oscillator.connect(gain)
    gain.connect(context.destination)
    oscillator.start()
    oscillator.stop(context.currentTime + preset.duration)
    window.setTimeout(() => void context.close().catch(() => undefined), Math.ceil((preset.duration + 0.08) * 1000))
  } catch {
    // Browser can block audio until the first user gesture. Chat refresh still runs.
  }
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

function normalizedDriverVehicleTypes(driver: Pick<DriverRow, 'vehicle_type' | 'vehicle_types'>) {
  const types = driver.vehicle_types?.length ? driver.vehicle_types : [driver.vehicle_type ?? 'motor']
  const normalized = Array.from(new Set(types.filter((type): type is string => type === 'motor' || type === 'mobil')))

  return normalized.length > 0 ? normalized : ['motor']
}

function driverVehicleLabel(driver: Pick<DriverRow, 'vehicle_type' | 'vehicle_types' | 'vehicle_seat_rows'>) {
  const labels = normalizedDriverVehicleTypes(driver).map((type) => type === 'mobil' ? `Mobil ${driver.vehicle_seat_rows ?? 2} baris` : 'Motor')

  return labels.join(' + ')
}

function driverPreferenceLabel(preference?: string | null) {
  if (preference === 'ladies') return 'Ladies'
  return 'Umum'
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

function branchGeofenceNames(branch: Branch) {
  const names = branch.geofence_areas?.map((area) => area.name).filter(Boolean) ?? []
  return names.length > 0 ? names.join(', ') : 'Geofence belum diset'
}

function branchLocationKey(value?: string | null) {
  return (value || 'tanpa-branch').trim().toLowerCase()
}

function userBranchLabel(user: User) {
  if (!user.branch) return '-'
  return [user.branch, user.branch_area].filter(Boolean).join(' - ')
}

function formatLocationDistance(value?: number | null) {
  if (value === null || value === undefined) return 'Belum ada pembanding'
  if (value < 250) return 'Tidak berubah signifikan'
  if (value < 1000) return `Berubah ${Math.round(value)} m`
  return `Berubah ${(value / 1000).toLocaleString('id-ID', { maximumFractionDigits: 1 })} km`
}

function locationRiskLabel(risk?: string | null) {
  if (risk === 'mock_location') return 'GPS tidak valid'
  if (risk === 'suspicious') return 'Suspicious'
  if (risk === 'moved_far') return 'Pindah jauh'
  if (risk === 'changed') return 'Lokasi berubah'
  return 'Normal'
}

function locationRiskTone(risk?: string | null) {
  if (risk === 'mock_location' || risk === 'suspicious' || risk === 'moved_far') return 'danger'
  if (risk === 'changed') return 'warning'
  return 'success'
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
    note: 'M5 3h11l3 3v15H5V3Zm10 2v4h4l-4-4ZM8 10v2h8v-2H8Zm0 4v2h8v-2H8Zm0 4v2h5v-2H8Z',
    clip: 'M16.5 6.5v9a4.5 4.5 0 0 1-9 0v-10a3.5 3.5 0 0 1 7 0v9.5a2.5 2.5 0 0 1-5 0V7h2v8a.5.5 0 0 0 1 0V5.5a1.5 1.5 0 0 0-3 0v10a2.5 2.5 0 0 0 5 0v-9h2Z',
    settings: 'M19.4 13.5a7.8 7.8 0 0 0 .1-1.5 7.8 7.8 0 0 0-.1-1.5l2-1.5-2-3.5-2.4 1a7.2 7.2 0 0 0-2.6-1.5L14 2h-4l-.4 2.5A7.2 7.2 0 0 0 7 6L4.6 5 2.6 8.5l2 1.5a7.8 7.8 0 0 0-.1 1.5c0 .5 0 1 .1 1.5l-2 1.5 2 3.5 2.4-1a7.2 7.2 0 0 0 2.6 1.5L10 22h4l.4-2.5A7.2 7.2 0 0 0 17 18l2.4 1 2-3.5-2-1.5ZM12 15.5a3.5 3.5 0 1 1 0-7 3.5 3.5 0 0 1 0 7Z',
    moon: 'M21 14.8A8.5 8.5 0 0 1 9.2 3a7 7 0 1 0 11.8 11.8Z',
    sun: 'M12 7a5 5 0 1 1 0 10 5 5 0 0 1 0-10Zm0-5h2v3h-2V2Zm0 17h2v3h-2v-3ZM2 12h3v2H2v-2Zm17 0h3v2h-3v-2ZM4.2 5.6l1.4-1.4 2.1 2.1-1.4 1.4-2.1-2.1Zm12.1 12.1 1.4-1.4 2.1 2.1-1.4 1.4-2.1-2.1Zm2.1-13.5 1.4 1.4-2.1 2.1-1.4-1.4 2.1-2.1ZM6.3 16.3l1.4 1.4-2.1 2.1-1.4-1.4 2.1-2.1Z',
    logout: 'M5 3h8v2H7v14h6v2H5V3Zm11.6 5.4L21.2 13l-4.6 4.6-1.4-1.4 2.2-2.2H10v-2h7.4l-2.2-2.2 1.4-1.4Z',
  }
  return <svg viewBox="0 0 24 24" aria-hidden="true"><path d={icons[name]} /></svg>
}

export default App

