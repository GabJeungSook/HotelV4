import 'package:flutter/material.dart';

import '../../api/api_client.dart';
import '../../api/housekeeping_api.dart';
import '../../state/auth_store.dart';
import '../../theme.dart';
import '../../util/fmt.dart';
import '../../widgets/homi.dart';
import 'cleaning_screen.dart';

/// The roomboy's queue: rooms waiting to be cleaned, most urgent first. Tap a
/// startable room to begin; an in-progress room opens the cleaning screen.
class HousekeepingQueueScreen extends StatefulWidget {
  const HousekeepingQueueScreen({super.key, required this.auth});

  final AuthStore auth;

  @override
  State<HousekeepingQueueScreen> createState() => _HousekeepingQueueScreenState();
}

class _HousekeepingQueueScreenState extends State<HousekeepingQueueScreen> {
  late final HousekeepingApi _api = HousekeepingApi(ApiClient(), widget.auth.token!);
  Map<String, dynamic>? _data;
  String? _error;
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final data = await _api.queue();
      if (mounted) setState(() => _data = data);
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _open(int taskId, {required bool alreadyStarted}) async {
    try {
      if (!alreadyStarted) await _api.start(taskId);
    } on ApiException catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
        await _load();
      }
      return;
    }
    if (!mounted) return;
    await Navigator.of(context).push(MaterialPageRoute(
      builder: (_) => CleaningScreen(auth: widget.auth, taskId: taskId),
    ));
    await _load();
  }

  @override
  Widget build(BuildContext context) {
    final name = _data?['user_name'] as String? ?? 'Housekeeping';
    final floors = _data?['floors'] as String?;
    final online = _data?['online'] as int?;
    final sub = [if (floors != null) floors, if (online != null) '$online on duty'].join(' · ');

    return Scaffold(
      appBar: AppBar(
        toolbarHeight: 64,
        titleSpacing: 12,
        leading: Padding(padding: const EdgeInsets.only(left: 16), child: Center(child: InitialsAvatar(name: name))),
        leadingWidth: 60,
        title: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(name, style: const TextStyle(color: HomiColors.ink, fontWeight: FontWeight.w600, fontSize: 16)),
            if (sub.isNotEmpty)
              Text(sub, style: const TextStyle(color: HomiColors.muted, fontSize: 12.5)),
          ],
        ),
        actions: [
          IconButton(icon: const Icon(Icons.refresh), onPressed: _loading ? null : _load),
          IconButton(icon: const Icon(Icons.logout), onPressed: widget.auth.logout),
          const SizedBox(width: 4),
        ],
      ),
      body: RefreshIndicator(onRefresh: _load, child: _body()),
    );
  }

  Widget _body() {
    final tasks = (_data?['tasks'] as List<dynamic>? ?? const []).cast<Map<String, dynamic>>();
    final current = _data?['current'] as Map<String, dynamic>?;
    final others = (_data?['others'] as List<dynamic>? ?? const []).cast<Map<String, dynamic>>();

    if (_loading && _data == null) {
      return const Center(child: CircularProgressIndicator());
    }
    if (_error != null) {
      return _ErrorState(message: _error!, onRetry: _load);
    }
    if (tasks.isEmpty && current == null && others.isEmpty) {
      return const _EmptyState();
    }

    return ListView(
      padding: const EdgeInsets.fromLTRB(16, 16, 16, 32),
      children: [
        if (current != null) ...[
          HomiCard(
            topEdge: HomiColors.violet,
            onTap: () => _open(current['id'] as int, alreadyStarted: true),
            child: Row(
              children: [
                Container(
                  width: 40, height: 40,
                  decoration: BoxDecoration(color: HomiColors.violet50, borderRadius: BorderRadius.circular(9)),
                  child: const Icon(Icons.cleaning_services_outlined, color: HomiColors.violet, size: 20),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text('Room ${current['room']}',
                          style: const TextStyle(color: HomiColors.ink, fontWeight: FontWeight.w600, fontSize: 15.5)),
                      const SizedBox(height: 3),
                      StatusText(
                        label: current['started_at'] == null
                            ? 'Cleaning now · tap to finish'
                            : 'Started ${timeOf(current['started_at'] as String?)} · tap to finish',
                        color: HomiColors.violet,
                      ),
                    ],
                  ),
                ),
                const Icon(Icons.chevron_right, color: HomiColors.faint),
              ],
            ),
          ),
          const SizedBox(height: 20),
        ],
        if (others.isNotEmpty) ...[
          const _SectionLabel('Others cleaning'),
          const SizedBox(height: 10),
          HomiCard(
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 6),
            child: Column(
              children: [
                for (final o in others)
                  Padding(
                    padding: const EdgeInsets.symmetric(vertical: 10),
                    child: Row(
                      children: [
                        Expanded(
                          child: Text('Room ${o['room']}',
                              style: const TextStyle(color: HomiColors.text, fontSize: 14, fontWeight: FontWeight.w500)),
                        ),
                        Text(
                          '${o['by']}${o['started_at'] != null ? ' · ${timeOf(o['started_at'] as String?)}' : ''}',
                          style: const TextStyle(color: HomiColors.muted, fontSize: 12.5),
                        ),
                      ],
                    ),
                  ),
              ],
            ),
          ),
          const SizedBox(height: 20),
        ],
        if (tasks.isNotEmpty) ...[
          const _SectionLabel('To clean'),
          const SizedBox(height: 10),
          for (final t in tasks) ...[_taskCard(t), const SizedBox(height: 10)],
        ],
      ],
    );
  }

  Widget _taskCard(Map<String, dynamic> t) {
    final urgent = t['urgent'] == true;
    final startable = t['startable'] == true;
    final tone = t['tone'] as String?;
    final (Color, String)? status = urgent
        ? (HomiColors.bad, 'Urgent')
        : tone == 'warn'
            ? (HomiColors.warn, 'Waiting')
            : null;

    return HomiCard(
      onTap: startable ? () => _open(t['id'] as int, alreadyStarted: false) : null,
      child: Opacity(
        opacity: startable ? 1 : 0.55,
        child: Row(
          children: [
            _roomTile('${t['room']}', urgent: urgent),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      Text('Room ${t['room']}',
                          style: const TextStyle(color: HomiColors.ink, fontWeight: FontWeight.w600, fontSize: 15.5)),
                      Text('  ·  ${t['type']}', style: const TextStyle(color: HomiColors.muted, fontSize: 13.5)),
                    ],
                  ),
                  const SizedBox(height: 3),
                  Text(t['detail'] as String? ?? '',
                      style: const TextStyle(color: HomiColors.muted, fontSize: 12.5)),
                  if (status != null) ...[
                    const SizedBox(height: 6),
                    StatusText(label: status.$2, color: status.$1),
                  ],
                ],
              ),
            ),
            if (startable)
              const Icon(Icons.chevron_right, color: HomiColors.faint)
            else
              const Text('Next', style: TextStyle(color: HomiColors.faint, fontSize: 12.5, fontWeight: FontWeight.w500)),
          ],
        ),
      ),
    );
  }

  Widget _roomTile(String number, {required bool urgent}) {
    return Container(
      width: 44, height: 44,
      alignment: Alignment.center,
      decoration: BoxDecoration(
        color: urgent ? HomiColors.bad50 : HomiColors.hover,
        borderRadius: BorderRadius.circular(10),
      ),
      child: Text(number,
          style: TextStyle(
            color: urgent ? HomiColors.bad : HomiColors.text,
            fontWeight: FontWeight.w600,
            fontSize: 15,
            fontFeatures: tabular,
          )),
    );
  }
}

