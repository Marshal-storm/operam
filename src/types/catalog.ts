/** Tipos del Módulo 2 — Catálogos, Instancias, Precios y Promociones. */

/** Filtro de segmento: "Todos" o el `name` real desde MySQL `segments`. */
export type CatalogSegmentFilter = string

export type ServiceType = 'recurring' | 'consumption' | 'single'

export type ServiceUnit =
  | 'user'
  | 'pack_5_users'
  | 'stamp'
  | 'hour'
  | 'license'
  | 'instance'

export type InstanceStatus = 'operational' | 'maintenance' | 'suspended' | 'decommissioned'

/** Emisor fiscal / legal (RF-1). Refs = nombres del cofre, nunca secretos en claro. */
export type Issuer = {
  id: number
  name: string
  rfc: string
  api_url: string | null
  /** Banderas seguras para badges (siempre visibles). */
  has_erp_secret: boolean
  has_merchant_token: boolean
  has_device_token: boolean
  /** Solo texto completo si JWT tiene edit en config_issuers; si no, máscara o null. */
  secret_ref: string | null
  integropay_merchant_token_ref: string | null
  integropay_device_token_ref: string | null
  is_active: boolean
  /** Conteo informativo de instancias vinculadas (listado paginado). */
  linked_instances_count?: number
}

export type BillingFrequency = 'monthly' | 'annual' | 'single'

export type DiscountType = 'percentage' | 'fixed_amount' | 'free_months'

export type PromotionAppliesTo = 'recurring' | 'single' | 'both'

export type PromotionMode = 'price_discount' | 'extra_units'

export type PromotionScope = {
  id?: number
  segment_id: number | null
  segment_name?: string | null
  client_id: number | null
  client_name?: string | null
}

export type CatalogService = {
  id: number
  code: string
  name: string
  description: string | null
  /** Nombre real desde JOIN `segments` (API: segment_name). */
  segment_name: string
  segment_id: number
  default_issuer: string | null
  default_issuer_id: number | null
  service_type: ServiceType
  unit_of_measure: ServiceUnit
  allow_package_conversion: boolean
  /** RF-2: producto paquete destino. */
  package_service_id: number | null
  package_service_code?: string | null
  package_service_name?: string | null
  package_factor: number
  is_active: boolean
}

export type CatalogInstance = {
  id: number
  code: string
  name: string
  issuer: string
  issuer_id: number
  segment_name: string
  segment_id: number
  access_url: string | null
  server_ip: string | null
  status: InstanceStatus
  api_token_last4?: string | null
  has_api_token?: boolean
}

export type PriceListItem = {
  id: number
  service_id: number
  service_code: string
  service_name: string
  /** Tipo del catálogo (JOIN services) — guía UI one_time/consumo. */
  service_type?: ServiceType
  frequency: BillingFrequency
  min_quantity: number
  max_quantity: number | null
  /** DECIMAL como string (RNF-1). */
  unit_price: string
}

export type PriceList = {
  id: number
  name: string
  segment_name: string
  segment_id: number
  currency: 'MXN' | 'USD'
  is_default: boolean
  valid_from: string
  valid_until: string | null
  is_active: boolean
  items: PriceListItem[]
}

export type Promotion = {
  id: number
  code: string
  name: string
  promo_mode: PromotionMode
  segment_name: string | null
  segment_id: number | null
  service_id: number | null
  service_name?: string | null
  service_code?: string | null
  min_quantity: number | null
  extra_units_pct: string | null
  discount_type: DiscountType
  discount_value: string
  applies_to: PromotionAppliesTo
  valid_from: string
  valid_until: string | null
  max_uses: number | null
  current_uses: number
  is_active: boolean
  visible_in_portal: boolean
  scopes: PromotionScope[]
  benefit_label: string
}

export type CatalogSnapshot = {
  services: CatalogService[]
  instances: CatalogInstance[]
  priceLists: PriceList[]
  promotions: Promotion[]
  /** Precios base del simulador RF-2 (MXN, lista Operam). */
  simulator: {
    singleUserPrice: string
    pack5Price: string
  }
}

export type PackageOptimizationResult = {
  userCount: number
  /** Factor RF-2 real del producto (usuarios por paquete). */
  packageFactor: number
  packsCount: number
  /** @deprecated Alias de packsCount. */
  packsOf5: number
  leftoverUsers: number
  singleUserPrice: string
  packPrice: string
  /** @deprecated Alias de packPrice. */
  pack5Price: string
  naiveTotal: string
  optimizedTotal: string
  savings: string
}
