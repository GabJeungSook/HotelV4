import 'package:flutter/material.dart';

import '../../api/api_client.dart';
import '../../api/kiosk_api.dart';
import '../../state/auth_store.dart';
import '../../util/money.dart';
import 'offer_screen.dart';

/// Kiosk "room and hours" (screen 28): each room type with its hourly options.
/// Picking an option goes to the offer. Long-stay is a later addition.
class PickRoomScreen extends StatefulWidget {
  const PickRoomScreen({super.key, required this.auth});

  final AuthStore auth;

  @override
  State<PickRoomScreen> createState() => _PickRoomScreenState();
}

class _PickRoomScreenState extends State<PickRoomScreen> {
  late final KioskApi _api = KioskApi(ApiClient(), widget.auth.token!);
  List<Map<String, dynamic>> _types = const [];
  bool _loading = true;
  String? _error;

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
      final data = await _api.rooms();
      final types = (data['types'] as List<dynamic>? ?? const []).cast<Map<String, dynamic>>();
      if (mounted) setState(() => _types = types);
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  void _pick(Map<String, dynamic> type, {required String kind, required int quantity}) {
    Navigator.of(context).push(MaterialPageRoute(
      builder: (_) => OfferScreen(
        auth: widget.auth,
        roomTypeId: type['id'] as int,
        kind: kind,
        quantity: quantity,
      ),
    ));
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Pick a room')),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _error != null
              ? Center(child: Text(_error!))
              : ListView(
                  padding: const EdgeInsets.all(16),
                  children: [for (final t in _types) _typeCard(t)],
                ),
    );
  }

  Widget _typeCard(Map<String, dynamic> t) {
    final full = t['full'] == true;
    final options = (t['options'] as List<dynamic>? ?? const []).cast<Map<String, dynamic>>();
    final longStay = t['long_stay'] as Map<String, dynamic>?;
    final maxDays = longStay?['max_days'] as int? ?? 0;
    final perDay = longStay?['per_day'] as int? ?? 0;
    final dayChoices = [1, 2, 3, 5, 7, 14, 30].where((d) => d <= maxDays).toList();
    return Card(
      margin: const EdgeInsets.only(bottom: 16),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(child: Text(t['name'] as String? ?? '', style: Theme.of(context).textTheme.titleLarge)),
                if (full)
                  Chip(
                    label: const Text('Full'),
                    backgroundColor: Theme.of(context).colorScheme.errorContainer,
                  ),
              ],
            ),
            if ((t['description'] as String?)?.isNotEmpty ?? false) ...[
              const SizedBox(height: 4),
              Text(t['description'] as String, style: Theme.of(context).textTheme.bodyMedium),
            ],
            const SizedBox(height: 12),
            if (full)
              Text('No rooms free right now.', style: Theme.of(context).textTheme.bodySmall)
            else ...[
              if (options.isNotEmpty)
                Wrap(
                  spacing: 8,
                  runSpacing: 8,
                  children: [
                    for (final o in options)
                      ActionChip(
                        label: Text('${o['hours']}h · ${pesos(o['amount'] as int)}'),
                        onPressed: () => _pick(t, kind: 'hourly', quantity: o['hours'] as int),
                      ),
                  ],
                ),
              if (dayChoices.isNotEmpty) ...[
                const SizedBox(height: 12),
                Text('Long stay', style: Theme.of(context).textTheme.labelLarge),
                const SizedBox(height: 8),
                Wrap(
                  spacing: 8,
                  runSpacing: 8,
                  children: [
                    for (final d in dayChoices)
                      ActionChip(
                        label: Text('${d}d · ${pesos(perDay * d)}'),
                        onPressed: () => _pick(t, kind: 'long_stay', quantity: d),
                      ),
                  ],
                ),
              ],
            ],
          ],
        ),
      ),
    );
  }
}
