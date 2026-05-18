import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import type { FormEvent, ReactNode } from 'react'
import { lazy, Suspense } from 'react'
import type { CellValueChangedEvent, ColDef } from 'ag-grid-community'
import Echo from 'laravel-echo'
import Pusher from 'pusher-js'
import 'ag-grid-community/styles/ag-grid.css'
import 'ag-grid-community/styles/ag-theme-quartz.css'
import './App.css'

const LazyAgGridReact = lazy(async () => {
  const [{ AgGridReact }, { AllCommunityModule, ModuleRegistry }] = await Promise.all([
    import('ag-grid-react'),
    import('ag-grid-community'),
  ])

  ModuleRegistry.registerModules([AllCommunityModule])

  return { default: AgGridReact }
})

declare global {
  interface Window {
    Pusher?: typeof Pusher
  }
}

type Role = 'admin' | 'gm' | 'hrd' | 'manager' | 'spv' | 'operator' | 'eksekutor' | 'web_admin' | 'cms_editor' | 'driver' | 'customer'
type View = 'dashboard' | 'orders' | 'request-orders' | 'users' | 'drivers' | 'settings' | 'master-pricing' | 'pricing' | 'price-settings' | 'ring-pricing' | 'keyword-parsers' | 'pricing-keyword-rules' | 'zone-pricing' | 'zone-pricing-tester' | 'branches' | 'geofence' | 'locations' | 'reports' | 'chats' | 'internal-chat' | 'audit-logs' | 'sticky-notes' | 'manual-order' | 'live-price-reviews' | 'order-crew-rules' | 'banners' | 'home-sections' | 'home-items' | 'announcements'
type DriverListMode = 'all' | 'online'
const adminAutoRefreshViews = new Set<View>(['orders', 'request-orders', 'chats', 'internal-chat'])
const adminBootstrapAutoRefreshViews = new Set<View>(['orders', 'request-orders'])
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
  branch_code?: string | null
  branch_area?: string | null
  branch_display_name?: string | null
  branch_scope_ids?: number[]
  branch_scopes?: Array<Pick<Branch, 'id' | 'branch_code' | 'name' | 'area' | 'display_name'>>
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
  branch_code?: string | null
  branch_display_name?: string | null
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
  can_accept_all_areas?: boolean
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
  can_repost_dispatch?: boolean
  dispatch_repost_count?: number
  dispatch_repost_remaining?: number
  last_reposted_at?: string | null
  last_reposted_by?: string | null
  dispatch_repost_history?: Array<{ count?: number; actor_name?: string; actor_role?: string; reposted_at?: string }>
  branch: string | null
  branch_code?: string | null
  branch_area?: string | null
  branch_display_name?: string | null
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
  crew_decision?: Record<string, unknown> | null
  crew_status?: string | null
  crews?: Array<{ id: number; role: string; label: string; status: string; driver?: string | null; service_charge?: number; accepted_at?: string | null }>
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
  branch_code?: string | null
  branch_area?: string | null
  branch_display_name?: string | null
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

type Branch = {
  id: number
  parent_branch_id?: number | null
  branch_code?: string | null
  name: string
  area: string | null
  display_name?: string | null
  latitude: string
  longitude: string
  radius_km?: string | number | null
  geofence_areas_count?: number
  geofence_areas?: Array<{ id: number; name: string }>
  parent?: Pick<Branch, 'id' | 'branch_code' | 'name' | 'area' | 'display_name'> | null
  children?: Array<Pick<Branch, 'id' | 'branch_code' | 'name' | 'area' | 'display_name'>>
  is_regency?: boolean
  is_operational_area?: boolean
}
type ServiceRow = { id: number; name: string; code: string; outside_area_only?: boolean }
type PriceSetting = { id: number; name: string; branch_id: number | null; min_km: string; max_km: string | null; price: number | null; is_formula: boolean; per_km_rate: number | null; subtract_value: number | null; is_active?: boolean; branch?: Branch | null }
type KeywordParser = { id: number; keyword: string; service_type: string; response_template: string; form_schema?: { fields?: Array<{ label?: string; name?: string; type?: string; required?: boolean; options?: string[] }> } | null; parser_type: 'simple' | 'advanced' | string; is_active: boolean; priority: number; created_at?: string | null; updated_at?: string | null }
type PricingKeywordRule = { id: number; name: string; keywords: string; amount: number; service_scopes?: string[] | null; is_active: boolean; priority: number; description?: string | null; created_at?: string | null; updated_at?: string | null }
type RingPricingRule = { id: number; branch_id: number | null; branch?: Pick<Branch, 'id' | 'branch_code' | 'name' | 'area' | 'display_name'> | null; service_type?: string | null; name: string; area_mode?: 'text' | 'polygon' | string; pickup_area: string; destination_area: string; pickup_aliases?: string[]; destination_aliases?: string[]; polygon_coordinates?: Array<{ lat: number; lng: number }>; polygon_match_point?: string | null; match_type?: 'point' | 'cross' | string; pickup_ring?: string | null; destination_ring?: string | null; ring: string; min_km?: string | number | null; max_km?: string | number | null; pricing_mode?: 'flat' | 'formula' | string; price: number; per_km_rate?: number | null; subtract_value?: number | null; service_fee?: number | null; priority?: number | null; is_bidirectional: boolean; source: string; is_active: boolean; created_at?: string | null; updated_at?: string | null }
type RingPricingSuggestion = { id: number; branch_id: number | null; branch?: Pick<Branch, 'id' | 'name' | 'area'> | null; service_type?: string | null; pickup_area: string; destination_area: string; ring?: string | null; suggestion_type?: string | null; learning_source?: string | null; suggested_price: number; previous_price?: number | null; system_price?: number | null; price_delta?: number | null; confidence?: number | null; occurrence_count: number; sample_order_ids?: number[]; evidence?: Record<string, unknown> | null; last_order_code?: string | null; last_edited_by?: string | null; status: string; created_at?: string | null; updated_at?: string | null }
type LivePriceReview = {
  id: number
  token: string
  status: 'pending' | 'approved' | 'rejected' | 'consumed' | 'cancelled' | string
  service_type?: string | null
  customer?: string | null
  branch?: string | null
  raw_text?: string | null
  parsed?: Record<string, unknown> | null
  system_price: number
  system_service_fee: number
  system_total_price: number
  corrected_price?: number | null
  corrected_service_fee?: number | null
  corrected_extra_charge?: number | null
  corrected_total_price?: number | null
  correction_reason?: string | null
  reviewed_by?: string | null
  order_id?: number | null
  order_code?: string | null
  confirmation_available_at?: string | null
  can_confirm?: boolean
  order_payload?: ManualOrderPayload | null
  quote?: ManualOrderPreview['quote']
  reviewed_at?: string | null
  consumed_at?: string | null
  created_at?: string | null
  updated_at?: string | null
}

const visibleLivePriceReviewStatuses = new Set(['pending', 'cancelled'])
const editableLivePriceReviewStatuses = new Set(['pending'])
type Geofence = { id: number; name: string; branch?: Branch | null; center_latitude: string; center_longitude: string; radius_meters: number; shape_type?: 'circle' | 'polygon' | string; polygon_coordinates?: Array<{ lat: number; lng: number }> | null; is_active: boolean }
type ZonePricingRule = {
  id: number
  name: string
  branch_id: number | null
  branch?: Pick<Branch, 'id' | 'name' | 'area'> | null
  geofence_area_id: number
  geofence_area?: Pick<Geofence, 'id' | 'name' | 'shape_type' | 'radius_meters'> & { branch?: Pick<Branch, 'id' | 'name' | 'area'> | null } | null
  service_type?: string | null
  match_point: 'destination' | 'pickup' | 'either' | 'both' | string
  price_mode: 'fixed' | 'extra' | 'percent' | string
  amount: number
  percent?: number | null
  min_km?: number | null
  max_km?: number | null
  is_active: boolean
  priority: number
  notes?: string | null
  created_at?: string | null
  updated_at?: string | null
}
type ZonePricingTesterResult = {
  pickup?: ZonePricingPoint | null
  destination?: ZonePricingPoint | null
  branch_id?: number | null
  branch?: Pick<Branch, 'id' | 'name' | 'area'> | null
  quote?: Record<string, unknown> | null
}
type ZonePricingPoint = {
  area?: { id: number; name: string; shape_type?: string | null; radius_meters?: number | null } | null
  branch?: Pick<Branch, 'id' | 'name' | 'area'> | null
  distance_meters?: number | null
}
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
  multi_crew_auto_cancel_enabled?: boolean
  multi_crew_auto_cancel_minutes?: number
  multi_crew_auto_cancel_message?: string
  driver_daily_priority_enabled?: boolean
  driver_daily_priority_hold_minutes?: number
  driver_daily_priority_windows?: DailyPriorityWindow[]
  night_tariff_enabled?: boolean
  night_tariff_rules?: NightTariffRule[]
  zone_pricing_enabled?: boolean
  live_price_review_enabled?: boolean
  live_price_review_delay_seconds?: number
  assign_driver_allowed_roles?: Role[]
  edit_tarif_allowed_roles?: Role[]
  feedback_templates?: {
    driver_accepted?: string
    order_auto_cancelled?: string
    order_cancelled?: string
  }
}
type NightTariffRule = { area?: string | null; start: string; end: string; percent: number }
type DailyPriorityWindow = { start: string; end: string }
type DepositReportRow = {
  driver_id: number
  deposit_id?: number | null
  driver: string
  driver_name?: string | null
  vehicle_type?: 'motor' | 'mobil' | string | null
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
  status?: 'paid' | 'unpaid' | string | null
  manual_override?: boolean
  next_cashback: number
}
type Permissions = {
  backend_access: boolean
  names: string[]
  assignable_roles: Role[]
  can_manage_policy: boolean
  can_manage_ring_pricing?: boolean
  can_manage_users: boolean
  can_manage_all_branches?: boolean
  can_suspend_drivers: boolean
  can_unsuspend_drivers?: boolean
  can_manage_driver_deposit?: boolean
  can_update_driver_config?: boolean
  can_manage_driver_auth?: boolean
  can_manage_system_settings: boolean
  can_manage_cms?: boolean
  can_edit_order_price: boolean
  can_create_manual_order: boolean
  can_view_report?: boolean
  can_export_report?: boolean
  can_monitor_live_order?: boolean
  can_monitor_live_chat?: boolean
  can_view_dispatch_repost_audit?: boolean
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
  keyword_parsers?: KeywordParser[]
  pricing_keyword_rules?: PricingKeywordRule[]
  ring_pricing_rules?: RingPricingRule[]
  ring_pricing_suggestions?: RingPricingSuggestion[]
  live_price_reviews?: LivePriceReview[]
  zone_pricing_rules?: ZonePricingRule[]
  geofences: Geofence[]
  location_logs: LocationLog[]
  chats: Chat[]
  audit_logs: AuditLog[]
}
type UserIndexResponse = {
  data: {
    data: User[]
    total?: number
  }
}
type AdminHomeBanner = {
  id: number
  title: string
  image?: string | null
  image_original?: string | null
  link?: string | null
  order?: number
  start_date?: string | null
  end_date?: string | null
  is_active?: boolean
}
type AdminHomeItem = {
  id: number
  section_id?: number
  title: string
  subtitle?: string | null
  image?: string | null
  image_original?: string | null
  icon?: string | null
  link?: string | null
  order?: number
  start_date?: string | null
  end_date?: string | null
  is_active?: boolean
}
type AdminHomeSection = {
  id: number
  name: string
  type: string
  order?: number
  is_active?: boolean
  items?: AdminHomeItem[]
}
type AdminAnnouncement = {
  id: number
  title: string
  content: string
  start_date?: string | null
  end_date?: string | null
  is_active?: boolean
}
type AdminHomeCmsData = {
  banners: AdminHomeBanner[]
  sections: AdminHomeSection[]
  items?: AdminHomeItem[]
  announcements: AdminAnnouncement[]
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
  web_admin: 'Web Admin',
  cms_editor: 'CMS Editor',
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
  web_admin: 'role-cyan',
  cms_editor: 'role-cyan',
  driver: 'role-green',
  customer: 'role-muted',
}

const knownRoles = Object.keys(roleLabels) as Role[]
const passwordEditableRoles = new Set<Role>(['admin', 'gm', 'hrd', 'manager', 'spv', 'operator', 'eksekutor', 'web_admin', 'cms_editor'])
const branchScopeRoles = new Set<Role>(['hrd', 'manager', 'spv', 'eksekutor'])

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
      { id: 'audit-logs', label: 'Audit Logs', icon: 'receipt' },
      { id: 'sticky-notes', label: 'Sticky Notes', icon: 'note' },
      { id: 'manual-order', label: 'Manual Order', icon: 'plus' },
      { id: 'live-price-reviews', label: 'Live Edit Harga', icon: 'cash' },
    ],
  },
  {
    id: 'management',
    label: 'Management',
    icon: 'users',
    items: [
      { id: 'users', label: 'Users', icon: 'users' },
      { id: 'drivers', label: 'Driver Management', icon: 'truck' },
    ],
  },
  {
    id: 'home-cms',
    label: 'Home',
    icon: 'note',
    items: [
      { id: 'banners', label: 'Banners', icon: 'note' },
      { id: 'home-sections', label: 'Home Sections', icon: 'note' },
      { id: 'home-items', label: 'Home Items', icon: 'note' },
      { id: 'announcements', label: 'Announcements', icon: 'note' },
    ],
  },
  {
    id: 'pricing-cms',
    label: 'Pricing',
    icon: 'cash',
    items: [
      { id: 'pricing-keyword-rules', label: 'Pricing Keyword Rules', icon: 'note' },
    ],
  },
  {
    id: 'jojobot-cms',
    label: 'JojoBot',
    icon: 'note',
    items: [
      { id: 'keyword-parsers', label: 'Keyword Parsers', icon: 'note' },
      { id: 'order-crew-rules', label: 'Order Crew Rules', icon: 'settings' },
    ],
  },
  {
    id: 'area',
    label: 'Area',
    icon: 'map',
    items: [
      { id: 'branches', label: 'Branches', icon: 'building' },
      { id: 'geofence', label: 'Geofence', icon: 'map' },
      { id: 'locations', label: 'Location Logs', icon: 'pin' },
    ],
  },
  {
    id: 'system-cms',
    label: 'System',
    icon: 'settings',
    items: [
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
  if (permissions.can_suspend_drivers || permissions.can_unsuspend_drivers || permissions.can_manage_driver_deposit || permissions.can_update_driver_config) views.add('drivers')
  if (permissions.can_edit_order_price || permissions.can_manage_policy) views.add('pricing')
  if (permissions.can_edit_order_price || permissions.can_manage_policy) {
    views.add('keyword-parsers')
    views.add('pricing-keyword-rules')
  }
  if (permissions.can_view_report) views.add('reports')
  if (permissions.can_monitor_live_chat) views.add('chats')
  if (permissions.can_use_internal_chat) views.add('internal-chat')
  if (permissions.can_use_internal_chat || permissions.can_view_report) views.add('audit-logs')
  if (permissions.can_use_internal_notes) views.add('sticky-notes')
  if (permissions.can_create_manual_order) {
    views.add('manual-order')
    views.add('live-price-reviews')
  }
  if (permissions.can_manage_system_settings) {
    views.add('settings')
  }
  if (permissions.can_manage_cms) {
    views.add('banners')
    views.add('home-sections')
    views.add('home-items')
    views.add('announcements')
  }
  if (['manager', 'spv', 'operator'].includes(role)) views.add('locations')

  return allMenus.map((item) => item.id).filter((id) => views.has(id))
}

function App() {
  const [token, setToken] = useState(() => localStorage.getItem('admin_token') || localStorage.getItem('token') || '')
  const [data, setData] = useState<Bootstrap | null>(null)
  const [view, setView] = useState<View>('dashboard')
  const [driverListMode, setDriverListMode] = useState<DriverListMode>('all')
  const [query, setQuery] = useState('')
  const [roleFilter, setRoleFilter] = useState<Role | 'all'>('all')
  const [serverUsers, setServerUsers] = useState<User[] | null>(null)
  const [serverUsersTotal, setServerUsersTotal] = useState<number | null>(null)
  const [usersLoading, setUsersLoading] = useState(false)
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
    'pricing-cms': true,
    'jojobot-cms': false,
    area: false,
    'system-cms': false,
  })
  const [adminNotice, setAdminNotice] = useState('')
  const [lastSyncedAt, setLastSyncedAt] = useState<Date | null>(null)
  const isRefreshingRef = useRef(false)
  const isBrowserBackRef = useRef(false)
  const lastOperHandlePendingRef = useRef<number | null>(null)

  const clearAuthSession = useCallback(() => {
    resetAdminEcho()
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
      const payload = await api<Bootstrap>(`/admin/bootstrap?view=${encodeURIComponent(view)}`)
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
  }, [api, token, view])

  useEffect(() => {
    const timer = window.setTimeout(() => {
      void load()
    }, 0)

    return () => window.clearTimeout(timer)
  }, [load])

  useEffect(() => {
    if (!token || !adminAutoRefreshViews.has(view)) return

    const refreshWhenVisible = () => {
      if (document.visibilityState === 'visible') void load(true)
    }

    document.addEventListener('visibilitychange', refreshWhenVisible)

    return () => {
      document.removeEventListener('visibilitychange', refreshWhenVisible)
    }
  }, [load, token, view])

  useEffect(() => {
    if (!token) return

    const refreshBootstrap = () => {
      if (document.visibilityState === 'visible') void load(true)
    }

    window.addEventListener('focus', refreshBootstrap)
    document.addEventListener('visibilitychange', refreshBootstrap)

    return () => {
      window.removeEventListener('focus', refreshBootstrap)
      document.removeEventListener('visibilitychange', refreshBootstrap)
    }
  }, [load, token])

  useEffect(() => {
    if (!token || !adminBootstrapAutoRefreshViews.has(view)) return

    const interval = window.setInterval(() => void load(true), 10000)

    return () => window.clearInterval(interval)
  }, [load, token, view])

  useEffect(() => {
    if (!token || !adminAutoRefreshViews.has(view)) return

    const echo = makeEcho(token)
    const ordersChannel = echo.private('orders')
    ordersChannel.listen('.order.created', () => void load(true))
    ordersChannel.listen('.order.status.updated', () => void load(true))
    ordersChannel.listen('.driver.accepted', () => void load(true))

    return () => {
      echo.leave('orders')
    }
  }, [load, token, view])

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
  const { current: buildInfo, update: updateInfo } = useBuildUpdate('admin')

  useEffect(() => {
    if (!token || !data || safeView !== 'users') {
      setServerUsers(null)
      setServerUsersTotal(null)
      setUsersLoading(false)
      return
    }

    let cancelled = false
    const timer = window.setTimeout(() => {
      const params = new URLSearchParams({ per_page: '100' })
      const trimmedQuery = query.trim()
      if (trimmedQuery !== '') params.set('q', trimmedQuery)
      if (roleFilter !== 'all') params.set('role', roleFilter)

      setUsersLoading(true)
      void api<UserIndexResponse>(`/admin/users?${params.toString()}`)
        .then((payload) => {
          if (cancelled) return
          setServerUsers(payload.data.data)
          setServerUsersTotal(payload.data.total ?? payload.data.data.length)
        })
        .catch((error) => {
          if (cancelled || isAuthError(error)) return
          setError(error instanceof Error ? error.message : 'Gagal memuat data user')
        })
        .finally(() => {
          if (!cancelled) setUsersLoading(false)
        })
    }, 250)

    return () => {
      cancelled = true
      window.clearTimeout(timer)
    }
  }, [api, data, query, roleFilter, safeView, token])

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
  const userRows = serverUsers ?? data.users
  const filteredUsers = userRows.filter((user) => {
    const text = `${user.username} ${user.name} ${user.email} ${user.phone ?? ''} ${user.address ?? ''} ${user.branch ?? ''} ${user.branch_code ?? ''} ${user.branch_area ?? ''} ${user.branch_display_name ?? ''}`.toLowerCase()
    return text.includes(query.toLowerCase()) && (roleFilter === 'all' || user.role === roleFilter)
  })
  const pendingOperHandles = (data.oper_handles ?? []).filter((item) => item.status === 'pending')
  const isAutoRefreshActive = adminAutoRefreshViews.has(safeView)

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
                      <button key={item.id} type="button" className={safeView === item.id ? 'nav-item active' : 'nav-item'} onClick={() => { if (item.id === 'drivers') setDriverListMode('all'); setView(item.id); setMobileNavOpen(false) }}>
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
              {isAutoRefreshActive && (
                <div className="auto-refresh-pill" title="Auto refresh aktif hanya di area operasional/chat">
                  <span />
                  Auto refresh
                  <small>{lastSyncedAt ? formatShortTime(lastSyncedAt.toISOString()) : 'sync'}</small>
                </div>
              )}
              <button className="theme-switch" type="button" onClick={toggleDarkMode} aria-label={darkMode ? 'Switch to light theme' : 'Switch to dark theme'} title={darkMode ? 'Light theme' : 'Dark theme'}>
                <span><Icon name={darkMode ? 'sun' : 'moon'} /></span>
              </button>
            </div>
          </header>

        {adminNotice && <div className="dispatch-toast oper-handle-toast">{adminNotice}</div>}
        {safeView === 'dashboard' && <Dashboard data={data} api={api} buildInfo={buildInfo} onChanged={refresh} onNavigate={setView} onOpenDrivers={(mode) => { setDriverListMode(mode); setView('drivers') }} onOpenOrder={(code) => { setQuery(code); setView('orders') }} />}
        {safeView === 'orders' && <OrdersTable orders={data.orders} operHandles={data.oper_handles ?? []} auditLogs={data.audit_logs} searchQuery={query} permissions={data.permissions} api={api} onChanged={refresh} onOpenDriverChat={(driverUserId) => { setChatDriverTargetId(driverUserId); setView('chats') }} />}
        {safeView === 'request-orders' && <RequestOrdersPanel orders={data.orders} searchQuery={query} permissions={data.permissions} onOpenDriverChat={(driverUserId) => { setChatDriverTargetId(driverUserId); setView('chats') }} />}
        {safeView === 'users' && <UsersPanel users={filteredUsers} totalUsers={serverUsersTotal} isLoading={usersLoading} branches={data.branches} me={data.me} roleFilter={roleFilter} onRoleFilterChange={setRoleFilter} permissions={data.permissions} api={api} onChanged={refresh} />}
        {safeView === 'drivers' && <DriverManagementPanel drivers={data.drivers} services={data.services} permissions={data.permissions} api={api} onChanged={refresh} initialListMode={driverListMode} />}
        {safeView === 'settings' && <SystemSettingsPanel settings={data.system_settings} permissions={data.permissions} api={api} onChanged={refresh} />}
        {isBackendCmsView(safeView) && <BackendCmsLinkPanel view={safeView} />}
        {safeView === 'keyword-parsers' && <KeywordParsersPanel parsers={data.keyword_parsers ?? []} services={data.services} permissions={data.permissions} api={api} onChanged={refresh} />}
        {safeView === 'pricing-keyword-rules' && <PricingKeywordRulesPanel rules={data.pricing_keyword_rules ?? []} services={data.services} permissions={data.permissions} api={api} onChanged={refresh} />}
        {safeView === 'zone-pricing' && <ZonePricingPanel rules={data.zone_pricing_rules ?? []} branches={data.branches} geofences={data.geofences} services={data.services} permissions={data.permissions} api={api} onChanged={refresh} />}
        {safeView === 'zone-pricing-tester' && <ZonePricingTesterPanel branches={data.branches} geofences={data.geofences} services={data.services} api={api} />}
        {safeView === 'reports' && <ReportsPanel data={data} api={api} token={token} />}
        {safeView === 'chats' && <AdminChatPanel initialChats={data.chats} api={api} me={data.me} token={token} permissions={data.permissions} notificationSound={notificationSound} targetDriverUserId={chatDriverTargetId} onTargetDriverHandled={clearChatDriverTarget} onOpenOrder={(code) => { setQuery(code); setView('orders') }} />}
        {safeView === 'internal-chat' && <InternalChatPanel api={api} me={data.me} branches={data.branches} users={data.users} orders={data.orders} onOpenOrder={(code) => { setQuery(code); setView('orders') }} />}
        {safeView === 'audit-logs' && <AuditLogsPanel initialLogs={data.audit_logs} api={api} />}
        {safeView === 'sticky-notes' && <StickyNotesPanel api={api} me={data.me} users={data.users} branches={data.branches} />}
        {safeView === 'manual-order' && <ManualOrderPanel me={data.me} branches={data.branches} api={api} onChanged={refresh} />}
        {safeView === 'live-price-reviews' && <LivePriceReviewPanel reviews={data.live_price_reviews ?? []} api={api} onChanged={refresh} />}
        {safeView === 'branches' && <BranchesPanel branches={data.branches} me={data.me} api={api} onChanged={refresh} />}
        {safeView === 'geofence' && <GeofencePanel geofences={data.geofences} />}
        {safeView === 'locations' && <LocationLogsPanel logs={data.location_logs} branches={data.branches} canViewMaps={data.me.role === 'admin'} />}
      </main>

      <AppUpdateNotice update={updateInfo} />
      {isUserFormOpen && <UserFormModal me={data.me} permissions={data.permissions} branches={data.branches} services={data.services} api={api} onClose={() => setUserFormOpen(false)} onCreated={async (password) => { alert(`Password sementara: ${password}`); await refresh(); setUserFormOpen(false) }} />}
      {isProfileOpen && <AdminProfileModal me={data.me} api={api} darkMode={darkMode} notificationSound={notificationSound} onDarkModeChange={toggleDarkMode} onNotificationSoundChange={(value) => { localStorage.setItem('admin_notification_sound', value); setNotificationSound(value); if (value !== 'off') playAdminNotificationSound(value) }} onClose={() => setProfileOpen(false)} onSaved={refresh} />}
    </div>
  )
}

