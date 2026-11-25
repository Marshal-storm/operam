/** Tipos del motor de saldos / bolsas / consumos (RF-4). */

export type BalanceBucket = 'included' | 'prepaid'

export type BalanceBucketRow = {
  id: number
  bucket: BalanceBucket | string
  period_start: string | null
  period_end: string | null
  granted_units: number
  consumed_units: number
  available_units: number
}

export type ContractBalanceServiceGroup = {
  service_id: number
  service_code: string
  service_name: string
  buckets: BalanceBucketRow[]
}

export type ConsumptionRow = {
  id: number
  balance_id: number
  service_id: number
  service_name: string
  units: number
  external_reference: string | null
  instance_id: number | null
  user_id: number | null
  user_name: string | null
  notes: string | null
  consumed_at: string
  created_at?: string
}

export type BalanceMovementRow = {
  id: number
  balance_id: number
  service_id: number
  service_name: string
  bucket: string
  movement_type: string
  units: number
  request_id: number | null
  promotion_id: number | null
  notes: string | null
  created_by: number | null
  created_by_name: string | null
  created_at: string
}

export type ContractBalancesPayload = {
  contract_id: number
  folio: string
  client_name: string
  services: ContractBalanceServiceGroup[]
  consumptions: {
    items: ConsumptionRow[]
    total: number
    page: number
    per_page: number
    total_pages: number
  }
  balance_movements: BalanceMovementRow[]
}

export type ConsumeBalanceInput = {
  contract_id: number
  service_id: number
  units: number
  external_reference?: string
  notes?: string
}

export type GrantBalanceInput = {
  contract_id: number
  service_id: number
  bucket: BalanceBucket
  units: number
  movement_type?: 'grant' | 'adjustment'
  period_start?: string
  period_end?: string
  notes?: string
}
