/// Format integer centavos from the API as pesos, e.g. 70000 -> "₱700.00".
/// The API always sends money as integer centavos (never floats); we divide by
/// 100 only here, for display.
String pesos(int centavos) {
  final negative = centavos < 0;
  final abs = centavos.abs();
  final whole = (abs ~/ 100).toString();
  final cents = (abs % 100).toString().padLeft(2, '0');

  final buf = StringBuffer();
  for (var i = 0; i < whole.length; i++) {
    if (i > 0 && (whole.length - i) % 3 == 0) buf.write(',');
    buf.write(whole[i]);
  }

  return '${negative ? '−' : ''}₱$buf.$cents';
}
