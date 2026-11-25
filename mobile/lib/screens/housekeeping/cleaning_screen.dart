import 'package:flutter/material.dart';

import '../../api/api_client.dart';
import '../../api/housekeeping_api.dart';
import '../../state/auth_store.dart';
import '../../theme.dart';
import '../../widgets/homi.dart';

/// The cleaning screen for one room: mark it ready, or report a problem with a
/// note. The violet top edge marks a cleaning in progress (DESIGN_SYSTEM §0).
class CleaningScreen extends StatefulWidget {
  const CleaningScreen({super.key, required this.auth, required this.taskId});

  final AuthStore auth;
  final int taskId;

  @override
  State<CleaningScreen> createState() => _CleaningScreenState();
}

class _CleaningScreenState extends State<CleaningScreen> {
  late final HousekeepingApi _api = HousekeepingApi(ApiClient(), widget.auth.token!);
  Map<String, dynamic>? _task;
  bool _loading = true;
  bool _busy = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final data = await _api.task(widget.taskId);
      if (mounted) setState(() => _task = data['task'] as Map<String, dynamic>);
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
        content: TextField(
          controller: controller,
          autofocus: true,
          maxLength: 255,
          maxLines: 3,
          decoration: const InputDecoration(hintText: 'What is the problem in this room?'),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context), child: const Text('Cancel')),
          FilledButton(
            style: FilledButton.styleFrom(minimumSize: const Size(96, 44)),
            onPressed: () => Navigator.pop(context, controller.text.trim()),
            child: const Text('Send'),
          ),
        ],
      ),
    );
    if (note != null && note.isNotEmpty) await _finish(ready: false, note: note);
  }

  @override
  Widget build(BuildContext context) {
    final room = _task?['room'];
    return Scaffold(
      appBar: AppBar(
        title: Text(room == null ? 'Cleaning' : 'Room $room'),
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
    return Padding(
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          HomiCard(
            padding: const EdgeInsets.all(20),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Container(
                      width: 44, height: 44,
                      alignment: Alignment.center,
                      decoration: BoxDecoration(color: HomiColors.violet50, borderRadius: BorderRadius.circular(10)),
                      child: const Icon(Icons.cleaning_services_outlined, color: HomiColors.violet, size: 22),
                    ),
                    const SizedBox(width: 14),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text('Room ${_task?['room']}',
                              style: const TextStyle(color: HomiColors.ink, fontWeight: FontWeight.w600, fontSize: 20)),
                          Text('${_task?['type'] ?? ''}', style: const TextStyle(color: HomiColors.muted, fontSize: 14)),
                        ],
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 16),
                const Text('Make up the room — strip and remake the bed, clean the bathroom, restock, and check the TV and aircon. Then mark it ready for the next guest.',
                    style: TextStyle(color: HomiColors.text, fontSize: 14, height: 1.45)),
              ],
            ),
          ),
          const Spacer(),
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
          const SizedBox(height: 8),
        ],
      ),
    );
  }
}
