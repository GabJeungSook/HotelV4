import 'package:flutter/material.dart';

import '../../state/auth_store.dart';
import '../../theme.dart';
import 'history_screen.dart';
import 'queue_screen.dart';

/// The roomboy's two tabs — the queue and "My cleans" — matching the web
/// housekeeping shell (PhoneLayout).
class RoomboyShell extends StatefulWidget {
  const RoomboyShell({super.key, required this.auth});

  final AuthStore auth;

  @override
  State<RoomboyShell> createState() => _RoomboyShellState();
}

class _RoomboyShellState extends State<RoomboyShell> {
  int _index = 0;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: IndexedStack(
        index: _index,
        children: [
          HousekeepingQueueScreen(auth: widget.auth),
          HistoryScreen(auth: widget.auth),
        ],
      ),
      bottomNavigationBar: DecoratedBox(
        decoration: const BoxDecoration(border: Border(top: BorderSide(color: HomiColors.line))),
        child: NavigationBar(
          selectedIndex: _index,
          onDestinationSelected: (i) => setState(() => _index = i),
          destinations: const [
            NavigationDestination(
              icon: Icon(Icons.checklist_rtl_outlined),
              selectedIcon: Icon(Icons.checklist_rtl),
              label: 'Queue',
            ),
            NavigationDestination(
              icon: Icon(Icons.history_outlined),
              selectedIcon: Icon(Icons.history),
              label: 'My cleans',
            ),
          ],
        ),
      ),
    );
  }
}
