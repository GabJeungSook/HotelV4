import 'dart:async';

import 'package:flutter/material.dart';
import 'package:qr_flutter/qr_flutter.dart';

import '../../api/api_client.dart';
import '../../api/kiosk_api.dart';
import '../../state/auth_store.dart';
import '../../theme.dart';
import '../../util/money.dart';
import '../../widgets/homi.dart';

/// Kiosk "room held" (screen 30): the code with its QR, the amount to pay at the
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
              ? Center(child: Text(_error!, style: const TextStyle(color: HomiColors.muted)))
              : Center(
                  child: ConstrainedBox(
                    constraints: const BoxConstraints(maxWidth: 420),
                    child: ListView(padding: const EdgeInsets.all(24), children: _content()),
                  ),
                ),
    );
  }

  List<Widget> _content() {
    final hold = _hold!;
    final expired = _left == Duration.zero;
    final soon = !expired && _left.inMinutes < 2;

    return [
      Center(
        child: Column(
          children: [
            const Icon(Icons.check_circle, color: HomiColors.ok, size: 40),
            const SizedBox(height: 10),
            Text(hold['room'] == null ? 'Room held' : 'Room ${hold['room']} is held for you',
                textAlign: TextAlign.center,
                style: const TextStyle(color: HomiColors.ink, fontWeight: FontWeight.w600, fontSize: 20)),
          ],
        ),
      ),
      const SizedBox(height: 20),
      HomiCard(
        padding: const EdgeInsets.all(24),
        child: Column(
          children: [
            const Text('Show this at the front desk',
                style: TextStyle(color: HomiColors.muted, fontSize: 13)),
            const SizedBox(height: 16),
            QrImageView(
              data: hold['code'] as String,
              size: 200,
              backgroundColor: Colors.white,
              eyeStyle: const QrEyeStyle(eyeShape: QrEyeShape.square, color: HomiColors.ink),
              dataModuleStyle: const QrDataModuleStyle(dataModuleShape: QrDataModuleShape.square, color: HomiColors.ink),
            ),
            const SizedBox(height: 16),
            SelectableText(
              hold['code'] as String,
              style: const TextStyle(
                fontFamily: 'GeistMono',
                color: HomiColors.ink, fontSize: 22, fontWeight: FontWeight.w600, letterSpacing: 2,
              ),
            ),
          ],
        ),
      ),
      const SizedBox(height: 20),
      Center(
        child: Column(
          children: [
            const Text('Pay at the desk', style: TextStyle(color: HomiColors.muted, fontSize: 13)),
            const SizedBox(height: 4),
            Text(pesos(hold['amount'] as int),
                style: const TextStyle(color: HomiColors.ink, fontWeight: FontWeight.w600, fontSize: 30, fontFeatures: tabular)),
          ],
        ),
      ),
      const SizedBox(height: 16),
      Center(
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(Icons.schedule, size: 16, color: expired || soon ? HomiColors.warn : HomiColors.muted),
            const SizedBox(width: 6),
            Text(
              expired ? 'This hold has expired' : 'Hold expires in $_countdown',
              style: TextStyle(
                color: expired || soon ? HomiColors.warn : HomiColors.muted,
                fontSize: 14,
                fontWeight: FontWeight.w500,
                fontFeatures: tabular,
              ),
            ),
          ],
        ),
      ),
      const SizedBox(height: 28),
      FilledButton(
        style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(56)),
        onPressed: () => Navigator.of(context).popUntil((r) => r.isFirst),
        child: const Text('Done', style: TextStyle(fontSize: 16)),
      ),
    ];
  }
}
