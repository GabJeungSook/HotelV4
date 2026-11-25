import 'package:flutter/material.dart';

import '../theme.dart';

/// The Homi wordmark. Brand blue is used here only — it is the logo colour.
class HomiLogo extends StatelessWidget {
  const HomiLogo({super.key, this.size = 30});

  final double size;

  @override
  Widget build(BuildContext context) {
    return Text(
      'Homi',
      style: TextStyle(
        fontSize: size,
        height: 1,
        fontWeight: FontWeight.w600,
        letterSpacing: -0.5,
        color: HomiColors.brand,
      ),
    );
  }
}

/// A white card: 1px line border, 12px radius, the hairline shadow from the
/// design system (0 1px 2px rgba(16,24,40,.05)). An optional coloured top edge
/// (used on the cleaning screen) — never a thick coloured side border.
class HomiCard extends StatelessWidget {
  const HomiCard({
    super.key,
    required this.child,
    this.padding = const EdgeInsets.all(16),
    this.onTap,
    this.topEdge,
    this.selected = false,
  });

  final Widget child;
  final EdgeInsets padding;
  final VoidCallback? onTap;
  final Color? topEdge;
  final bool selected;

  @override
  Widget build(BuildContext context) {
    final border = selected ? HomiColors.selectLine : HomiColors.line;
    final radius = BorderRadius.circular(12);
    return DecoratedBox(
      decoration: BoxDecoration(
        color: selected ? HomiColors.primary50 : HomiColors.card,
        borderRadius: radius,
        border: Border.all(color: border),
        boxShadow: const [BoxShadow(color: HomiColors.shadow, offset: Offset(0, 1), blurRadius: 2)],
      ),
      child: ClipRRect(
        borderRadius: radius,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            if (topEdge != null) Container(height: 3, color: topEdge),
            Material(
              type: MaterialType.transparency,
              child: InkWell(
                onTap: onTap,
                child: Padding(padding: padding, child: child),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// A status: a small solid dot plus the label in the same tone's text colour —
/// no fill, no border, no pill (DESIGN_SYSTEM §1 rule 3).
class StatusText extends StatelessWidget {
  const StatusText({super.key, required this.label, required this.color});

  final String label;
  final Color color;

  @override
  Widget build(BuildContext context) {
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Container(
          width: 7,
          height: 7,
          decoration: BoxDecoration(color: color, shape: BoxShape.circle),
        ),
        const SizedBox(width: 6),
        Text(label,
            style: TextStyle(color: color, fontSize: 12.5, fontWeight: FontWeight.w500)),
      ],
    );
  }
}

/// A small stat tile for the "My cleans" summary: a value over a label, with an
/// optional tone that colours the value.
class StatTile extends StatelessWidget {
  const StatTile({super.key, required this.label, required this.value, this.sub, this.valueColor});

  final String label;
  final String value;
  final String? sub;
  final Color? valueColor;

  @override
  Widget build(BuildContext context) {
    return HomiCard(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 14),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(label.toUpperCase(),
              style: const TextStyle(color: HomiColors.faint, fontSize: 10.5, fontWeight: FontWeight.w600, letterSpacing: 0.5)),
          const SizedBox(height: 8),
          Row(
            crossAxisAlignment: CrossAxisAlignment.baseline,
            textBaseline: TextBaseline.alphabetic,
            children: [
              Text(value,
                  style: TextStyle(color: valueColor ?? HomiColors.ink, fontSize: 24, fontWeight: FontWeight.w600, fontFeatures: tabular)),
              if (sub != null) ...[
                const SizedBox(width: 4),
                Text(sub!, style: const TextStyle(color: HomiColors.muted, fontSize: 12)),
              ],
            ],
          ),
        ],
      ),
    );
  }
}

/// A round initials avatar in one of the design system's soft tones, picked
/// stably from the name.
class InitialsAvatar extends StatelessWidget {
  const InitialsAvatar({super.key, required this.name, this.size = 36});

  final String name;
  final double size;

  static const _tones = <List<Color>>[
    [Color(0xFFEAF4FB), HomiColors.primaryInk],
    [HomiColors.ok50, HomiColors.ok],
    [HomiColors.warn50, HomiColors.warn],
    [HomiColors.violet50, HomiColors.violet],
    [Color(0xFFF2F4F7), Color(0xFF475467)],
  ];

  String get _initials {
    final parts = name.trim().split(RegExp(r'\s+')).where((p) => p.isNotEmpty).toList();
    if (parts.isEmpty) return '?';
    if (parts.length == 1) return parts.first.substring(0, 1).toUpperCase();
    return (parts.first.substring(0, 1) + parts.last.substring(0, 1)).toUpperCase();
  }

  @override
  Widget build(BuildContext context) {
    final tone = _tones[name.hashCode.abs() % _tones.length];
    return Container(
      width: size,
      height: size,
      alignment: Alignment.center,
      decoration: BoxDecoration(color: tone[0], shape: BoxShape.circle),
      child: Text(_initials,
          style: TextStyle(color: tone[1], fontWeight: FontWeight.w600, fontSize: size * 0.36)),
    );
  }
}
