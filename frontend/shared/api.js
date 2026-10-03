// Backend ile konuşan tek katman. Başka bir client (mobil, el terminali vb.) yazarken
// aynı sözleşme docs/API.md'de tarif edilmiştir.

export class ApiError extends Error {
  constructor(status, body) {
    const err = body?.error ?? {};
    super(err.message || `HTTP ${status}`);
    this.status = status;
    this.code = err.code || 'UNKNOWN';
    this.field = err.field ?? null;
  }
}

export class ApiClient {
  constructor({ baseUrl, apiKey }) {
    this.baseUrl = baseUrl.replace(/\/+$/, '');
    this.apiKey = apiKey;
  }

  async request(method, path, body) {
    let res;
    try {
      res = await fetch(this.baseUrl + path, {
        method,
        headers: {
          'Content-Type': 'application/json',
          'X-API-Key': this.apiKey,
        },
        body: body === undefined ? undefined : JSON.stringify(body),
      });
    } catch {
      throw new ApiError(0, { error: { code: 'NETWORK', message: 'Sunucuya ulaşılamıyor.' } });
    }

    if (res.status === 204) return null;
    const json = await res.json().catch(() => null);
    if (!res.ok) throw new ApiError(res.status, json);
    return json;
  }

  // Sistem
  health() { return this.request('GET', '/api/v1/health'); }
  check(field, value, exceptId) {
    const q = new URLSearchParams({ field, value });
    if (exceptId) q.set('except_id', exceptId);
    return this.request('GET', `/api/v1/check?${q}`);
  }

  // PO
  listPos(search = '', limit = 50, offset = 0) {
    const q = new URLSearchParams({ search, limit, offset });
    return this.request('GET', `/api/v1/pos?${q}`);
  }
  getPo(id) { return this.request('GET', `/api/v1/pos/${id}`); }
  createPo(data) { return this.request('POST', '/api/v1/pos', data); }
  updatePo(id, data) { return this.request('PUT', `/api/v1/pos/${id}`, data); }
  deletePo(id) { return this.request('DELETE', `/api/v1/pos/${id}`); }

  // İş emri
  createWorkOrder(poId, data) { return this.request('POST', `/api/v1/pos/${poId}/work-orders`, data); }
  updateWorkOrder(id, data) { return this.request('PUT', `/api/v1/work-orders/${id}`, data); }
  deleteWorkOrder(id) { return this.request('DELETE', `/api/v1/work-orders/${id}`); }

  // Üretim
  stations() { return this.request('GET', '/api/v1/stations'); }
  lookup(barcode) { return this.request('GET', `/api/v1/production/lookup/${encodeURIComponent(barcode)}`); }
  scan(barcode, stationId) {
    return this.request('POST', '/api/v1/production/scan', { barcode, station_id: stationId });
  }
}

export const STATUS_LABELS = {
  open: 'Bekliyor',
  in_progress: 'Üretimde',
  done: 'Tamamlandı',
  cancelled: 'İptal',
};

/** Üretimdeki iş emri için bulunduğu istasyonun adını, diğerleri için durum adını döndürür. */
export function statusLabel(wo) {
  if (wo.status === 'in_progress' && wo.current_station_name) return wo.current_station_name;
  return STATUS_LABELS[wo.status] ?? wo.status;
}

export function escapeHtml(value) {
  return String(value ?? '').replace(/[&<>"']/g, (c) => (
    { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
  ));
}
