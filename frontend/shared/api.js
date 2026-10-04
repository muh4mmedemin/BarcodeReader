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
  /**
   * @param {{baseUrl: string, token?: string|null, onUnauthorized?: () => void}} options
   *   onUnauthorized: oturum geçersizse (401) çağrılır; giriş isteği hariç.
   */
  constructor({ baseUrl, token = null, onUnauthorized = null }) {
    this.baseUrl = baseUrl.replace(/\/+$/, '');
    this.token = token;
    this.onUnauthorized = onUnauthorized;
  }

  /** Ham istek: body JSON nesnesi veya FormData (dosya yükleme) olabilir. Hata durumunda ApiError fırlatır. */
  async send(method, path, body) {
    const headers = {};
    if (this.token) headers.Authorization = `Bearer ${this.token}`;
    const isForm = body instanceof FormData;
    if (body !== undefined && !isForm) headers['Content-Type'] = 'application/json';

    let res;
    try {
      res = await fetch(this.baseUrl + path, {
        method,
        headers,
        body: body === undefined ? undefined : (isForm ? body : JSON.stringify(body)),
      });
    } catch {
      throw new ApiError(0, { error: { code: 'NETWORK', message: 'Sunucuya ulaşılamıyor.' } });
    }

    if (!res.ok) {
      const json = await res.json().catch(() => null);
      if (res.status === 401 && path !== '/api/v1/auth/login') this.onUnauthorized?.();
      throw new ApiError(res.status, json);
    }
    return res;
  }

  async request(method, path, body) {
    const res = await this.send(method, path, body);
    return res.status === 204 ? null : res.json().catch(() => null);
  }

  /** Dosyayı indirir ve tarayıcıya kaydettirir; ad sunucunun Content-Disposition başlığından alınır. */
  async download(path, fallbackName) {
    const res = await this.send('GET', path);
    const blob = await res.blob();
    const cd = res.headers.get('Content-Disposition') ?? '';
    const utf = /filename\*=UTF-8''([^;]+)/i.exec(cd);
    const plain = /filename="?([^";]+)"?/i.exec(cd);
    const name = utf ? decodeURIComponent(utf[1]) : (plain ? plain[1] : fallbackName);

    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = name;
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(a.href), 10_000);
    return name;
  }

  // Oturum
  login(username, password) { return this.request('POST', '/api/v1/auth/login', { username, password }); }
  logout() { return this.request('POST', '/api/v1/auth/logout'); }
  me() { return this.request('GET', '/api/v1/me'); }

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
  overview() { return this.request('GET', '/api/v1/reports/overview'); }
  stationReport(days) { return this.request('GET', `/api/v1/reports/stations?days=${days}`); }
  board() { return this.request('GET', '/api/v1/board'); }
  boardVersion() { return this.request('GET', '/api/v1/board/version'); }
  getPo(id) { return this.request('GET', `/api/v1/pos/${id}`); }
  createPo(data) { return this.request('POST', '/api/v1/pos', data); }
  updatePo(id, data) { return this.request('PUT', `/api/v1/pos/${id}`, data); }
  deletePo(id) { return this.request('DELETE', `/api/v1/pos/${id}`); }

  // İş emri
  createWorkOrder(poId, data) { return this.request('POST', `/api/v1/pos/${poId}/work-orders`, data); }
  updateWorkOrder(id, data) { return this.request('PUT', `/api/v1/work-orders/${id}`, data); }
  moveWorkOrder(id, target) { return this.request('POST', `/api/v1/work-orders/${id}/move`, target); }
  workOrderHistory(id) { return this.request('GET', `/api/v1/work-orders/${id}/scans`); }
  exportPo(id) { return this.download(`/api/v1/pos/${id}/export`, `po-${id}.xlsx`); }

  // Excel şablonu
  templateInfo() { return this.request('GET', '/api/v1/templates/po'); }
  downloadTemplate() { return this.download('/api/v1/templates/po/file', 'po-sablon.xlsx'); }
  uploadTemplate(file) {
    const form = new FormData();
    form.append('file', file);
    return this.request('POST', '/api/v1/templates/po', form);
  }
  resetTemplate() { return this.request('DELETE', '/api/v1/templates/po'); }

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
