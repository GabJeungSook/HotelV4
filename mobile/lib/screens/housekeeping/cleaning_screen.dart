import 'package:flutter/material.dart';

import '../../api/api_client.dart';
import '../../api/housekeeping_api.dart';
import '../../state/auth_store.dart';

/// The cleaning screen for one room: mark it ready, or report a problem with a note.
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
      if (mounted) setState(() => _task = data);
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
        title: const Text('Report a problem'),
        content: TextField(
          controller: controller,
          autofocus: true,
          maxLength: 255,
          decoration: const InputDecoration(hintText: 'What is the problem?'),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context), child: const Text('Cancel')),
          FilledButton(
            onPressed: () => Navigator.pop(context, controller.text.trim()),
            child: const Text('Send'),
          ),
        ],
      ),
    );
    if (note != null && note.isNotEmpty) {
      await _finish(ready: false, note: note);
    }
  }

  @override
  Widget build(BuildContext context) {
    final room = _task?['room'];
    return Scaffold(
      appBar: AppBar(title: Text(room == null ? 'Cleaning' : 'Room $room')),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _error != null
              ? Center(child: Text(_error!))
              : Padding(
                  padding: const EdgeInsets.all(20),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      Text('Room ${_task?['room']} · ${_task?['type'] ?? ''}',
                          style: Theme.of(context).textTheme.titleLarge),
                      const SizedBox(height: 8),
                      Text(_task?['detail'] as String? ?? '', style: Theme.of(context).textTheme.bodyMedium),
                      const Spacer(),
                      FilledButton.icon(
                        onPressed: _busy ? null : () => _finish(ready: true),
                        icon: const Icon(Icons.check_circle),
                        label: const Padding(
                          padding: EdgeInsets.symmetric(vertical: 12),
                          child: Text('Room is ready'),
                        ),
                      ),
                      const SizedBox(height: 12),
                      OutlinedButton.icon(
                        onPressed: _busy ? null : _reportProblem,
                        icon: const Icon(Icons.report_problem_outlined),
                        label: const Padding(
                          padding: EdgeInsets.symmetric(vertical: 12),
                          child: Text('Report a problem'),
                        ),
                      ),
                    ],
                  ),
                ),
    );
  }
}
