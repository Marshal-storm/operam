/** Tipos del módulo Utilidad por segmento (RF-10 / RF-11.5). */

export type SegmentCostCategory =
  | 'servers'
  | 'support_payroll'
  | 'implementation_payroll'
  | 'pac_folios'
  | 'other'

export type SegmentCost = {
  id: number
  segment_id: number
  segment_name: string | null
  month: string
  category: SegmentCostCategory | string
  concept: string
  amount: string
  notes: string | null
  created_by_user_id: number | null
  created_by_name: string | null
  created_at?: string
  updated_at?: string
}

export type UnitEconomics = {
  active_contracts_count: number
  active_concurrent_users_count: number
  revenue_per_contract_mxn: string
  cost_per_contract_mxn: string
  profit_per_contract_mxn: string
  revenue_per_user_mxn: string
  cost_per_user_mxn: string
  profit_per_user_mxn: string
}

export type SegmentProfitMetrics = {
  recurring_revenue_mxn: string
  non_recurring_revenue_mxn: string
  total_revenue_mxn: string
  total_cost_mxn: string
  gross_profit_mxn: string
  gross_margin_pct: string
} & UnitEconomics

export type SegmentProfitRow = SegmentProfitMetrics & {
  segment_id: number
  segment_name: string
}

export type MonthlyTrendPoint = {
  month: string
  total_revenue_mxn: string
  total_cost_mxn: string
  gross_profit_mxn: string
}

export type SegmentProfitabilitySummary = {
  month: string
  segment_filter: number | null
  consolidated: SegmentProfitMetrics
  by_segment: SegmentProfitRow[]
  monthly_trend: MonthlyTrendPoint[]
}

export type NonRecurringRow = {
  service_id: number
  service_name: string
  invoice_type?: string
  segment_id: number | null
  segment_name: string | null
  month: string
  operations_count: number
  billed_amount_mxn: string
  gifted_units: number
}

export type ExceptionReportRow = {
  exception_uid: string
  source: 'movement' | 'service_request' | string
  source_id: number
  date: string
  user_id: number | null
  user_name: string
  policy: string | null
  action: string | null
  reason: string | null
  overdue_balance_at_exception: string | null
  contract_folio: string | null
  client_name: string | null
  segment_id: number | null
  segment_name: string | null
}

export type Paginated<T> = {
  items: T[]
  total: number
  page: number
  per_page: number
  total_pages: number
  month?: string
}

export type SegmentCostWriteInput = {
  segment_id: number
  month: string
  category: SegmentCostCategory
  concept: string
  amount: string
  notes?: string | null
}

export const COST_CATEGORY_LABELS: Record<SegmentCostCategory, string> = {
  servers: 'Servidores',
  support_payroll: 'Nómina soporte',
  implementation_payroll: 'Nómina implementación',
  pac_folios: 'PAC / Folios',
  other: 'Otros',
}
