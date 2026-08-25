#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
for f in \
  "$ROOT/app/Payments/PaymentProviderRegistry.php" \
  "$ROOT/app/Payments/ConfiguredGateway.php" \
  "$ROOT/app/Core/SmsProviderRegistry.php" \
  "$ROOT/app/Core/ConfiguredSmsProvider.php" \
  "$ROOT/app/Core/SecretStore.php" \
  "$ROOT/migrations/v25_provider_configuration.sql" \
  "$ROOT/migrations/v25_provider_configuration_mysql.sql"; do test -s "$f" || { echo "FAIL missing $f"; exit 1; }; done
php -l "$ROOT/app/Payments/PaymentProviderRegistry.php" >/dev/null
php -l "$ROOT/app/Core/SecretStore.php" >/dev/null
php -l "$ROOT/app/Core/Mail.php" >/dev/null
php -l "$ROOT/app/Core/Sms.php" >/dev/null
php -l "$ROOT/app/Controllers/MainController.php" >/dev/null
grep -q "v25_provider_configuration" "$ROOT/app/Core/Bootstrap.php"
grep -q "save-provider-settings" "$ROOT/public/index.php"
grep -q "provider-settings" "$ROOT/app/Views/admin.php"
grep -q "POSTYAR_SECRET_KEY" "$ROOT/config/config.example.php"
grep -q "PaymentProviderRegistry::all" "$ROOT/app/Controllers/MainController.php"
grep -q "SmsProviderRegistry::all" "$ROOT/app/Controllers/MainController.php"
echo "WAVE_R3_PROVIDER_CONFIGURATION: PASS"
