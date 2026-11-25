import 'package:flutter/material.dart';

import '../../api/api_client.dart';
import '../../api/housekeeping_api.dart';
import '../../state/auth_store.dart';
import 'cleaning_screen.dart';

/// The roomboy's queue: rooms waiting to be cleaned, newest-urgent first. Tap a
/// startable room to begin; an in-progress room jumps straight to the cleaning screen.
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
      if (!alreadyStarted) {
        await _api.start(taskId);
      }
    } on ApiException catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
      await _load();
      return;
    }
    if (!mounted) return;
    await Navigator.of(context).push(MaterialPageRoute(
      builder: (_) => CleaningScreen(auth: widget.auth, taskId: taskId),
    ));
    await _load(); // refresh after finishing
  }

  @override
  Widget build(BuildContext context) {
    final tasks = (_data?['tasks'] as List<dynamic>? ?? const []).cast<Map<String, dynamic>>();
    final current = _data?['current'] as Map<String, dynamic>?;

    return Scaffold(
      appBar: AppBar(
        title: Text(_data?['user_name'] as String? ?? 'Housekeeping'),
        actions: [
          IconButton(icon: const Icon(Icons.refresh), onPressed: _loading ? null : _load),
          IconButton(icon: const Icon(Icons.logout), onPressed: widget.auth.logout),
        ],
      ),
      body: RefreshIndicator(
        onRefresh: _load,
        child: _buildBody(tasks, current),
      ),
    );
  }

  Widget _buildBody(List<Map<String, dynamic>> tasks, Map<String, dynamic>? current) {
    if (_loading && _data == null) {
      return const Center(child: CircularProgressIndicator());
    }
    if (_error != null) {
      return ListView(children: [
        const SizedBox(height: 80),
        Center(child: Text(_error!)),
        const SizedBox(height: 12),
        Center(child: FilledButton(onPressed: _load, child: const Text('Try again'))),
      ]);
    }

    return ListView(
      padding: const EdgeInsets.all(12),
      children: [
        if (current != null)
          Card(
            color: Theme.of(context).colorScheme.secondaryContainer,
            child: ListTile(
              leading: const Icon(Icons.cleaning_services),
              title: Text('Room ${current['room']} — in progress'),
              subtitle: const Text('Tap to finish'),
              onTap: () => _open(current['id'] as int, alreadyStarted: true),
            ),
          ),
        if (tasks.isEmpty && current == null)
          const Padding(
            padding: EdgeInsets.only(top: 80),
            child: Center(child: Text('Nothing to clean right now.')),
          ),
        for (final t in tasks) _taskTile(t),
      ],
    );
  }

  Widget _taskTile(Map<String, dynamic> t) {
    final urgent = t['urgent'] == true;
    final startable = t['startable'] == true;
    return Card(
      child: ListTile(
        leading: CircleAvatar(
          backgroundColor: urgent ? Theme.of(context).colorScheme.errorContainer : null,
          child: Text('${t['room']}'),
        ),
        title: Text('Room ${t['room']} · ${t['type']}'),
        subtitle: Text(t['detail'] as String? ?? ''),
        trailing: startable
            ? const Icon(Icons.chevron_right)
            : Text('next', style: Theme.of(context).textTheme.labelSmall),
        enabled: startable,
        onTap: startable ? () => _open(t['id'] as int, alreadyStarted: false) : null,
      ),
    );
  }
}
