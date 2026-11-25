/// Local clock time from an ISO string, e.g. "9:41 AM". The API sends UTC ISO
/// strings; we show them in the device's local time.
String timeOf(String? iso) {
  if (iso == null) return '—';
  final dt = DateTime.tryParse(iso)?.toLocal();
  if (dt == null) return '—';
  final h = dt.hour % 12 == 0 ? 12 : dt.hour % 12;
  final m = dt.minute.toString().padLeft(2, '0');
  return '$h:$m ${dt.hour < 12 ? 'AM' : 'PM'}';
}

/// Whole minutes elapsed since an ISO timestamp (never negative).
int minutesSince(String? iso) {
  final dt = iso == null ? null : DateTime.tryParse(iso)?.toLocal();
  if (dt == null) return 0;
  final diff = DateTime.now().difference(dt).inMinutes;
  return diff < 0 ? 0 : diff;
}
