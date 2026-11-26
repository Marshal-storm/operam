import type { PortalRequestIntent, ServiceRequestType } from '@/types/request'

/** Etiquetas es-MX del tipo interno de solicitud (Bandeja `/solicitudes`). */
export const SERVICE_REQUEST_TYPE_LABEL: Record<ServiceRequestType, string> = {
  one_time: 'Pago único',
  consumption: 'Consumo',
  recurring_expansion: 'Expansión recurrente',
  plan_change: 'Cambio de plan',
}

/** Intención original del cliente cuando la solicitud llega desde el Portal (RF-8.1). */
export const PORTAL_REQUEST_INTENT_LABEL: Record<PortalRequestIntent, string> = {
  add_users: 'Alta de usuarios',
  remove_users: 'Baja de usuarios',
  change_frequency: 'Cambio de frecuencia',
  cancel_service: 'Cancelación de servicio',
  extra_service: 'Servicio extra',
}
