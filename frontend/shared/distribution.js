// İş emirlerinin konuma göre dağılımı: Bekliyor / her istasyon / Tamamlandı / İptal.
// PO Oluşturma (tablo) ve PO Takip (pasta) sayfaları ortak kullanır.

/**
 * @param {Array} workOrders  İş emirleri (status, current_station_id, current_station_name)
 * @param {Array} stations    Aktif istasyonlar, hat sırasıyla
 * @returns {Array<{key: string, label: string, status: string, count: number, shade: number}>}
 *   shade: istasyon satırlarında hat sırasına göre 0..1 (renk tonu için)
 */
export function groupByLocation(workOrders, stations) {
  const groups = [];
  const inProgress = workOrders.filter((wo) => wo.status === 'in_progress');
  const lineStations = stations.filter((s) => !s.is_final); // son istasyon = Tamamlandı

  groups.push({ key: 'open', label: 'Bekliyor', status: 'open', count: workOrders.filter((wo) => wo.status === 'open').length });

  lineStations.forEach((s, i) => {
    groups.push({
      key: `st-${s.id}`,
      label: s.name,
      status: 'in_progress',
      count: inProgress.filter((wo) => wo.current_station_id === s.id).length,
      shade: lineStations.length > 1 ? i / (lineStations.length - 1) : 1,
    });
  });

  // Pasife alınmış istasyonlarda kalan iş emirleri
  const listed = new Set(lineStations.map((s) => s.id));
  const others = new Map();
  for (const wo of inProgress.filter((w) => !listed.has(w.current_station_id))) {
    others.set(wo.current_station_name, (others.get(wo.current_station_name) ?? 0) + 1);
  }
  for (const [name, count] of others) {
    groups.push({ key: `old-${name}`, label: `${name} (pasif istasyon)`, status: 'in_progress', count, shade: 0 });
  }

  groups.push({ key: 'done', label: 'Tamamlandı', status: 'done', count: workOrders.filter((wo) => wo.status === 'done').length });

  const cancelled = workOrders.filter((wo) => wo.status === 'cancelled').length;
  if (cancelled) groups.push({ key: 'cancelled', label: 'İptal', status: 'cancelled', count: cancelled });

  return groups;
}

export function percent(part, total) {
  return total ? `%${Math.round((part / total) * 100)}` : '—';
}

/** Dilim rengi: durum rengi; istasyonlar hat sırasına göre açıktan koyuya amber. */
export function groupColor(group) {
  switch (group.status) {
    case 'open': return 'var(--idle)';
    case 'done': return 'var(--ok)';
    case 'cancelled': return 'var(--alarm)';
    default: return `color-mix(in srgb, var(--warn) ${Math.round(40 + (group.shade ?? 1) * 60)}%, var(--surface))`;
  }
}
