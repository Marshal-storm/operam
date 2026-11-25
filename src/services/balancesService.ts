import { ApiClientError, createApiFetch } from '@/lib/apiClient'
import type {
  ConsumeBalanceInput,
  ContractBalancesPayload,
  GrantBalanceInput,
} from '@/types/balance'

const API_BASE_URL = (
  import.meta.env.VITE_API_URL ??
  import.meta.env.VITE_API_BASE_URL ??
  'http://localhost:8080/api'
).replace(/\/$/, '')

type TokenGetter = () => string | null
let getToken: TokenGetter = () => null

export function configureBalancesAuth(tokenGetter: TokenGetter): void {
  getToken = tokenGetter
}

const apiFetch = createApiFetch({
  getBaseUrl: () => API_BASE_URL,
  getToken: () => getToken(),
})

export { ApiClientError as BalancesApiError }

export function fetchContractBalances(
  contractId: number,
  params?: { page?: number; per_page?: number },
): Promise<ContractBalancesPayload> {
  const qs = new URLSearchParams()
  if (params?.page) qs.set('page', String(params.page))
  if (params?.per_page) qs.set('per_page', String(params.per_page))
  const suffix = qs.toString() ? `?${qs.toString()}` : ''
  return apiFetch(`/balances/contract/${contractId}${suffix}`)
}

export function consumeBalance(input: ConsumeBalanceInput): Promise<unknown> {
  return apiFetch('/balances/consume', {
    method: 'POST',
    body: JSON.stringify(input),
  })
}

export function grantBalance(input: GrantBalanceInput): Promise<unknown> {
  return apiFetch('/balances/grant', {
    method: 'POST',
    body: JSON.stringify(input),
  })
}
