/// The signed-in device account, as returned by /api/auth/login and /api/me.
class AppUser {
  const AppUser({
    required this.id,
    required this.name,
    required this.role,
    this.branchId,
    this.branchName,
  });

  final int id;
  final String name;
  final String role; // 'kiosk' | 'housekeeper'
  final int? branchId;
  final String? branchName;

  bool get isKiosk => role == 'kiosk';
  bool get isHousekeeper => role == 'housekeeper';

  factory AppUser.fromJson(Map<String, dynamic> json) {
    return AppUser(
      id: json['id'] as int,
      name: json['name'] as String,
      role: json['role'] as String,
      branchId: json['branch_id'] as int?,
      branchName: json['branch_name'] as String?,
    );
  }
}
