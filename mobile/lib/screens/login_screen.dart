import 'package:flutter/material.dart';

import '../api/api_client.dart';
import '../state/auth_store.dart';
import '../theme.dart';
import '../widgets/homi.dart';

/// One sign-in for both device roles; the returned role decides which app shows.
class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key, required this.auth});

  final AuthStore auth;

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _username = TextEditingController();
  final _password = TextEditingController();
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    _username.dispose();
    _password.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await widget.auth.login(_username.text.trim(), _password.text, 'Homi mobile');
    } on ApiException catch (e) {
      setState(() => _error = e.message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(24),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 400),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  const HomiLogo(size: 40),
                  const SizedBox(height: 10),
                  const Text('Sign in to this device',
                      style: TextStyle(color: HomiColors.muted, fontSize: 15)),
                  const SizedBox(height: 24),
                  HomiCard(
                    padding: const EdgeInsets.all(20),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        if (_error != null) ...[
                          _Alert(_error!),
                          const SizedBox(height: 16),
                        ],
                        TextField(
                          controller: _username,
                          decoration: const InputDecoration(
                            labelText: 'Username',
                            prefixIcon: Icon(Icons.person_outline, color: HomiColors.faint),
                          ),
                          autocorrect: false,
                          enableSuggestions: false,
                          textInputAction: TextInputAction.next,
                        ),
                        const SizedBox(height: 14),
                        TextField(
                          controller: _password,
                          decoration: const InputDecoration(
                            labelText: 'Password',
                            prefixIcon: Icon(Icons.lock_outline, color: HomiColors.faint),
                          ),
                          obscureText: true,
                          onSubmitted: (_) => _submit(),
                        ),
                        const SizedBox(height: 20),
                        FilledButton(
                          onPressed: _busy ? null : _submit,
                          child: _busy
                              ? const SizedBox(
                                  height: 20, width: 20,
                                  child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                              : const Text('Sign in'),
                        ),
                      ],
                    ),
                  ),
                  const SizedBox(height: 16),
                  const Text('Kiosk or housekeeping account only.',
                      textAlign: TextAlign.center,
                      style: TextStyle(color: HomiColors.faint, fontSize: 13)),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}

/// A refusal shown inline: the bad tone's soft fill and text, no pill.
class _Alert extends StatelessWidget {
  const _Alert(this.message);

  final String message;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
      decoration: BoxDecoration(
        color: HomiColors.bad50,
        borderRadius: BorderRadius.circular(8),
        border: Border.all(color: HomiColors.badLine),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Icon(Icons.error_outline, size: 18, color: HomiColors.bad),
          const SizedBox(width: 8),
          Expanded(
            child: Text(message, style: const TextStyle(color: HomiColors.bad, fontSize: 13.5)),
          ),
        ],
      ),
    );
  }
}
