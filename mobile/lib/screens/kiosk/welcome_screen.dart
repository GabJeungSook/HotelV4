import 'package:flutter/material.dart';

import '../../api/api_client.dart';
import '../../api/kiosk_api.dart';
import '../../state/auth_store.dart';

/// Kiosk welcome (screen 27): the hotel name, the house rules and how many rooms
/// are free. The pick-room → offer → hold → held flow is the next step; the
/// KioskApi already exposes rooms(), offer(), hold() and held() for it.
class KioskWelcomeScreen extends StatefulWidget {
  const KioskWelcomeScreen({super.key, required this.auth});

  final AuthStore auth;

  @override
  State<KioskWelcomeScreen> createState() => _KioskWelcomeScreenState();
}

class _KioskWelcomeScreenState extends State<KioskWelcomeScreen> {
  late final KioskApi _api = KioskApi(ApiClient(), widget.auth.token!);
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
      final data = await _api.welcome();
      if (mounted) setState(() => _data = data);
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final rules = (_data?['rules'] as List<dynamic>? ?? const []).cast<String>();
    final free = _data?['free'];
    return Scaffold(
      appBar: AppBar(
        title: const Text('Kiosk'),
        actions: [
          IconButton(icon: const Icon(Icons.refresh), onPressed: _loading ? null : _load),
          IconButton(icon: const Icon(Icons.logout), onPressed: widget.auth.logout),
        ],
      ),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _error != null
              ? Center(child: Text(_error!))
              : ListView(
                  padding: const EdgeInsets.all(24),
                  children: [
                    Text(
                      _data?['hotel'] as String? ?? 'Welcome',
                      style: Theme.of(context).textTheme.headlineMedium,
                    ),
                    const SizedBox(height: 8),
                    if (free != null)
                      Text('$free rooms free now', style: Theme.of(context).textTheme.titleMedium),
                    const SizedBox(height: 24),
                    Text('House rules', style: Theme.of(context).textTheme.titleSmall),
                    const SizedBox(height: 8),
                    for (final rule in rules)
                      Padding(
                        padding: const EdgeInsets.symmetric(vertical: 4),
                        child: Row(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            const Text('•  '),
                            Expanded(child: Text(rule)),
                          ],
                        ),
                      ),
                    const SizedBox(height: 32),
                    FilledButton(
                      // The booking flow (pick room → hold) is the next step.
                      onPressed: free == null || (free is int && free == 0) ? null : () {},
                      child: const Padding(
                        padding: EdgeInsets.symmetric(vertical: 12),
                        child: Text('Get a room'),
                      ),
                    ),
                  ],
                ),
    );
  }
}
