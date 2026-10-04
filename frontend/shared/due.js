// PO termin durumu. Tarihler YYYY-MM-DD; "bugün" tarayıcının yerel tarihidir.

export const SOON_DAYS = 2;

export function todayLocal() {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

/** İki YYYY-MM-DD arasındaki gün farkı (b - a). */
function dayDiff(a, b) {
  return Math.round((Date.parse(`${b}T00:00:00Z`) - Date.parse(`${a}T00:00:00Z`)) / 86400000);
}

/** Tüm iş emirleri bitti mi (tamamlandı veya iptal). İş emri yoksa bitmemiş sayılır. */
export function isComplete(workOrders) {
  return workOrders.length > 0 && workOrders.every((wo) => wo.status === 'done' || wo.status === 'cancelled');
}

/**
 * @param {boolean} complete  PO'nun tüm iş emirleri bitti mi (isComplete)
 * @returns {{kind: 'none'|'done'|'late'|'soon'|'ok', label: string, title: string}}
 *   late: termin geçti ve bitmedi · soon: termine 0–2 gün kaldı ve bitmedi
 */
export function dueStatus(dueDate, complete, today = todayLocal()) {
  if (!dueDate) return { kind: 'none', label: '—', title: 'Termin tarihi yok' };
  const date = formatDate(dueDate);
  if (complete) return { kind: 'done', label: date, title: `Termin ${date} · tamamlandı` };

  const left = dayDiff(today, dueDate);
  if (left < 0) return { kind: 'late', label: `${-left} gün gecikti`, title: `Termin ${date}` };
  if (left <= SOON_DAYS) {
    return { kind: 'soon', label: left === 0 ? 'Termin bugün' : `${left} gün kaldı`, title: `Termin ${date}` };
  }
  return { kind: 'ok', label: date, title: `Termin ${date} · ${left} gün kaldı` };
}

/** 2026-10-15 → 15.10.2026 */
export function formatDate(iso) {
  const [y, m, d] = String(iso).split('-');
  return d && m && y ? `${d}.${m}.${y}` : iso;
}
