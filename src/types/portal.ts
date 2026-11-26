/** Tipos del Portal del Cliente (RF-7) — contrato JSON de `/api/portal/*`. Montos siempre string. */

/** Perfil de la sesión `client_portal`. */
export type PortalProfile = {
  client_id: number
  client_name: string
  contact_id: number | null
  contact_name: string
  contact_email: string
  contract_id: number | null
  scope: 'client_portal'
}

export type PortalSessionPayload = {
  token: string
  token_type: 'Bearer'
  expires_in: number
  idle_timeout_minutes?: number
  expires_at?: string
  profile: PortalProfile
}

export type PortalKeepalivePayload = {
  expires_at: string
  idle_timeout_minutes: number
}

/** Configuración pública del portal (`GET /api/portal/auth/config`). */
export type PortalPublicConfig = {
  magic_link_ttl_minutes: number
  idle_timeout_minutes: number
}

/** Respuesta genérica de `request-link`; la URL de prueba solo llega en sandbox local. */
export type PortalRequestLinkResult = {
  message: string
  ttl_minutes: number | null
  sandbox_preview_url: string | null
}

/** Desglose del Monto Exigible Devengado de un contrato. */
export type PortalExigible = {
  exigible_amount: string
  is_current: boolean
  detail: string
  previous_overdue: string
  accrued_amount: string
  effective_monthly_amount: string
  paid_in_period: string
  elapsed_months: number
  unpaid_months: string[]
}

export type PortalContractLine = {
  id: number
  service_id: number | null
  service_code: string | null
  description: string
  service_type: string
  billing_frequency: string
  unit_of_measure: string | null
  quantity: number
  unit_price: string
  discount_amount: string
  subtotal: string
  is_package_converted: boolean
}

export type PortalBalance = {
  id: number
  service_id: number
  service_code: string
  service_name: string
  bucket: string
  period_start: string | null
  period_end: string | null
  granted_units: number
  consumed_units: number
  available_units: number
}

export type PortalInvoiceStatus = 'pending' | 'partial' | 'paid' | 'overdue' | 'cancelled'

export type PortalInvoice = {
  id: number
  folio: string
  contract_id: number
  contract_folio: string | null
  invoice_type: string
  concept: string
  currency: string
  subtotal: string
  tax_amount: string
  total: string
  paid_amount: string
  balance: string
  issue_date: string
  due_date: string
  status: PortalInvoiceStatus
}

export type PortalPayment = {
  id: number
  invoice_id: number
  invoice_folio: string
  amount: string
  payment_method: string
  reference: string | null
  payment_date: string
}

/** Instrucciones SPEI del emisor (vacías si el administrador aún no las captura). */
export type PortalSpei = {
  issuer_id: number | null
  issuer_name: string | null
  bank_name: string | null
  clabe: string | null
  account_holder: string | null
  reference_hint: string | null
  is_configured: boolean
}

export type PortalContract = {
  id: number
  folio: string
  status: string
  frequency: string
  currency: string
  anniversary_date: string
  next_cutoff_date: string | null
  mrr_mxn: string
  is_current: boolean
  exigible_amount_mxn: string
  exigible_detail: string
  exigible: PortalExigible
  /** Saldo abierto total del contrato (todos los documentos pendientes). */
  open_balance_mxn: string
  concurrent_users: number
  lines: PortalContractLine[]
  balances: PortalBalance[]
  issuer: { id: number; name: string }
  /** Instrucciones SPEI del emisor de este contrato. */
  spei: PortalSpei
}

export type PortalContractDetail = PortalContract & {
  invoices: PortalInvoice[]
}

/** Tipos de solicitud que el cliente puede levantar desde el Portal (RF-8.1). */
export type PortalRequestType =
  | 'add_users'
  | 'remove_users'
  | 'change_frequency'
  | 'cancel_service'
  | 'extra_service'

/** Estado amigable calculado en el servidor. */
export type PortalRequestStatus = 'received' | 'approved' | 'executed' | 'rejected' | 'cancelled'

export type PortalRequest = {
  id: number
  folio: string
  contract_id: number
  contract_folio: string | null
  origin: string
  request_type: string
  portal_request_type: PortalRequestType | null
  status: string
  portal_status: PortalRequestStatus
  currency: string
  subtotal: string
  notes: string | null
  resolution_notes: string | null
  items: Array<{ service_name: string; quantity: number }>
  created_at: string
  resolved_at: string | null
}

