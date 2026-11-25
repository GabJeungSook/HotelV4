import 'package:flutter/material.dart';

import 'screens/housekeeping/roomboy_shell.dart';
import 'screens/kiosk/welcome_screen.dart';
import 'screens/login_screen.dart';
import 'state/auth_store.dart';
import 'theme.dart';

void main() {
  runApp(const HomiApp());
}

class HomiApp extends StatefulWidget {
  const HomiApp({super.key});

  @override
  State<HomiApp> createState() => _HomiAppState();
}

class _HomiAppState extends State<HomiApp> {
  final AuthStore _auth = AuthStore();

  @override
  void initState() {
    super.initState();
    _auth.restore();
  }

  @override
  void dispose() {
    _auth.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'Homi',
      debugShowCheckedModeBanner: false,
      theme: HomiTheme.light(),
      home: ListenableBuilder(
        listenable: _auth,
        builder: (context, _) {
          if (_auth.loading) {
            return const Scaffold(body: Center(child: CircularProgressIndicator()));
          }
          if (!_auth.signedIn) {
            return LoginScreen(auth: _auth);
          }
          // One app, routed by the signed-in role (temporary default — see README).
          final user = _auth.user!;
          if (user.isKiosk) {
            return KioskWelcomeScreen(auth: _auth);
          }
          return RoomboyShell(auth: _auth);
        },
      ),
    );
  }
}