type BuildInfo = {
  app: string
  sha: string
  full_sha?: string
  message?: string
  committed_at?: string
  history?: BuildHistoryItem[]
  built_at?: string
}
type BuildHistoryItem = {
  sha: string
  full_sha?: string
  message?: string
  committed_at?: string
}

function useBuildUpdate(appName: string) {
  const [current, setCurrent] = useState<BuildInfo | null>(null)
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
          setCurrent(latest)
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

  return { current, update }
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
        <PasswordInput label="Password" required autoComplete="current-password" minLength={1} />
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

function Dashboard({ data, api, buildInfo, onChanged, onNavigate, onOpenDrivers, onOpenOrder }: { data: Bootstrap; api: ApiClient; buildInfo: BuildInfo | null; onChanged: () => Promise<void>; onNavigate: (view: View) => void; onOpenDrivers: (mode: DriverListMode) => void; onOpenOrder: (code: string) => void }) {
  if (data.me.role === 'eksekutor') {
    return <EksekutorDashboard data={data} api={api} onChanged={onChanged} onNavigate={onNavigate} onOpenOrder={onOpenOrder} />
  }

  const activeOrders = data.orders.filter(isActiveOrderStatus).length || data.stats.active_orders
  const onlineDrivers = data.drivers.filter((driver) => driver.driver_state === 'online' && driver.driver_status === 'active' && driver.is_active && !driver.is_suspended).length
  const unassignedOrders = data.orders.filter((order) => isWaitingDriverStatus(order.status) && !order.driver).length
  const unansweredChats = data.chats.filter((chat) => Number(chat.unread_count ?? 0) > 0 || ['waiting', 'open'].includes(String(chat.status).toLowerCase())).length
  const pendingOperHandles = (data.oper_handles ?? []).filter((item) => item.status === 'pending').length
  const nightTariffActive = isNightTariffCurrentlyActive(data.system_settings)
  const topDriver = topDriverToday(data.drivers, data.orders)

  return (
    <div className="dashboard-grid">
      {['admin', 'gm'].includes(data.me.role) && <AdminUpdateStatusCard buildInfo={buildInfo} />}
      <StatsRow stats={[
        { label: 'Active Order Realtime', value: activeOrders, icon: 'bag', tone: 'amber', action: 'Orders', onClick: () => onNavigate('orders') },
        { label: 'Online Driver', value: onlineDrivers, icon: 'truck', tone: 'green', action: 'Drivers', onClick: () => onOpenDrivers('online') },
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
      <DashboardLivePriceReview reviews={data.live_price_reviews ?? []} api={api} onChanged={onChanged} onNavigate={() => onNavigate('live-price-reviews')} />
      <DriverPerformanceSnapshot drivers={data.drivers} onOpenDrivers={() => onOpenDrivers('all')} />
      <OperatorPerformanceSnapshot operators={data.operator_performance ?? []} onOpenChats={() => onNavigate('chats')} />
      <RecentActivity orders={data.orders} onOpenOrder={onOpenOrder} />
      <PriceEditActivity auditLogs={data.audit_logs} />
      {data.permissions.can_view_dispatch_repost_audit && <DispatchRepostActivity auditLogs={data.audit_logs} />}
    </div>
  )
}

function AdminUpdateStatusCard({ buildInfo }: { buildInfo: BuildInfo | null }) {
  const [detailOpen, setDetailOpen] = useState(false)
  const detail = buildInfoDetail(buildInfo)

  return (
    <>
      <button className="admin-update-status-card" type="button" onClick={() => setDetailOpen(true)}>
        <div className="admin-update-status-copy">
          <span>Status update terbaru</span>
          <strong>{detail.title}</strong>
          <small>
            {detail.version}
            {detail.commitTime ? ` - commit ${detail.commitTime}` : ''}
            {detail.buildTime ? ` - build ${detail.buildTime}` : ''}
          </small>
        </div>
        <div className="admin-update-status-badge">
          <b>{buildInfo?.app?.toUpperCase() || 'ADMIN'}</b>
          <span>{detail.sha || 'sync'}</span>
        </div>
      </button>
      {detailOpen && <AdminUpdateDetailModal buildInfo={buildInfo} onClose={() => setDetailOpen(false)} />}
    </>
  )
}

function AdminUpdateDetailModal({ buildInfo, onClose }: { buildInfo: BuildInfo | null; onClose: () => void }) {
  const detail = buildInfoDetail(buildInfo)
  const history = buildInfoHistory(buildInfo)

  return (
    <div className="modal-backdrop" role="presentation">
      <div className="modal admin-update-detail-modal" role="dialog" aria-modal="true">
        <div className="modal-header">
          <div>
            <h2>Detail Update Terbaru</h2>
            <p>Informasi ini hanya ditampilkan untuk Admin dan GM.</p>
          </div>
          <button type="button" className="icon-button" onClick={onClose}><Icon name="close" /></button>
        </div>
        <div className="admin-update-detail-body">
          <section>
            <span>Ringkasan</span>
            <strong>{detail.title}</strong>
            <p>{detail.description}</p>
          </section>
          <div className="admin-update-detail-grid">
            <InfoBox label="Aplikasi" value={buildInfo?.app?.toUpperCase() || 'ADMIN'} />
            <InfoBox label="Versi" value={detail.sha || 'Belum terbaca'} />
            <InfoBox label="Waktu commit" value={detail.commitTime || 'Belum tersedia'} />
            <InfoBox label="Waktu build" value={detail.buildTime || 'Belum tersedia'} />
          </div>
          <section className="admin-update-history-section">
            <span>3 history update terakhir</span>
            <div className="admin-update-history-list">
              {history.length === 0 && (
                <article className="admin-update-history-item">
                  <b>i</b>
                  <div>
                    <strong>Belum ada pembaruan aplikasi publik</strong>
                    <p>Pembaruan internal khusus superadmin tidak ditampilkan di dashboard Admin/GM.</p>
                  </div>
                </article>
              )}
              {history.map((item, index) => (
                <article key={`${item.sha}-${index}`} className="admin-update-history-item">
                  <b>{index + 1}</b>
                  <div>
                    <strong>{translateBuildMessage(item.message)}</strong>
                    <p>{buildHistoryDescription(item)}</p>
                    <small>Versi {item.sha || '-'}{item.committed_at ? ` - commit ${formatShortDateTime(item.committed_at)}` : ''}</small>
                  </div>
                </article>
              ))}
            </div>
          </section>
          <label>
            Hash lengkap
            <input value={detail.fullSha || detail.sha || 'Belum tersedia'} readOnly />
          </label>
        </div>
      </div>
    </div>
  )
}

function InfoBox({ label, value }: { label: string; value: string }) {
  return <div className="admin-update-info-box"><span>{label}</span><strong>{value}</strong></div>
}

function StatsRow({ stats }: { stats: { label: string; value: number; icon: string; tone: string; action?: string; onClick?: () => void }[] }) {
  return <section className="stats-row">{stats.map((stat) => <article className={`stat-card ${stat.tone}`} key={stat.label} role={stat.onClick ? 'button' : undefined} tabIndex={stat.onClick ? 0 : undefined} onClick={stat.onClick} onKeyDown={(event) => { if (stat.onClick && (event.key === 'Enter' || event.key === ' ')) stat.onClick() }}><div className="stat-icon"><Icon name={stat.icon} /></div><span>{stat.label}</span><strong>{stat.value}</strong>{stat.action && <small className="stat-action">{stat.action}</small>}<div className="sparkline"><i></i><i></i><i></i><i></i><i></i></div></article>)}</section>
}

function EksekutorDashboard({ data, api, onChanged, onNavigate, onOpenOrder }: { data: Bootstrap; api: ApiClient; onChanged: () => Promise<void>; onNavigate: (view: View) => void; onOpenOrder: (code: string) => void }) {
  const [assignOrder, setAssignOrder] = useState<Order | null>(null)
  const [dispatchMessage, setDispatchMessage] = useState('')
  const dispatchOrders = data.orders.filter(isDispatchPendingOrder)
  const criticalOrders = dispatchOrders.filter((order) => order.sla_status === 'critical' || Number(order.waiting_seconds ?? 0) >= 600)
  const firstAssignableOrder = dispatchOrders.find((order) => !isDispatchRepostOrder(order))
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
                  <span>
                    {order.branch_area || order.branch || '-'} - {isDispatchRepostOrder(order) ? 'butuh release/repost' : statusDispatchLabel(order.status)} - waiting {formatWaitingTime(order.waiting_seconds)}
                    {Number(order.dispatch_repost_count ?? 0) > 0 ? ` - repost ${order.dispatch_repost_count}/4` : ''}
                  </span>
                </div>
                <div className="suggested-driver">
                  <small>Suggested</small>
                  <b>{order.suggested_drivers?.[0]?.name ?? 'Belum ada idle driver'}</b>
                </div>
                <div className="dispatch-row-actions">
                  {isDispatchRepostOrder(order) ? (
                    <button className="primary-button compact" type="button" onClick={() => void repostDispatchOrder(api, order, setDispatchMessage, onChanged)}>Release/Repost</button>
                  ) : (
                    <>
                      <button className="secondary-button compact" type="button" onClick={() => void broadcastOrderToDrivers(api, order, setDispatchMessage)}>Broadcast</button>
                      <button className="primary-button compact" type="button" onClick={() => setAssignOrder(order)}>Assign Driver</button>
                    </>
                  )}
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
        <button type="button" disabled={!firstAssignableOrder} onClick={() => firstAssignableOrder && setAssignOrder(firstAssignableOrder)}><Icon name="truck" />Assign Driver</button>
        <button type="button" disabled={!dispatchOrders[0]} onClick={() => {
          const first = dispatchOrders[0]
          if (!first) return
          if (isDispatchRepostOrder(first)) {
            void repostDispatchOrder(api, first, setDispatchMessage, onChanged)
            return
          }
          void broadcastOrderToDrivers(api, first, setDispatchMessage)
        }}><Icon name="shield" />{isDispatchRepostOrder(dispatchOrders[0]) ? 'Release' : 'Broadcast Driver'}</button>
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

async function repostDispatchOrder(api: ApiClient, order: Order, setMessage: (value: string) => void, onChanged: () => Promise<void>) {
  try {
    const payload = await api<{ message?: string }>(`/admin/orders/${order.id}/repost-dispatch`, { method: 'POST' })
    setMessage(payload.message ?? 'Order berhasil direpost.')
    await onChanged()
  } catch (error) {
    setMessage(error instanceof Error ? error.message : 'Release/repost order gagal.')
  } finally {
    window.setTimeout(() => setMessage(''), 3200)
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
          <div><h2>Assign Driver</h2><p>{order.code} - {order.customer || 'Customer'} - {order.branch_area || order.branch}</p></div>
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

function DashboardLivePriceReview({ reviews, api, onChanged, onNavigate }: { reviews: LivePriceReview[]; api: ApiClient; onChanged: () => Promise<void>; onNavigate: () => void }) {
  const initialRows = reviews.filter((review) => visibleLivePriceReviewStatuses.has(review.status))
  const [rows, setRows] = useState<LivePriceReview[]>(initialRows)
  const [selectedId, setSelectedId] = useState<number | null>(initialRows[0]?.id ?? null)
  const [savingId, setSavingId] = useState<number | null>(null)
  const [isEditing, setIsEditing] = useState(false)
  const syncingRef = useRef(false)

  useEffect(() => {
    const activeRows = reviews.filter((review) => visibleLivePriceReviewStatuses.has(review.status))
    setRows(activeRows)
    setSelectedId((current) => (current && activeRows.some((review) => review.id === current)) ? current : (activeRows[0]?.id ?? null))
  }, [reviews])

  const syncReviews = useCallback(async () => {
    if (syncingRef.current || savingId || isEditing || document.visibilityState !== 'visible') return
    syncingRef.current = true
    try {
      const response = await api<{ data: LivePriceReview[] }>('/admin/live-price-reviews')
      const activeRows = response.data.filter((review) => visibleLivePriceReviewStatuses.has(review.status))
      const currentIds = rows.map((review) => `${review.id}:${review.status}`).join('|')
      const nextIds = activeRows.map((review) => `${review.id}:${review.status}`).join('|')
      setRows(activeRows)
      setSelectedId((current) => (current && activeRows.some((review) => review.id === current)) ? current : (activeRows[0]?.id ?? null))
      if (currentIds !== nextIds) await onChanged()
    } finally {
      syncingRef.current = false
    }
  }, [api, isEditing, onChanged, rows, savingId])

  useEffect(() => {
    const interval = window.setInterval(() => void syncReviews(), 7000)
    const onFocus = () => void syncReviews()

    window.addEventListener('focus', onFocus)
    document.addEventListener('visibilitychange', onFocus)

    return () => {
      window.clearInterval(interval)
      window.removeEventListener('focus', onFocus)
      document.removeEventListener('visibilitychange', onFocus)
    }
  }, [syncReviews])

  const selected = rows.find((review) => review.id === selectedId) ?? rows[0] ?? null
  const payload = selected?.order_payload ?? null
  const correctedPrice = Number(selected?.corrected_price ?? selected?.system_price ?? 0)
  const correctedFee = Number(selected?.corrected_service_fee ?? selected?.system_service_fee ?? 0)
  const correctedExtra = Number(selected?.corrected_extra_charge ?? 0)
  const correctedTotal = correctedPrice + correctedFee + correctedExtra

  const updateSelected = (patch: Partial<LivePriceReview>) => {
    if (!selected) return
    setRows((current) => current.map((review) => review.id === selected.id ? { ...review, ...patch } : review))
  }

  const approveSelected = async () => {
    if (!selected) return
    setSavingId(selected.id)
    try {
      await api(`/admin/live-price-reviews/${selected.id}/approve`, {
        method: 'POST',
        body: JSON.stringify({
          price: correctedPrice,
          service_fee: correctedFee,
          extra_charge: correctedExtra,
          reason: selected.correction_reason || 'Live dashboard correction',
        }),
      })
      await onChanged()
      await syncReviews()
    } finally {
      setSavingId(null)
    }
  }

  return (
    <section className="panel live-price-dashboard-panel">
      <div className="live-price-dashboard-title">
        <div>
          <span>Customer live correction</span>
          <h2>Live Edit Harga</h2>
          <p>Order customer menunggu koreksi harga sebelum tombol konfirmasi aktif.</p>
        </div>
        <button className="secondary-button compact" type="button" onClick={onNavigate}>Buka halaman lengkap</button>
      </div>
      <div className="live-price-dashboard-grid">
        <div className="live-price-dashboard-column">
          <div className="live-price-dashboard-head"><span>Live order</span><b>{rows.length}</b></div>
          <div className="live-price-dashboard-queue">
            {rows.length === 0 && <EmptyPanel title="Tidak ada live correction" copy="Order customer yang menunggu koreksi harga akan muncul di sini." />}
            {rows.slice(0, 8).map((review) => (
              <button className={selected?.id === review.id ? 'live-price-queue-item active' : 'live-price-queue-item'} type="button" key={review.id} onClick={() => setSelectedId(review.id)}>
                <strong>{review.customer ?? 'Customer'}</strong>
                <span>{serviceDisplayName(review.service_type ?? review.order_payload?.service_type ?? 'Order')} - {review.branch ?? 'Cabang belum terbaca'}</span>
                <small>{review.status === 'cancelled' ? (review.correction_reason ?? 'Customer membatalkan order.') : `Rp ${Number(review.system_total_price ?? 0).toLocaleString('id-ID')}`} - {formatShortDateTime(review.created_at ?? null)}</small>
              </button>
            ))}
          </div>
        </div>

        <div className="live-price-dashboard-column preview">
          <div className="live-price-dashboard-head"><span>Preview order</span><b>{selected?.status ?? '-'}</b></div>
          {!selected && <EmptyPanel title="Belum ada preview" copy="Pilih live order untuk melihat detail parsing dan harga sistem." />}
          {selected && (
            <div className="live-price-dashboard-preview">
              <strong>{serviceDisplayName(selected.service_type ?? payload?.service_type ?? 'Order')}</strong>
              {selected.status === 'cancelled' && <div className="notice danger compact">{selected.correction_reason ?? `${selected.customer ?? 'Customer'} telah membatalkan order.`}</div>}
              <p className="preserve-lines">{selected.raw_text || '-'}</p>
              <div className="manual-preview-detail">
                <div><span>Pickup</span><b>{payload?.pickup_address ?? String(selected.parsed?.pickup_address ?? '-')}</b></div>
                <div><span>Tujuan</span><b>{payload?.destination_address ?? String(selected.parsed?.destination_address ?? '-')}</b></div>
                <div><span>Tarif sistem</span><b>Rp {Number(selected.system_price ?? 0).toLocaleString('id-ID')}</b></div>
                <div><span>Service fee</span><b>Rp {Number(selected.system_service_fee ?? 0).toLocaleString('id-ID')}</b></div>
                <div><span>Total sistem</span><b>Rp {Number(selected.system_total_price ?? 0).toLocaleString('id-ID')}</b></div>
              </div>
            </div>
          )}
        </div>

        <div className="live-price-dashboard-column edit">
          <div className="live-price-dashboard-head"><span>Edit harga</span><b>Final</b></div>
          {!selected && <EmptyPanel title="Belum ada koreksi" copy="Kolom edit aktif setelah ada order customer masuk." />}
          {selected && (
            <div className="live-price-dashboard-editor">
              {selected.status === 'cancelled' && <div className="notice danger compact">Order dibatalkan customer. Tidak perlu koreksi harga.</div>}
              <label>Tarif final<input type="number" value={correctedPrice} onFocus={() => setIsEditing(true)} onBlur={() => setIsEditing(false)} onChange={(event) => updateSelected({ corrected_price: Number(event.target.value) })} /></label>
              <label>Service fee<input type="number" value={correctedFee} onFocus={() => setIsEditing(true)} onBlur={() => setIsEditing(false)} onChange={(event) => updateSelected({ corrected_service_fee: Number(event.target.value) })} /></label>
              <label>Tambahan/potongan<input type="number" value={correctedExtra} onFocus={() => setIsEditing(true)} onBlur={() => setIsEditing(false)} onChange={(event) => updateSelected({ corrected_extra_charge: Number(event.target.value) })} /></label>
              <label>Catatan koreksi<textarea value={selected.correction_reason ?? ''} onFocus={() => setIsEditing(true)} onBlur={() => setIsEditing(false)} onChange={(event) => updateSelected({ correction_reason: event.target.value })} /></label>
              <div className="manual-preview-total"><span>Total customer</span><strong>Rp {correctedTotal.toLocaleString('id-ID')}</strong></div>
              <button className="primary-button compact" type="button" disabled={savingId === selected.id || !editableLivePriceReviewStatuses.has(selected.status)} onClick={() => void approveSelected()}>
                {savingId === selected.id ? 'Mengirim...' : selected.status === 'pending' ? 'Konfirmasi harga' : 'Sudah dibatalkan'}
              </button>
            </div>
          )}
        </div>
      </div>
    </section>
  )
}

function RecentActivity({ orders, onOpenOrder }: { orders: Order[]; onOpenOrder: (code: string) => void }) {
  return <section className="panel activity-panel compact-activity"><PanelHeader title="Recent order activity" action="Ringkas" /><div className="activity-list">{orders.slice(0, 5).map((order) => <div className="activity-item order-activity-item compact" key={order.id}><div><button className="order-code-link inline" type="button" onClick={() => onOpenOrder(order.code)}>{order.code}</button><span>{order.customer || '-'} - {order.service}</span>{order.status === 'CANCELLED' && <em>{order.cancel_reason || 'Dibatalkan tanpa alasan tersimpan.'}</em>}</div><StatusBadge status={order.status} /></div>)}</div></section>
}

function PriceEditActivity({ auditLogs }: { auditLogs: AuditLog[] }) {
  const logs = auditLogs.filter((log) => isPriceAuditLog(log)).slice(0, 6)

  return (
    <section className="panel activity-panel compact-activity">
      <PanelHeader title="History edit harga" action={`${logs.length} log`} />
      <div className="activity-list">
        {logs.length === 0 && <EmptyPanel title="Belum ada edit harga" copy="Log operator dan eksekutor yang mengubah harga akan tampil di sini." />}
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

function DispatchRepostActivity({ auditLogs }: { auditLogs: AuditLog[] }) {
  const logs = auditLogs.filter((log) => log.action === 'reposted_timeout_order').slice(0, 8)

  return (
    <section className="panel activity-panel compact-activity">
      <PanelHeader title="History repost eksekutor" action={`${logs.length} log`} />
      <div className="activity-list">
        {logs.length === 0 && <EmptyPanel title="Belum ada repost order" copy="Order timeout yang direlease/repost eksekutor akan tercatat di sini." />}
        {logs.map((log) => (
          <div className="activity-item order-activity-item compact" key={log.id}>
            <div>
              <strong>{log.subject_label ?? String(log.metadata?.order_code ?? 'Order')}</strong>
              <span>Repost #{String(log.metadata?.repost_count ?? '-')} oleh {log.user}</span>
              <em>Driver diberi notifikasi: {String(log.metadata?.notified_driver_count ?? 0)}</em>
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
  return ((isWaitingDriverStatus(order.status) && !order.driver) || isDispatchRepostOrder(order))
}

function isDispatchRepostOrder(order?: Order | null) {
  return Boolean(order?.can_repost_dispatch)
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

function isOnlineDriverRow(driver: DriverRow) {
  return driver.driver_state === 'online'
    && driver.driver_status === 'active'
    && driver.is_active
    && !driver.is_suspended
}

function UsersPanel({ users, totalUsers, isLoading, branches, me, roleFilter, onRoleFilterChange, permissions, api, onChanged }: { users: User[]; totalUsers: number | null; isLoading: boolean; branches: Branch[]; me: User; roleFilter: Role | 'all'; onRoleFilterChange: (role: Role | 'all') => void; permissions: Permissions; api: ApiClient; onChanged: () => Promise<void> }) {
  const [editingUser, setEditingUser] = useState<User | null>(null)
  const [selectedUserId, setSelectedUserId] = useState<number | null>(null)
  const detailRef = useRef<HTMLElement | null>(null)
  const canEditUser = (user: User) => permissions.can_manage_users && user.id !== me.id && (['admin', 'gm'].includes(me.role) || !['admin', 'gm'].includes(user.role))
  const canAdministerUser = (user: User) => canEditUser(user) && (['admin', 'gm'].includes(me.role) || permissions.names.includes('create_user'))
  const selectedUser = useMemo(() => users.find((user) => user.id === selectedUserId) ?? users[0] ?? null, [selectedUserId, users])
  useEffect(() => {
    if (users.length === 0) {
      if (selectedUserId !== null) setSelectedUserId(null)
      return
    }
    if (!users.some((user) => user.id === selectedUserId)) setSelectedUserId(users[0].id)
  }, [selectedUserId, users])
  const selectUser = (user: User) => {
    setSelectedUserId(user.id)
    window.setTimeout(() => detailRef.current?.scrollIntoView({ behavior: 'smooth', block: 'nearest' }), 0)
  }
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
    <section className="panel user-management-panel">
      <PanelHeader title="User management" action={isLoading ? 'Mencari...' : `${users.length}${totalUsers !== null && totalUsers !== users.length ? ` dari ${totalUsers}` : ''} records`} />
      <div className="table-toolbar user-toolbar">
        <select value={roleFilter} onChange={(event) => onRoleFilterChange(event.target.value as Role | 'all')}><option value="all">All visible roles</option>{Object.entries(roleLabels).map(([key, label]) => <option value={key} key={key}>{label}</option>)}</select>
        <span className="toolbar-hint">Klik baris user untuk melihat detail dan aksi. Admin/GM only can edit Admin & GM accounts.</span>
      </div>
      <div className="user-management-layout">
        <div className="table-wrap user-table-wrap">
          <table>
            <thead><tr><th>User</th><th>Name</th><th>Role</th><th>Branch</th><th>Lokasi</th><th>Status</th></tr></thead>
            <tbody>
              {users.map((user) => (
                <tr
                  key={user.id}
                  className={selectedUser?.id === user.id ? 'selected-row' : ''}
                  tabIndex={0}
                  onClick={() => selectUser(user)}
                  onKeyDown={(event) => {
                    if (event.key === 'Enter' || event.key === ' ') {
                      event.preventDefault()
                      selectUser(user)
                    }
                  }}
                >
                  <td><div className="user-identity-cell"><UserAvatar user={user} /><div><strong>{user.username}</strong><span>{user.email}</span></div></div></td>
                  <td><strong className="user-name-cell">{user.name}</strong></td>
                  <td><RoleBadge role={user.role} /></td>
                  <td><span className="user-branch-cell">{userBranchLabel(user)}</span></td>
                  <td><UserLocationSummary user={user} canViewMaps={me.role === 'admin'} /></td>
                  <td><span className={user.is_suspended ? 'status danger' : user.is_active ? 'status success' : 'status muted'}>{user.is_suspended ? 'Suspended' : user.is_active ? 'Active' : 'Inactive'}</span></td>
                </tr>
              ))}
              {users.length === 0 && (
                <tr>
                  <td colSpan={6}><EmptyPanel title="Belum ada user" copy="User yang sesuai filter akan tampil di sini." /></td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
        <section className="user-detail-panel" ref={detailRef}>
          {!selectedUser && <EmptyPanel title="Pilih user" copy="Detail dan aksi user akan tampil di sini." />}
          {selectedUser && (
            <>
              <div className="user-detail-hero">
                <UserAvatar user={selectedUser} />
                <div>
                  <span>User terpilih</span>
                  <strong>{selectedUser.name}</strong>
                  <small>{selectedUser.username} - {selectedUser.email}</small>
                </div>
              </div>
              <div className="user-detail-statuses">
                <RoleBadge role={selectedUser.role} />
                <span className={selectedUser.is_suspended ? 'status danger' : selectedUser.is_active ? 'status success' : 'status muted'}>{selectedUser.is_suspended ? 'Suspended' : selectedUser.is_active ? 'Active' : 'Inactive'}</span>
                {!canEditUser(selectedUser) && <span className="status muted">Locked</span>}
              </div>
              <div className="user-detail-grid">
                <div><span>Telepon</span><strong>{selectedUser.phone || '-'}</strong></div>
                <div><span>Cabang</span><strong>{userBranchLabel(selectedUser)}</strong></div>
                <div><span>Alamat</span><strong>{selectedUser.address || '-'}</strong></div>
                <div><span>Lokasi</span><UserLocationSummary user={selectedUser} canViewMaps={me.role === 'admin'} /></div>
              </div>
              <div className="user-action-panel">
                <span>Aksi akun</span>
                <div>
                  {canEditUser(selectedUser) && <button className="mini-button" type="button" onClick={() => setEditingUser(selectedUser)}>Edit</button>}
                  {canAdministerUser(selectedUser) && <button className="mini-button" type="button" onClick={() => void resetPassword(selectedUser)}>Reset Pass</button>}
                  {canAdministerUser(selectedUser) && selectedUser.role === 'customer' && <button className="mini-button" type="button" onClick={() => void resetToken(selectedUser)}>Reset Token</button>}
                  {canAdministerUser(selectedUser) && <button className="mini-button reject" type="button" onClick={() => void destroy(selectedUser)}>Delete</button>}
                  {!canEditUser(selectedUser) && <span className="status muted">Akun ini tidak dapat diedit oleh role Anda.</span>}
                </div>
              </div>
            </>
          )}
        </section>
      </div>
      {editingUser && <UserEditModal me={me} user={editingUser} branches={branches} permissions={permissions} api={api} onClose={() => setEditingUser(null)} onSaved={async () => { await onChanged(); setEditingUser(null) }} />}
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

function DriverManagementPanel({ drivers, services, permissions, api, onChanged, initialListMode = 'all' }: { drivers: DriverRow[]; services: ServiceRow[]; permissions: Permissions; api: ApiClient; onChanged: () => Promise<void>; initialListMode?: DriverListMode }) {
  const [configDriver, setConfigDriver] = useState<DriverRow | null>(null)
  const [authDriver, setAuthDriver] = useState<DriverRow | null>(null)
  const [branchFilter, setBranchFilter] = useState('all')
  const [listMode, setListMode] = useState<DriverListMode>(initialListMode)
  const [performancePeriod, setPerformancePeriod] = useState<'today' | 'month' | 'all'>('month')
  const [selectedDriverId, setSelectedDriverId] = useState<number | null>(null)
  const [importOpen, setImportOpen] = useState(false)
  const detailRef = useRef<HTMLElement | null>(null)
  const branchOptions = useMemo(() => {
    const unique = new Map<string, string>()
    drivers.forEach((driver) => unique.set(driverBranchKey(driver), driverBranchLabel(driver)))
    return [...unique.entries()].sort((first, second) => first[1].localeCompare(second[1]))
  }, [drivers])
  useEffect(() => {
    setListMode(initialListMode)
  }, [initialListMode])

  const filteredDrivers = useMemo(() => drivers.filter((driver) => {
    if (branchFilter !== 'all' && driverBranchKey(driver) !== branchFilter) return false
    if (listMode === 'online') return isOnlineDriverRow(driver)

    return true
  }), [branchFilter, drivers, listMode])
  const selectedDriver = useMemo(
    () => filteredDrivers.find((driver) => driver.id === selectedDriverId) ?? filteredDrivers[0] ?? null,
    [filteredDrivers, selectedDriverId],
  )
  const canManageDriverDeposit = permissions.can_manage_driver_deposit ?? permissions.can_suspend_drivers
  const canUpdateDriverConfig = permissions.can_update_driver_config ?? permissions.can_suspend_drivers

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
          Status tampilan
          <select value={listMode} onChange={(event) => setListMode(event.target.value as DriverListMode)}>
            <option value="all">Semua driver</option>
            <option value="online">Driver ON saja</option>
          </select>
        </label>
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
        {permissions.can_manage_users && (
          <div className="driver-import-actions">
            <button className="mini-button" type="button" onClick={() => setImportOpen(true)}>Import CSV</button>
          </div>
        )}
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
                      {canManageDriverDeposit && <button className="mini-button reject" type="button" disabled={!selectedDriver.driver_id || selectedDriver.deposit_status === 'unpaid'} onClick={() => void markDeposit(selectedDriver, 'unpaid')}>Unpaid</button>}
                    </div>
                  </div>
                )}
                {canManageDriverDeposit && (
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
                    {canUpdateDriverConfig && <button className="mini-button" type="button" disabled={!selectedDriver.driver_id} onClick={() => setConfigDriver(selectedDriver)}>Config</button>}
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
      {importOpen && <DriverImportModal api={api} onClose={() => setImportOpen(false)} onImported={async () => { await onChanged(); setImportOpen(false) }} />}
    </section>
  )
}

function DriverImportModal({ api, onClose, onImported }: { api: ApiClient; onClose: () => void; onImported: () => Promise<void> }) {
  const [file, setFile] = useState<File | null>(null)
  const [allowCreate, setAllowCreate] = useState(true)
  const [saving, setSaving] = useState(false)

  const downloadTemplate = async () => {
    const token = localStorage.getItem('admin_token') || localStorage.getItem('token') || ''
    const response = await fetch(`${API_BASE}/admin/drivers/import-template`, {
      headers: { Accept: 'text/csv', Authorization: `Bearer ${token}` },
    })
    if (!response.ok) {
      alert('Gagal download template CSV driver.')
      return
    }

    const blob = await response.blob()
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = `driver-management-template-${new Date().toISOString().slice(0, 10)}.csv`
    document.body.appendChild(link)
    link.click()
    link.remove()
    URL.revokeObjectURL(url)
  }

  const submit = async (event: FormEvent) => {
    event.preventDefault()
    if (!file) {
      alert('Pilih file CSV driver terlebih dahulu.')
      return
    }

    setSaving(true)
    try {
      const form = new FormData()
      form.append('file', file)
      form.append('allow_create', allowCreate ? '1' : '0')
      const result = await api<{ message?: string; created: number; updated: number; skipped: number; errors?: string[]; credentials?: Array<{ username: string; email: string; password: string }> }>('/admin/drivers/import', {
        method: 'POST',
        body: form,
      })

      const credentials = result.credentials?.length
        ? `\n\nPassword driver baru:\n${result.credentials.map((item) => `${item.username} / ${item.email} / ${item.password}`).join('\n')}`
        : ''
      const errors = result.errors?.length ? `\n\nCatatan:\n${result.errors.join('\n')}` : ''
      alert(`${result.message ?? 'Import driver selesai.'}${credentials}${errors}`)
      await onImported()
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="modal-backdrop" role="presentation">
      <div className="modal driver-import-modal" role="dialog" aria-modal="true">
        <div className="modal-header">
          <div>
            <h2>Import Driver CSV</h2>
            <p>Buat akun driver massal atau update driver existing dari file CSV.</p>
          </div>
          <button type="button" className="icon-button" onClick={onClose}><Icon name="close" /></button>
        </div>
        <form className="user-form" onSubmit={submit}>
          <fieldset>
            <legend>File import</legend>
            <div className="driver-import-guide">
              <strong>Alur aman</strong>
              <span>Download template, isi di Excel/Sheets, simpan sebagai CSV, lalu upload kembali.</span>
              <span>Untuk driver baru, kosongkan `user_id` dan `driver_id`. Isi minimal `name`, `username`, `email_google`, dan cabang.</span>
            </div>
            <button className="secondary-button" type="button" onClick={() => void downloadTemplate()}>Download Template CSV</button>
            <label>Upload CSV<input type="file" accept=".csv,text/csv,text/plain" onChange={(event) => setFile(event.target.files?.[0] ?? null)} required /></label>
            <label className="toggle-row"><input type="checkbox" checked={allowCreate} onChange={(event) => setAllowCreate(event.target.checked)} />Buat driver baru jika belum ada</label>
          </fieldset>
          <div className="modal-actions">
            <button type="button" className="secondary-button" onClick={onClose}>Cancel</button>
            <button className="primary-button" disabled={saving} type="submit">{saving ? 'Importing...' : 'Import Driver'}</button>
          </div>
        </form>
      </div>
    </div>
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
  const [canAcceptAllAreas, setCanAcceptAllAreas] = useState(Boolean(driver.can_accept_all_areas))
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
          can_accept_all_areas: canAcceptAllAreas,
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
            <label className="driver-ladies-card">
              <input type="checkbox" checked={canAcceptAllAreas} onChange={(event) => setCanAcceptAllAreas(event.target.checked)} />
              <span>
                <strong>Akses all area</strong>
                <small>Driver dapat melihat dan menerima order dari semua cabang. Gunakan hanya untuk driver lintas area.</small>
              </span>
              <b>{canAcceptAllAreas ? 'Aktif' : 'Cabang saja'}</b>
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
  const [multiCrewAutoCancelEnabled, setMultiCrewAutoCancelEnabled] = useState(settings.multi_crew_auto_cancel_enabled ?? true)
  const [multiCrewAutoCancelMinutes, setMultiCrewAutoCancelMinutes] = useState(settings.multi_crew_auto_cancel_minutes ?? 7)
  const [multiCrewAutoCancelMessage, setMultiCrewAutoCancelMessage] = useState(settings.multi_crew_auto_cancel_message ?? 'Maaf, order {order_code} dibatalkan otomatis karena {helper_label} belum menerima dalam {minutes} menit.')
  const [driverDailyPriorityEnabled, setDriverDailyPriorityEnabled] = useState(settings.driver_daily_priority_enabled ?? true)
  const [driverDailyPriorityHoldMinutes, setDriverDailyPriorityHoldMinutes] = useState(settings.driver_daily_priority_hold_minutes ?? 3)
  const [driverDailyPriorityWindows, setDriverDailyPriorityWindows] = useState<DailyPriorityWindow[]>(settings.driver_daily_priority_windows ?? defaultDailyPriorityWindows())
  const [nightTariffEnabled, setNightTariffEnabled] = useState(settings.night_tariff_enabled ?? true)
  const [nightTariffRules, setNightTariffRules] = useState<NightTariffRule[]>(settings.night_tariff_rules ?? defaultNightTariffRules())
  const [zonePricingEnabled, setZonePricingEnabled] = useState(settings.zone_pricing_enabled ?? true)
  const [livePriceReviewEnabled, setLivePriceReviewEnabled] = useState(settings.live_price_review_enabled ?? false)
  const [livePriceReviewDelaySeconds, setLivePriceReviewDelaySeconds] = useState(settings.live_price_review_delay_seconds ?? 5)
  const [assignDriverAllowedRoles, setAssignDriverAllowedRoles] = useState<Role[]>(settings.assign_driver_allowed_roles ?? ['operator', 'eksekutor'])
  const [editTarifAllowedRoles, setEditTarifAllowedRoles] = useState<Role[]>(settings.edit_tarif_allowed_roles ?? defaultEditTarifRoles())
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
    setMultiCrewAutoCancelEnabled(settings.multi_crew_auto_cancel_enabled ?? true)
    setMultiCrewAutoCancelMinutes(settings.multi_crew_auto_cancel_minutes ?? 7)
    setMultiCrewAutoCancelMessage(settings.multi_crew_auto_cancel_message ?? 'Maaf, order {order_code} dibatalkan otomatis karena {helper_label} belum menerima dalam {minutes} menit.')
    setDriverDailyPriorityEnabled(settings.driver_daily_priority_enabled ?? true)
    setDriverDailyPriorityHoldMinutes(settings.driver_daily_priority_hold_minutes ?? 3)
    setDriverDailyPriorityWindows(settings.driver_daily_priority_windows ?? defaultDailyPriorityWindows())
    setNightTariffEnabled(settings.night_tariff_enabled ?? true)
    setNightTariffRules(settings.night_tariff_rules ?? defaultNightTariffRules())
    setZonePricingEnabled(settings.zone_pricing_enabled ?? true)
    setLivePriceReviewEnabled(settings.live_price_review_enabled ?? false)
    setLivePriceReviewDelaySeconds(settings.live_price_review_delay_seconds ?? 5)
    setAssignDriverAllowedRoles(settings.assign_driver_allowed_roles ?? ['operator', 'eksekutor'])
    setEditTarifAllowedRoles(settings.edit_tarif_allowed_roles ?? defaultEditTarifRoles())
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
          multi_crew_auto_cancel_enabled: multiCrewAutoCancelEnabled,
          multi_crew_auto_cancel_minutes: multiCrewAutoCancelMinutes,
          multi_crew_auto_cancel_message: multiCrewAutoCancelMessage,
          driver_daily_priority_enabled: driverDailyPriorityEnabled,
          driver_daily_priority_hold_minutes: driverDailyPriorityHoldMinutes,
          driver_daily_priority_windows: driverDailyPriorityWindows,
          night_tariff_enabled: nightTariffEnabled,
          night_tariff_rules: nightTariffRules,
          zone_pricing_enabled: zonePricingEnabled,
          live_price_review_enabled: livePriceReviewEnabled,
          live_price_review_delay_seconds: livePriceReviewDelaySeconds,
          assign_driver_allowed_roles: assignDriverAllowedRoles,
          edit_tarif_allowed_roles: editTarifAllowedRoles,
          feedback_templates: feedbackTemplates,
        }),
      })
      await onChanged()
    } finally {
      setSaving(false)
    }
  }

  return (
    <section className="panel system-settings-panel">
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
            <h2>Live Edit Harga Customer</h2>
            <p>Jika aktif, preview order customer masuk antrean operator/eksekutor. Customer melihat loading harga sampai operator mengonfirmasi harga.</p>
          </div>
          <span className="status info">{livePriceReviewEnabled ? 'Aktif' : 'Nonaktif'}</span>
        </div>
        <div className="settings-grid">
          <label className="admin-toggle-row">
            <input
              type="checkbox"
              checked={livePriceReviewEnabled}
              disabled={!permissions.can_manage_system_settings}
              onChange={(event) => setLivePriceReviewEnabled(event.target.checked)}
            />
            <span>Aktifkan live correction harga customer</span>
          </label>
          <label>
            Delay tombol konfirmasi
            <input
              type="number"
              min={5}
              max={10}
              value={livePriceReviewDelaySeconds}
              disabled={!permissions.can_manage_system_settings}
              onChange={(event) => setLivePriceReviewDelaySeconds(Math.max(5, Math.min(10, Number(event.target.value))))}
            />
          </label>
        </div>
        <div className="notice">Manual Order operator tetap langsung memakai halaman manual order saat ini. Fitur ini khusus order dari FE customer.</div>
      </div>
      <div className="feedback-cms daily-priority-card">
        <div className="section-head">
          <div>
            <h2>Driver Daily Priority</h2>
            <p>Driver yang pertama kali OFFLINE ke ONLINE pada hari berjalan mendapat prioritas 1 order jika tetap memenuhi syarat area, layanan, setoran, dan suspend.</p>
          </div>
          <span className="status info">CMS</span>
        </div>
        <div className="settings-grid priority-settings-grid">
          <label className="admin-toggle-row">
            <input type="checkbox" checked={driverDailyPriorityEnabled} disabled={!permissions.can_manage_system_settings} onChange={(event) => setDriverDailyPriorityEnabled(event.target.checked)} />
            <span>Aktifkan prioritas harian driver</span>
          </label>
          <label className="priority-duration-field">Durasi tahan prioritas<input type="number" min={1} max={60} value={driverDailyPriorityHoldMinutes} disabled={!permissions.can_manage_system_settings} onChange={(event) => setDriverDailyPriorityHoldMinutes(Math.max(1, Math.min(60, Number(event.target.value))))} /></label>
        </div>
        <div className="settings-list priority-window-list">
          <div className="priority-window-header">
            <strong>Jam aktif prioritas</strong>
            <span>{driverDailyPriorityWindows.length} jadwal</span>
          </div>
          {driverDailyPriorityWindows.map((window, index) => (
            <div className="settings-row compact priority-window-row" key={`daily-priority-${index}`}>
              <div className="priority-window-fields">
                <label><span>Mulai</span><input type="time" value={window.start} disabled={!permissions.can_manage_system_settings} onChange={(event) => setDriverDailyPriorityWindows((rows) => rows.map((row, rowIndex) => rowIndex === index ? { ...row, start: event.target.value } : row))} /></label>
                <label><span>Selesai</span><input type="time" value={window.end} disabled={!permissions.can_manage_system_settings} onChange={(event) => setDriverDailyPriorityWindows((rows) => rows.map((row, rowIndex) => rowIndex === index ? { ...row, end: event.target.value } : row))} /></label>
              </div>
              <button className="ghost-button priority-remove-button" type="button" disabled={!permissions.can_manage_system_settings || driverDailyPriorityWindows.length <= 1} onClick={() => setDriverDailyPriorityWindows((rows) => rows.filter((_, rowIndex) => rowIndex !== index))}>Hapus</button>
            </div>
          ))}
          <button className="secondary-button priority-add-button" type="button" disabled={!permissions.can_manage_system_settings} onClick={() => setDriverDailyPriorityWindows((rows) => [...rows, { start: '05:00', end: '11:00' }])}>Tambah jam aktif</button>
        </div>
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
            <h2>Auto-cancel Multi Crew</h2>
            <p>Timeout khusus setelah rider menerima order tetapi helper belum menerima slot. Tidak mengikuti auto-cancel cari driver 10 menit.</p>
          </div>
          <span className="status info">CMS</span>
        </div>
        <div className="settings-grid">
          <label className="admin-toggle-row">
            <input type="checkbox" checked={multiCrewAutoCancelEnabled} disabled={!permissions.can_manage_system_settings} onChange={(event) => setMultiCrewAutoCancelEnabled(event.target.checked)} />
            <span>Aktifkan auto-cancel multi-crew</span>
          </label>
          <label>Batas tunggu helper<input type="number" min={1} max={180} value={multiCrewAutoCancelMinutes} disabled={!permissions.can_manage_system_settings} onChange={(event) => setMultiCrewAutoCancelMinutes(Math.max(1, Math.min(180, Number(event.target.value))))} /></label>
          <label className="span-2">Pesan customer<textarea value={multiCrewAutoCancelMessage} disabled={!permissions.can_manage_system_settings} onChange={(event) => setMultiCrewAutoCancelMessage(event.target.value)} /></label>
        </div>
        <div className="notice">Placeholder: {'{order_code}'}, {'{minutes}'}, {'{helper_label}'}, {'{driver_name}'}.</div>
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
            <h2>Assign Driver Order</h2>
            <p>Atur role manajemen yang boleh memilih driver langsung di Order Operations. Admin dan GM selalu aktif.</p>
          </div>
          <span className="status info">CMS</span>
        </div>
        <div className="service-check-grid assign-role-grid">
          {assignDriverRoleOptions.map((role) => (
            <label className="toggle-row" key={role.value}>
              <input
                type="checkbox"
                checked={assignDriverAllowedRoles.includes(role.value)}
                disabled={!permissions.can_manage_system_settings}
                onChange={() => setAssignDriverAllowedRoles((current) => toggleRoleValue(current, role.value))}
              />
              {role.label}
            </label>
          ))}
        </div>
        <div className="notice">Role yang tidak dicentang tetap bisa monitor order sesuai hak aksesnya, tetapi tombol assign dan broadcast driver disembunyikan.</div>
      </div>
      <div className="feedback-cms">
        <div className="section-head">
          <div>
            <h2>Role Pricing CMS</h2>
            <p>Atur role yang boleh mengakses edit tarif, master ring, zone pricing, dan pricing policy. Admin dan GM selalu aktif.</p>
          </div>
          <span className="status info">CMS</span>
        </div>
        <div className="service-check-grid assign-role-grid">
          {editTarifRoleOptions.map((role) => (
            <label className="toggle-row" key={role.value}>
              <input
                type="checkbox"
                checked={editTarifAllowedRoles.includes(role.value)}
                disabled={!permissions.can_manage_system_settings}
                onChange={() => setEditTarifAllowedRoles((current) => toggleRoleValue(current, role.value))}
              />
              {role.label}
            </label>
          ))}
        </div>
        <div className="notice">Default sementara: HRD, Manager, SPV, Operator, dan Eksekutor dapat menu pricing. Hapus centang jika role tidak boleh edit tarif.</div>
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
  const [assignOrder, setAssignOrder] = useState<Order | null>(null)
  const [selectedOrderId, setSelectedOrderId] = useState<number | null>(null)
  const latestOrders = useMemo(() => sortOrdersNewest(orders), [orders])
  const filteredOrders = latestOrders.filter((order) => orderMatchesSearch(order, searchQuery))
  const selectedOrder = filteredOrders.find((order) => order.id === selectedOrderId) ?? filteredOrders[0] ?? null
  const priceLogs = auditLogs.filter((log) => log.action === 'updated_order_price').slice(0, 5)
  const dispatchRepostLogs = permissions.can_view_dispatch_repost_audit ? auditLogs.filter((log) => log.action === 'reposted_timeout_order').slice(0, 5) : []
  void priceLogs.map(priceLogSummary)
  return (
    <section className="panel order-operations-panel">
      <PanelHeader title="Order operations" action={`${filteredOrders.length}/${orders.length} orders`} />
      {permissions.can_edit_order_price && <div className="notice">Edit harga hanya aktif saat order berjalan, lalu dikirim realtime ke customer dan driver.</div>}
      {dispatchRepostLogs.length > 0 && (
        <div className="order-audit-strip">
          {dispatchRepostLogs.map((log) => (
            <article key={log.id}>
              <span>{log.subject_label ?? String(log.metadata?.order_code ?? 'Order')}</span>
              <strong>Repost #{String(log.metadata?.repost_count ?? '-')}</strong>
              <small>{log.user} · {formatShortDateTime(log.created_at)}</small>
            </article>
          ))}
        </div>
      )}
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
                  <td>{displayBranchValue(order.branch_display_name ?? order.branch, order.branch_area)}</td>
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
        <OrderDetailPanel
          order={selectedOrder}
          permissions={permissions}
          onOpenDriverChat={onOpenDriverChat}
          onAssignDriver={permissions.can_assign_driver && selectedOrder && isDispatchPendingOrder(selectedOrder) ? () => setAssignOrder(selectedOrder) : undefined}
          onEditPrice={permissions.can_edit_order_price && selectedOrder && canEditOrderPrice(selectedOrder) ? () => setEditingOrder(selectedOrder) : undefined}
        />
      </div>
      {editingOrder && <OrderPriceModal order={editingOrder} api={api} onClose={() => setEditingOrder(null)} onSaved={async () => { await onChanged(); setEditingOrder(null) }} />}
      {assignOrder && <AssignDriverModal order={assignOrder} api={api} onClose={() => setAssignOrder(null)} onAssigned={async () => { await onChanged(); setAssignOrder(null) }} />}
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
              <span>{displayBranchValue(item.branch_display_name ?? item.branch, item.branch_area)} · {item.service || '-'} · {formatShortDateTime(item.created_at)}</span>
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

function OrderDetailPanel({ order, permissions, onEditPrice, onAssignDriver, onOpenDriverChat }: { order: Order | null; permissions: Permissions; onEditPrice?: () => void; onAssignDriver?: () => void; onOpenDriverChat?: (driverUserId: number) => void }) {
  if (!order) {
    return (
      <aside className="order-detail-panel empty-detail">
        <EmptyPanel title="Pilih order" copy="Klik baris pada tabel untuk membuka detail operasional order." />
      </aside>
    )
  }

  const payment = order.payment_label || paymentLabel(order.payment_method)
  const detailText = order.raw_text || order.notes || ''
  const orderContent = orderContentText(order)
  const shouldShowRawNote = Boolean(detailText.trim()) && detailText.trim() !== orderContent.trim()

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
        <DetailItem label="Cabang / Area" value={displayBranchValue(order.branch_display_name ?? order.branch, order.branch_area)} />
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
      {orderContent && (
        <div className="order-content-card">
          <span>{order.source === 'driver_request' ? 'Format request driver' : 'Isi pesanan'}</span>
          <pre>{orderContent}</pre>
        </div>
      )}
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
      {(order.crews?.length || order.crew_status) && (
        <div className="order-crew-card">
          <div>
            <span>Crew order</span>
            <strong>{crewStatusText(order.crew_status)}</strong>
          </div>
          {(order.crews ?? []).map((crew) => (
            <p key={`${crew.role}-${crew.id}`}>
              {crew.label || crew.role}: {crew.driver || (crew.status === 'pending' ? 'menunggu driver' : '-')} · {crewStatusText(crew.status)}
              {Number(crew.service_charge ?? 0) > 0 ? ` · Harga helper Rp ${Number(crew.service_charge ?? 0).toLocaleString('id-ID')}` : ''}
            </p>
          ))}
          {order.crew_decision && <small>Rule: {String(order.crew_decision.rule_name ?? 'Crew decision')} · Estimasi helper Rp {Number(order.crew_decision.helper_fee ?? order.crew_decision.helper_service_charge ?? order.pricing_breakdown?.crew_helper_fee ?? 0).toLocaleString('id-ID')}</small>}
        </div>
      )}
      {order.cancel_reason && <div className="notice danger">Cancel reason: {order.cancel_reason}</div>}
      {shouldShowRawNote && <div className="order-raw-note"><span>Catatan / raw order</span><p>{detailText}</p></div>}
      <div className="order-detail-actions">
        {permissions.can_assign_driver && onAssignDriver && <button className="secondary-button compact" type="button" onClick={onAssignDriver}>Assign Driver</button>}
        {permissions.can_edit_order_price && onEditPrice && <button className="primary-button compact" type="button" onClick={onEditPrice}>Edit harga</button>}
      </div>
    </aside>
  )
}

function DetailItem({ label, value }: { label: string; value: ReactNode }) {
  return <div className="detail-item"><span>{label}</span><strong>{value}</strong></div>
}

function RequestOrdersPanel({ orders, searchQuery, permissions, onOpenDriverChat }: { orders: Order[]; searchQuery: string; permissions: Permissions; onOpenDriverChat: (driverUserId: number) => void }) {
  const [selectedOrderId, setSelectedOrderId] = useState<number | null>(null)
  const requestOrders = useMemo(() => sortOrdersNewest(orders).filter((order) => order.source === 'driver_request'), [orders])
  const filteredOrders = requestOrders.filter((order) => orderMatchesSearch(order, searchQuery))
  const selectedOrder = filteredOrders.find((order) => order.id === selectedOrderId) ?? filteredOrders[0] ?? null

  return (
    <section className="panel order-operations-panel request-orders-panel">
      <PanelHeader title="Request Order" action={`Driver request terbaru - ${filteredOrders.length}/${requestOrders.length}`} />
      <div className="notice">Klik baris untuk melihat format penulisan asli dan detail pesanan.</div>
      <div className="order-operations-layout">
        <div className="table-wrap order-table-wrap request-order-table-wrap">
          <table>
            <thead>
              <tr><th>Order</th><th>Driver</th><th>Service</th><th>Total</th><th>Status</th><th>Waktu</th></tr>
            </thead>
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
                  <td><strong>{order.code}</strong><span>{order.customer || '-'}</span></td>
                  <td>{order.driver_user_id && order.driver ? <button className="inline-action-link" type="button" onClick={(event) => { event.stopPropagation(); onOpenDriverChat(order.driver_user_id!) }}>{order.driver}</button> : order.driver || '-'}</td>
                  <td>{order.service}</td>
                  <td><strong>Rp {order.total.toLocaleString('id-ID')}</strong><span>Tarif Rp {order.price.toLocaleString('id-ID')} - Fee Rp {order.service_charge.toLocaleString('id-ID')}</span></td>
                  <td><StatusBadge status={order.status} /></td>
                  <td>{formatShortDateTime(order.created_at)}</td>
                </tr>
              ))}
            </tbody>
          </table>
          {filteredOrders.length === 0 && <EmptyPanel title="Request order belum ada" copy="Order dari request driver akan tampil di sini." />}
        </div>
        <OrderDetailPanel order={selectedOrder} permissions={permissions} onOpenDriverChat={onOpenDriverChat} />
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
        <div className="modal-header"><div><h2>Edit harga order</h2><p>{order.code} - {order.customer || 'Customer'}</p></div><button type="button" className="icon-button" onClick={onClose}><Icon name="close" /></button></div>
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

export function PricingPanel({ ringRules, ringSuggestions, branches, services, permissions, api, onChanged }: { ringRules: RingPricingRule[]; ringSuggestions: RingPricingSuggestion[]; branches: Branch[]; services: ServiceRow[]; permissions: Permissions; api: ApiClient; onChanged: () => Promise<void> }) {
  const [showRingForm, setShowRingForm] = useState(false)
  const [showImportForm, setShowImportForm] = useState(false)
  const [ringFormula, setRingFormula] = useState(false)
  const [importingGeojson, setImportingGeojson] = useState(false)
  const [learningRequests, setLearningRequests] = useState(false)
  const [geojsonImportMessage, setGeojsonImportMessage] = useState<string | null>(null)
  const [geojsonImportError, setGeojsonImportError] = useState<string | null>(null)
  const createRing = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    const formElement = event.currentTarget
    const form = new FormData(formElement)
    const polygonRaw = String(form.get('polygon_geojson') ?? '').trim()
    let polygonCoordinates: unknown[] = []
    if (polygonRaw) {
      try {
        polygonCoordinates = JSON.parse(polygonRaw)
      } catch {
        alert('GeoJSON polygon tidak valid. Export/copy JSON dari geojson.io lalu tempel ulang.')
        return
      }
    }
    await api('/admin/ring-pricing-rules', {
      method: 'POST',
      body: JSON.stringify({
        name: form.get('name'),
        branch_id: Number(form.get('branch_id')) || null,
        service_type: form.get('service_type') || null,
        pickup_area: form.get('pickup_area') || form.get('name'),
        destination_area: form.get('destination_area') || form.get('name'),
        area_mode: 'polygon',
        polygon_coordinates: polygonCoordinates,
        polygon_match_point: form.get('polygon_match_point') || 'destination_then_pickup',
        match_type: form.get('match_type') || 'point',
        pickup_ring: form.get('pickup_ring') || null,
        destination_ring: form.get('destination_ring') || null,
        ring: form.get('ring'),
        min_km: Number(form.get('min_km') || 0),
        max_km: form.get('max_km') ? Number(form.get('max_km')) : null,
        pricing_mode: ringFormula ? 'formula' : 'flat',
        price: Number(form.get('price') || 0),
        per_km_rate: ringFormula ? Number(form.get('per_km_rate') || 0) : null,
        subtract_value: ringFormula ? Number(form.get('subtract_value') || 0) : 0,
        service_fee: Number(form.get('service_fee') || 0),
        priority: Number(form.get('priority') || 0),
        is_bidirectional: form.get('is_bidirectional') === 'on',
        is_active: form.get('is_active') === 'on',
      }),
    })
    formElement.reset()
    setRingFormula(false)
    setShowRingForm(false)
    await onChanged()
  }
  const importGeojson = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    const formElement = event.currentTarget
    const form = new FormData(formElement)
    const file = form.get('geojson_file')
    if (!(file instanceof File) || file.size === 0) {
      setGeojsonImportError('Pilih file GeoJSON terlebih dahulu.')
      return
    }

    setImportingGeojson(true)
    setGeojsonImportMessage(null)
    setGeojsonImportError(null)
    try {
      const payload = await api<{ message?: string; created?: number; updated?: number; skipped?: string[] }>('/admin/ring-pricing-rules/import-geojson', {
        method: 'POST',
        body: form,
      })
      const skipped = payload.skipped?.length ? `, ${payload.skipped.length} skip` : ''
      setGeojsonImportMessage(payload.message ?? `Import selesai: ${payload.created ?? 0} baru, ${payload.updated ?? 0} update${skipped}.`)
      formElement.reset()
      await onChanged()
    } catch (error) {
      setGeojsonImportError(error instanceof Error ? error.message : 'Import GeoJSON gagal.')
    } finally {
      setImportingGeojson(false)
    }
  }
  const destroyRing = async (rule: RingPricingRule) => {
    if (!confirm(`Delete master ring ${rule.name}?`)) return
    await api(`/admin/ring-pricing-rules/${rule.id}`, { method: 'DELETE' })
    await onChanged()
  }
  const toggleRing = async (rule: RingPricingRule) => {
    await api(`/admin/ring-pricing-rules/${rule.id}/active`, {
      method: 'PATCH',
      body: JSON.stringify({ is_active: !rule.is_active }),
    })
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
  const learnRequestOrders = async () => {
    setLearningRequests(true)
    setGeojsonImportMessage(null)
    setGeojsonImportError(null)
    try {
      const payload = await api<{ message?: string }>('/admin/ring-pricing-suggestions/learn-request-orders', {
        method: 'POST',
        body: JSON.stringify({ limit: 500 }),
      })
      setGeojsonImportMessage(payload.message ?? 'Learning request order selesai.')
      await onChanged()
    } catch (error) {
      setGeojsonImportError(error instanceof Error ? error.message : 'Learning request order gagal.')
    } finally {
      setLearningRequests(false)
    }
  }
  const canManageRing = Boolean(permissions.can_manage_ring_pricing)
  const activeRingCount = ringRules.filter((rule) => rule.is_active).length
  const learnedRingCount = ringRules.filter((rule) => rule.source === 'learned').length
  const operationalBranches = branches.filter(isOperationalBranch)
  return (
    <section className="panel pricing-panel master-ring-panel">
      <div className="section-head master-ring-head">
        <div><h2>Master Ring Pricing</h2><p>Min/max jarak, polygon, service fee, dan formula harga dikelola dari Master Ring.</p></div>
        <div className="section-actions">
          {canManageRing && <button className="secondary-button compact" type="button" disabled={learningRequests} onClick={() => void learnRequestOrders()}><Icon name="shield" />{learningRequests ? 'Learning...' : 'Learn Request Order'}</button>}
          {canManageRing && <button className="secondary-button compact" type="button" onClick={() => setShowImportForm((value) => !value)}><Icon name="upload" />{showImportForm ? 'Tutup Import' : 'Import GeoJSON'}</button>}
          {canManageRing && <button className="secondary-button compact" type="button" onClick={() => setShowRingForm((value) => !value)}><Icon name="plus" />{showRingForm ? 'Tutup Form' : 'Master Ring'}</button>}
        </div>
      </div>
      <div className="master-ring-summary">
        <article><span>Total route</span><strong>{ringRules.length}</strong><small>{activeRingCount} aktif</small></article>
        <article><span>Suggestion</span><strong>{ringSuggestions.length}</strong><small>Dari koreksi harga</small></article>
        <article><span>Learned</span><strong>{learnedRingCount}</strong><small>Sudah jadi rule</small></article>
      </div>
      {showRingForm && canManageRing && (
        <form className="admin-inline-form pricing-create-form ring-create-form" onSubmit={createRing}>
          <div className="ring-form-title"><strong>Tambah Master Ring Polygon</strong><span>Polygon bisa ditempel dari geojson.io. Rule aktif langsung dipakai untuk pricing customer.</span></div>
          <label>Nama master<input name="name" required placeholder="ASB Ring 1" /></label>
          <label>Area operasional<select name="branch_id" required><option value="">Pilih area</option>{operationalBranches.map((branch) => <option key={branch.id} value={branch.id}>{branchLabel(branch)}</option>)}</select></label>
          <label>Layanan<select name="service_type"><option value="">Semua layanan</option>{services.map((service) => <option key={service.id} value={service.code}>{service.name}</option>)}</select></label>
          <label>Ring<select name="ring" defaultValue="ring_1"><option value="ring_1">Ring 1</option><option value="ring_2">Ring 2</option><option value="ring_3">Ring 3</option></select></label>
          <label>Min KM dari pusat<input name="min_km" type="number" min="0" step="0.1" defaultValue="0" /></label>
          <label>Max KM dari pusat<input name="max_km" type="number" min="0" step="0.1" defaultValue="4" placeholder="Kosong = unlimited" /></label>
          <label>Priority<input name="priority" type="number" step="1" defaultValue="300" /></label>
          <label>Service fee<input name="service_fee" type="number" min="0" step="1000" defaultValue="1000" /></label>
          <label className="toggle-row inline-toggle"><input type="checkbox" checked={ringFormula} onChange={(event) => setRingFormula(event.target.checked)} />Formula tarif</label>
          {!ringFormula && <label>Harga jasa<input name="price" type="number" min="0" step="1000" required /></label>}
          {ringFormula && <label>Harga minimum<input name="price" type="number" min="0" step="1000" defaultValue="0" /></label>}
          {ringFormula && <label>Rate / KM<input name="per_km_rate" type="number" min="0" /></label>}
          {ringFormula && <label>Subtract<input name="subtract_value" type="number" min="0" defaultValue="0" /></label>}
          <label>Mode titik<select name="polygon_match_point" defaultValue="destination_then_pickup"><option value="destination_then_pickup">Tujuan, fallback pickup</option><option value="destination">Tujuan saja</option><option value="pickup">Pickup saja</option><option value="either">Pickup atau tujuan</option><option value="both">Pickup dan tujuan</option></select></label>
          <label>Cross ring<select name="match_type" defaultValue="point"><option value="point">Single ring</option><option value="cross">Cross ring</option></select></label>
          <label>Pickup ring<select name="pickup_ring" defaultValue=""><option value="">Auto</option><option value="ring_1">Ring 1</option><option value="ring_2">Ring 2</option><option value="ring_3">Ring 3</option></select></label>
          <label>Destination ring<select name="destination_ring" defaultValue=""><option value="">Auto</option><option value="ring_1">Ring 1</option><option value="ring_2">Ring 2</option><option value="ring_3">Ring 3</option></select></label>
          <label>Nama pickup<input name="pickup_area" placeholder="Opsional, default nama master" /></label>
          <label>Nama tujuan<input name="destination_area" placeholder="Opsional, default nama master" /></label>
          <label className="span-2">GeoJSON polygon<textarea name="polygon_geojson" required rows={7} placeholder='Tempel Feature/FeatureCollection/Polygon dari geojson.io' /></label>
          <label className="toggle-row inline-toggle"><input name="is_bidirectional" type="checkbox" defaultChecked />Dua arah</label>
          <label className="toggle-row inline-toggle"><input name="is_active" type="checkbox" defaultChecked />Aktif</label>
          <div className="ring-form-actions"><button className="secondary-button" type="button" onClick={() => setShowRingForm(false)}>Batal</button><button className="primary-button" type="submit">Simpan Master Ring</button></div>
        </form>
      )}
      {showImportForm && canManageRing && (
        <form className="admin-inline-form pricing-create-form ring-import-form" onSubmit={importGeojson}>
          <div className="ring-form-title"><strong>Import GeoJSON ke Master Ring</strong><span>File FeatureCollection dari geojson.io akan dibuat menjadi rule Master Ring. Jika cabang dikosongkan, sistem memilih cabang terdekat dari centroid polygon.</span></div>
          <label>File GeoJSON<input name="geojson_file" type="file" accept=".geojson,.json,application/geo+json,application/json" required /></label>
          <label>Area operasional<select name="branch_id"><option value="">Auto area terdekat</option>{operationalBranches.map((branch) => <option key={branch.id} value={branch.id}>{branchLabel(branch)}</option>)}</select></label>
          <label>Layanan<select name="service_type"><option value="">Semua layanan</option>{services.map((service) => <option key={service.id} value={service.code}>{service.name}</option>)}</select></label>
          <label>Mode titik<select name="polygon_match_point" defaultValue="destination_then_pickup"><option value="destination_then_pickup">Tujuan, fallback pickup</option><option value="destination">Tujuan saja</option><option value="pickup">Pickup saja</option><option value="either">Pickup atau tujuan</option><option value="both">Pickup dan tujuan</option></select></label>
          <label className="toggle-row inline-toggle"><input name="is_active" type="checkbox" defaultChecked />Aktif setelah import</label>
          <label className="toggle-row inline-toggle"><input name="replace_existing" type="checkbox" />Replace import GeoJSON lama untuk cabang ini</label>
          <div className="ring-form-actions"><button className="primary-button" type="submit" disabled={importingGeojson}>{importingGeojson ? 'Importing...' : 'Import GeoJSON'}</button></div>
          {geojsonImportMessage && <div className="notice success span-2">{geojsonImportMessage}</div>}
          {geojsonImportError && <div className="notice danger span-2">{geojsonImportError}</div>}
        </form>
      )}
      <div className="pricing-subsection">
        <PanelHeader title="Master Ring Route" action={`${ringRules.length} rules`} />
        <div className="pricing-list ring-pricing-list">{ringRules.map((rule) => <article className="pricing-card ring-card" key={rule.id}><div className="pricing-card-main"><div className="ring-card-title"><strong>{rule.name}</strong><span className={rule.is_active ? 'status success' : 'status muted'}>{rule.is_active ? 'Aktif' : 'Nonaktif'}</span></div><span>{rule.branch ? branchLabel(rule.branch as Branch) : 'Global'} · {rule.service_type ?? 'semua layanan'} · {ringLabel(rule.ring)} · priority {rule.priority ?? 0}</span><small>{formatRingDistance(rule)} · service fee Rp {(rule.service_fee ?? 0).toLocaleString('id-ID')} · {rule.match_type === 'cross' ? `cross ${ringLabel(rule.pickup_ring ?? 'auto')} ke ${ringLabel(rule.destination_ring ?? 'auto')}` : 'single ring'}</small>{rule.area_mode === 'polygon' && <small className="ring-aliases">Polygon: {rule.polygon_coordinates?.length ?? 0} titik · {rule.polygon_match_point ?? 'destination_then_pickup'}</small>}{rule.area_mode !== 'polygon' && ((rule.pickup_aliases?.length ?? 0) > 0 || (rule.destination_aliases?.length ?? 0) > 0) && <small className="ring-aliases">Alias: {[...(rule.pickup_aliases ?? []), ...(rule.destination_aliases ?? [])].slice(0, 5).join(', ')}</small>}</div><em>{formatRingPrice(rule)}</em>{canManageRing && <div className="ring-card-actions"><button className={rule.is_active ? 'mini-button reject' : 'mini-button'} type="button" onClick={() => void toggleRing(rule)}>{rule.is_active ? 'Nonaktifkan' : 'Aktifkan'}</button><button className="mini-button reject" type="button" onClick={() => void destroyRing(rule)}>Delete</button></div>}</article>)}</div>
        {ringRules.length === 0 && <EmptyPanel title="Master ring kosong" copy="Tambahkan route ring resmi agar harga tidak hanya mengandalkan jarak maps." />}
      </div>
      {canManageRing && ringSuggestions.length > 0 && (
        <div className="pricing-subsection">
          <PanelHeader title="AI Pricing Learning Suggestions" action={`${ringSuggestions.length} pending`} />
          <div className="pricing-list ring-pricing-list">{ringSuggestions.map((suggestion) => <article className="pricing-card ring-card suggestion" key={suggestion.id}><div className="pricing-card-main"><div className="ring-card-title"><strong>{suggestion.pickup_area} ? {suggestion.destination_area}</strong><span className="status warning">{suggestionTypeLabel(suggestion.suggestion_type)}</span></div><span>{suggestion.branch ? branchLabel(suggestion.branch as Branch) : 'Global'} · {suggestion.service_type ?? 'semua layanan'} · {ringLabel(suggestion.ring ?? '-')} · confidence {suggestion.confidence ?? 60}%</span><small>{suggestion.occurrence_count}x data · system Rp {Number(suggestion.system_price ?? suggestion.previous_price ?? 0).toLocaleString('id-ID')} · saran Rp {suggestion.suggested_price.toLocaleString('id-ID')} · delta Rp {Number(suggestion.price_delta ?? 0).toLocaleString('id-ID')}</small><small>Source: {suggestion.learning_source ?? '-'} · terakhir {suggestion.last_order_code ?? '-'} oleh {suggestion.last_edited_by ?? '-'}</small></div><em>Rp {suggestion.suggested_price.toLocaleString('id-ID')}</em><div className="ring-card-actions"><button className="mini-button" type="button" onClick={() => void approveSuggestion(suggestion)}>Approve</button><button className="mini-button reject" type="button" onClick={() => void rejectSuggestion(suggestion)}>Reject</button></div></article>)}</div>
        </div>
      )}
    </section>
  )
}

function formatRingDistance(rule: RingPricingRule) {
  const min = Number(rule.min_km ?? 0).toLocaleString('id-ID', { maximumFractionDigits: 2 })
  const max = rule.max_km === null || rule.max_km === undefined ? 'unlimited' : Number(rule.max_km).toLocaleString('id-ID', { maximumFractionDigits: 2 })

  return `${min} - ${max} KM dari pusat cabang`
}

function formatRingPrice(rule: RingPricingRule) {
  if (rule.pricing_mode === 'formula') {
    return `(${(rule.per_km_rate ?? 0).toLocaleString('id-ID')} x KM) - ${(rule.subtract_value ?? 0).toLocaleString('id-ID')}`
  }

  return `Rp ${rule.price.toLocaleString('id-ID')}`
}

function ringLabel(value: string) {
  return value.replace(/_/g, ' ').replace(/\bring\b/i, 'Ring').replace(/\b(\d)\b/, '$1')
}

function suggestionTypeLabel(value?: string | null) {
  if (value === 'request_order_sample') return 'Request order'
  if (value === 'price_edit') return 'Edit harga'
  return 'AI Learn'
}

export function MasterPricingPanel({ data, onNavigate }: { data: Bootstrap; onNavigate: (view: View) => void }) {
  const cards: Array<{ view: View; title: string; value: string; copy: string }> = [
    { view: 'ring-pricing', title: 'Master Ring', value: `${data.ring_pricing_rules?.length ?? 0}`, copy: 'Polygon, jarak pusat cabang, service fee, formula, dan cross ring.' },
    { view: 'pricing-keyword-rules', title: 'Pricing Keyword Rules', value: `${data.pricing_keyword_rules?.length ?? 0}`, copy: 'Tambahan jasa dari keyword dan sub keyword.' },
    { view: 'keyword-parsers', title: 'Keyword Parsers', value: `${data.keyword_parsers?.length ?? 0}`, copy: 'Keyword JojoBot dan schema form order.' },
  ]

  return (
    <section className="panel master-pricing-hub">
      <div className="section-head">
        <div>
          <h2>Master Pricing</h2>
          <p>Pusat kontrol tarif. Menu yang tampil tetap mengikuti role preview admin.</p>
        </div>
      </div>
      <div className="master-pricing-grid">
        {cards.map((card) => (
          <button className="master-pricing-card" key={card.view} type="button" onClick={() => onNavigate(card.view)}>
            <span>{card.title}</span>
            <strong>{card.value}</strong>
            <small>{card.copy}</small>
          </button>
        ))}
      </div>
    </section>
  )
}

const backendCmsLinks: Partial<Record<View, { title: string; path: string; copy: string }>> = {
  'order-crew-rules': {
    title: 'Order Crew Rules',
    path: '/admin/order-crew-rules',
    copy: 'Atur rule multi crew seperti rider/helper, keyword pemicu, timeout helper, dan harga helper dari backend CMS.',
  },
  banners: {
    title: 'Banners',
    path: '/admin/banners',
    copy: 'Kelola gambar banner home customer. Preview role memastikan menu ini bisa dibuka sesuai izin role.',
  },
  'home-sections': {
    title: 'Home Sections',
    path: '/admin/home-sections',
    copy: 'Atur section home customer seperti slider, promo, dan susunan konten CMS.',
  },
  'home-items': {
    title: 'Home Items',
    path: '/admin/home-items',
    copy: 'Kelola item konten home customer, termasuk jadwal tampil dan masa berlaku.',
  },
  announcements: {
    title: 'Announcements',
    path: '/admin/announcements',
    copy: 'Kelola pengumuman/promo khusus yang tampil pada home customer.',
  },
}

function isBackendCmsView(view: View) {
  return Boolean(backendCmsLinks[view])
}

function BackendCmsLinkPanel({ view }: { view: View }) {
  const link = backendCmsLinks[view]
  const [cms, setCms] = useState<AdminHomeCmsData | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  useEffect(() => {
    let active = true
    setLoading(true)
    setError('')
    fetch(`${API_BASE}/home`, { headers: { Accept: 'application/json' } })
      .then((response) => {
        if (!response.ok) throw new Error(`HTTP ${response.status}`)
        return response.json()
      })
      .then((payload: AdminHomeCmsData) => {
        if (!active) return
        setCms({
          banners: payload.banners ?? [],
          sections: payload.sections ?? [],
          items: payload.items ?? [],
          announcements: payload.announcements ?? [],
        })
      })
      .catch((fetchError) => {
        if (!active) return
        setError(fetchError instanceof Error ? fetchError.message : 'Gagal memuat preview CMS')
      })
      .finally(() => {
        if (active) setLoading(false)
      })

    return () => {
      active = false
    }
  }, [])

  if (!link) return null
  const stats = cms ? cmsStats(cms) : []

  return (
    <section className="panel backend-cms-panel home-cms-preview-panel">
      <div className="backend-cms-head">
        <div>
          <span className="eyebrow">Backend CMS</span>
          <h2>{link.title}</h2>
          <p>{link.copy}</p>
        </div>
        <a className="primary-link-button" href={`${APP_BASE}${link.path}`} target="_blank" rel="noreferrer">
          Buka di Backend
        </a>
      </div>
      <div className="home-cms-stat-grid">
        {stats.map((stat) => <div className={stat.active ? 'home-cms-stat active' : 'home-cms-stat'} key={stat.label}><span>{stat.label}</span><strong>{stat.value}</strong><small>{stat.copy}</small></div>)}
      </div>
      {loading && <div className="home-cms-empty">Memuat preview CMS...</div>}
      {!loading && error && <div className="home-cms-empty danger">Preview belum bisa dimuat: {error}</div>}
      {!loading && cms && <HomeCmsPreview view={view} cms={cms} />}
    </section>
  )
}

function cmsStats(cms: AdminHomeCmsData) {
  return [
    { label: 'Banner', value: cms.banners.length, copy: 'slide aktif di home', active: cms.banners.length > 0 },
    { label: 'Section', value: cms.sections.length, copy: 'blok konten customer', active: cms.sections.length > 0 },
    { label: 'Item', value: (cms.items?.length ?? cms.sections.flatMap((section) => section.items ?? []).length), copy: 'konten dalam section', active: (cms.items?.length ?? cms.sections.flatMap((section) => section.items ?? []).length) > 0 },
    { label: 'Announcement', value: cms.announcements.length, copy: 'promo/pengumuman', active: cms.announcements.length > 0 },
  ]
}

function HomeCmsPreview({ view, cms }: { view: View; cms: AdminHomeCmsData }) {
  const items = cms.items?.length ? cms.items : cms.sections.flatMap((section) => section.items ?? [])

  if (view === 'banners') {
    return (
      <div className="home-cms-preview-grid banner">
        {cms.banners.map((banner) => <CmsImageCard key={banner.id} title={banner.title} subtitle={cmsDateRange(banner.start_date, banner.end_date)} image={banner.image_original ?? banner.image} badge={`Urutan ${banner.order ?? 0}`} />)}
        {cms.banners.length === 0 && <HomeCmsEmpty title="Banner belum tersedia" copy="Tambahkan banner di backend agar muncul sebagai slider di home customer." />}
      </div>
    )
  }

  if (view === 'home-sections') {
    return (
      <div className="home-cms-section-list">
        {cms.sections.map((section) => (
          <article className="home-cms-section-card" key={section.id}>
            <div><strong>{section.name}</strong><span>{section.type} · {section.items?.length ?? 0} item</span></div>
            <b>{section.is_active === false ? 'Nonaktif' : 'Aktif'}</b>
          </article>
        ))}
        {cms.sections.length === 0 && <HomeCmsEmpty title="Section belum tersedia" copy="Buat section untuk menentukan area slider, promo, atau pengumuman." />}
      </div>
    )
  }

  if (view === 'home-items') {
    return (
      <div className="home-cms-preview-grid">
        {items.map((item) => <CmsImageCard key={item.id} title={item.title} subtitle={item.subtitle ?? cmsDateRange(item.start_date, item.end_date)} image={item.image_original ?? item.image ?? item.icon} badge={`Urutan ${item.order ?? 0}`} />)}
        {items.length === 0 && <HomeCmsEmpty title="Home item belum tersedia" copy="Item akan mengisi section home customer sesuai jadwal tampilnya." />}
      </div>
    )
  }

  if (view === 'announcements') {
    return (
      <div className="home-cms-section-list">
        {cms.announcements.map((announcement) => (
          <article className="home-cms-announcement-card" key={announcement.id}>
            <strong>{announcement.title}</strong>
            <p>{announcement.content}</p>
            <span>{cmsDateRange(announcement.start_date, announcement.end_date)}</span>
          </article>
        ))}
        {cms.announcements.length === 0 && <HomeCmsEmpty title="Announcement belum tersedia" copy="Announcement tampil sebagai promo/pemberitahuan pada home customer." />}
      </div>
    )
  }

  return null
}

function CmsImageCard({ title, subtitle, image, badge }: { title: string; subtitle?: string | null; image?: string | null; badge?: string }) {
  const src = image ? assetUrl(image) : ''

  return (
    <article className="home-cms-image-card">
      {src ? <img src={src} alt={title} loading="lazy" /> : <div className="home-cms-image-placeholder"><Icon name="note" /></div>}
      <div>
        {badge && <span>{badge}</span>}
        <strong>{title}</strong>
        {subtitle && <small>{subtitle}</small>}
      </div>
    </article>
  )
}

function HomeCmsEmpty({ title, copy }: { title: string; copy: string }) {
  return <div className="home-cms-empty"><strong>{title}</strong><p>{copy}</p></div>
}

function cmsDateRange(start?: string | null, end?: string | null) {
  if (!start && !end) return 'Selalu tampil'
  return [start ? `Mulai ${formatShortDateTime(start)}` : null, end ? `Sampai ${formatShortDateTime(end)}` : null].filter(Boolean).join(' · ')
}

function KeywordParsersPanel({ parsers, services, permissions, api, onChanged }: { parsers: KeywordParser[]; services: ServiceRow[]; permissions: Permissions; api: ApiClient; onChanged: () => Promise<void> }) {
  const [showForm, setShowForm] = useState(false)
  const [message, setMessage] = useState('')
  const canManage = permissions.can_edit_order_price || permissions.can_manage_policy
  const serviceOptions = services.map((service) => ({ label: `${service.name} (${service.code})`, value: service.code.toUpperCase() }))

  const create = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    const formElement = event.currentTarget
    const form = new FormData(formElement)
    let schema: unknown = { fields: [] }
    const schemaText = String(form.get('form_schema') ?? '').trim()
    if (schemaText) schema = JSON.parse(schemaText)
    await api('/admin/keyword-parsers', {
      method: 'POST',
      body: JSON.stringify({
        keyword: form.get('keyword'),
        service_type: form.get('service_type'),
        parser_type: form.get('parser_type'),
        response_template: form.get('response_template'),
        form_schema: schema,
        priority: Number(form.get('priority') || 0),
        is_active: form.get('is_active') === 'on',
      }),
    })
    formElement.reset()
    setShowForm(false)
    setMessage('Keyword parser berhasil disimpan.')
    await onChanged()
  }

  const destroy = async (parser: KeywordParser) => {
    if (!confirm(`Hapus keyword parser ${parser.keyword}?`)) return
    await api(`/admin/keyword-parsers/${parser.id}`, { method: 'DELETE' })
    setMessage('Keyword parser berhasil dihapus.')
    await onChanged()
  }

  return (
    <section className="panel keyword-cms-panel">
      <div className="section-head">
        <div><h2>Keyword Parsers</h2><p>Atur keyword JojoBot, tipe parser, template jawaban, dan schema form.</p></div>
        {canManage && <button className="primary-button compact" type="button" onClick={() => setShowForm((value) => !value)}><Icon name="plus" />{showForm ? 'Tutup' : 'Tambah Parser'}</button>}
      </div>
      {message && <div className="notice success">{message}</div>}
      {showForm && canManage && (
        <form className="admin-inline-form keyword-create-form" onSubmit={(event) => void create(event).catch((error) => setMessage(error instanceof Error ? error.message : 'Gagal menyimpan parser'))}>
          <label>Keyword<input name="keyword" required placeholder="belanja, beli, belikan" /></label>
          <label>Service<select name="service_type" required>{serviceOptions.map((service) => <option key={service.value} value={service.value}>{service.label}</option>)}</select></label>
          <label>Parser<select name="parser_type" defaultValue="simple"><option value="simple">Simple</option><option value="advanced">Advanced</option></select></label>
          <label>Priority<input name="priority" type="number" defaultValue="0" /></label>
          <label className="toggle-row inline-toggle"><input name="is_active" type="checkbox" defaultChecked />Aktif</label>
          <label className="span-2">Response<textarea name="response_template" required placeholder="Silakan isi form order..." /></label>
          <label className="span-2">Form schema JSON<textarea name="form_schema" placeholder='{"fields":[{"label":"Alamat","name":"pickup","type":"text","required":true}]}' /></label>
          <div className="ring-form-actions"><button className="secondary-button" type="button" onClick={() => setShowForm(false)}>Batal</button><button className="primary-button" type="submit">Simpan Parser</button></div>
        </form>
      )}
      <div className="keyword-rule-list">
        {parsers.map((parser) => (
          <article className="keyword-rule-card" key={parser.id}>
            <div><strong>{parser.keyword}</strong><span>{parser.service_type} · {parser.parser_type} · priority {parser.priority}</span><small>{parser.response_template}</small></div>
            <span className={parser.is_active ? 'status success' : 'status muted'}>{parser.is_active ? 'Aktif' : 'Nonaktif'}</span>
            {canManage && <button className="mini-button reject" type="button" onClick={() => void destroy(parser)}>Delete</button>}
          </article>
        ))}
      </div>
      {parsers.length === 0 && <EmptyPanel title="Keyword parser kosong" copy="Tambahkan keyword agar JojoBot bisa memilih layanan dan schema form." />}
    </section>
  )
}

function PricingKeywordRulesPanel({ rules, services, permissions, api, onChanged }: { rules: PricingKeywordRule[]; services: ServiceRow[]; permissions: Permissions; api: ApiClient; onChanged: () => Promise<void> }) {
  const [showForm, setShowForm] = useState(false)
  const [message, setMessage] = useState('')
  const canManage = permissions.can_edit_order_price || permissions.can_manage_policy
  const serviceOptions = [{ label: 'All services', value: 'all' }, ...zoneServiceOptions(services)]

  const create = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    const formElement = event.currentTarget
    const form = new FormData(formElement)
    const scopes = form.getAll('service_scopes').map(String)
    await api('/admin/pricing-keyword-rules', {
      method: 'POST',
      body: JSON.stringify({
        name: form.get('name'),
        keywords: form.get('keywords'),
        amount: Number(form.get('amount') || 0),
        service_scopes: scopes.length > 0 ? scopes : ['all'],
        priority: Number(form.get('priority') || 0),
        is_active: form.get('is_active') === 'on',
        description: form.get('description') || null,
      }),
    })
    formElement.reset()
    setShowForm(false)
    setMessage('Pricing keyword rule berhasil disimpan.')
    await onChanged()
  }

  const destroy = async (rule: PricingKeywordRule) => {
    if (!confirm(`Hapus pricing keyword ${rule.name}?`)) return
    await api(`/admin/pricing-keyword-rules/${rule.id}`, { method: 'DELETE' })
    setMessage('Pricing keyword rule berhasil dihapus.')
    await onChanged()
  }

  return (
    <section className="panel keyword-cms-panel">
      <div className="section-head">
        <div><h2>Pricing Keyword Rules</h2><p>Rule tambahan service charge dari keyword, termasuk sub keyword seperti depan roxy.</p></div>
        {canManage && <button className="primary-button compact" type="button" onClick={() => setShowForm((value) => !value)}><Icon name="plus" />{showForm ? 'Tutup' : 'Tambah Rule'}</button>}
      </div>
      {message && <div className="notice success">{message}</div>}
      {showForm && canManage && (
        <form className="admin-inline-form keyword-create-form" onSubmit={(event) => void create(event).catch((error) => setMessage(error instanceof Error ? error.message : 'Gagal menyimpan rule'))}>
          <label>Nama<input name="name" required placeholder="Depan Roxy charge" /></label>
          <label>Keywords<input name="keywords" required placeholder="depan roxy, seberang roxy" /></label>
          <label>Nominal<input name="amount" type="number" step="1000" defaultValue="3000" required /><small>Isi minus untuk diskon, contoh -2000.</small></label>
          <label>Priority<input name="priority" type="number" defaultValue="0" /></label>
          <label className="span-2">Scope layanan<select name="service_scopes" multiple defaultValue={['all']}>{serviceOptions.map((service) => <option key={service.value} value={service.value}>{service.label}</option>)}</select></label>
          <label className="toggle-row inline-toggle"><input name="is_active" type="checkbox" defaultChecked />Aktif</label>
          <label className="span-2">Deskripsi<textarea name="description" placeholder="Catatan internal rule." /></label>
          <div className="ring-form-actions"><button className="secondary-button" type="button" onClick={() => setShowForm(false)}>Batal</button><button className="primary-button" type="submit">Simpan Rule</button></div>
        </form>
      )}
      <div className="keyword-rule-list">
        {rules.map((rule) => (
          <article className="keyword-rule-card" key={rule.id}>
            <div><strong>{rule.name}</strong><span>{rule.keywords} · {rule.service_scopes?.join(', ') || 'all'} · priority {rule.priority}</span>{rule.description && <small>{rule.description}</small>}</div>
            <em>Rp {rule.amount.toLocaleString('id-ID')}</em>
            <span className={rule.is_active ? 'status success' : 'status muted'}>{rule.is_active ? 'Aktif' : 'Nonaktif'}</span>
            {canManage && <button className="mini-button reject" type="button" onClick={() => void destroy(rule)}>Delete</button>}
          </article>
        ))}
      </div>
      {rules.length === 0 && <EmptyPanel title="Pricing keyword kosong" copy="Tambahkan keyword charge agar service charge tidak perlu hardcoded." />}
    </section>
  )
}

function ZonePricingPanel({ rules, branches, geofences, services, permissions, api, onChanged }: { rules: ZonePricingRule[]; branches: Branch[]; geofences: Geofence[]; services: ServiceRow[]; permissions: Permissions; api: ApiClient; onChanged: () => Promise<void> }) {
  const [showForm, setShowForm] = useState(false)
  const [saving, setSaving] = useState(false)
  const [message, setMessage] = useState('')
  const activeRules = rules.filter((rule) => rule.is_active).length
  const polygonRules = rules.filter((rule) => rule.geofence_area?.shape_type === 'polygon').length
  const canManage = permissions.can_edit_order_price || permissions.can_manage_policy
  const serviceOptions = zoneServiceOptions(services)

  const create = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    const formElement = event.currentTarget
    const form = new FormData(formElement)
    setSaving(true)
    setMessage('')
    try {
      await api('/admin/zone-pricing-rules', {
        method: 'POST',
        body: JSON.stringify({
          name: form.get('name'),
          branch_id: Number(form.get('branch_id')) || null,
          geofence_area_id: Number(form.get('geofence_area_id')),
          service_type: form.get('service_type') || null,
          match_point: form.get('match_point') || 'destination',
          price_mode: form.get('price_mode') || 'fixed',
          amount: Number(form.get('amount') || 0),
          percent: form.get('percent') ? Number(form.get('percent')) : null,
          min_km: form.get('min_km') ? Number(form.get('min_km')) : null,
          max_km: form.get('max_km') ? Number(form.get('max_km')) : null,
          priority: Number(form.get('priority') || 0),
          is_active: form.get('is_active') === 'on',
          notes: form.get('notes') || null,
        }),
      })
      formElement.reset()
      setShowForm(false)
      setMessage('Zone pricing rule berhasil disimpan.')
      await onChanged()
    } catch (error) {
      setMessage(error instanceof Error ? error.message : 'Gagal menyimpan Zone Pricing.')
    } finally {
      setSaving(false)
    }
  }

  const destroy = async (rule: ZonePricingRule) => {
    if (!confirm(`Hapus rule zona ${rule.name}?`)) return
    setMessage('')
    try {
      await api(`/admin/zone-pricing-rules/${rule.id}`, { method: 'DELETE' })
      setMessage('Zone pricing rule berhasil dihapus.')
      await onChanged()
    } catch (error) {
      setMessage(error instanceof Error ? error.message : 'Gagal menghapus Zone Pricing.')
    }
  }

  return (
    <section className="panel zone-pricing-panel">
      <div className="section-head master-ring-head">
        <div>
          <h2>Zone Pricing Rules</h2>
          <p>Tarif khusus berdasarkan geofence circle atau polygon. Akses menu ini mengikuti role preview admin.</p>
        </div>
        {canManage && <button className="primary-button compact" type="button" onClick={() => setShowForm((value) => !value)}><Icon name="plus" />{showForm ? 'Tutup Form' : 'Tambah Rule'}</button>}
      </div>

      <div className="zone-help-card">
        <strong>Alur singkat</strong>
        <span>Order masuk, cabang dideteksi dari geofence, tarif dasar/ring dihitung, rule zona dicek sesuai pickup/tujuan, lalu fixed/extra/percent diterapkan.</span>
      </div>

      <div className="master-ring-summary zone-summary">
        <article><span>Total rule</span><strong>{rules.length}</strong><small>{activeRules} aktif</small></article>
        <article><span>Polygon rule</span><strong>{polygonRules}</strong><small>Area gambar bebas</small></article>
        <article><span>Geofence tersedia</span><strong>{geofences.length}</strong><small>{branches.length} cabang</small></article>
      </div>

      {message && <div className={message.toLowerCase().includes('gagal') || message.toLowerCase().includes('error') ? 'notice danger' : 'notice success'}>{message}</div>}

      {showForm && canManage && (
        <form className="admin-inline-form zone-create-form" onSubmit={create}>
          <div className="ring-form-title"><strong>Tambah Zone Pricing</strong><span>Gunakan tester setelah simpan untuk memastikan rule yang aktif sudah benar.</span></div>
          <label>Nama rule<input name="name" required placeholder="STB Panarukan fixed 12k" /></label>
          <label>Cabang<select name="branch_id"><option value="">Global</option>{branches.map((branch) => <option key={branch.id} value={branch.id}>{branchLabel(branch)}</option>)}</select></label>
          <label>Zona / Geofence<select name="geofence_area_id" required><option value="">Pilih zona</option>{geofences.map((area) => <option key={area.id} value={area.id}>{geofenceOptionLabel(area)}</option>)}</select></label>
          <label>Layanan<select name="service_type"><option value="">Semua layanan</option>{serviceOptions.map((service) => <option key={service.value} value={service.value}>{service.label}</option>)}</select></label>
          <label>Titik dicek<select name="match_point" defaultValue="destination"><option value="destination">Tujuan / alamat antar</option><option value="pickup">Pickup / lokasi pembelian</option><option value="either">Pickup atau tujuan</option><option value="both">Pickup dan tujuan</option></select></label>
          <label>Mode tarif<select name="price_mode" defaultValue="fixed"><option value="fixed">Fixed mengganti tarif dasar</option><option value="extra">Extra tambah nominal</option><option value="percent">Percent tambah persen</option></select></label>
          <label>Nominal Rp<input name="amount" type="number" min="0" step="1000" defaultValue="12000" /></label>
          <label>Persen<input name="percent" type="number" min="0" max="300" step="1" placeholder="Untuk mode percent" /></label>
          <label>Min KM<input name="min_km" type="number" min="0" step="0.1" placeholder="Opsional" /></label>
          <label>Max KM<input name="max_km" type="number" min="0" step="0.1" placeholder="Opsional" /></label>
          <label>Priority<input name="priority" type="number" defaultValue="0" /></label>
          <label className="toggle-row inline-toggle"><input name="is_active" type="checkbox" defaultChecked />Aktif</label>
          <label className="span-2">Catatan<textarea name="notes" placeholder="Catatan internal admin, tidak tampil ke customer." /></label>
          <div className="ring-form-actions"><button className="secondary-button" type="button" onClick={() => setShowForm(false)}>Batal</button><button className="primary-button" type="submit" disabled={saving}>{saving ? 'Menyimpan...' : 'Simpan Rule'}</button></div>
        </form>
      )}

      <div className="zone-rule-grid">
        {rules.map((rule) => (
          <article className={rule.is_active ? 'zone-rule-card' : 'zone-rule-card inactive'} key={rule.id}>
            <div className="zone-rule-main">
              <div className="ring-card-title"><strong>{rule.name}</strong><span className={rule.is_active ? 'status success' : 'status muted'}>{rule.is_active ? 'Aktif' : 'Nonaktif'}</span></div>
              <span>{rule.branch ? branchLabel(rule.branch as Branch) : 'Global'} · {rule.service_type ?? 'semua layanan'} · {zoneMatchPointLabel(rule.match_point)}</span>
              <small>{rule.geofence_area?.name ?? `Geofence #${rule.geofence_area_id}`} · {zoneShapeLabel(rule.geofence_area?.shape_type)} · priority {rule.priority}</small>
              {(rule.min_km !== null || rule.max_km !== null) && <small>Jarak: {rule.min_km ?? 0} - {rule.max_km ?? 'unlimited'} km</small>}
              {rule.notes && <p>{rule.notes}</p>}
            </div>
            <div className="zone-rule-price">
              <span>{zonePriceModeLabel(rule.price_mode)}</span>
              <strong>{zoneRuleValue(rule)}</strong>
              {canManage && <button className="mini-button reject" type="button" onClick={() => void destroy(rule)}>Delete</button>}
            </div>
          </article>
        ))}
      </div>
      {rules.length === 0 && <EmptyPanel title="Belum ada Zone Pricing" copy="Tambahkan rule untuk tarif berbasis area geofence atau polygon." />}
    </section>
  )
}

function ZonePricingTesterPanel({ branches, geofences, services, api }: { branches: Branch[]; geofences: Geofence[]; services: ServiceRow[]; api: ApiClient }) {
  const [result, setResult] = useState<ZonePricingTesterResult | null>(null)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')
  const serviceOptions = zoneServiceOptions(services)

  const submit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    const form = new FormData(event.currentTarget)
    setLoading(true)
    setError('')
    setResult(null)
    try {
      const payload = await api<{ data: ZonePricingTesterResult }>('/admin/zone-pricing-tester', {
        method: 'POST',
        body: JSON.stringify({
          branch_id: Number(form.get('branch_id')) || null,
          service_type: form.get('service_type') || 'delivery',
          pickup_lat: Number(form.get('pickup_lat')),
          pickup_lng: Number(form.get('pickup_lng')),
          destination_lat: Number(form.get('destination_lat')),
          destination_lng: Number(form.get('destination_lng')),
          stops: Number(form.get('stops') || 1),
          notes: form.get('notes') || null,
        }),
      })
      setResult(payload.data)
    } catch (error) {
      setError(error instanceof Error ? error.message : 'Tester gagal dijalankan.')
    } finally {
      setLoading(false)
    }
  }

  return (
    <section className="panel zone-tester-panel">
      <div className="section-head master-ring-head">
        <div>
          <h2>Zone Pricing Tester</h2>
          <p>Simulasikan pickup dan tujuan untuk melihat cabang, geofence, dan rule tarif yang akan dipakai sistem.</p>
        </div>
        <span className="status info">{geofences.length} geofence</span>
      </div>
      <div className="zone-tester-layout">
        <form className="admin-inline-form zone-tester-form" onSubmit={submit}>
          <label>Cabang fallback<select name="branch_id"><option value="">Auto dari geofence</option>{branches.map((branch) => <option key={branch.id} value={branch.id}>{branchLabel(branch)}</option>)}</select></label>
          <label>Layanan<select name="service_type" defaultValue="delivery">{serviceOptions.map((service) => <option key={service.value} value={service.value}>{service.label}</option>)}</select></label>
          <label>Pickup latitude<input name="pickup_lat" type="number" step="0.00000001" defaultValue="-7.70630000" required /></label>
          <label>Pickup longitude<input name="pickup_lng" type="number" step="0.00000001" defaultValue="114.00980000" required /></label>
          <div className="coordinate-actions">
            <span>Pickup map</span>
            <button className="secondary-button compact" type="button" onClick={(event) => openMapsFromForm(event.currentTarget.form, 'pickup')}><Icon name="map" />Buka Maps</button>
          </div>
          <label>Tujuan latitude<input name="destination_lat" type="number" step="0.00000001" defaultValue="-7.71000000" required /></label>
          <label>Tujuan longitude<input name="destination_lng" type="number" step="0.00000001" defaultValue="114.02000000" required /></label>
          <div className="coordinate-actions">
            <span>Tujuan map</span>
            <button className="secondary-button compact" type="button" onClick={(event) => openMapsFromForm(event.currentTarget.form, 'destination')}><Icon name="map" />Buka Maps</button>
          </div>
          <label>Jumlah titik<input name="stops" type="number" min="1" defaultValue="1" /></label>
          <label className="span-2">Catatan / keyword<textarea name="notes" placeholder="Contoh: depan roxy, pasar panji, kue tart" /></label>
          <button className="primary-button" type="submit" disabled={loading}>{loading ? 'Testing...' : 'Test Zone Pricing'}</button>
        </form>

        <div className="zone-tester-result">
          {error && <div className="notice danger">{error}</div>}
          {!result && !error && <EmptyPanel title="Belum ada hasil tester" copy="Isi koordinat lalu klik Test Zone Pricing." />}
          {result && (
            <>
              <div className="tester-result-head">
                <span>Cabang terdeteksi</span>
                <strong>{result.branch ? branchLabel(result.branch as Branch) : 'Belum terdeteksi'}</strong>
              </div>
              <div className="tester-point-grid">
                <ZonePointCard title="Pickup" point={result.pickup} />
                <ZonePointCard title="Tujuan" point={result.destination} />
              </div>
              <div className="tester-quote-card">
                <span>Tarif hasil simulasi</span>
                <strong>Rp {Number(result.quote?.final_price ?? result.quote?.total_price ?? result.quote?.price ?? 0).toLocaleString('id-ID')}</strong>
                <small>Source: {String(result.quote?.tarif_source ?? 'default')} · Rule: {String(result.quote?.zone_pricing_rule_name ?? '-')}</small>
              </div>
              <pre className="zone-json-preview">{JSON.stringify(result.quote ?? {}, null, 2)}</pre>
            </>
          )}
        </div>
      </div>
    </section>
  )
}

function ZonePointCard({ title, point }: { title: string; point?: ZonePricingPoint | null }) {
  return (
    <article className="zone-point-card">
      <span>{title}</span>
      <strong>{point?.area?.name ?? 'Di luar geofence'}</strong>
      <small>{point?.branch ? branchLabel(point.branch as Branch) : '-'} · {point?.area ? zoneShapeLabel(point.area.shape_type) : 'no zone'}</small>
      {point?.distance_meters !== null && point?.distance_meters !== undefined && <em>{Math.round(point.distance_meters)} m dari center</em>}
    </article>
  )
}

function ReportsPanel({ data, api, token }: { data: Bootstrap; api: ApiClient; token: string }) {
  const now = new Date()
  const [month, setMonth] = useState(now.getMonth() + 1)
  const [year, setYear] = useState(now.getFullYear())
  const [depositVehicleType, setDepositVehicleType] = useState<'motor' | 'mobil'>('motor')
  const [depositRows, setDepositRows] = useState<DepositReportRow[]>([])
  const [loadingDeposits, setLoadingDeposits] = useState(false)
  const [depositFullscreen, setDepositFullscreen] = useState(false)
  const completed = data.orders.filter((order) => /completed|done/i.test(order.status)).length

  const loadDeposits = useCallback(async () => {
    setLoadingDeposits(true)
    try {
      const payload = await api<{ data: { rows: DepositReportRow[] } }>(`/admin/reports/driver-deposits?month=${month}&year=${year}&vehicle_type=${depositVehicleType}`)
      setDepositRows(payload.data.rows)
    } finally {
      setLoadingDeposits(false)
    }
  }, [api, month, year, depositVehicleType])

  useEffect(() => {
    void loadDeposits()
  }, [loadDeposits])

  useEffect(() => {
    if (!depositFullscreen) return

    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') setDepositFullscreen(false)
    }

    document.body.classList.add('admin-report-fullscreen-open')
    window.addEventListener('keydown', onKeyDown)

    return () => {
      document.body.classList.remove('admin-report-fullscreen-open')
      window.removeEventListener('keydown', onKeyDown)
    }
  }, [depositFullscreen])

  const depositColumnDefs = useMemo<ColDef<DepositReportRow>[]>(() => [
    { field: 'driver', headerName: 'Driver', pinned: 'left', minWidth: 150 },
    { field: 'area', headerName: 'Area', minWidth: 130 },
    { field: 'orders_count', headerName: 'JML Order Bulan Rekap', editable: true, type: 'numericColumn', width: 135, valueParser: agNumberParser, valueFormatter: agNumberFormatter, cellClass: 'ag-editable-money' },
    { field: 'base_service_omset', headerName: 'Omset Jasa Dasar Bulan Rekap', editable: true, type: 'numericColumn', width: 170, valueParser: agNumberParser, valueFormatter: agNumberFormatter, cellClass: 'ag-editable-money' },
    { field: 'base_service_deposit', headerName: 'Setoran 20% Bulan Rekap', editable: true, type: 'numericColumn', width: 165, valueParser: agNumberParser, valueFormatter: agNumberFormatter, cellClass: 'ag-editable-money' },
    { field: 'previous_bill', headerName: 'Tagihan Bln Lalu', editable: true, type: 'numericColumn', width: 135, valueParser: agNumberParser, valueFormatter: agNumberFormatter, cellClass: 'ag-editable-money' },
    { field: 'bpjs_jht', headerName: 'JHT BPJSTK', editable: false, type: 'numericColumn', width: 120, valueFormatter: agRequiredNumberFormatter },
    { field: 'bpjs', headerName: 'Premi BPJSTK', editable: false, type: 'numericColumn', width: 125, valueFormatter: agRequiredNumberFormatter },
    { field: 'previous_cashback_reward', headerName: 'Reward Cashback Bulan Lalu', editable: true, type: 'numericColumn', width: 160, valueParser: agNumberParser, valueFormatter: agNumberFormatter, cellClass: 'ag-editable-money', headerClass: 'ag-orange-head' },
    { field: 'bill_before_bansos', headerName: 'Total Tagihan', editable: true, type: 'numericColumn', width: 130, valueParser: agNumberParser, valueFormatter: agNumberFormatter, cellClass: 'ag-editable-money' },
    { field: 'bansos', headerName: 'Bansos Area', editable: true, type: 'numericColumn', width: 120, valueParser: agNumberParser, valueFormatter: agNumberFormatter, cellClass: 'ag-editable-money' },
    { field: 'total_bill', headerName: `Total Tagihan ${monthName(month).toUpperCase()}`, editable: true, type: 'numericColumn', width: 145, valueParser: agNumberParser, valueFormatter: agNumberFormatter, cellClass: 'ag-editable-money ag-total-cell', headerClass: 'ag-yellow-head' },
    { field: 'paid_amount', headerName: 'Terbayar', editable: true, type: 'numericColumn', width: 125, valueParser: agNumberParser, valueFormatter: agNumberFormatter, cellClass: 'ag-editable-money' },
    { field: 'remaining_bill', headerName: 'Sisa Tagihan', editable: true, type: 'numericColumn', width: 130, valueParser: agNumberParser, valueFormatter: agNumberFormatter, cellClass: 'ag-editable-money ag-total-cell', headerClass: 'ag-yellow-head' },
    { field: 'paid_at', headerName: 'Tgl Bayar (Auto)', editable: false, width: 135 },
    { field: 'status', headerName: 'Status', editable: true, cellEditor: 'agSelectCellEditor', cellEditorParams: { values: ['paid', 'unpaid'] }, width: 115 },
    { field: 'next_cashback', headerName: 'Cashback 10% Utk Bulan Depan', editable: true, type: 'numericColumn', width: 160, valueParser: agNumberParser, valueFormatter: agNumberFormatter, cellClass: 'ag-editable-money' },
  ], [month])

  const saveDepositCell = useCallback(async (event: CellValueChangedEvent<DepositReportRow>) => {
    const field = event.colDef.field
    const row = event.data
    if (!row?.driver_id || !field || event.newValue === event.oldValue) return
    if (!['orders_count', 'base_service_omset', 'base_service_deposit', 'previous_bill', 'previous_cashback_reward', 'bill_before_bansos', 'bansos', 'total_bill', 'paid_amount', 'remaining_bill', 'status', 'next_cashback'].includes(field)) return

    try {
      const payload = await api<{ data: { rows: DepositReportRow[] } }>(`/admin/reports/driver-deposits/${row.driver_id}?month=${month}&year=${year}&vehicle_type=${depositVehicleType}`, {
        method: 'PATCH',
        body: JSON.stringify({ [field]: event.newValue === '' ? null : event.newValue }),
      })
      setDepositRows(payload.data.rows)
    } catch (error) {
      alert(error instanceof Error ? error.message : 'Update setoran gagal.')
      await loadDeposits()
    }
  }, [api, loadDeposits, month, year, depositVehicleType])

  const exportExcel = async () => {
    const response = await fetch(`${API_BASE}/admin/reports/driver-deposits/export?month=${month}&year=${year}&vehicle_type=${depositVehicleType}`, {
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
    anchor.download = `rekap-setoran-driver-${depositVehicleType}-${year}-${String(month).padStart(2, '0')}.xls`
    anchor.click()
    URL.revokeObjectURL(url)
  }

  return (
    <div className="reports-stack">
      <section className="panel reports-panel">
        <div className="section-head"><div><h2>Reports</h2><p>Ringkasan operasional berdasarkan data yang bisa diakses role kamu.</p></div></div>
        <div className="report-grid"><ReportCard title="Orders" value={String(data.orders.length)} meta={`${completed} selesai`} tone="order" /><ReportCard title="Drivers" value={String(data.stats.total_drivers)} meta="visible drivers" tone="driver" /><ReportCard title="Suspicious GPS" value={String(data.location_logs.filter((log) => log.is_suspicious).length)} meta="needs review" tone="risk" /></div>
      </section>

      <section className={`panel deposit-report-panel${depositFullscreen ? ' is-fullscreen' : ''}`}>
        <div className="section-head">
          <div>
            <h2>Rekap Setoran Driver {depositVehicleType === 'mobil' ? 'Mobil' : 'Motor'}</h2>
            <p>Format mengikuti report setoran bulanan, data driver memakai username.</p>
          </div>
          <div className="deposit-report-actions">
            <select value={month} onChange={(event) => setMonth(Number(event.target.value))}>
              {Array.from({ length: 12 }, (_, index) => index + 1).map((item) => <option key={item} value={item}>{monthName(item)}</option>)}
            </select>
            <input type="number" value={year} min={2020} max={2100} onChange={(event) => setYear(Number(event.target.value))} />
            <select value={depositVehicleType} onChange={(event) => setDepositVehicleType(event.target.value as 'motor' | 'mobil')}>
              <option value="motor">Driver Motor</option>
              <option value="mobil">Driver Mobil</option>
            </select>
            {data.permissions.can_export_report && <button className="secondary-button compact" type="button" onClick={() => void exportExcel()}>Export Excel</button>}
            <button
              className="secondary-button compact"
              type="button"
              aria-pressed={depositFullscreen}
              onClick={() => setDepositFullscreen((value) => !value)}
            >
              {depositFullscreen ? 'Keluar Layar Penuh' : 'Layar Penuh'}
            </button>
          </div>
        </div>
        <div className="deposit-report-wrap ag-theme-quartz-dark jojo-deposit-grid">
          <Suspense fallback={<EmptyPanel title="Memuat grid report" copy="Komponen tabel besar sedang disiapkan." />}>
            <LazyAgGridReact
              rowData={depositRows}
              columnDefs={depositColumnDefs as never}
              getRowId={((params: { data: DepositReportRow }) => String(params.data.driver_id)) as never}
              defaultColDef={{ sortable: true, resizable: true, filter: true, wrapHeaderText: true, autoHeaderHeight: true }}
              headerHeight={76}
              floatingFiltersHeight={42}
              singleClickEdit
              stopEditingWhenCellsLoseFocus
              onCellValueChanged={(event) => void saveDepositCell(event as CellValueChangedEvent<DepositReportRow>)}
            />
          </Suspense>
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
      const form = new FormData(event.currentTarget)
      const body = new FormData()
      body.append('name', name.trim())
      body.append('phone', phone.trim())
      body.append('address', address.trim())
      const password = form.get('password')
      if (typeof password === 'string' && password.trim()) body.append('password', password.trim())
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
            {passwordEditableRoles.has(me.role) && <PasswordInput label="Password baru" placeholder="Kosongkan jika tidak diganti" helper="Minimal 8 karakter. Berlaku untuk login FE admin/BE." />}
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

type AuditSection = {
  id: string
  title: string
  description: string
  match: (log: AuditLog) => boolean
}

const auditSections: AuditSection[] = [
  {
    id: 'price',
    title: 'Audit Harga',
    description: 'Edit harga manual, live correction, approval dan reject harga customer.',
    match: (log) => isPriceAuditLog(log) || log.action.includes('live_price'),
  },
  {
    id: 'dispatch',
    title: 'Audit Dispatch & Order',
    description: 'Assign driver, repost, timeout, cancel, oper handle, dan perubahan order.',
    match: (log) => ['order', 'dispatch', 'assign', 'repost', 'cancel', 'oper'].some((keyword) => log.action.includes(keyword)),
  },
  {
    id: 'driver',
    title: 'Audit Driver & Setoran',
    description: 'Suspend, unsuspend, token driver, BPJS, setoran, dan konfigurasi driver.',
    match: (log) => ['driver', 'suspend', 'deposit', 'setoran', 'bpjs', 'bansos'].some((keyword) => log.action.includes(keyword)),
  },
  {
    id: 'security',
    title: 'Audit Security',
    description: 'Login gagal, reset token, session, auth Google, lock/unlock akun.',
    match: (log) => ['login', 'token', 'session', 'auth', 'google', 'lock'].some((keyword) => log.action.includes(keyword)),
  },
]

function AuditLogsPanel({ initialLogs, api }: { initialLogs: AuditLog[]; api: ApiClient }) {
  const [logs, setLogs] = useState<AuditLog[]>(initialLogs.filter((log) => !isInternalSccAuditLog(log)))
  const [query, setQuery] = useState('')
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')

  const refresh = useCallback(async () => {
    setLoading(true)
    setError('')
    try {
      const params = new URLSearchParams({ limit: '1000' })
      if (query.trim() !== '') params.set('q', query.trim())
      const response = await api<{ data: AuditLog[] }>(`/admin/audit-logs?${params.toString()}`)
      setLogs(response.data.filter((log) => !isInternalSccAuditLog(log)))
    } catch (error) {
      setError(error instanceof Error ? error.message : 'Audit logs belum bisa dimuat.')
    } finally {
      setLoading(false)
    }
  }, [api, query])

  useEffect(() => {
    const timer = window.setTimeout(() => void refresh(), 250)
    return () => window.clearTimeout(timer)
  }, [refresh])

  const normalizedQuery = query.trim().toLowerCase()
  const filteredLogs = logs.filter((log) => {
    if (normalizedQuery === '') return true
    return [
      log.user,
      log.role ?? '',
      log.action,
      log.subject_type,
      log.subject_label ?? '',
      JSON.stringify(log.metadata ?? {}),
    ].join(' ').toLowerCase().includes(normalizedQuery)
  })

  const sections = [
    ...auditSections.map((section) => ({ ...section, logs: filteredLogs.filter(section.match).slice(0, 250) })),
    {
      id: 'all',
      title: 'Semua Audit Logs',
      description: 'Gabungan seluruh audit terbaru sesuai scope role dan cabang.',
      match: () => true,
      logs: filteredLogs.slice(0, 500),
    },
  ]

  return (
    <section className="panel audit-logs-page">
      <PanelHeader title="Audit Logs" action={`${filteredLogs.length}/${logs.length} log`} />
      <div className="audit-logs-toolbar">
        <input value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Cari actor, action, subject, metadata..." />
        <button className="secondary-button compact" type="button" disabled={loading} onClick={() => void refresh()}>{loading ? 'Memuat...' : 'Refresh'}</button>
      </div>
      {error && <div className="notice danger">{error}</div>}
      <div className="audit-section-grid">
        {sections.map((section) => (
          <AuditLogSection key={section.id} title={section.title} description={section.description} logs={section.logs} filename={`audit-${section.id}`} />
        ))}
      </div>
    </section>
  )
}

function isInternalSccAuditLog(log: AuditLog) {
  const action = (log.action || '').toLowerCase()
  const subject = `${log.subject_type || ''} ${log.subject_label || ''}`.toLowerCase()
  return action.startsWith('system_control_') || subject.includes('system control center')
}

function AuditLogSection({ title, description, logs, filename }: { title: string; description: string; logs: AuditLog[]; filename: string }) {
  return (
    <article className="audit-log-card">
      <div className="audit-log-card-head">
        <div>
          <h2>{title}</h2>
          <p>{description}</p>
        </div>
        <div className="audit-log-actions">
          <span className="status muted">{logs.length} log</span>
          <button className="secondary-button compact" type="button" disabled={logs.length === 0} onClick={() => downloadAuditLogsXls(logs, filename)}>Export XLS</button>
        </div>
      </div>
      {logs.length === 0 && <EmptyPanel title="Belum ada data" copy="Audit log untuk kategori ini belum tersedia." />}
      {logs.length > 0 && (
        <div className="responsive-table audit-log-table-wrap">
          <table className="audit-log-table">
            <thead>
              <tr>
                <th className="audit-col-time">Waktu</th>
                <th className="audit-col-actor">Actor</th>
                <th className="audit-col-role">Role</th>
                <th className="audit-col-action">Action</th>
                <th className="audit-col-subject">Subject</th>
                <th className="audit-col-label">Label</th>
                <th className="audit-col-meta">Metadata</th>
              </tr>
            </thead>
            <tbody>
              {logs.map((log) => (
                <tr key={`${filename}-${log.id}`}>
                  <td>{formatShortDateTime(log.created_at)}</td>
                  <td>{log.user}</td>
                  <td><span className={`role-chip ${roleColors[(log.role ?? 'customer') as Role] ?? 'role-muted'}`}>{roleLabels[(log.role ?? 'customer') as Role] ?? '-'}</span></td>
                  <td><span className="status info">{log.action}</span></td>
                  <td>{log.subject_type}{log.subject_id ? ` #${log.subject_id}` : ''}</td>
                  <td>{log.subject_label ?? '-'}</td>
                  <td>{compactMetadata(log.metadata)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </article>
  )
}

function compactMetadata(metadata?: Record<string, unknown> | null) {
  const text = JSON.stringify(metadata ?? {}, null, 0)
  return text.length > 160 ? `${text.slice(0, 160)}...` : text
}

function downloadAuditLogsXls(logs: AuditLog[], prefix: string) {
  const headers = ['Waktu', 'Actor', 'Role', 'Action', 'Subject', 'Subject ID', 'Label', 'Metadata']
  const data = logs.map((log) => [
    formatShortDateTime(log.created_at),
    log.user,
    roleLabels[(log.role ?? 'customer') as Role] ?? '-',
    log.action,
    log.subject_type,
    log.subject_id ?? '',
    log.subject_label ?? '',
    JSON.stringify(log.metadata ?? {}),
  ])
  const html = `<table border="1"><thead><tr>${headers.map((header) => `<th>${escapeHtml(header)}</th>`).join('')}</tr></thead><tbody>${data.map((row) => `<tr>${row.map((cell) => `<td>${escapeHtml(String(cell))}</td>`).join('')}</tr>`).join('')}</tbody></table>`
  downloadTextFile(html, `${prefix}-${new Date().toISOString().slice(0, 10)}.xls`, 'application/vnd.ms-excel;charset=utf-8')
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
  const canPickAnyBranch = ['admin', 'gm'].includes(me.role)
  const branchOptions = useMemo(() => visibleBranchesForUser(branches, me, canPickAnyBranch).filter(isOperationalBranch), [branches, canPickAnyBranch, me])
  const ownBranch = branchOptions.find((branch) => branch.id === me.branch_id) ?? branchOptions.find((branch) => branch.parent_branch_id === me.branch_id) ?? null
  const [branchId, setBranchId] = useState(() => String(ownBranch?.id ?? branchOptions[0]?.id ?? ''))
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
    const userBranch = branchOptions.find((branch) => branch.id === me.branch_id) ?? branchOptions.find((branch) => branch.parent_branch_id === me.branch_id)
    if (userBranch && branchId !== String(userBranch.id)) {
      setBranchId(String(userBranch.id))
    }
  }, [branchId, branchOptions, branchTouched, me.branch_id])

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
              {branchOptions.map((branch) => <option key={branch.id} value={branch.id}>{branchLabel(branch)}</option>)}
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
          {containsTart && <div className="manual-route-status warning"><span>Rule kue tart</span><b>Submit membuat 1 order multi-crew. Rider menerima order dulu, lalu sistem membuka slot helper dengan harga sesuai CMS crew rule.</b></div>}
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

function LivePriceReviewPanel({ reviews, api, onChanged }: { reviews: LivePriceReview[]; api: ApiClient; onChanged: () => Promise<void> }) {
  const initialRows = reviews.filter((review) => visibleLivePriceReviewStatuses.has(review.status))
  const [rows, setRows] = useState<LivePriceReview[]>(initialRows)
  const [selectedId, setSelectedId] = useState<number | null>(initialRows[0]?.id ?? null)
  const [auditRows, setAuditRows] = useState<LivePriceReview[]>([])
  const [auditError, setAuditError] = useState('')
  const [auditAllowed, setAuditAllowed] = useState(true)
  const [savingId, setSavingId] = useState<number | null>(null)
  const [isEditing, setIsEditing] = useState(false)
  const syncingRef = useRef(false)

  useEffect(() => {
    const activeRows = reviews.filter((review) => visibleLivePriceReviewStatuses.has(review.status))
    setRows(activeRows)
    setSelectedId((current) => (current && activeRows.some((review) => review.id === current)) ? current : (activeRows[0]?.id ?? null))
  }, [reviews])

  const refreshAuditRows = useCallback(async () => {
    setAuditError('')
    try {
      const response = await api<{ data: LivePriceReview[] }>('/admin/live-price-review-audits')
      setAuditRows(response.data)
      setAuditAllowed(true)
    } catch (error) {
      setAuditRows([])
      if (error instanceof Error && error.message.includes('HTTP 403')) {
        setAuditAllowed(false)
        setAuditError('')
        return
      }
      setAuditError(error instanceof Error ? error.message : 'Audit live edit harga belum bisa dimuat.')
    }
  }, [api])

  const refreshReviews = useCallback(async (force = false) => {
    if (!force && (syncingRef.current || savingId || isEditing || document.visibilityState !== 'visible')) return
    syncingRef.current = true
    try {
      const response = await api<{ data: LivePriceReview[] }>('/admin/live-price-reviews')
      const activeRows = response.data.filter((review) => visibleLivePriceReviewStatuses.has(review.status))
      const currentIds = rows.map((review) => `${review.id}:${review.status}`).join('|')
      const nextIds = activeRows.map((review) => `${review.id}:${review.status}`).join('|')
      setRows(activeRows)
      setSelectedId((current) => (current && activeRows.some((review) => review.id === current)) ? current : (activeRows[0]?.id ?? null))
      if (currentIds !== nextIds) await onChanged()
    } finally {
      syncingRef.current = false
    }
  }, [api, isEditing, onChanged, rows, savingId])

  useEffect(() => {
    const interval = window.setInterval(() => void refreshReviews(), 7000)
    const onFocus = () => void refreshReviews()

    window.addEventListener('focus', onFocus)
    document.addEventListener('visibilitychange', onFocus)

    return () => {
      window.clearInterval(interval)
      window.removeEventListener('focus', onFocus)
      document.removeEventListener('visibilitychange', onFocus)
    }
  }, [refreshReviews])

  useEffect(() => {
    void refreshAuditRows()
  }, [refreshAuditRows])

  const updateRow = (id: number, patch: Partial<LivePriceReview>) => {
    setRows((current) => current.map((row) => row.id === id ? { ...row, ...patch } : row))
  }

  const approve = async (review: LivePriceReview) => {
    setSavingId(review.id)
    try {
      await api(`/admin/live-price-reviews/${review.id}/approve`, {
        method: 'POST',
        body: JSON.stringify({
          price: Number(review.corrected_price ?? review.system_price),
          service_fee: Number(review.corrected_service_fee ?? review.system_service_fee),
          extra_charge: Number(review.corrected_extra_charge ?? 0),
          reason: review.correction_reason ?? 'Harga dikonfirmasi live.',
        }),
      })
      setRows((current) => current.filter((row) => row.id !== review.id))
      setSelectedId((current) => (current === review.id ? null : current))
      await refreshReviews(true)
      await refreshAuditRows()
      await onChanged()
    } finally {
      setSavingId(null)
    }
  }

  const reject = async (review: LivePriceReview) => {
    const reason = window.prompt('Alasan tolak / minta customer ulang order', review.correction_reason ?? 'Alamat atau harga perlu dicek ulang.')
    if (reason === null) return
    setSavingId(review.id)
    try {
      await api(`/admin/live-price-reviews/${review.id}/reject`, {
        method: 'POST',
        body: JSON.stringify({ reason }),
      })
      setRows((current) => current.filter((row) => row.id !== review.id))
      setSelectedId((current) => (current === review.id ? null : current))
      await refreshReviews(true)
      await refreshAuditRows()
      await onChanged()
    } finally {
      setSavingId(null)
    }
  }

  const selected = rows.find((review) => review.id === selectedId) ?? rows[0] ?? null
  const selectedPayload = selected?.order_payload ?? null
  const correctedPrice = Number(selected?.corrected_price ?? selected?.system_price ?? 0)
  const correctedFee = Number(selected?.corrected_service_fee ?? selected?.system_service_fee ?? 0)
  const correctedExtra = Number(selected?.corrected_extra_charge ?? 0)
  const correctedTotal = correctedPrice + correctedFee + correctedExtra

  return (
    <section className="panel live-price-review-panel">
      <PanelHeader title="Live Edit Harga Customer" action={`${rows.length} review`} />
      <div className="notice">Koreksi Harga dari Customer oleh Operator/Eksekutor Secara Live.</div>
      <div className="manual-ai-actions">
        <button className="secondary-button compact" type="button" onClick={() => void refreshReviews(true)}>Refresh review</button>
      </div>
      <div className="live-price-dashboard-grid live-price-review-workspace">
        <div className="live-price-dashboard-column">
          <div className="live-price-dashboard-head"><span>Live order</span><b>{rows.length}</b></div>
          <div className="live-price-dashboard-queue">
            {rows.length === 0 && <EmptyPanel title="Tidak ada review harga" copy="Preview customer yang butuh koreksi harga akan muncul di sini." />}
            {rows.map((review) => (
              <button className={selected?.id === review.id ? 'live-price-queue-item active' : 'live-price-queue-item'} type="button" key={review.id} onClick={() => setSelectedId(review.id)}>
                <strong>{review.customer ?? 'Customer'}</strong>
                <span>{serviceDisplayName(review.service_type ?? review.order_payload?.service_type ?? 'Order')} - {review.branch ?? 'Cabang belum terbaca'}</span>
                <small>{review.status === 'cancelled' ? (review.correction_reason ?? 'Customer membatalkan order.') : `Rp ${Number(review.system_total_price ?? 0).toLocaleString('id-ID')}`} - {formatShortDateTime(review.created_at ?? null)}</small>
              </button>
            ))}
          </div>
        </div>

        <div className="live-price-dashboard-column preview">
          <div className="live-price-dashboard-head"><span>Preview order</span><b>{selected?.status ?? '-'}</b></div>
          {!selected && <EmptyPanel title="Belum ada preview" copy="Pilih live order untuk melihat detail parsing dan harga sistem." />}
          {selected && (
            <div className="live-price-dashboard-preview">
              <strong>{serviceDisplayName(selected.service_type ?? selectedPayload?.service_type ?? 'Order')}</strong>
              {selected.status === 'cancelled' && <div className="notice danger compact">{selected.correction_reason ?? `${selected.customer ?? 'Customer'} telah membatalkan order.`}</div>}
              <p className="preserve-lines">{selected.raw_text || '-'}</p>
              <div className="manual-preview-detail">
                <div><span>Pickup</span><b>{selectedPayload?.pickup_address ?? String(selected.parsed?.pickup_address ?? '-')}</b></div>
                <div><span>Tujuan</span><b>{selectedPayload?.destination_address ?? String(selected.parsed?.destination_address ?? '-')}</b></div>
                <div><span>Tarif sistem</span><b>Rp {Number(selected.system_price ?? 0).toLocaleString('id-ID')}</b></div>
                <div><span>Service fee</span><b>Rp {Number(selected.system_service_fee ?? 0).toLocaleString('id-ID')}</b></div>
                <div><span>Total sistem</span><b>Rp {Number(selected.system_total_price ?? 0).toLocaleString('id-ID')}</b></div>
              </div>
            </div>
          )}
        </div>

        <div className="live-price-dashboard-column edit">
          <div className="live-price-dashboard-head"><span>Edit harga</span><b>Final</b></div>
          {!selected && <EmptyPanel title="Belum ada koreksi" copy="Kolom edit aktif setelah ada order customer masuk." />}
          {selected && (
            <div className="live-price-dashboard-editor">
              {selected.status === 'cancelled' && <div className="notice danger compact">Order dibatalkan customer. Tidak perlu koreksi harga.</div>}
              <label>Tarif final<input type="number" value={correctedPrice} onFocus={() => setIsEditing(true)} onBlur={() => setIsEditing(false)} onChange={(event) => updateRow(selected.id, { corrected_price: Number(event.target.value) })} /></label>
              <label>Service fee<input type="number" value={correctedFee} onFocus={() => setIsEditing(true)} onBlur={() => setIsEditing(false)} onChange={(event) => updateRow(selected.id, { corrected_service_fee: Number(event.target.value) })} /></label>
              <label>Tambahan/potongan<input type="number" value={correctedExtra} onFocus={() => setIsEditing(true)} onBlur={() => setIsEditing(false)} onChange={(event) => updateRow(selected.id, { corrected_extra_charge: Number(event.target.value) })} /></label>
              <label>Alasan<textarea value={selected.correction_reason ?? ''} onFocus={() => setIsEditing(true)} onBlur={() => setIsEditing(false)} onChange={(event) => updateRow(selected.id, { correction_reason: event.target.value })} /></label>
              <div className="manual-preview-total"><span>Total customer</span><strong>Rp {correctedTotal.toLocaleString('id-ID')}</strong></div>
              <div className="manual-preview-actions">
                <button className="primary-button compact" type="button" disabled={savingId === selected.id || !editableLivePriceReviewStatuses.has(selected.status)} onClick={() => void approve({ ...selected, corrected_price: correctedPrice, corrected_service_fee: correctedFee, corrected_extra_charge: correctedExtra, corrected_total_price: correctedTotal })}>{savingId === selected.id ? 'Menyimpan...' : selected.status === 'pending' ? 'Konfirmasi harga' : 'Sudah dibatalkan'}</button>
                <button className="mini-button reject" type="button" disabled={savingId === selected.id || !editableLivePriceReviewStatuses.has(selected.status)} onClick={() => void reject(selected)}>Tolak</button>
              </div>
            </div>
          )}
        </div>
      </div>
      {auditAllowed && <LivePriceAuditTable rows={auditRows} error={auditError} onRefresh={() => void refreshAuditRows()} />}
    </section>
  )
}

function LivePriceAuditTable({ rows, error, onRefresh }: { rows: LivePriceReview[]; error: string; onRefresh: () => void }) {
  return (
    <div className="live-price-audit-card">
      <div className="section-head compact">
        <div>
          <h2>History Audit Live Edit Harga</h2>
          <p>Download performa koreksi harga operator dan eksekutor untuk evaluasi manajemen.</p>
        </div>
        <div className="manual-ai-actions">
          <button className="secondary-button compact" type="button" onClick={onRefresh}>Refresh audit</button>
          <button className="secondary-button compact" type="button" disabled={rows.length === 0} onClick={() => downloadLivePriceAudit(rows, 'xls')}>Export XLS</button>
          <button className="secondary-button compact" type="button" disabled={rows.length === 0} onClick={() => downloadLivePriceAudit(rows, 'csv')}>Export CSV</button>
        </div>
      </div>
      {error && <div className="notice danger">{error}</div>}
      {!error && rows.length === 0 && <EmptyPanel title="Belum ada audit" copy="Riwayat approve/reject live edit harga akan muncul setelah operator atau eksekutor memproses order." />}
      {!error && rows.length > 0 && (
        <div className="responsive-table live-price-audit-table-wrap">
          <table className="live-price-audit-table">
            <thead>
              <tr>
                <th>Waktu</th>
                <th>Status</th>
                <th>Reviewer</th>
                <th>Customer</th>
                <th>Cabang</th>
                <th>Layanan</th>
                <th>Order</th>
                <th>Sistem</th>
                <th>Koreksi</th>
                <th>Selisih</th>
                <th>Alasan</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((row) => {
                const systemTotal = Number(row.system_total_price ?? 0)
                const correctedTotal = Number(row.corrected_total_price ?? row.system_total_price ?? 0)
                const delta = correctedTotal - systemTotal
                return (
                  <tr key={row.id}>
                    <td>{formatShortDateTime(row.reviewed_at ?? row.updated_at ?? row.created_at ?? null)}</td>
                    <td><span className={`status ${row.status === 'rejected' ? 'danger' : row.status === 'pending' ? 'warning' : 'success'}`}>{row.status}</span></td>
                    <td>{row.reviewed_by ?? '-'}</td>
                    <td>{row.customer ?? '-'}</td>
                    <td>{row.branch ?? '-'}</td>
                    <td>{serviceDisplayName(row.service_type ?? '-')}</td>
                    <td>{row.order_code ?? '-'}</td>
                    <td>Rp {systemTotal.toLocaleString('id-ID')}</td>
                    <td>Rp {correctedTotal.toLocaleString('id-ID')}</td>
                    <td className={delta < 0 ? 'negative' : delta > 0 ? 'positive' : ''}>Rp {delta.toLocaleString('id-ID')}</td>
                    <td>{row.correction_reason ?? '-'}</td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}

function downloadLivePriceAudit(rows: LivePriceReview[], format: 'xls' | 'csv') {
  const headers = ['Waktu', 'Status', 'Reviewer', 'Customer', 'Cabang', 'Layanan', 'Order', 'Total Sistem', 'Total Koreksi', 'Selisih', 'Alasan']
  const data = rows.map((row) => {
    const systemTotal = Number(row.system_total_price ?? 0)
    const correctedTotal = Number(row.corrected_total_price ?? row.system_total_price ?? 0)
    return [
      formatShortDateTime(row.reviewed_at ?? row.updated_at ?? row.created_at ?? null),
      row.status,
      row.reviewed_by ?? '-',
      row.customer ?? '-',
      row.branch ?? '-',
      serviceDisplayName(row.service_type ?? '-'),
      row.order_code ?? '-',
      systemTotal,
      correctedTotal,
      correctedTotal - systemTotal,
      row.correction_reason ?? '-',
    ]
  })

  const filename = `audit-live-edit-harga-${new Date().toISOString().slice(0, 10)}.${format}`
  if (format === 'csv') {
    const csv = [headers, ...data]
      .map((row) => row.map((cell) => `"${String(cell).replace(/"/g, '""')}"`).join(','))
      .join('\n')
    downloadTextFile(csv, filename, 'text/csv;charset=utf-8')
    return
  }

  const html = `<table border="1"><thead><tr>${headers.map((header) => `<th>${escapeHtml(header)}</th>`).join('')}</tr></thead><tbody>${data.map((row) => `<tr>${row.map((cell) => `<td>${escapeHtml(String(cell))}</td>`).join('')}</tr>`).join('')}</tbody></table>`
  downloadTextFile(html, filename, 'application/vnd.ms-excel;charset=utf-8')
}

function downloadTextFile(content: string, filename: string, type: string) {
  const blob = new Blob([content], { type })
  const url = URL.createObjectURL(blob)
  const anchor = document.createElement('a')
  anchor.href = url
  anchor.download = filename
  anchor.click()
  URL.revokeObjectURL(url)
}

function escapeHtml(value: string) {
  return value
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;')
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

function serviceDisplayName(value: unknown) {
  const service = String(value ?? '').replace(/_/g, ' ').trim()
  return service ? service.replace(/\b\w/g, (letter) => letter.toUpperCase()) : 'Order'
}

function BranchesPanel({ branches, me, api, onChanged }: { branches: Branch[]; me: User; api: ApiClient; onChanged: () => Promise<void> }) {
  const [showForm, setShowForm] = useState(false)
  const canCreate = ['admin', 'gm'].includes(me.role)
  const regencyOptions = branches.filter(isRegencyBranch)
  const submit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    const formElement = event.currentTarget
    const form = new FormData(formElement)
    await api('/admin/branches', {
      method: 'POST',
      body: JSON.stringify({
        branch_code: String(form.get('branch_code') || '').trim().toUpperCase(),
        parent_branch_id: Number(form.get('parent_branch_id')) || null,
        name: form.get('name'),
        area: form.get('area'),
        latitude: Number(form.get('latitude')),
        longitude: Number(form.get('longitude')),
        radius_km: Number(form.get('radius_km') || 5),
      }),
    })
    formElement.reset()
    setShowForm(false)
    await onChanged()
  }
  return <section className="panel branches-panel"><div className="section-head"><div><h2>Branches</h2><p>Kelola Branch kota/kab dan Area operasional di bawahnya.</p></div>{canCreate && <button className="primary-button compact" onClick={() => setShowForm((value) => !value)} type="button"><Icon name="plus" />Add Cabang</button>}</div>{showForm && <form className="admin-inline-form branch-create-form" onSubmit={submit}><label>Parent branch<select name="parent_branch_id"><option value="">Level kota/kab</option>{regencyOptions.map((branch) => <option key={branch.id} value={branch.id}>{branchLabel(branch)}</option>)}</select></label><label>Kode unik<input name="branch_code" required maxLength={20} pattern="[A-Za-z0-9][A-Za-z0-9_-]*" placeholder="STB / STBKT" /></label><label>Kabupaten / Kota<input name="name" required placeholder="Situbondo" /></label><label>Area / Kecamatan<input name="area" placeholder="Kosongkan jika level kota/kab" /></label><label>Latitude<input name="latitude" required type="number" step="0.00000001" placeholder="-7.706" /></label><label>Longitude<input name="longitude" required type="number" step="0.00000001" placeholder="114.009" /></label><label>Radius KM<input name="radius_km" required type="number" step="0.1" min="0.1" defaultValue="5" /></label><button className="primary-button" type="submit">Save Cabang</button><p className="form-note">Branch kota/kab contoh STB. Area operasional contoh STBKT, STBASB, STBBSK di bawah STB. Driver, order, pricing, dan coverage melekat ke area operasional.</p></form>}<div className="branch-grid">{branches.map((branch) => <article className="branch-card" key={branch.id}><div className="branch-map"><span>{(branch.branch_code || branch.name).slice(0, 2).toUpperCase()}</span></div><div className="branch-card-body"><strong>{branchLabel(branch)}</strong><span className="branch-area-name">{isRegencyBranch(branch) ? 'Branch kota/kab' : `Area ${branch.area || 'belum diisi'}`}</span><p>Kode unik: {branch.branch_code || '-'}{branch.parent ? ` - parent ${branchLabel(branch.parent as Branch)}` : ''}</p><b>{branch.radius_km ?? 5} km radius - {branchGeofenceNames(branch)}</b></div></article>)}</div></section>
}

function GeofencePanel({ geofences }: { geofences: Geofence[] }) {
  return <section className="panel"><PanelHeader title="Geofence areas" action={`${geofences.length} areas`} /><div className="activity-list">{geofences.map((area) => <AreaRow key={area.id} name={area.name} branch={area.branch ? branchLabel(area.branch) : '-'} radius={`${area.radius_meters} m`} active={area.is_active} />)}</div></section>
}

function LocationLogsPanel({ logs, branches, canViewMaps }: { logs: LocationLog[]; branches: Branch[]; canViewMaps: boolean }) {
  const [branchFilter, setBranchFilter] = useState('all')
  const [page, setPage] = useState(1)
  const [pageSize, setPageSize] = useState(25)
  const branchOptions = useMemo(() => {
    const values = new Map<string, string>()
    branches.forEach((branch) => values.set(branchLocationKey(branchLabel(branch)), branchLabel(branch)))
    logs.forEach((log) => {
      if (log.branch) values.set(branchLocationKey(log.branch), log.branch)
    })

    return [...values.entries()].sort((first, second) => first[1].localeCompare(second[1]))
  }, [branches, logs])
  const filteredLogs = logs.filter((log) => branchFilter === 'all' || branchLocationKey(log.branch || '') === branchFilter)
  const totalPages = Math.max(1, Math.ceil(filteredLogs.length / pageSize))
  const safePage = Math.min(page, totalPages)
  const pagedLogs = filteredLogs.slice((safePage - 1) * pageSize, safePage * pageSize)

  useEffect(() => {
    setPage(1)
  }, [branchFilter, pageSize, logs.length])

  return (
    <section className="panel location-log-panel">
      <PanelHeader title="Location logs" action={`${filteredLogs.length}/${logs.length} logs`} />
      <div className="table-toolbar location-log-toolbar">
        <select value={branchFilter} onChange={(event) => setBranchFilter(event.target.value)}>
          <option value="all">Semua branch</option>
          {branchOptions.map(([key, label]) => <option key={key} value={key}>{label}</option>)}
        </select>
        <span className="toolbar-hint">Filter global untuk audit GPS per cabang.</span>
        <select value={pageSize} onChange={(event) => setPageSize(Number(event.target.value))}>
          {[10, 25, 50, 100].map((size) => <option key={size} value={size}>{size} / page</option>)}
        </select>
      </div>
      {filteredLogs.length === 0 && <EmptyPanel title="Log lokasi kosong" copy="Tidak ada GPS log untuk filter branch ini." />}
      {filteredLogs.length > 0 && (
        <>
          <div className="responsive-table location-log-table-wrap">
            <table className="location-log-table">
              <thead>
                <tr>
                  <th>Waktu</th>
                  <th>User</th>
                  <th>Branch</th>
                  <th>Latitude</th>
                  <th>Longitude</th>
                  <th>Akurasi</th>
                  <th>Provider</th>
                  <th>Status</th>
                  <th>Reason</th>
                  <th>Aksi</th>
                </tr>
              </thead>
              <tbody>
                {pagedLogs.map((log) => {
                  const statusClass = log.is_mock_location || log.is_suspicious ? 'danger' : log.is_valid ? 'success' : 'muted'
                  const statusLabel = log.is_mock_location ? 'GPS tidak valid' : log.is_suspicious ? 'Suspicious' : log.is_valid ? 'Valid' : 'Invalid'
                  return (
                    <tr key={log.id}>
                      <td>{formatShortDateTime(log.created_at)}</td>
                      <td><strong>{log.user || '-'}</strong></td>
                      <td>{log.branch || '-'}</td>
                      <td>{canViewMaps ? log.latitude : 'Disembunyikan'}</td>
                      <td>{canViewMaps ? log.longitude : 'Disembunyikan'}</td>
                      <td>{log.accuracy ? `${Math.round(log.accuracy)} m` : '-'}</td>
                      <td>{log.provider || '-'}</td>
                      <td><span className={`status ${statusClass}`}>{statusLabel}</span></td>
                      <td>{log.reason || '-'}</td>
                      <td>{canViewMaps && log.maps_url ? <a className="mini-button" href={log.maps_url} target="_blank" rel="noreferrer">Maps</a> : '-'}</td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
          <div className="table-pagination">
            <span>Page {safePage} / {totalPages}</span>
            <div>
              <button className="secondary-button compact" type="button" disabled={safePage <= 1} onClick={() => setPage((value) => Math.max(1, value - 1))}>Prev</button>
              <button className="secondary-button compact" type="button" disabled={safePage >= totalPages} onClick={() => setPage((value) => Math.min(totalPages, value + 1))}>Next</button>
            </div>
          </div>
        </>
      )}
    </section>
  )
}

function openMapsFromForm(form: HTMLFormElement | null, point: 'pickup' | 'destination') {
  if (!form) return
  const data = new FormData(form)
  const lat = Number(data.get(`${point}_lat`))
  const lng = Number(data.get(`${point}_lng`))

  if (!Number.isFinite(lat) || !Number.isFinite(lng)) {
    alert('Isi latitude dan longitude dulu.')
    return
  }

  window.open(`https://www.google.com/maps/search/?api=1&query=${lat},${lng}`, '_blank', 'noopener,noreferrer')
}

function cleanRoleOptions(roles: Role[]) {
  return roles.filter((role): role is Role => knownRoles.includes(role))
}

function formBranchScopeIds(form: FormData, role: Role) {
  if (!branchScopeRoles.has(role)) return []

  return form.getAll('branch_scope_ids')
    .map((value) => Number(value))
    .filter((value) => Number.isInteger(value) && value > 0)
}

function isRegencyBranch(branch: Branch) {
  return branch.is_regency === true || (!branch.parent_branch_id && !branch.area)
}

function isOperationalBranch(branch: Branch) {
  return branch.is_operational_area === true || !isRegencyBranch(branch)
}

function visibleBranchesForUser(branches: Branch[], me: User, canPickAnyBranch: boolean) {
  if (canPickAnyBranch) return branches

  const scopeIds = new Set((me.branch_scope_ids ?? []).map(Number))
  const ownId = me.branch_id ? Number(me.branch_id) : null

  return branches.filter((branch) => {
    if (ownId && branch.id === ownId) return true
    if (scopeIds.has(branch.id)) return true
    if (ownId && branch.parent_branch_id === ownId) return true
    if (branch.parent_branch_id && scopeIds.has(branch.parent_branch_id)) return true

    return false
  })
}

function branchOptionsForRole(branches: Branch[], role: Role) {
  const options = ['hrd', 'manager'].includes(role)
    ? branches.filter(isRegencyBranch)
    : branches.filter(isOperationalBranch)

  return options.length > 0 ? options : branches
}

function operationalScopeOptions(branches: Branch[], selectedBranchId?: string | number | null) {
  const selected = branches.find((branch) => String(branch.id) === String(selectedBranchId ?? ''))
  const parentId = selected && isRegencyBranch(selected) ? selected.id : selected?.parent_branch_id
  const scoped = branches.filter((branch) => isOperationalBranch(branch) && (!parentId || branch.parent_branch_id === parentId || branch.id === selected?.id))

  return scoped.length > 0 ? scoped : branches.filter(isOperationalBranch)
}

function firstBranchId(branches: Branch[]) {
  return branches[0]?.id ? String(branches[0].id) : ''
}

function PasswordInput({ name = 'password', label = 'Password', placeholder, required = false, helper, autoComplete = 'new-password', minLength = 8 }: { name?: string; label?: string; placeholder?: string; required?: boolean; helper?: string; autoComplete?: string; minLength?: number }) {
  const [visible, setVisible] = useState(false)

  return (
    <label>
      {label}
      <span className="password-field">
        <input name={name} type={visible ? 'text' : 'password'} required={required} minLength={minLength} placeholder={placeholder} autoComplete={autoComplete} />
        <button type="button" aria-label={visible ? 'Sembunyikan password' : 'Lihat password'} onClick={() => setVisible((value) => !value)}>
          <Icon name={visible ? 'eye-off' : 'eye'} />
        </button>
      </span>
      {helper && <small className="field-hint">{helper}</small>}
    </label>
  )
}

function UserEditModal({ me, user, branches, permissions, api, onClose, onSaved }: { me: User; user: User; branches: Branch[]; permissions: Permissions; api: ApiClient; onClose: () => void; onSaved: () => void }) {
  const [role, setRole] = useState<Role>(user.role)
  const [saving, setSaving] = useState(false)
  const canEditDriverIdentity = user.role !== 'driver' || ['admin', 'gm', 'manager', 'hrd', 'spv'].includes(me.role)
  const isDriverIdentityLocked = !canEditDriverIdentity
  const canPickAnyBranch = permissions.can_manage_all_branches === true
  const visibleBranches = useMemo(() => visibleBranchesForUser(branches, me, canPickAnyBranch), [branches, canPickAnyBranch, me])
  const branchOptions = useMemo(() => branchOptionsForRole(visibleBranches, role), [visibleBranches, role])
  const defaultBranchId = useMemo(() => {
    const current = user.branch_id ? String(user.branch_id) : ''
    return branchOptions.some((branch) => String(branch.id) === current) ? current : firstBranchId(branchOptions)
  }, [branchOptions, user.branch_id])
  const scopeOptions = useMemo(() => operationalScopeOptions(visibleBranches, defaultBranchId), [defaultBranchId, visibleBranches])
  const defaultScopeIds = branchScopeRoles.has(user.role) && (user.branch_scope_ids?.length ?? 0) > 0
    ? (user.branch_scope_ids ?? []).map(String)
    : scopeOptions.map((branch) => String(branch.id))
  const canChooseBranch = canPickAnyBranch || branchOptions.length > 1
  const roleOptions = useMemo(() => {
    const options = cleanRoleOptions(permissions.assignable_roles)
    return options.includes(user.role) ? options : [user.role, ...options]
  }, [permissions.assignable_roles, user.role])
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
          branch_scope_ids: formBranchScopeIds(form, role),
          is_active: form.get('is_active') === 'on',
          is_suspended: form.get('is_suspended') === 'on',
          suspension_reason: form.get('suspension_reason') || null,
          ...(!isDriverIdentityLocked && passwordEditableRoles.has(role) && form.get('password') ? { password: form.get('password') } : {}),
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
          <fieldset><legend>Account</legend><div className="form-grid"><label>Username<input name="username" required readOnly={isDriverIdentityLocked} defaultValue={user.username} /></label><label>Name<input name="name" required readOnly={isDriverIdentityLocked} defaultValue={user.name} /></label><label>Email<input name="email" type="email" required defaultValue={user.email} /></label><label>Phone<input name="phone" defaultValue={user.phone ?? ''} /></label>{isDriverIdentityLocked && <small className="field-hint span-2">Nama dan username driver hanya bisa diubah oleh Admin/GM/Manager/HRD/SPV. Driver tetap read-only dari aplikasi driver.</small>}</div></fieldset>
          <fieldset><legend>Access</legend><div className="form-grid"><label>{['hrd', 'manager'].includes(role) ? 'Branch kota/kab' : 'Area operasional'}<select name="branch_id" defaultValue={defaultBranchId} disabled={!canChooseBranch}><option value="">No branch</option>{branchOptions.map((branch) => <option key={branch.id} value={branch.id}>{branchLabel(branch)}</option>)}</select>{!canChooseBranch && <input type="hidden" name="branch_id" value={defaultBranchId} />}</label><label>Role<select value={role} onChange={(event) => setRole(event.target.value as Role)}>{roleOptions.map((item) => <option key={item} value={item}>{roleLabels[item]}</option>)}</select></label>{branchScopeRoles.has(role) && <label className="span-2">Area akses<select name="branch_scope_ids" multiple defaultValue={defaultScopeIds} size={Math.min(Math.max(scopeOptions.length, 3), 8)}>{scopeOptions.map((branch) => <option key={branch.id} value={branch.id}>{branchLabel(branch)}</option>)}</select><small className="field-hint">Manager dan HRD level kota/kab dapat melihat semua area di bawah branch-nya.</small></label>}{!isDriverIdentityLocked && passwordEditableRoles.has(role) && <PasswordInput label="Password baru" placeholder="Kosongkan jika tidak diganti" helper="Minimal 8 karakter. User memakai password ini saat login berikutnya." />}<label className="toggle-row"><input name="is_active" type="checkbox" defaultChecked={user.is_active} />Active</label><label className="toggle-row"><input name="is_suspended" type="checkbox" defaultChecked={user.is_suspended} />Suspended</label>{role === 'driver' && <label>Bansos Driver<input name="driver_bansos_amount" type="number" min="0" placeholder="Kosong = otomatis area" defaultValue={user.driver_bansos_amount ?? ''} /></label>}{role === 'driver' && <label className="toggle-row"><input name="driver_bpjs_jht_enabled" type="checkbox" defaultChecked={user.driver_bpjs_jht_enabled ?? true} />JHT BPJS</label>}<label className="span-2">Suspension reason<textarea name="suspension_reason" defaultValue="" placeholder="Optional reason" /></label></div></fieldset>
          <div className="modal-actions"><button type="button" className="secondary-button" onClick={onClose}>Cancel</button><button className="primary-button" type="submit" disabled={saving}>{saving ? 'Saving...' : 'Save user'}</button></div>
        </form>
      </div>
    </div>
  )
}

function UserFormModal({ me, permissions, branches, services, api, onClose, onCreated }: { me: User; permissions: Permissions; branches: Branch[]; services: ServiceRow[]; api: ApiClient; onClose: () => void; onCreated: (password: string) => void | Promise<void> }) {
  const roleOptions = useMemo(() => cleanRoleOptions(permissions.assignable_roles), [permissions.assignable_roles])
  const [role, setRole] = useState<Role>(roleOptions[0] ?? 'operator')
  const [vehicleType, setVehicleType] = useState<'motor' | 'mobil'>('motor')
  const [allowedServices, setAllowedServices] = useState<string[]>([])
  const canPickAnyBranch = permissions.can_manage_all_branches === true
  const visibleBranches = useMemo(() => visibleBranchesForUser(branches, me, canPickAnyBranch), [branches, canPickAnyBranch, me])
  const branchOptions = useMemo(() => branchOptionsForRole(visibleBranches, role), [visibleBranches, role])
  const defaultBranchId = canPickAnyBranch ? '' : firstBranchId(branchOptions)
  const scopeOptions = useMemo(() => operationalScopeOptions(visibleBranches, defaultBranchId), [defaultBranchId, visibleBranches])
  const defaultScopeIds = !canPickAnyBranch ? scopeOptions.map((branch) => String(branch.id)) : []
  const canChooseBranch = canPickAnyBranch || branchOptions.length > 1
  const roleSummary = roleOptions.map((item) => roleLabels[item]).join(', ')
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
        branch_scope_ids: formBranchScopeIds(form, role),
        is_active: form.get('is_active') === 'on',
        is_suspended: false,
        ...(passwordEditableRoles.has(role) && form.get('password') ? { password: form.get('password') } : {}),
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
  return (
    <div className="modal-backdrop" role="presentation">
      <div className="modal" role="dialog" aria-modal="true">
        <div className="modal-header"><div><h2>Create user</h2><p>Assignable roles: {roleSummary || 'Tidak ada role tersedia'}</p></div><button type="button" className="icon-button" onClick={onClose}><Icon name="close" /></button></div>
        <form className="user-form" onSubmit={submit}>
          <fieldset><legend>Info User</legend><div className="form-grid"><label>Username<input name="username" required /></label><label>Name<input name="name" required /></label><label>Email<input name="email" type="email" required /></label><label>Phone<input name="phone" /></label>{passwordEditableRoles.has(role) && <PasswordInput label="Password login" placeholder="Isi jika ingin password manual" helper="Jika dikosongkan, sistem tetap membuat password sementara otomatis." />}</div></fieldset>
          <fieldset><legend>Role & Branch</legend><div className="form-grid"><label>Role<select value={role} onChange={(event) => setRole(event.target.value as Role)}>{roleOptions.map((item) => <option key={item} value={item}>{roleLabels[item]}</option>)}</select></label><label>{['hrd', 'manager'].includes(role) ? 'Branch kota/kab' : 'Area operasional'}<select name="branch_id" defaultValue={defaultBranchId} disabled={!canChooseBranch}><option value="">No branch</option>{branchOptions.map((branch) => <option key={branch.id} value={branch.id}>{branchLabel(branch)}</option>)}</select>{!canChooseBranch && <input type="hidden" name="branch_id" value={defaultBranchId} />}</label>{branchScopeRoles.has(role) && <label className="span-2">Area akses<select name="branch_scope_ids" multiple defaultValue={defaultScopeIds} size={Math.min(Math.max(scopeOptions.length, 3), 8)}>{scopeOptions.map((branch) => <option key={branch.id} value={branch.id}>{branchLabel(branch)}</option>)}</select><small className="field-hint">Manager dan HRD level kota/kab dapat melihat semua area di bawah branch-nya.</small></label>}<label className="toggle-row"><input name="is_active" type="checkbox" defaultChecked />Active</label>{role === 'driver' && <label>Tipe kendaraan<select name="vehicle_type" value={vehicleType} onChange={(event) => setVehicleType(event.target.value as 'motor' | 'mobil')}><option value="motor">Motor</option><option value="mobil">Mobil</option></select></label>}{role === 'driver' && vehicleType === 'mobil' && <label>Kapasitas mobil<select name="vehicle_seat_rows" defaultValue="2"><option value="2">2 baris - citycar/default</option><option value="3">3 baris - MPV/keluarga</option></select></label>}{role === 'driver' && <label>Bansos Driver<input name="driver_bansos_amount" type="number" min="0" placeholder="Kosong = otomatis area" /></label>}{role === 'driver' && <label className="toggle-row"><input name="driver_bpjs_jht_enabled" type="checkbox" defaultChecked />JHT BPJS</label>}{role === 'driver' && <label className="toggle-row driver-ladies-toggle"><input name="is_ladies_driver" type="checkbox" />Driver Ladies</label>}</div>{role === 'driver' && <div className="service-config-pills"><strong>Config layanan driver</strong><span>Kosongkan jika driver boleh menerima semua layanan.</span>{services.map((service) => <label key={service.id} className="toggle-row service-pill"><input type="checkbox" checked={allowedServices.includes(service.code)} onChange={() => toggleService(service.code)} />{service.name}</label>)}</div>}</fieldset>
          <div className="modal-actions"><button type="button" className="secondary-button" onClick={onClose}>Cancel</button><button className="primary-button" type="submit" disabled={roleOptions.length === 0}>Create real user</button></div>
        </form>
      </div>
    </div>
  )
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
let adminEchoToken = ''

function isLocalRealtimeHost(host?: string) {
  return !host || ['localhost', '127.0.0.1', '::1'].includes(host)
}

function resolveRealtimeConfig() {
  const configuredHost = import.meta.env.VITE_REVERB_HOST
  const apiUrl = new URL(APP_BASE)
  const browserHost = window.location.hostname
  const fallbackHost = isLocalRealtimeHost(apiUrl.hostname) ? browserHost : apiUrl.hostname
  const isPublicHost = !isLocalRealtimeHost(fallbackHost)
  const host = isPublicHost && isLocalRealtimeHost(configuredHost) ? fallbackHost : (configuredHost || fallbackHost)
  const scheme = isPublicHost && isLocalRealtimeHost(configuredHost)
    ? apiUrl.protocol.replace(':', '')
    : (import.meta.env.VITE_REVERB_SCHEME ?? apiUrl.protocol.replace(':', '') ?? window.location.protocol.replace(':', '') ?? 'http')
  const port = isPublicHost && isLocalRealtimeHost(configuredHost) && scheme === 'https'
    ? 443
    : Number(import.meta.env.VITE_REVERB_PORT ?? (scheme === 'https' ? 443 : 8080))

  return { host, scheme, port }
}

function makeEcho(token: string) {
  if (adminEcho && adminEchoToken === token) return adminEcho

  if (adminEcho) {
    adminEcho.disconnect()
    adminEcho = null
  }

  window.Pusher = Pusher
  adminEchoToken = token
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

function resetAdminEcho() {
  adminEcho?.disconnect()
  adminEcho = null
  adminEchoToken = ''
}

function RoleBadge({ role }: { role: Role }) {
  return <span className={`role-badge ${roleColors[role]}`}>{roleLabels[role]}</span>
}

function StatusBadge({ status }: { status: string }) {
  const tone = status === 'COMPLETED' ? 'success' : status === 'CANCELLED' ? 'danger' : status === 'SEARCHING_DRIVER' ? 'warning' : 'info'
  return <span className={`status ${tone}`}>{status}</span>
}

function buildInfoDetail(buildInfo: BuildInfo | null) {
  const visibleItem = buildInfoHistory(buildInfo)[0]
  const source = visibleItem ?? buildInfo
  const title = translateBuildMessage(source?.message)
  const explanation = buildUpdateExplanation(source?.message)

  return {
    title,
    description: source?.message
      ? explanation
      : 'Informasi update belum tersedia. Jalankan proses build/deploy agar versi terbaru dapat terbaca di dashboard.',
    version: source?.sha ? `Versi ${source.sha}` : 'Versi belum terbaca',
    sha: source?.sha || '',
    fullSha: source?.full_sha || '',
    commitTime: source?.committed_at ? formatShortDateTime(source.committed_at) : '',
    buildTime: buildInfo?.built_at ? formatShortDateTime(buildInfo.built_at) : '',
  }
}

function buildInfoHistory(buildInfo: BuildInfo | null): BuildHistoryItem[] {
  const history = buildInfo?.history?.filter((item) => item?.sha && !isInternalSccBuildMessage(item.message)) ?? []
  if (history.length > 0) return history.slice(0, 3)

  return buildInfo?.sha && !isInternalSccBuildMessage(buildInfo.message) ? [{
    sha: buildInfo.sha,
    full_sha: buildInfo.full_sha,
    message: buildInfo.message,
    committed_at: buildInfo.committed_at,
  }] : []
}

function buildHistoryDescription(item: BuildHistoryItem) {
  return buildUpdateExplanation(item.message)
}

function translateBuildMessage(message?: string | null) {
  const map: Record<string, string> = {
    'Show live price cancellation reason': 'Menampilkan alasan pembatalan pada Live Edit Harga',
    'Refine driver identity and timeout order flow': 'Merapikan identitas driver dan alur order kembali',
    'Fix audit logs responsive table': 'Memperbaiki tampilan Audit Logs di mobile',
    'Add Joker Mobil pricing CMS and split deposit reports': 'Menambahkan CMS tarif Joker Mobil dan split report setoran',
    'Add live price review audit tables': 'Menambahkan tabel audit Live Edit Harga',
    'Improve live price review layout': 'Merapikan tampilan Live Edit Harga',
    'Add customer history operator chat action': 'Menambahkan tombol chat operator pada history order',
    'Add AI learning queue for live price corrections': 'Menambahkan queue AI learning untuk koreksi harga',
    'Add Horizon monitoring link': 'Menambahkan akses monitoring queue',
    'Improve AI parser free text handling': 'Meningkatkan AI parser untuk teks order bebas',
    'Add Joker Mobil deposit calculation': 'Menambahkan perhitungan setoran Joker Mobil',
    'Add Indonesian update detail modal': 'Menambahkan detail update berbahasa Indonesia',
    'Show latest update status on admin dashboard': 'Menambahkan status update terbaru di dashboard admin',
    'Refine driver profile finance details': 'Merapikan detail keuangan pada profil driver',
    'Add all area driver access setting': 'Menambahkan pengaturan akses all area untuk driver',
    'Add CMS role control for driver assignment': 'Menambahkan CMS role untuk assign driver',
    'Show branch performance in driver app': 'Menampilkan performa cabang di aplikasi driver',
    'Render customer home CMS banners': 'Menampilkan banner CMS pada home customer',
  }

  return message && map[message] ? map[message] : 'Pembaruan sistem terbaru telah tersedia'
}

function buildUpdateExplanation(message?: string | null) {
  const map: Record<string, string> = {
    'Show live price cancellation reason': 'Jika customer membatalkan order saat proses koreksi harga, operator/eksekutor sekarang melihat alasan batal secara jelas di Live Edit Harga sehingga order tidak menggantung.',
    'Refine driver identity and timeout order flow': 'Profil dan identitas driver dirapikan, lalu alur order kembali setelah timeout dibuat lebih jelas agar customer tidak bingung ketika driver belum ditemukan.',
    'Fix audit logs responsive table': 'Tabel Audit Logs diperbaiki agar kolom, tombol export, dan metadata tetap terbaca saat dibuka dari HP atau layar kecil.',
    'Add Joker Mobil pricing CMS and split deposit reports': 'Ditambahkan CMS khusus tarif Joker Mobil: rumus ring, titik hitung jasa, pickup, jasa tunggu/malam/helper, serta report setoran dipisah antara Driver Motor dan Driver Mobil.',
    'Add live price review audit tables': 'Riwayat koreksi harga operator dan eksekutor ditampilkan dalam tabel audit yang bisa diekspor, membantu manajemen melihat performa koreksi harga.',
    'Improve live price review layout': 'Halaman Live Edit Harga dibuat lebih ringan dan lebih ringkas: daftar live order, preview order, dan koreksi harga tampil dalam layout yang mudah dipantau.',
    'Add customer history operator chat action': 'Customer yang ordernya belum mendapat driver dapat membuka chat operator dari detail History Order setelah melewati waktu tunggu.',
    'Add AI learning queue for live price corrections': 'Koreksi harga live dicatat sebagai bahan learning AI melalui queue khusus, sehingga hasil koreksi bisa menjadi referensi parsing dan pricing berikutnya.',
    'Add Horizon monitoring link': 'Monitoring queue ditambahkan agar admin dapat mengecek job realtime, AI learning, dan proses background yang berjalan.',
    'Improve AI parser free text handling': 'AI parser diperluas agar lebih tahan membaca format order bebas, termasuk nama, nomor HP, lokasi pembelian, alamat antar, dan catatan barang.',
    'Add Joker Mobil deposit calculation': 'Perhitungan setoran layanan Joker Mobil ditambahkan agar potongan manajemen mengikuti aturan mobil, bukan lagi disamakan dengan motor.',
    'Add Indonesian update detail modal': 'Dashboard admin kini menampilkan detail pembaruan dalam bahasa Indonesia agar Admin/GM bisa memahami update tanpa membuka Git.',
    'Show latest update status on admin dashboard': 'Status versi terbaru ditampilkan di dashboard admin dengan waktu commit dan waktu build.',
    'Refine driver profile finance details': 'Detail finance di profil driver dirapikan agar tagihan, cashback, BPJS, JHT, dan jatuh tempo lebih mudah dibaca.',
    'Add all area driver access setting': 'Pengaturan driver lintas area ditambahkan agar akses order bisa dikontrol dari konfigurasi, bukan dari kode.',
    'Add CMS role control for driver assignment': 'Hak assign driver per role dipindahkan ke CMS agar manajemen bisa mengatur akses tanpa deploy ulang.',
    'Show branch performance in driver app': 'Aplikasi driver menampilkan performa cabang agar driver melihat konteks operasional cabangnya.',
    'Render customer home CMS banners': 'Banner dari CMS home customer sudah dirender di FE customer agar konten promo bisa diatur dari backend.',
  }

  return message && map[message] ? map[message] : 'Pembaruan ini berisi perbaikan stabilitas, tampilan, dan alur operasional aplikasi. Detail teknis internal tidak ditampilkan di dashboard umum.'
}

function isInternalSccBuildMessage(message?: string | null) {
  const text = (message || '').toLowerCase()
  return text.includes('system control center')
    || text.includes(' scc')
    || text.includes('scheduled database auto backup')
    || text.includes('auto database backup')
    || text.includes('database auto backup')
}

const assignDriverRoleOptions: Array<{ value: Role; label: string }> = [
  { value: 'manager', label: 'Manager' },
  { value: 'spv', label: 'SPV' },
  { value: 'operator', label: 'Operator' },
  { value: 'eksekutor', label: 'Eksekutor' },
]

const editTarifRoleOptions: Array<{ value: Role; label: string }> = [
  { value: 'hrd', label: 'HRD' },
  { value: 'manager', label: 'Manager' },
  { value: 'spv', label: 'SPV' },
  { value: 'operator', label: 'Operator' },
  { value: 'eksekutor', label: 'Eksekutor' },
]

function defaultEditTarifRoles(): Role[] {
  return ['hrd', 'manager', 'spv', 'operator', 'eksekutor']
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

function defaultDailyPriorityWindows(): DailyPriorityWindow[] {
  return [
    { start: '05:00', end: '11:00' },
    { start: '13:00', end: '17:00' },
  ]
}

function updateNightRule(rows: NightTariffRule[], index: number, patch: Partial<NightTariffRule>) {
  return rows.map((row, rowIndex) => rowIndex === index ? { ...row, ...patch } : row)
}

function toggleRoleValue(values: Role[], role: Role) {
  return values.includes(role) ? values.filter((item) => item !== role) : [...values, role]
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

function zoneServiceOptions(services: ServiceRow[]) {
  const fromCms = services.map((service) => ({ label: service.name, value: serviceTypeFromService(service) }))
  const fallback = [
    { label: 'Ojek', value: 'ojek' },
    { label: 'Delivery', value: 'delivery' },
    { label: 'Belanja', value: 'belanja' },
    { label: 'Kurir', value: 'kurir' },
    { label: 'Gift Order', value: 'gift_order' },
    { label: 'Joker Mobil', value: 'joker_mobil' },
  ]
  const seen = new Set<string>()

  return [...fromCms, ...fallback].filter((item) => {
    if (seen.has(item.value)) return false
    seen.add(item.value)
    return true
  })
}

function geofenceOptionLabel(area: Geofence) {
  return `${area.name} - ${area.branch ? branchLabel(area.branch) : 'Tanpa cabang'} - ${zoneShapeLabel(area.shape_type)}`
}

function zoneShapeLabel(shape?: string | null) {
  return shape === 'polygon' ? 'Polygon' : 'Circle'
}

function zoneMatchPointLabel(value?: string | null) {
  return {
    destination: 'Cek tujuan',
    pickup: 'Cek pickup',
    either: 'Pickup/tujuan',
    both: 'Pickup dan tujuan',
  }[String(value ?? 'destination')] ?? String(value ?? '-')
}

function zonePriceModeLabel(value?: string | null) {
  return {
    fixed: 'Tarif tetap',
    extra: 'Tambah nominal',
    percent: 'Tambah persen',
  }[String(value ?? 'fixed')] ?? String(value ?? '-')
}

function zoneRuleValue(rule: ZonePricingRule) {
  if (rule.price_mode === 'percent') return `${Number(rule.percent ?? 0).toLocaleString('id-ID')}%`
  return `Rp ${Number(rule.amount ?? 0).toLocaleString('id-ID')}`
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
  return { dashboard: 'Admin Dashboard', orders: 'Order Operations', 'request-orders': 'Request Order', users: 'User Management', drivers: 'Driver Management', settings: 'System Settings', 'master-pricing': 'Master Pricing', pricing: 'Pricing & Policy', 'price-settings': 'Price Settings', 'ring-pricing': 'Master Ring', 'keyword-parsers': 'Keyword Parsers', 'pricing-keyword-rules': 'Pricing Keyword Rules', 'zone-pricing': 'Zone Pricing Rules', 'zone-pricing-tester': 'Zone Pricing Tester', branches: 'Branch Management', geofence: 'Geofence Areas', locations: 'Location Logs', reports: 'Reports', chats: 'Chat Monitor', 'internal-chat': 'Internal Chat', 'audit-logs': 'Audit Logs', 'sticky-notes': 'Sticky Notes', 'manual-order': 'Manual Order', 'live-price-reviews': 'Live Edit Harga', 'order-crew-rules': 'Order Crew Rules', banners: 'Banners', 'home-sections': 'Home Sections', 'home-items': 'Home Items', announcements: 'Announcements' }[view]
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
  return `${order.code} ${order.customer ?? ''} ${order.driver ?? ''} ${order.service} ${displayBranchValue(order.branch_display_name ?? order.branch, order.branch_area)} ${order.status} ${order.source ?? ''} ${order.cancel_reason ?? ''} ${order.oper_handle?.status ?? ''} ${order.oper_handle?.reason ?? ''}`
    .toLowerCase()
    .includes(searchQuery.toLowerCase())
}

function orderContentText(order: Order) {
  const rawText = (order.raw_text ?? '').trim()
  if (rawText) return rawText

  const noteText = (order.notes ?? '').trim()
  if (noteText) return noteText

  const detailKeys = ['detail', 'details', 'description', 'item', 'items', 'jenis_pembelian', 'purchase_items', 'request_text']
  const breakdown = order.pricing_breakdown ?? {}
  for (const key of detailKeys) {
    const value = breakdown[key]
    if (Array.isArray(value) && value.length > 0) return value.map((item) => `- ${String(item)}`).join('\n')
    if (typeof value === 'string' && value.trim()) return value.trim()
  }

  const lines = [
    order.customer ? `Customer: ${order.customer}` : '',
    order.pickup_address ? `Jemput/Pembelian: ${order.pickup_address}` : '',
    order.destination_address ? `Tujuan/Antar: ${order.destination_address}` : '',
    `Layanan: ${order.service}`,
    `Total: Rp ${order.total.toLocaleString('id-ID')}`,
  ].filter(Boolean)

  return lines.join('\n')
}

function operHandleStatusLabel(item: OperHandle) {
  if (item.status === 'approved') return 'Approved'
  if (item.operator_approved_at && !item.spv_approved_at) return 'Menunggu SPV'
  if (!item.operator_approved_at && item.spv_approved_at) return 'Menunggu Operator'
  if (item.status === 'pending') return 'Menunggu approval'
  return item.status
}

function crewStatusText(status?: string | null) {
  const key = String(status ?? '').toLowerCase()
  if (key === 'waiting_helper') return 'Menunggu helper'
  if (key === 'ready') return 'Crew siap'
  if (key === 'accepted') return 'Diterima'
  if (key === 'pending') return 'Menunggu'
  if (key === 'cancelled') return 'Batal'
  return key || 'Tidak ada crew'
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

function displayBranchValue(branch: unknown, area?: string | null) {
  if (typeof branch === 'string' && branch.trim()) return [branch, area].filter(Boolean).join(' - ')
  if (branch && typeof branch === 'object') {
    const value = branch as { branch_code?: unknown; name?: unknown; area?: unknown; display_name?: unknown }
    return stringValue(value.display_name) || [stringValue(value.branch_code), stringValue(value.name), stringValue(value.area) || area].filter(Boolean).join(' - ') || '-'
  }

  return area || '-'
}

function priceLogSummary(log: AuditLog) {
  const metadata = log.metadata ?? {}
  if (log.action === 'approved_live_price_review') {
    const total = Number(metadata.corrected_total_price ?? 0)
    return total > 0 ? `Live edit disetujui Rp ${total.toLocaleString('id-ID')}` : 'Live edit harga disetujui'
  }
  if (log.action === 'rejected_live_price_review') {
    return 'Live edit harga ditolak'
  }

  const after = metadata.after && typeof metadata.after === 'object' ? metadata.after as Record<string, unknown> : {}
  const total = Number(after.total ?? 0)

  return total > 0 ? `Total baru Rp ${total.toLocaleString('id-ID')}` : 'Harga diedit'
}

function isPriceAuditLog(log: AuditLog) {
  return ['updated_order_price', 'approved_live_price_review', 'rejected_live_price_review'].includes(log.action)
}

function monthName(month: number) {
  return new Intl.DateTimeFormat('id-ID', { month: 'long' }).format(new Date(2026, month - 1, 1))
}

function agNumberParser(params: { newValue: unknown }) {
  const cleaned = String(params.newValue ?? '').replace(/[^\d-]/g, '')
  const value = Number(cleaned)

  return Number.isFinite(value) ? Math.max(0, value) : 0
}

function agNumberFormatter(params: { value: unknown }) {
  const value = Number(params.value ?? 0)

  return value === 0 ? '-' : value.toLocaleString('en-US')
}

function agRequiredNumberFormatter(params: { value: unknown }) {
  const value = Number(params.value ?? 0)

  return Number.isFinite(value) ? value.toLocaleString('en-US') : '0'
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
  return branch.display_name || [branch.branch_code, branch.name, branch.area].filter(Boolean).join(' - ')
}

function branchGeofenceNames(branch: Branch) {
  const names = branch.geofence_areas?.map((area) => area.name).filter(Boolean) ?? []
  return names.length > 0 ? names.join(', ') : 'Geofence belum diset'
}

function branchLocationKey(value?: string | null) {
  return (value || 'tanpa-branch').trim().toLowerCase()
}

function userBranchLabel(user: User) {
  if (user.branch_display_name) return user.branch_display_name
  if (!user.branch) return '-'
  return [user.branch_code, user.branch, user.branch_area].filter(Boolean).join(' - ')
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
  if (!path) return ''
  if (/^https?:\/\//i.test(path)) return normalizeRemoteAsset(path)

  const cleanPath = path.startsWith('/') ? path : `/${path}`
  return `${APP_BASE}${cleanPath}`
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
    upload: 'M12 3 6.5 8.5 7.9 9.9 11 6.8V16h2V6.8l3.1 3.1 1.4-1.4L12 3ZM5 18h14v2H5v-2Z',
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
    eye: 'M12 5c5 0 8.5 4.2 10 7-1.5 2.8-5 7-10 7s-8.5-4.2-10-7c1.5-2.8 5-7 10-7Zm0 2c-3.6 0-6.4 2.7-7.7 5 1.3 2.3 4.1 5 7.7 5s6.4-2.7 7.7-5C18.4 9.7 15.6 7 12 7Zm0 2.5a2.5 2.5 0 1 1 0 5 2.5 2.5 0 0 1 0-5Z',
    'eye-off': 'M4.3 3 21 19.7 19.7 21l-3-3A10 10 0 0 1 12 19c-5 0-8.5-4.2-10-7a17.6 17.6 0 0 1 4.1-4.8L3 4.3 4.3 3Zm3.2 5.6A15.6 15.6 0 0 0 4.3 12c1.3 2.3 4.1 5 7.7 5 1.1 0 2.1-.3 3-.7l-2-2a2.5 2.5 0 0 1-3.3-3.3L7.5 8.6ZM12 5c5 0 8.5 4.2 10 7a17.6 17.6 0 0 1-3.1 4.1l-1.4-1.4a15.6 15.6 0 0 0 2.2-2.7C18.4 9.7 15.6 7 12 7c-.9 0-1.7.2-2.5.5L8 6a9.7 9.7 0 0 1 4-.9Zm2.4 7.5A2.5 2.5 0 0 0 11.5 9.6L9.8 7.9A4.5 4.5 0 0 1 16.1 14l-1.7-1.6Z',
    moon: 'M21 14.8A8.5 8.5 0 0 1 9.2 3a7 7 0 1 0 11.8 11.8Z',
    sun: 'M12 7a5 5 0 1 1 0 10 5 5 0 0 1 0-10Zm0-5h2v3h-2V2Zm0 17h2v3h-2v-3ZM2 12h3v2H2v-2Zm17 0h3v2h-3v-2ZM4.2 5.6l1.4-1.4 2.1 2.1-1.4 1.4-2.1-2.1Zm12.1 12.1 1.4-1.4 2.1 2.1-1.4 1.4-2.1-2.1Zm2.1-13.5 1.4 1.4-2.1 2.1-1.4-1.4 2.1-2.1ZM6.3 16.3l1.4 1.4-2.1 2.1-1.4-1.4 2.1-2.1Z',
    logout: 'M5 3h8v2H7v14h6v2H5V3Zm11.6 5.4L21.2 13l-4.6 4.6-1.4-1.4 2.2-2.2H10v-2h7.4l-2.2-2.2 1.4-1.4Z',
  }
  return <svg viewBox="0 0 24 24" aria-hidden="true"><path d={icons[name]} /></svg>
}

export default App