export type PortalCreateRequestInput = {
  contract_id: number
  request_type: PortalRequestType
  service_id?: number
  quantity?: number
  notes: string
}

export type PortalOverview = {
  generated_at: string
  today: string
  client: { id: number; name: string; legal_name: string }
  contact: { id: number | null; name: string; email: string }
  default_contract_id: number | null
  contracts: PortalContract[]
  pending_invoices: PortalInvoice[]
  recent_invoices: PortalInvoice[]
  spei: PortalSpei
  recent_requests: PortalRequest[]
}

export type PortalInvoicesPage = {
  items: PortalInvoice[]
  total: number
  page: number
  per_page: number
  total_pages: number
  payments: PortalPayment[]
}

export type PortalInvoicesQuery = {
  page?: number
  per_page?: number
  status?: 'open' | 'paid'
  contract_id?: number
}

/* ── Pagos con tarjeta (IntegroPAY) ─────────────────────────────────────────────────── */

export type PortalPaymentMode = 'exigible' | 'total' | 'custom'

export type PortalCreatePaymentLinkInput = {
  contract_id: number
  mode: PortalPaymentMode
  amount?: string
  invoice_ids?: number[]
}

export type PortalPaymentLink = {
  payment_link_id: number
  external_link_id: string
  url: string
  amount: string
  expires_at: string
  is_sandbox: boolean
}

export type PortalPaymentLinkResult = PortalPaymentLink & {
  mode: PortalPaymentMode
  documents: Array<{ invoice_id: number; folio: string; amount: string }>
}

export type PortalSandboxLinkStatus = 'created' | 'paid' | 'expired' | 'cancelled'

export type PortalSandboxLink = {
  external_link_id: string
  status: PortalSandboxLinkStatus
  amount: string
  currency: string
  concept: string
  issuer_name: string
  contract_folio: string | null
  purpose: 'purchase' | 'balance'
  expires_at: string
  paid_at: string | null
  documents: Array<{ folio: string; amount: string }>
}

export type PortalSandboxConfirmResult = {
  applied?: boolean
  idempotent_replay?: boolean
  transaction_id: string
  amount?: string
  purchase_id?: number | null
  contract_reactivated?: boolean
}

/* ── Tienda (RF-2.8) ────────────────────────────────────────────────────────────────── */

export type PortalPriceTier = {
  min_quantity: number
  max_quantity: number | null
  unit_price: string
}

export type PortalStorePromotion = {
  id: number
  name: string
  extra_units_pct: string
  min_quantity: number | null
}

export type PortalStoreItem = {
  id: number
  code: string
  name: string
  description: string | null
  service_type: string
  unit_of_measure: string
  currency: string
  price_tiers: PortalPriceTier[]
  from_price: string
  promotions: PortalStorePromotion[]
}

export type PortalStoreCatalog = {
  contract_id: number | null
  contracts: Array<{ id: number; folio: string }>
  currency: string
  items: PortalStoreItem[]
  active_promotions: Array<PortalStorePromotion & { service_id: number; service_name: string }>
}

export type PortalStoreQuoteQuery = {
  contract_id: number
  service_id: number
  quantity: number
}

export type PortalStoreQuote = {
  contract_id: number
  service_id: number
  service_code: string
  service_name: string
  service_type: string
  unit_of_measure: string
  quantity: number
  unit_price: string
  subtotal: string
  tax_amount: string
  total: string
  currency: string
  extra_units: number
  total_units_to_receive: number
  promotion_id: number | null
  promotion_name: string | null
  extra_units_pct: string | null
}

export type PortalStorePaymentMethod = 'card_integropay' | 'spei'

export type PortalCheckoutInput = PortalStoreQuoteQuery & {
  payment_method: PortalStorePaymentMethod
}

export type PortalCheckoutResult = {
  purchase_id: number
  payment_method: PortalStorePaymentMethod
  quote: PortalStoreQuote
  payment_link: PortalPaymentLink | null
  invoice: { id: number; folio: string; total: string; due_date: string } | null
  spei: PortalSpei | null
}
