import 'package:flutter/material.dart';

/// Homi's design tokens, ported from docs/DESIGN_SYSTEM.md §2 so the native apps
/// read as the same product as the web app. Cool greys around the Homi blue,
/// white cards with a 1px line and a hairline shadow, one primary blue for the
/// single main action, colour only where it means status.
class HomiColors {
  const HomiColors._();

  // Surfaces & text
  static const ink = Color(0xFF0F1728);
  static const text = Color(0xFF344054);
  static const muted = Color(0xFF667085);
  static const faint = Color(0xFF98A2B3);
  static const line = Color(0xFFE4E7EC);
  static const lineStrong = Color(0xFFD0D5DD);
  static const row = Color(0xFFEEF0F3);
  static const bg = Color(0xFFF7F8FA);
  static const card = Color(0xFFFFFFFF);
  static const hover = Color(0xFFF2F4F7);

  // Brand & selection
  static const brand = Color(0xFF009EF5); // logo only — never a button or background
  static const primary = Color(0xFF0077C2); // the one solid action, links, focus, selection
  static const primaryInk = Color(0xFF005F9E);
  static const primary50 = Color(0xFFEAF4FB);
  static const selectLine = Color(0xFF6FB5E3);
  static const accent = Color(0xFFB7791F);
  static const accent50 = Color(0xFFFBF1DC);

  // Status (text · soft fill · edge)
  static const ok = Color(0xFF067647);
  static const ok50 = Color(0xFFECFDF3);
  static const okLine = Color(0xFFABEFC6);
  static const warn = Color(0xFFB54708);
  static const warn50 = Color(0xFFFFFAEB);
  static const warnLine = Color(0xFFFEDF89);
  static const bad = Color(0xFFB42318);
  static const bad50 = Color(0xFFFEF3F2);
  static const badLine = Color(0xFFFECDCA);
  static const violet = Color(0xFF5925DC);
  static const violet50 = Color(0xFFF4F3FF);
  static const violetLine = Color(0xFFD9D6FE);

  static const shadow = Color(0x0D101828); // 0 1px 2px rgba(16,24,40,.05)
}

/// Numbers (money, counts, codes, timers): Geist with tabular figures.
const tabular = [FontFeature.tabularFigures()];

class HomiTheme {
  const HomiTheme._();

  static ThemeData light() {
    final base = ThemeData(useMaterial3: true, brightness: Brightness.light, fontFamily: 'Geist');
    final textTheme = base.textTheme.apply(bodyColor: HomiColors.text, displayColor: HomiColors.ink);

    const radius = 12.0;

    return base.copyWith(
      scaffoldBackgroundColor: HomiColors.bg,
      colorScheme: base.colorScheme.copyWith(
        primary: HomiColors.primary,
        onPrimary: Colors.white,
        surface: HomiColors.card,
        error: HomiColors.bad,
      ),
      textTheme: textTheme,
      canvasColor: HomiColors.card,
      dividerColor: HomiColors.line,
      textSelectionTheme: const TextSelectionThemeData(
        cursorColor: HomiColors.primary,
        selectionColor: HomiColors.primary50,
        selectionHandleColor: HomiColors.primary,
      ),
      appBarTheme: AppBarTheme(
        backgroundColor: HomiColors.card,
        surfaceTintColor: Colors.transparent,
        foregroundColor: HomiColors.ink,
        elevation: 0,
        scrolledUnderElevation: 0,
        centerTitle: false,
        titleTextStyle: textTheme.titleMedium?.copyWith(color: HomiColors.ink, fontWeight: FontWeight.w600),
        shape: const Border(bottom: BorderSide(color: HomiColors.line)),
        iconTheme: const IconThemeData(color: HomiColors.muted),
      ),
      cardTheme: CardThemeData(
        color: HomiColors.card,
        elevation: 0,
        margin: EdgeInsets.zero,
        shadowColor: HomiColors.shadow,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(radius),
          side: const BorderSide(color: HomiColors.line),
        ),
      ),
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          backgroundColor: HomiColors.primary,
          foregroundColor: Colors.white,
          disabledBackgroundColor: const Color(0xFFD7DCE4),
          disabledForegroundColor: const Color(0xFF8B93A2),
          minimumSize: const Size.fromHeight(52),
          textStyle: textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w600),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
        ),
      ),
      outlinedButtonTheme: OutlinedButtonThemeData(
        style: OutlinedButton.styleFrom(
          foregroundColor: HomiColors.text,
          backgroundColor: HomiColors.card,
          minimumSize: const Size.fromHeight(52),
          side: const BorderSide(color: HomiColors.lineStrong),
          textStyle: textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w600),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
        ),
      ),
      textButtonTheme: TextButtonThemeData(
        style: TextButton.styleFrom(foregroundColor: HomiColors.primary),
      ),
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: HomiColors.card,
        labelStyle: const TextStyle(color: HomiColors.muted),
        floatingLabelStyle: const TextStyle(color: HomiColors.primaryInk),
        hintStyle: const TextStyle(color: HomiColors.faint),
        contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 16),
        enabledBorder: _border(HomiColors.lineStrong),
        border: _border(HomiColors.lineStrong),
        focusedBorder: _border(HomiColors.primary, width: 1.5),
        errorBorder: _border(HomiColors.bad),
        focusedErrorBorder: _border(HomiColors.bad, width: 1.5),
      ),
      snackBarTheme: SnackBarThemeData(
        backgroundColor: HomiColors.ink,
        contentTextStyle: const TextStyle(color: Colors.white),
        behavior: SnackBarBehavior.floating,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
      ),
      progressIndicatorTheme: const ProgressIndicatorThemeData(color: HomiColors.primary),
    );
  }

  static OutlineInputBorder _border(Color color, {double width = 1}) => OutlineInputBorder(
        borderRadius: BorderRadius.circular(10),
        borderSide: BorderSide(color: color, width: width),
      );
}
