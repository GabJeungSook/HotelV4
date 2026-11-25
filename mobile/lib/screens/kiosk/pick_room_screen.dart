import 'package:flutter/material.dart';

import '../../api/api_client.dart';
import '../../api/kiosk_api.dart';
import '../../state/auth_store.dart';
import '../../theme.dart';
import '../../util/money.dart';
import '../../widgets/homi.dart';
import 'offer_screen.dart';

/// Kiosk "room and hours" (screen 28): each room type with its hourly and
/// whole-day options. Picking an option goes to the offer.
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
      builder: (_) => OfferScreen(auth: widget.auth, roomTypeId: type['id'] as int, kind: kind, quantity: quantity),
    ));
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Pick a room')),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _error != null
              ? Center(child: Text(_error!, style: const TextStyle(color: HomiColors.muted)))
              : Center(
                  child: ConstrainedBox(
                    constraints: const BoxConstraints(maxWidth: 680),
                    child: ListView(
                      padding: const EdgeInsets.fromLTRB(16, 20, 16, 24),
                      children: [
                        const Text('Choose your room',
                            style: TextStyle(color: HomiColors.ink, fontSize: 30, fontWeight: FontWeight.w600, letterSpacing: -0.8)),
                        const SizedBox(height: 4),
                        const Text('Pick a type, then how long you will stay.',
                            style: TextStyle(color: HomiColors.muted, fontSize: 15)),
                        const SizedBox(height: 20),
                        for (final t in _types) ...[_typeCard(t), const SizedBox(height: 16)],
                      ],
                    ),
                  ),
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

    return HomiCard(
      padding: const EdgeInsets.all(20),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(t['name'] as String? ?? '',
                    style: const TextStyle(color: HomiColors.ink, fontWeight: FontWeight.w600, fontSize: 19)),
              ),
              if (full) const StatusText(label: 'Full', color: HomiColors.muted),
            ],
          ),
          if ((t['description'] as String?)?.isNotEmpty ?? false) ...[
            const SizedBox(height: 4),
            Text(t['description'] as String, style: const TextStyle(color: HomiColors.muted, fontSize: 13.5)),
          ],
          if (!full && t['room'] is Map) ...[
            const SizedBox(height: 6),
            Row(
              children: [
                const Icon(Icons.meeting_room_outlined, size: 15, color: HomiColors.ok),
                const SizedBox(width: 5),
                Text('${(t['room'] as Map)['label']} ready',
                    style: const TextStyle(color: HomiColors.ok, fontSize: 12.5, fontWeight: FontWeight.w500)),
              ],
            ),
          ],
          if (full) ...[
            const SizedBox(height: 14),
            const Text('No rooms free right now.', style: TextStyle(color: HomiColors.faint, fontSize: 13)),
          ] else ...[
            if (options.isNotEmpty) ...[
              const SizedBox(height: 16),
              _groupLabel('By the hour'),
              const SizedBox(height: 10),
              Wrap(
                spacing: 10, runSpacing: 10,
                children: [
                  for (final o in options)
                    _OptionTile(
                      top: '${o['hours']} hrs',
                      price: pesos(o['amount'] as int),
                      onTap: () => _pick(t, kind: 'hourly', quantity: o['hours'] as int),
                    ),
                ],
              ),
            ],
            if (dayChoices.isNotEmpty) ...[
              const SizedBox(height: 16),
              _groupLabel('Long stay'),
              const SizedBox(height: 10),
              Wrap(
                spacing: 10, runSpacing: 10,
                children: [
                  for (final d in dayChoices)
                    _OptionTile(
                      top: d == 1 ? '1 day' : '$d days',
                      price: pesos(perDay * d),
                      onTap: () => _pick(t, kind: 'long_stay', quantity: d),
                    ),
                ],
              ),
            ],
          ],
        ],
      ),
    );
  }

  Widget _groupLabel(String text) => Text(
        text.toUpperCase(),
        style: const TextStyle(color: HomiColors.faint, fontSize: 11, fontWeight: FontWeight.w600, letterSpacing: 0.5),
      );
}

/// A tappable rate tile: the stay length over its price, bordered, that fills
/// with the select tint on press.
class _OptionTile extends StatelessWidget {
  const _OptionTile({required this.top, required this.price, required this.onTap});

  final String top;
  final String price;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: HomiColors.card,
      borderRadius: BorderRadius.circular(10),
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(10),
        highlightColor: HomiColors.primary50,
        child: Container(
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(10),
            border: Border.all(color: HomiColors.lineStrong),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(top, style: const TextStyle(color: HomiColors.ink, fontWeight: FontWeight.w600, fontSize: 15)),
              const SizedBox(height: 2),
              Text(price, style: const TextStyle(color: HomiColors.muted, fontSize: 13.5, fontFeatures: tabular)),
            ],
          ),
        ),
      ),
    );
  }
}
