import 'dart:async';

import 'package:flutter/material.dart';
import 'package:qr_flutter/qr_flutter.dart';

import '../../api/api_client.dart';
import '../../api/kiosk_api.dart';
import '../../state/auth_store.dart';
import '../../util/money.dart';

/// Kiosk "room held" (screen 30): the code and its QR, the amount to pay at the
/// desk, and a countdown until the hold expires.
class HeldScreen extends StatefulWidget {
  const HeldScreen({super.key, required this.auth, required this.code});

  final AuthStore auth;
  final String code;

  @override
  State<HeldScreen> createState() => _HeldScreenState();
}

class _HeldScreenState extends State<HeldScreen> {
  late final KioskApi _api = KioskApi(ApiClient(), widget.auth.token!);
  Map<String, dynamic>? _hold;
  bool _loading = true;
  String? _error;
  Timer? _ticker;
  Duration _left = Duration.zero;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _ticker?.cancel();
    super.dispose();
  }

  Future<void> _load() async {
    try {
      final data = await _api.held(widget.code);
      final hold = data['hold'] as Map<String, dynamic>;
      if (!mounted) return;
      setState(() => _hold = hold);
      _startTicker(hold['expires_at'] as String?);
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  void _startTicker(String? expiresAt) {
    final expiry = expiresAt == null ? null : DateTime.tryParse(expiresAt);
    if (expiry == null) return;
    void tick() {
      final left = expiry.difference(DateTime.now());
      setState(() => _left = left.isNegative ? Duration.zero : left);
    }

    tick();
    _ticker = Timer.periodic(const Duration(seconds: 1), (_) => tick());
  }

  String get _countdown {
    final m = _left.inMinutes.toString().padLeft(2, '0');
    final s = (_left.inSeconds % 60).toString().padLeft(2, '0');
    return '$m:$s';
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Room held'), automaticallyImplyLeading: false),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _error != null
              ? Center(child: Text(_error!))
              : _content(),
    );
  }

  Widget _content() {
    final hold = _hold!;
    final expired = _left == Duration.zero;
    return ListView(
      padding: const EdgeInsets.all(24),
      children: [
        Center(
          child: Text(
            hold['room'] == null ? 'Your code' : 'Room ${hold['room']}',
            style: Theme.of(context).textTheme.titleLarge,
          ),
        ),
        const SizedBox(height: 16),
        Center(
          child: Container(
            padding: const EdgeInsets.all(16),
            decoration: BoxDecoration(
              color: Colors.white,
              borderRadius: BorderRadius.circular(12),
            ),
            child: QrImageView(data: hold['code'] as String, size: 200),
          ),
        ),
        const SizedBox(height: 16),
        Center(
          child: SelectableText(
            hold['code'] as String,
            style: Theme.of(context).textTheme.headlineSmall?.copyWith(letterSpacing: 2),
          ),
        ),
        const SizedBox(height: 24),
        Center(child: Text('Pay at the desk', style: Theme.of(context).textTheme.bodyMedium)),
        Center(child: Text(pesos(hold['amount'] as int), style: Theme.of(context).textTheme.headlineMedium)),
        const SizedBox(height: 24),
        Center(
          child: Text(
            expired ? 'This hold has expired.' : 'Hold expires in $_countdown',
            style: Theme.of(context).textTheme.titleMedium?.copyWith(
                  color: expired ? Theme.of(context).colorScheme.error : null,
                ),
          ),
        ),
        const SizedBox(height: 32),
        FilledButton(
          onPressed: () => Navigator.of(context).popUntil((r) => r.isFirst),
          child: const Padding(
            padding: EdgeInsets.symmetric(vertical: 12),
            child: Text('Done'),
          ),
        ),
      ],
    );
  }
}
