/** Tipos del Módulo 3 — Contratos (listado, detalle y ciclo de vida). */

export type ContractStatus = 'active' | 'suspended' | 'cancelled' | 'draft'
export type ContractBillingFrequency = 'monthly' | 'annual' | 'single'

export type ContractLifecycleOperation =
  | 'expansion'
  | 'contraction'
  | 'price_adjustment'
  | 'frequency_change'
  | 'issuer_transfer'
  | 'churn'
  | 'reactivation'

export type ContractMrrCategory =
  | 'new'
  | 'expansion'
  | 'price_adjustment'
  | 'frequency_change'
  | 'contraction'
  | 'churn'
  | 'reactivation'
  | 'transfer'

export type ContractSummary = {
  total_contracts: number
  active_contracts: number
  total_mrr_mxn: string
  suspended_contracts: number
}

export type ContractListItem = {
  id: number
  folio: string
  client_id: number
  client_name: string
  client_legal_name?: string
  client_rfc: string
  segment_id: number
  segment_name: string
  issuer_id: number
  issuer_name: string
  instance_id: number | null
  instance_name: string | null
  price_list_id: number | null
  price_list_name: string | null
  promotion_id: number | null
  promotion_code?: string | null
  billing_frequency: ContractBillingFrequency
  frequency: ContractBillingFrequency
  anniversary_date: string
  currency: 'MXN' | 'USD' | string
  exchange_rate: string
  credit_days: number
  stamp_on_issue: boolean
  status: ContractStatus
  mrr_mxn: string
  next_billing_date: string | null
  next_cutoff_date: string | null
  origin_contract_id?: number | null
  termination_reason?: string | null
  notes: string | null
  lines_count: number
  created_by: number | null
  created_at: string | null
  updated_at: string | null
  /** Cartera (collection_statuses) — útil para solicitudes. */
  contract_is_current?: boolean
  contract_overdue_balance?: string
  contract_days_overdue?: number
}

export type ContractLine = {
  id: number
  contract_id: number
  service_id: number
  description: string
  service_type: string
  billing_frequency: string
  quantity: number
  unit_price: string
  discount_amount: string
  subtotal: string
  mrr_mxn: string
  is_package_converted: boolean
  active: boolean
  created_at?: string
  updated_at?: string
}

export type ContractMovement = {
  id: number
  contract_id: number
  contract_line_id: number | null
  service_id: number | null
  movement_type: string
  mrr_category: ContractMrrCategory | string
  effective_date: string
  quantity_before: string
  quantity_after: string
  mrr_before: string
  mrr_after: string
  mrr_delta_mxn: string
  exchange_rate: string
  user_id: number | null
  user_name: string | null
  request_id?: number | null
  /** Folio de solicitud origen (RF-3.7), ej. SOL-0002. */
  request_folio?: string | null
  reason: string | null
  has_exception: boolean
  reversed_movement_id: number | null
  /** True si ya existe otro asiento con reversed_movement_id = este id. */
  is_reversed: boolean
  created_at: string
}

export type ContractMovementReversalInput = {
  reason: string
  effective_date?: string
}

export type ContractDetail = ContractListItem & {
  lines: ContractLine[]
  movements: ContractMovement[]
  active_lines_count?: number
  active_recurring_count?: number
  active_one_time_count?: number
}

export type ContractLifecycleInput = {
  operation: ContractLifecycleOperation
  effective_date?: string
  reason: string
  contract_line_id?: number
  service_id?: number
  quantity?: number
  unit_price?: string
  unit_price_override?: string
  deactivate?: boolean
  billing_frequency?: 'monthly' | 'annual'
  issuer_id?: number
}

export type ContractLineInput = {
  service_id: number
  quantity: number
  unit_price_override?: string
}

export type ContractCreateInput = {
  client_id: number
  instance_id?: number | null
  price_list_id: number
  billing_frequency: 'monthly' | 'annual'
  anniversary_date: string
  credit_days: number
  stamp_on_issue: boolean
  promotion_id?: number | null
  exchange_rate?: string
  notes?: string
  lines: ContractLineInput[]
}

/** Filtros + porcentaje del Ajuste Masivo de Precios (RF-3.6). */
export type BulkPriceAdjustmentInput = {
  segment_id?: number
  issuer_id?: number
  service_id?: number
  anniversary_from?: string
  anniversary_to?: string
  /** Positivo = incremento, negativo = decremento (2 decimales, nunca 0). */
  percentage: string
}

export type BulkPriceAdjustmentApplyInput = BulkPriceAdjustmentInput & {
  reason: string
}

export type BulkPriceAdjustmentSummary = {
  percentage: string
  affected_contracts_count: number
  affected_lines_count: number
  current_total_mrr_mxn: string
  new_total_mrr_mxn: string
  total_mrr_delta_mxn: string
}

export type BulkPriceAdjustmentItem = {
  contract_id: number
  folio: string
  client_name: string
  segment_name: string
  issuer_name: string
  next_billing_date: string
  lines_count: number
  old_mrr_mxn: string
  new_mrr_mxn: string
  mrr_delta_mxn: string
}

export type BulkPriceAdjustmentPreview = {
  summary: BulkPriceAdjustmentSummary
  items: BulkPriceAdjustmentItem[]
}

export type BulkPriceAdjustmentResult = BulkPriceAdjustmentPreview & {
  batch_id: number
  batch_code: string
}

export type BulkPriceAdjustmentServiceOption = {
  id: number
  code: string
  name: string
}
