import 'dart:async';

import 'package:flutter/material.dart';

import '../../api/api_client.dart';
import '../../api/housekeeping_api.dart';
import '../../state/auth_store.dart';
import '../../theme.dart';
import '../../util/fmt.dart';
import '../../widgets/homi.dart';

/// Cleaning in progress (web screen 33): a timer against the target, the
/// checklist, and the two outcomes — ready, or a problem with a note. The violet
/// top edge marks a cleaning in progress (DESIGN_SYSTEM §0).
class CleaningScreen extends StatefulWidget {
  const CleaningScreen({super.key, required this.auth, required this.taskId});

  final AuthStore auth;
  final int taskId;

  @override
  State<CleaningScreen> createState() => _CleaningScreenState();
}

class _CleaningScreenState extends State<CleaningScreen> {
  late final HousekeepingApi _api = HousekeepingApi(ApiClient(), widget.auth.token!);
  Map<String, dynamic>? _data;
  final Set<int> _ticked = {};
  bool _loading = true;
  bool _busy = false;
  String? _error;
  Timer? _ticker;

  Map<String, dynamic>? get _task => _data?['task'] as Map<String, dynamic>?;

  @override
  void initState() {
    super.initState();
    _ticker = Timer.periodic(const Duration(seconds: 20), (_) => setState(() {}));
    _load();
  }

  @override
  void dispose() {
    _ticker?.cancel();
    super.dispose();
  }

  Future<void> _load() async {
    try {
      final data = await _api.task(widget.taskId);
      if (mounted) setState(() => _data = data);
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _finish({required bool ready, String? note}) async {
    setState(() => _busy = true);
    try {
      final res = await _api.finish(widget.taskId, ready: ready, problemNote: note);
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(res['message'] as String? ?? 'Saved.')));
      Navigator.of(context).pop();
    } on ApiException catch (e) {
      if (mounted) {
        setState(() => _busy = false);
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
      }
    }
  }

  Future<void> _reportProblem() async {
    final controller = TextEditingController();
    final note = await showDialog<String>(
      context: context,
      builder: (context) => AlertDialog(
        backgroundColor: HomiColors.card,
        title: const Text('Report a problem', style: TextStyle(color: HomiColors.ink, fontWeight: FontWeight.w600)),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('Room ${_task?['room']} goes to Maintenance instead of Available. An admin returns it when it is fixed.',
                style: const TextStyle(color: HomiColors.muted, fontSize: 13)),
            const SizedBox(height: 12),
            TextField(controller: controller, autofocus: true, maxLength: 255, maxLines: 3,
                decoration: const InputDecoration(hintText: 'What is wrong?')),
          ],
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context), child: const Text('Cancel')),
          FilledButton(
            style: FilledButton.styleFrom(minimumSize: const Size(96, 44)),
            onPressed: () => Navigator.pop(context, controller.text.trim()),
            child: const Text('Send to maintenance'),
          ),
        ],
      ),
    );
    if (note != null && note.isNotEmpty) await _finish(ready: false, note: note);
  }

  @override
  Widget build(BuildContext context) {
    final room = _task?['room'];
    final startedAt = _task?['started_at'] as String?;
    return Scaffold(
      appBar: AppBar(
        toolbarHeight: startedAt == null ? kToolbarHeight : 60,
        title: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(room == null ? 'Cleaning' : 'Room $room', style: const TextStyle(color: HomiColors.ink, fontWeight: FontWeight.w600, fontSize: 16)),
            if (startedAt != null)
              Text('Started ${timeOf(startedAt)}', style: const TextStyle(color: HomiColors.muted, fontSize: 12.5)),
          ],
        ),
        bottom: const PreferredSize(
          preferredSize: Size.fromHeight(3),
          child: ColoredBox(color: HomiColors.violet, child: SizedBox(height: 3, width: double.infinity)),
        ),
      ),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _error != null
              ? Center(child: Text(_error!, style: const TextStyle(color: HomiColors.muted)))
              : _content(),
    );
  }

  Widget _content() {
    final checklist = (_data?['checklist'] as List<dynamic>? ?? const []).cast<String>();
    final target = _data?['target_minutes'] as int?;
    final elapsed = minutesSince(_task?['started_at'] as String?);
    final over = target != null && elapsed > target;

    return Column(
      children: [
        Expanded(
          child: ListView(
            padding: const EdgeInsets.all(16),
            children: [
              HomiCard(
                padding: const EdgeInsets.all(18),
                child: Row(
                  children: [
                    Container(
                      width: 44, height: 44, alignment: Alignment.center,
                      decoration: BoxDecoration(color: HomiColors.violet50, borderRadius: BorderRadius.circular(10)),
                      child: const Icon(Icons.cleaning_services_outlined, color: HomiColors.violet, size: 22),
                    ),
                    const SizedBox(width: 14),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text('Room ${_task?['room']}',
                              style: const TextStyle(color: HomiColors.ink, fontWeight: FontWeight.w600, fontSize: 19)),
                          Text('${_task?['type'] ?? ''}', style: const TextStyle(color: HomiColors.muted, fontSize: 13.5)),
                        ],
                      ),
                    ),
                    if (_task?['started_at'] != null)
                      Column(
                        crossAxisAlignment: CrossAxisAlignment.end,
                        children: [
                          Text('$elapsed min',
                              style: TextStyle(color: over ? HomiColors.warn : HomiColors.ink, fontWeight: FontWeight.w600, fontSize: 18, fontFeatures: tabular)),
                          if (target != null)
                            Text('target $target', style: TextStyle(color: over ? HomiColors.warn : HomiColors.muted, fontSize: 11.5)),
                        ],
                      ),
                  ],
                ),
              ),
              if (checklist.isNotEmpty) ...[
                const SizedBox(height: 16),
                HomiCard(
                  padding: const EdgeInsets.fromLTRB(8, 6, 8, 6),
                  child: Column(
                    children: [
                      for (var i = 0; i < checklist.length; i++)
                        CheckboxListTile(
                          value: _ticked.contains(i),
                          onChanged: (v) => setState(() => v == true ? _ticked.add(i) : _ticked.remove(i)),
                          title: Text(checklist[i], style: const TextStyle(color: HomiColors.text, fontSize: 14.5)),
                          activeColor: HomiColors.primary,
                          controlAffinity: ListTileControlAffinity.leading,
                          contentPadding: const EdgeInsets.symmetric(horizontal: 8),
                          dense: false,
                        ),
                    ],
                  ),
                ),
              ],
            ],
          ),
        ),
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 8, 16, 16),
          child: Column(
            children: [
              FilledButton.icon(
                onPressed: _busy ? null : () => _finish(ready: true),
                icon: const Icon(Icons.check_circle_outline, size: 20),
                label: const Text('Room is ready'),
              ),
              const SizedBox(height: 12),
              OutlinedButton.icon(
                onPressed: _busy ? null : _reportProblem,
                icon: const Icon(Icons.report_problem_outlined, size: 20, color: HomiColors.warn),
                label: const Text('Report a problem'),
                style: OutlinedButton.styleFrom(foregroundColor: HomiColors.warn, side: const BorderSide(color: HomiColors.warnLine)),
              ),
            ],
          ),
        ),
      ],
    );
  }
}
