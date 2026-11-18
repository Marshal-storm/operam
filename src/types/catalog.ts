/** Tipos del Módulo 2 — Catálogos, Instancias, Precios y Promociones. */

export type CatalogSegmentFilter = 'Todos' | 'Operam' | 'Construsync'

export type ServiceType = 'recurrente' | 'consumo' | 'unico'

export type ServiceUnit =
  | 'usuario'
  | 'paquete_5_usuarios'
  | 'timbre'
  | 'hora'
  | 'licencia'
  | 'instancia'

export type InstanceStatus = 'operativa' | 'mantenimiento' | 'suspendida' | 'baja'

/** Emisor fiscal / legal (RF-1). Refs = nombres del cofre, nunca secretos en claro. */
export type Issuer = {
  id: number
  nombre: string
  rfc: string
  api_url: string | null
  secret_ref: string | null
  integropay_merchant_token_ref: string | null
  integropay_device_token_ref: string | null
  activo: boolean
  /** Conteo informativo de instancias vinculadas (listado paginado). */
  instancias_vinculadas?: number
}

export type BillingFrequency = 'mensual' | 'anual' | 'unico'

export type DiscountType = 'porcentaje' | 'monto_fijo' | 'meses_gratis'

export type CatalogService = {
  id: number
  codigo: string
  nombre: string
  descripcion: string | null
  segmento: 'Operam' | 'Construsync'
  segmento_id: number
  emisor_default: string | null
  emisor_default_id: number | null
  tipo: ServiceType
  unidad_medida: ServiceUnit
  permite_conversion_paquete: boolean
  activo: boolean
}

export type CatalogInstance = {
  id: number
  codigo: string
  nombre: string
  emisor: string
  emisor_id: number
  segmento: 'Operam' | 'Construsync'
  segmento_id: number
  url_acceso: string | null
  ip_servidor: string | null
  estado: InstanceStatus
}

export type PriceListItem = {
  id: number
  servicio_id: number
  servicio_codigo: string
  servicio_nombre: string
  frecuencia: BillingFrequency
  cantidad_min: number
  cantidad_max: number | null
  /** DECIMAL como string (RNF-1). */
  precio_unitario: string
}

export type PriceList = {
  id: number
  nombre: string
  segmento: 'Operam' | 'Construsync'
  segmento_id: number
  moneda: 'MXN' | 'USD'
  es_default: boolean
  vigente_desde: string
  vigente_hasta: string | null
  activa: boolean
  items: PriceListItem[]
}

export type Promotion = {
  id: number
  codigo: string
  nombre: string
  segmento: 'Operam' | 'Construsync' | null
  segmento_id: number | null
  tipo_descuento: DiscountType
  valor_descuento: string
  aplica_a: 'recurrente' | 'unico' | 'ambos'
  vigente_desde: string
  vigente_hasta: string | null
  usos_maximos: number | null
  usos_actuales: number
  activa: boolean
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
  packsOf5: number
  leftoverUsers: number
  singleUserPrice: string
  pack5Price: string
  naiveTotal: string
  optimizedTotal: string
  savings: string
}
