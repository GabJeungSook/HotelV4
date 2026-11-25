import 'package:flutter_test/flutter_test.dart';
import 'package:homi_mobile/models/app_user.dart';
import 'package:homi_mobile/util/money.dart';

void main() {
  group('pesos()', () {
    test('formats centavos as pesos with two decimals', () {
      expect(pesos(70000), '₱700.00');
      expect(pesos(45), '₱0.45');
      expect(pesos(0), '₱0.00');
    });

    test('groups thousands', () {
      expect(pesos(175194500), '₱1,751,945.00');
      expect(pesos(100000), '₱1,000.00');
    });

    test('shows a minus for negatives (e.g. a discount line)', () {
      expect(pesos(-5000), '−₱50.00');
    });
  });

  group('AppUser.fromJson', () {
    test('reads the profile and resolves the role flags', () {
      final kiosk = AppUser.fromJson({
        'id': 7,
        'name': 'Lobby tablet',
        'role': 'kiosk',
        'branch_id': 1,
        'branch_name': 'Hotel Dalisay GenSan',
      });
      expect(kiosk.isKiosk, isTrue);
      expect(kiosk.isHousekeeper, isFalse);
      expect(kiosk.branchName, 'Hotel Dalisay GenSan');

      final roomboy = AppUser.fromJson({'id': 3, 'name': 'Dodong', 'role': 'housekeeper'});
      expect(roomboy.isHousekeeper, isTrue);
      expect(roomboy.branchId, isNull);
    });
  });
}
