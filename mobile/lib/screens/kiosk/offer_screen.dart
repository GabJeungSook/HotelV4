import 'package:flutter/material.dart';
import 'package:uuid/uuid.dart';

import '../../api/api_client.dart';
import '../../api/kiosk_api.dart';
import '../../state/auth_store.dart';
import '../../util/money.dart';
import 'held_screen.dart';

/// Kiosk "your name" + the offer (screen 29): the price breakdown for the chosen
/// type and hours, the guest's name and optional mobile, then Hold my room.
class OfferScreen extends StatefulWidget {
  const OfferScreen({
    super.key,
    required this.auth,
    required this.roomTypeId,
    required this.kind,
    required this.quantity,
  });

  final AuthStore auth;
  final int roomTypeId;
  final String kind;
  final int quantity;

  @override
  State<OfferScreen> createState() => _OfferScreenState();
}

class _OfferScreenState extends State<OfferScreen> {
  late final KioskApi _api = KioskApi(ApiClient(), widget.auth.token!);
  final _name = TextEditingController();
  final _mobile = TextEditingController();
  // One key per visit to this screen, so a double-tap on Hold cannot double-book.
  final String _idempotencyKey = const Uuid().v4();

  Map<String, dynamic>? _offer;
  bool _loading = true;
  bool _holding = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _name.dispose();
    _mobile.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    try {
      final data = await _api.offer(roomType: widget.roomTypeId, kind: widget.kind, quantity: widget.quantity);
      if (mounted) setState(() => _offer = data['offer'] as Map<String, dynamic>);
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _hold() async {
    if (_name.text.trim().length < 3) {
      setState(() => _error = 'Type your name, at least 3 letters.');
      return;
    }
    setState(() {
      _holding = true;
      _error = null;
    });
    try {
      final res = await _api.hold(
        roomTypeId: widget.roomTypeId,
        kind: widget.kind,
        quantity: widget.quantity,
        guestName: _name.text.trim(),
        guestMobile: _mobile.text.trim(),
        idempotencyKey: _idempotencyKey,
      );
      if (!mounted) return;
      final code = res['code'] as String;
      Navigator.of(context).pushReplacement(MaterialPageRoute(
        builder: (_) => HeldScreen(auth: widget.auth, code: code),
      ));
    } on ApiException catch (e) {
      if (mounted) {
        setState(() {
          _holding = false;
          _error = e.message; // e.g. "room taken" (409) or a validation message
        });
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Your stay')),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _offer == null
              ? Center(child: Text(_error ?? 'That option is not available.'))
              : _form(),
    );
  }

  Widget _form() {
    final offer = _offer!;
    final discount = offer['discount'] as Map<String, dynamic>?;
    final deposit = offer['deposit'] as Map<String, dynamic>;
    return ListView(
      padding: const EdgeInsets.all(20),
      children: [
        Text('${offer['type']['name']} · ${offer['stay_label']}', style: Theme.of(context).textTheme.titleLarge),
        if (offer['room'] is Map)
          Text((offer['room'] as Map)['label'] as String? ?? '', style: Theme.of(context).textTheme.bodyMedium),
        const SizedBox(height: 16),
        _row('Room', offer['room_amount'] as int),
        if (discount != null) _row(discount['name'] as String, -(discount['amount'] as int)),
        _row(deposit['name'] as String? ?? 'Deposit', deposit['amount'] as int),
        const Divider(height: 24),
        _row('To pay at the desk', offer['total'] as int, bold: true),
        const SizedBox(height: 24),
        if (_error != null) ...[
          Text(_error!, style: TextStyle(color: Theme.of(context).colorScheme.error)),
          const SizedBox(height: 12),
        ],
        TextField(
          controller: _name,
          decoration: const InputDecoration(labelText: 'Your name', border: OutlineInputBorder()),
          textCapitalization: TextCapitalization.words,
        ),
        const SizedBox(height: 12),
        TextField(
          controller: _mobile,
          decoration: const InputDecoration(labelText: 'Mobile (optional)', border: OutlineInputBorder(), hintText: '09xx xxx xxxx'),
          keyboardType: TextInputType.phone,
        ),
        const SizedBox(height: 24),
        FilledButton(
          onPressed: _holding ? null : _hold,
          child: Padding(
            padding: const EdgeInsets.symmetric(vertical: 12),
            child: _holding
                ? const SizedBox(height: 20, width: 20, child: CircularProgressIndicator(strokeWidth: 2))
                : const Text('Hold my room'),
          ),
        ),
      ],
    );
  }

  Widget _row(String label, int amount, {bool bold = false}) {
    final style = bold ? Theme.of(context).textTheme.titleMedium : Theme.of(context).textTheme.bodyLarge;
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [Text(label, style: style), Text(pesos(amount), style: style)],
      ),
    );
  }
}