class _SectionLabel extends StatelessWidget {
  const _SectionLabel(this.text);
  final String text;
  @override
  Widget build(BuildContext context) => Text(
        text.toUpperCase(),
        style: const TextStyle(color: HomiColors.faint, fontSize: 11, fontWeight: FontWeight.w600, letterSpacing: 0.5),
      );
}

class _EmptyState extends StatelessWidget {
  const _EmptyState();
  @override
  Widget build(BuildContext context) => ListView(
        children: const [
          SizedBox(height: 120),
          Icon(Icons.check_circle_outline, size: 44, color: Color(0xFFB3BAC6)),
          SizedBox(height: 12),
          Center(child: Text('All clear', style: TextStyle(color: HomiColors.ink, fontWeight: FontWeight.w600, fontSize: 16))),
          SizedBox(height: 4),
          Center(child: Text('No rooms to clean right now.', style: TextStyle(color: HomiColors.muted))),
        ],
      );
}

class _ErrorState extends StatelessWidget {
  const _ErrorState({required this.message, required this.onRetry});
  final String message;
  final VoidCallback onRetry;
  @override
  Widget build(BuildContext context) => ListView(
        padding: const EdgeInsets.all(24),
        children: [
          const SizedBox(height: 100),
          Center(child: Text(message, textAlign: TextAlign.center, style: const TextStyle(color: HomiColors.muted))),
          const SizedBox(height: 16),
          Center(child: SizedBox(width: 160, child: FilledButton(onPressed: onRetry, child: const Text('Try again')))),
        ],
      );
}
