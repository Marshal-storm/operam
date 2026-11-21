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
