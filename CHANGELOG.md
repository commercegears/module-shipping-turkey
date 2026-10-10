# Changelog

## 1.0.2 - 2026-10-10

### Changed
- Aras, Yurtiçi and Geliver declare the desi capability (`CAP_DESI`), so the label form shows the desi of the parcels
  for them only (Shipping Core 1.1.2 no longer shows it for DPD, DHL or Royal Mail). Requires Shipping Core 1.1.2.

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
