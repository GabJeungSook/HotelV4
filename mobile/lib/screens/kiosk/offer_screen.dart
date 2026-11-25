import 'package:flutter/material.dart';
import 'package:uuid/uuid.dart';

import '../../api/api_client.dart';
import '../../api/kiosk_api.dart';
import '../../state/auth_store.dart';
import '../../theme.dart';
import '../../util/money.dart';
import '../../widgets/homi.dart';
import 'held_screen.dart';

/// Kiosk "your name" + the offer (screen 29): the price for the chosen type and
/// stay, the guest's name and optional mobile, then Hold my room.
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
      Navigator.of(context).pushReplacement(MaterialPageRoute(
        builder: (_) => HeldScreen(auth: widget.auth, code: res['code'] as String),
      ));
    } on ApiException catch (e) {
      if (mounted) setState(() { _holding = false; _error = e.message; });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Your stay')),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _offer == null
              ? Center(child: Text(_error ?? 'That option is not available.', style: const TextStyle(color: HomiColors.muted)))
              : Center(
                  child: ConstrainedBox(
                    constraints: const BoxConstraints(maxWidth: 520),
                    child: ListView(padding: const EdgeInsets.all(20), children: _form()),
                  ),
                ),
    );
  }

  List<Widget> _form() {
    final offer = _offer!;
    final discount = offer['discount'] as Map<String, dynamic>?;
    final deposit = offer['deposit'] as Map<String, dynamic>;
    return [
      Text('${offer['type']['name']} · ${offer['stay_label']}',
          style: const TextStyle(color: HomiColors.ink, fontWeight: FontWeight.w600, fontSize: 22)),
      if (offer['room'] is Map)
        Padding(
          padding: const EdgeInsets.only(top: 4),
          child: Text((offer['room'] as Map)['label'] as String? ?? '',
              style: const TextStyle(color: HomiColors.muted, fontSize: 14)),
        ),
      const SizedBox(height: 20),
      if (discount != null) ...[
        Container(
          padding: const EdgeInsets.all(16),
          decoration: BoxDecoration(
            color: HomiColors.accent50,
            borderRadius: BorderRadius.circular(12),
            border: Border.all(color: const Color(0xFFF2D7A8)),
          ),
          child: Row(
            children: [
              const Icon(Icons.local_offer_outlined, size: 20, color: HomiColors.accent),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(discount['name'] as String,
                        style: const TextStyle(color: HomiColors.accent, fontWeight: FontWeight.w600, fontSize: 14.5)),
                    if ((discount['text'] as String?)?.isNotEmpty ?? false)
                      Text(discount['text'] as String, style: const TextStyle(color: HomiColors.muted, fontSize: 12.5)),
                  ],
                ),
              ),
              const StatusText(label: 'Applied', color: HomiColors.ok),
            ],
          ),
        ),
        const SizedBox(height: 16),
      ],
      HomiCard(
        padding: const EdgeInsets.all(20),
        child: Column(
          children: [
            _row('Room', pesos(offer['room_amount'] as int)),
            if (discount != null) ...[
              const SizedBox(height: 10),
              _row(discount['name'] as String, '−${pesos(discount['amount'] as int)}', amountColor: HomiColors.ok),
            ],
            const SizedBox(height: 10),
            _row(deposit['name'] as String? ?? 'Deposit', pesos(deposit['amount'] as int)),
            const Padding(padding: EdgeInsets.symmetric(vertical: 16), child: Divider(height: 1, color: HomiColors.line)),
            _row('To pay at the desk', pesos(offer['total'] as int), bold: true),
          ],
        ),
      ),
      const SizedBox(height: 24),
      if (_error != null) ...[
        Text(_error!, style: const TextStyle(color: HomiColors.bad, fontSize: 13.5)),
        const SizedBox(height: 12),
      ],
      TextField(
        controller: _name,
        decoration: const InputDecoration(labelText: 'Your name', prefixIcon: Icon(Icons.person_outline, color: HomiColors.faint)),
        textCapitalization: TextCapitalization.words,
      ),
      const SizedBox(height: 14),
      TextField(
        controller: _mobile,
        decoration: const InputDecoration(
          labelText: 'Mobile (optional)',
          hintText: '0917 555 0142',
          prefixIcon: Icon(Icons.phone_outlined, color: HomiColors.faint),
        ),
        keyboardType: TextInputType.phone,
      ),
      const SizedBox(height: 24),
      FilledButton(
        style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(56)),
        onPressed: _holding ? null : _hold,
        child: _holding
            ? const SizedBox(height: 20, width: 20, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
            : const Text('Hold my room', style: TextStyle(fontSize: 16)),
      ),
    ];
  }

  Widget _row(String label, String amount, {bool bold = false, Color? amountColor}) {
    final labelStyle = TextStyle(
      color: bold ? HomiColors.ink : HomiColors.text,
      fontSize: bold ? 16 : 14.5,
      fontWeight: bold ? FontWeight.w600 : FontWeight.w400,
    );
    final amountStyle = TextStyle(
      color: amountColor ?? (bold ? HomiColors.ink : HomiColors.text),
      fontSize: bold ? 20 : 14.5,
      fontWeight: FontWeight.w600,
      fontFeatures: tabular,
    );
    return Row(
      mainAxisAlignment: MainAxisAlignment.spaceBetween,
      children: [Text(label, style: labelStyle), Text(amount, style: amountStyle)],
    );
  }
}
