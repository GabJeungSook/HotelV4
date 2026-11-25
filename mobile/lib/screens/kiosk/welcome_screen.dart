import 'package:flutter/material.dart';

import '../../api/api_client.dart';
import '../../api/kiosk_api.dart';
import '../../state/auth_store.dart';
import '../../theme.dart';
import '../../widgets/homi.dart';
import 'pick_room_screen.dart';

/// Kiosk welcome (screen 27): the hotel, how many rooms are free and the house
/// rules, then into the booking flow. Built for a lobby tablet — large, calm,
/// one clear action.
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
    final hasRooms = free is int && free > 0;

    return Scaffold(
      body: SafeArea(
        child: Column(
          children: [
            Padding(
              padding: const EdgeInsets.fromLTRB(24, 16, 12, 0),
              child: Row(
                children: [
                  const HomiLogo(size: 26),
                  const Spacer(),
                  IconButton(
                    icon: const Icon(Icons.logout, color: HomiColors.faint),
                    tooltip: 'Sign out device',
                    onPressed: widget.auth.logout,
                  ),
                ],
              ),
            ),
            Expanded(
              child: _loading
                  ? const Center(child: CircularProgressIndicator())
                  : _error != null
                      ? Center(child: Text(_error!, style: const TextStyle(color: HomiColors.muted)))
                      : Center(
                          child: SingleChildScrollView(
                            padding: const EdgeInsets.all(24),
                            child: ConstrainedBox(
                              constraints: const BoxConstraints(maxWidth: 560),
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  const Text('Welcome to',
                                      style: TextStyle(color: HomiColors.muted, fontSize: 17)),
                                  const SizedBox(height: 4),
                                  Text(
                                    _data?['hotel'] as String? ?? 'our hotel',
                                    style: const TextStyle(
                                      color: HomiColors.ink,
                                      fontSize: 40,
                                      height: 1.05,
                                      fontWeight: FontWeight.w600,
                                      letterSpacing: -1.2,
                                    ),
                                  ),
                                  const SizedBox(height: 14),
                                  StatusText(
                                    label: hasRooms ? '$free rooms free now' : 'No rooms free right now',
                                    color: hasRooms ? HomiColors.ok : HomiColors.muted,
                                  ),
                                  const SizedBox(height: 28),
                                  HomiCard(
                                    padding: const EdgeInsets.all(20),
                                    child: Column(
                                      crossAxisAlignment: CrossAxisAlignment.start,
                                      children: [
                                        const Text('House rules',
                                            style: TextStyle(color: HomiColors.ink, fontWeight: FontWeight.w600, fontSize: 15)),
                                        const SizedBox(height: 12),
                                        for (final rule in rules) _rule(rule),
                                      ],
                                    ),
                                  ),
                                  const SizedBox(height: 28),
                                  FilledButton(
                                    style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(60)),
                                    onPressed: hasRooms
                                        ? () => Navigator.of(context).push(MaterialPageRoute(
                                              builder: (_) => PickRoomScreen(auth: widget.auth),
                                            ))
                                        : null,
                                    child: const Text('Get a room', style: TextStyle(fontSize: 17)),
                                  ),
                                ],
                              ),
                            ),
                          ),
                        ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _rule(String text) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 5),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Padding(
              padding: EdgeInsets.only(top: 2),
              child: Icon(Icons.check, size: 17, color: HomiColors.ok),
            ),
            const SizedBox(width: 10),
            Expanded(child: Text(text, style: const TextStyle(color: HomiColors.text, fontSize: 14.5, height: 1.4))),
          ],
        ),
      );
}
