/** Tipos del Módulo 4 Paso 1 — Bandeja de Solicitudes. */

export type ServiceRequestStatus =
  | 'pending'
  | 'quoted'
  | 'approved'
  | 'rejected'
  | 'cancelled'

export type ServiceRequestType = 'one_time' | 'consumption' | 'recurring_expansion' | 'plan_change'

export type ServiceRequestOrigin = 'internal' | 'portal'

/** Intención del cliente capturada en el Portal (`service_requests.portal_request_type`). */
export type PortalRequestIntent =
  | 'add_users'
  | 'remove_users'
  | 'change_frequency'
  | 'cancel_service'
  | 'extra_service'

export type ServiceRequestItemType = 'recurring' | 'consumption' | 'one_time'

export type ServiceRequestKpis = {
  pending_count: number
  quoted_count: number
  approved_month_count: number
  approved_one_time_total_mxn: string
}

export type ServiceRequestItem = {
  id: number
  service_request_id: number
  service_id: number
  service_name: string
  service_code: string
  service_type: ServiceRequestItemType
  quantity: number
  unit_price: string
  subtotal: string
  mrr_impact_mxn: string
  created_at: string
  updated_at: string
}

export type ServiceRequestListItem = {
  id: number
  folio: string
  contract_id: number
  contract_folio: string
  contract_status: string
  contract_mrr_mxn: string
  client_id: number
  client_name: string
  client_rfc: string
  segment_id: number
  segment_name: string
  issuer_id: number
  issuer_name: string
  origin: ServiceRequestOrigin
  request_type: ServiceRequestType
  portal_request_type: PortalRequestIntent | null
  status: ServiceRequestStatus
  currency: 'MXN' | 'USD' | string
  subtotal: string
  mrr_impact_mxn: string
  notes: string | null
  resolution_notes: string | null
  with_exception: boolean
  exception_policy: string | null
  requested_by_user_id: number | null
  requested_by_name: string | null
  resolved_by_user_id: number | null
  resolved_by_name: string | null
  resolved_at: string | null
  created_at: string
  updated_at: string
  contract_is_current: boolean
  contract_overdue_balance: string
  contract_days_overdue: number
  /** Factura activa en cobranza (si ya se generó desde la solicitud). */
  invoice_id: number | null
  invoice_folio: string | null
  services_summary: string
  items?: ServiceRequestItem[]
  price_list_id?: number | null
  contract_frequency?: string
  contract_exchange_rate?: string
  contract_currency?: string
}

export type ServiceRequestDetail = ServiceRequestListItem & {
  items: ServiceRequestItem[]
}

export type ServiceRequestCreateInput = {
  contract_id: number
  notes?: string
  items: Array<{
    service_id: number
    quantity: number
    custom_unit_price?: string
  }>
}

export type ServiceRequestQuoteInput = {
  resolution_notes?: string
  items?: ServiceRequestCreateInput['items']
}

export type ServiceRequestApproveInput = {
  with_exception?: boolean
  resolution_notes?: string
}

export type ServiceRequestRejectInput = {
  resolution_notes: string
}
