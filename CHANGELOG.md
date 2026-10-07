# Changelog

## 1.0.1 - 2026-10-07

### Changed
- Connection forms: say where the Aras, Yurtiçi and Geliver credentials come from and what the sandbox switch does for
  each (Aras and Yurtiçi test web services, Geliver test shipments); test values shown as "Test system: ..."; field
  labels in title case.
- Requires Shipping Core 1.1 (`CredentialsHelpInterface`).

## 1.0.0 - 2026-10-06

### Added
- Turkish carriers: Aras Kargo, Yurtiçi Kargo, Geliver adapter for Softaware Shipping Connect (moved from the former single module
  softaware/module-shipping-connect 1.0.0; adapter codes unchanged, so existing connections keep working).
