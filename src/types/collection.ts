/** Tipos del Módulo 4 Paso 2 — Cobranza (`invoices` / `payments` / cartera). */

export type InvoiceStatus = 'pending' | 'partial' | 'paid' | 'overdue' | 'cancelled'

export type InvoiceType = 'recurring' | 'one_time' | 'consumption' | 'mixed'

export type PaymentMethod =
  | 'transfer'
  | 'card_integropay'
  | 'cash'
  | 'check'
  | 'other'

export type AgingBucketKey =
  | 'upcoming'
  | 'days_1_30'
  | 'days_31_60'
  | 'days_61_90'
  | 'suspended_90_plus'

export type NoticeCode = 'none' | 'reminder' | 'warning' | 'urgent' | 'suspension'

export type InvoiceListItem = {
  id: number
  folio: string
  contract_id: number
  contract_folio: string
  client_id: number
  client_name: string
  segment_id: number
  segment_name: string
  issuer_id: number
  issuer_name: string
  request_id: number | null
  request_folio: string | null
  invoice_type: InvoiceType
  concept: string
  currency: 'MXN' | 'USD'
  subtotal: string
  tax_amount: string
  total: string
  paid_amount: string
  balance: string
  issue_date: string
  due_date: string
  days_overdue: number
  status: InvoiceStatus
  credit_days: number
  notes: string | null
  created_at: string
  updated_at: string
}

export type PaymentRecord = {
  id: number
  amount: string
  payment_method: PaymentMethod
  reference: string | null
  payment_date: string
  notes: string | null
  recorded_by_user_id: number | null
  is_reversed: boolean
  reversed_at: string | null
  reversal_reason: string | null
  created_at: string
}

export type InvoiceDetail = InvoiceListItem & {
  payments: PaymentRecord[]
  /** RF-6.3: el pago liquidó la deuda de licencias >90 días y el contrato se reactivó. */
  contract_reactivated?: boolean
}

export type CollectionKpis = {
  upcoming_total_mxn: string
  overdue_total_mxn: string
  contracts_over_60_days: number
  suspended_contracts_count: number
}

export type CollectionAgingBucket = {
  key: AgingBucketKey
  label: string
  amount_mxn: string
  color: string
}

export type ContractPortfolioItem = {
  contract_id: number
  contract_folio: string
  contract_status: string
  client_id: number
  client_name: string
  segment_id: number
  segment_name: string
  mrr_mxn: string
  upcoming_balance: string
  overdue_balance: string
  days_overdue: number
  notice_level: number
  notice_code: NoticeCode
  notice_label: string
  exclude_suspension: boolean
  is_suspended: boolean
}

export type IssueInvoiceInput = {
  contract_id: number
  mode: 'recurring_cutoff' | 'custom'
  concept?: string
  amount?: string
  invoice_type?: InvoiceType
  issue_date?: string
  due_date?: string
  tax_amount?: string
  notes?: string
}

export type RecordPaymentInput = {
  amount: string
  payment_method: PaymentMethod
  payment_date?: string
  reference?: string
  notes?: string
}

export type SuspensionCheckResult = {
  evaluated_count: number
  suspended_count: number
  excluded_count: number
  suspended_folios: string[]
  as_of: string
}
