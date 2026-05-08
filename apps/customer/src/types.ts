export type View = 'login' | 'home' | 'order' | 'chat' | 'history' | 'profile'

export type ServiceType = string

export type LatLng = {
  lat: number
  lng: number
}

export type Address = LatLng & {
  label: string
}

export type GeocodeResult = {
  destination: {
    text: string
    lat: number
    lng: number
    formatted_address: string
    provider?: string
  }
  distance?: number
  price?: number
  quote?: PriceQuote
}

export type User = {
  id: number
  username?: string
  name: string
  email: string
  phone?: string
  branch_id?: number
  branch?: string
  branch_name?: string
  branch_area?: string
  branch_display_name?: string
  lat?: number | string | null
  lng?: number | string | null
  address?: string | null
  area_status?: 'inside_branch' | 'outside_branch' | string
  location_updated_at?: string | null
  role?: string
  profile_completed?: boolean
  profile_photo_url?: string | null
}

export type Branch = {
  id: number
  name: string
  area?: string | null
  latitude?: number | string | null
  longitude?: number | string | null
}

export type PriceQuote = {
  distance?: number
  distance_km: number
  tarif?: number
  price: number
  base_price?: number
  service_fee?: number
  service_fee_breakdown?: Array<{ point: number; label: string; fee: number }>
  extra_charge?: number
  keyword_charge?: number
  service_charge: number
  subtotal?: number
  minimum_price?: number
  final_price?: number
  total_price: number
  stops?: number
}

export type Order = {
  id: number
  order_code?: string
  code?: string
  service_type?: ServiceType | string
  service?: ServiceType | string
  pickup_address?: string
  destination_address?: string
  total_price?: number
  total?: number
  price?: number
  service_charge?: number
  extra_charge?: number
  distance_km?: number
  stops?: number
  pricing_breakdown?: PriceQuote
  status: string
  created_at?: string
  expired_at?: string | null
  driver?: {
    id?: number
    user_id?: number
    user?: {
      id?: number
      name?: string
      phone?: string
    } | null
  } | null
  driver_name?: string | null
  cancel_reason?: string | null
  feedback?: OrderFeedback | null
  rating?: {
    id?: number
    rating: number
    comment?: string | null
  } | null
  payment_method?: 'cash' | 'transfer' | string | null
  payment_label?: string | null
  payment_meta?: Record<string, unknown> | null
  preferred_vehicle_type?: 'motor' | 'mobil' | string | null
}

export type OrderFeedback = {
  type: string
  title: string
  message: string
  tone?: 'success' | 'error' | 'info' | string
}

export type ChatMessage = {
  id: string | number
  from?: 'customer' | 'driver' | 'cs' | 'system'
  sender_type?: string
  sender_id?: number | null
  text?: string
  message?: string
  image_url?: string | null
  audio_url?: string | null
  audio_duration?: number | null
  is_read?: boolean
  created_at?: string
  time: string
}

export type ChatConversation = {
  id: number
  order_id?: number | null
  type: 'customer_driver' | 'customer_operator' | 'driver_operator'
  status: 'waiting' | 'active' | 'closed' | string
  sla_status?: 'waiting' | 'on_time' | 'late' | string | null
  closed_at?: string | null
  operator_rating?: number | null
  rating_requested?: boolean
  rating_requested_at?: string | null
}

export type Toast = {
  id: string
  type: 'success' | 'error' | 'info'
  message: string
}

export type FavoriteAddress = Address & {
  id: string
  name: string
}

export type Banner = {
  id: number
  title: string
  image: string
  image_original?: string
  link?: string
  order: number
  start_date?: string
  end_date?: string
}

export type HomeSectionItem = {
  id: number
  title: string
  subtitle?: string
  image?: string
  image_original?: string
  icon?: string
  link?: string
  extra_data?: Record<string, unknown>
  order: number
  start_date?: string
  end_date?: string
}

export type HomeSection = {
  id: number
  name: string
  type: string
  order: number
  items: HomeSectionItem[]
}

export type Announcement = {
  id: number
  title: string
  content: string
  start_date?: string
  end_date?: string
}

export type HomeData = {
  banners: Banner[]
  sections: HomeSection[]
  announcements: Announcement[]
}

export type DynamicServiceField = {
  type: 'text' | 'textarea' | 'select' | 'date' | string
  name: string
  label?: string
  options?: string[]
}

export type DynamicService = {
  id: number
  name: string
  code: string
  service_type?: string
  whatsapp_redirect_enabled?: boolean
  whatsapp_number?: string | null
  whatsapp_message_template?: string | null
  form_schema?: {
    fields?: DynamicServiceField[]
  } | null
}

export type PublicSettings = {
  map: {
    provider: 'google' | 'mapbox' | 'osm'
    api_key: string | null
  }
  oauth: {
    google_enabled: boolean
  }
  push?: {
    enabled: boolean
    vapid_key?: string | null
    firebase_config?: Record<string, string> | null
  }
  payment?: {
    methods: Array<{ key: 'cash' | 'transfer' | string; label: string; description?: string }>
    transfer_accounts?: Array<{ bank?: string; account_name?: string; account_number?: string }>
    transfer_account?: { bank?: string; account_name?: string; account_number?: string }
    qris_image_url?: string | null
  }
  support?: {
    complaint_whatsapp_number?: string | null
    complaint_whatsapp_url?: string | null
  }
}
