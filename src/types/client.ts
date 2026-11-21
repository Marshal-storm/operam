/**
 * Tipos del directorio de clientes (Módulo 3 Paso 1).
 */

export type Client = {
  id: number
  segment_id: number
  issuer_id: number
  erp_client_id: string | null
  name: string
  legal_name: string
  rfc: string
  contact_name: string | null
  contact_email: string | null
  contact_phone: string | null
  credit_days: number
  stamp_on_issue: boolean
  exclude_suspension: boolean
  active: boolean
  segment_name: string | null
  issuer_name: string | null
  active_contracts_count: number
  created_at?: string | null
  updated_at?: string | null
}

export type ClientsSummary = {
  total: number
  active_count: number
  auto_stamp_count: number
  excluded_suspension_count: number
}

export type ClientWriteInput = {
  segment_id: number
  issuer_id: number
  erp_client_id?: string | null
  name: string
  legal_name: string
  rfc: string
  contact_name?: string | null
  contact_email?: string | null
  contact_phone?: string | null
  credit_days?: number | null
  stamp_on_issue?: boolean
  exclude_suspension?: boolean
  active?: boolean
}
