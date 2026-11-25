import 'package:flutter/material.dart';

import '../../api/api_client.dart';
import '../../api/housekeeping_api.dart';
import '../../state/auth_store.dart';
import '../../theme.dart';
import '../../util/fmt.dart';
import '../../widgets/homi.dart';

/// "My cleans today" (web screen 34): the roomboy's own record for the day — how
/// many rooms, the average time, how many were late, and each finished clean.
class HistoryScreen extends StatefulWidget {
  const HistoryScreen({super.key, required this.auth});

  final AuthStore auth;

  @override
  State<HistoryScreen> createState() => _HistoryScreenState();
}

class _HistoryScreenState extends State<HistoryScreen> {
  late final HousekeepingApi _api = HousekeepingApi(ApiClient(), widget.auth.token!);
  Map<String, dynamic>? _data;
  bool _loading = true;
  String? _error;

  @override
  void initState() {
    super.initState();
    load();
  }

  Future<void> load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final data = await _api.history();
      if (mounted) setState(() => _data = data);
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final count = _data?['count'] as int? ?? 0;
    final late = _data?['late'] as int? ?? 0;
    final avg = _data?['average_minutes'] as int?;
    final cleans = (_data?['cleans'] as List<dynamic>? ?? const []).cast<Map<String, dynamic>>();
    final lateNote = _data?['late_note'] as String?;

    return Scaffold(
      appBar: AppBar(
        toolbarHeight: 64,
        title: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          mainAxisSize: MainAxisSize.min,
          children: [
            const Text('My cleans', style: TextStyle(color: HomiColors.ink, fontWeight: FontWeight.w600, fontSize: 16)),
            Text('Today · $count ${count == 1 ? 'room' : 'rooms'}${late > 0 ? ' · $late late' : ''}',
                style: const TextStyle(color: HomiColors.muted, fontSize: 12.5)),
          ],
        ),
        actions: [
          IconButton(icon: const Icon(Icons.refresh), onPressed: _loading ? null : load),
          const SizedBox(width: 4),
        ],
      ),
      body: _loading && _data == null
          ? const Center(child: CircularProgressIndicator())
          : _error != null
              ? Center(child: Text(_error!, style: const TextStyle(color: HomiColors.muted)))
              : RefreshIndicator(
                  onRefresh: load,
                  child: ListView(
                    padding: const EdgeInsets.fromLTRB(16, 16, 16, 32),
                    children: [
                      Row(
                        children: [
                          Expanded(child: StatTile(label: 'Rooms done', value: '$count', valueColor: HomiColors.primaryInk)),
                          const SizedBox(width: 10),
                          Expanded(child: StatTile(label: 'Average', value: avg == null ? '—' : '$avg', sub: avg == null ? null : 'min')),
                          const SizedBox(width: 10),
                          Expanded(child: StatTile(label: 'Late', value: '$late', valueColor: late > 0 ? HomiColors.bad : HomiColors.ink)),
                        ],
                      ),
                      const SizedBox(height: 20),
                      if (cleans.isEmpty)
                        const _Empty()
                      else
                        HomiCard(
                          padding: EdgeInsets.zero,
                          child: Column(
                            children: [
                              for (var i = 0; i < cleans.length; i++) ...[
                                if (i > 0) const Divider(height: 1, color: HomiColors.row),
                                _cleanRow(cleans[i]),
                              ],
                            ],
                          ),
                        ),
                      if (lateNote != null) ...[
                        const SizedBox(height: 14),
                        Text(lateNote, style: const TextStyle(color: HomiColors.muted, fontSize: 13, height: 1.4)),
                      ],
                    ],
                  ),
                ),
    );
  }

  Widget _cleanRow(Map<String, dynamic> c) {
    final took = c['took_minutes'] as int?;
    final late = c['late'] == true;
    final problem = c['problem'] == true;
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
      child: Row(
        children: [
          SizedBox(
            width: 64,
            child: Text('Room ${c['room']}',
                style: const TextStyle(color: HomiColors.ink, fontWeight: FontWeight.w600, fontSize: 15, fontFeatures: tabular)),
          ),
          const SizedBox(width: 8),
          Expanded(
            child: Text('Finished ${timeOf(c['finished_at'] as String?)}',
                style: const TextStyle(color: HomiColors.muted, fontSize: 13.5)),
          ),
          if (late)
            const StatusText(label: 'Late', color: HomiColors.bad)
          else if (problem)
            const StatusText(label: 'Problem', color: HomiColors.warn)
          else
            Text(took == null ? '—' : '$took min',
                style: const TextStyle(color: HomiColors.text, fontSize: 13.5, fontFeatures: tabular)),
        ],
      ),
    );
  }
}

class _Empty extends StatelessWidget {
  const _Empty();
  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.only(top: 80),
        child: Column(
          children: const [
            Icon(Icons.cleaning_services_outlined, size: 40, color: Color(0xFFB3BAC6)),
            SizedBox(height: 12),
            Text('No rooms cleaned yet today', style: TextStyle(color: HomiColors.ink, fontWeight: FontWeight.w600, fontSize: 15)),
            SizedBox(height: 4),
            Text('Finished rooms show up here.', style: TextStyle(color: HomiColors.muted, fontSize: 13)),
          ],
        ),
      );
}
