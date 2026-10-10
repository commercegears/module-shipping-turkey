# Softaware Shipping Connect: Turkish carriers: Aras Kargo, Yurtiçi Kargo, Geliver

Carrier package for Softaware Shipping Connect (`softaware/module-shipping-core`). Package
`softaware/module-shipping-turkey`, module `Softaware_ShippingTurkey`.

Status: **Aras Kargo: tested** against the Aras test web service (order, label, COD, cancel); Aras tracking is beta. **Yurtiçi Kargo and Geliver: beta** (written from the documentation/SDK, tested with recorded responses; the Yurtiçi test service only answers from Turkish IP addresses).

## What it adds

| | Aras Kargo (`aras`) | Yurtiçi Kargo (`yurtici`) | Geliver (`geliver`) |
|---|---|---|---|
| Labels | Aras label image -> PDF | module prints its own PDF label with the cargo key (Code 128) | PDF from the chosen carrier |
| Desi / weight | yes | yes | yes |
| Cash on delivery | cash or card | cash or card | - |
| Tracking | XML service user needed | queryShipment | yes |
| Returns | - | - | yes (return of the original shipment) |
| Cancel | yes | yes | yes |
| Multi-parcel | one parcel per label | parcel count on one label | one parcel |

Turkish addresses: region = il (province), city = ilçe (district); province plate codes are filled automatically.
Geliver reaches Aras, Yurtiçi, PTT, Sürat, HepsiJet and Kolay Gelsin through one account (cheapest offer or a fixed
carrier service code).

## Installation

```
composer require softaware/module-shipping-turkey
bin/magento setup:upgrade
```

Requires `softaware/module-shipping-core` ^1.1.2 (installed automatically). Then add a connection in
Softaware > Shipping Connect > Carrier Connections > Add Carrier Connection > Aras Kargo, Yurtiçi Kargo or Geliver, enter the credentials, use
"Test Connection", add checkout methods and save. Labels, tracking and returns are then available from the order
view and the order grid (see the Shipping Core README).

## Credentials and test access

- **Aras Kargo:** customer code and web service (SetOrder) user from your Aras branch; for tracking an XML service user
  created in esasweb.araskargo.com.tr > Entegrasyon > XML Servisleri. The Aras test system is available for testing.
- **Yurtiçi Kargo:** contract and web service user (wsUserName/wsPassword). The test web service accepts only Turkish
  IP addresses (or whitelisted ones).
- **Geliver:** account at app.geliver.io (tax plate or e-Devlet residence document and the signed agreement), API
  token and the ID of your sender address. "Sandbox / Test Mode" creates Geliver test shipments.

## Data sent to the carrier

recipient name, address (il/ilçe), phone (and e-mail for Yurtiçi/Geliver), desi and weight, COD amount, order number. Credentials are stored encrypted by Shipping Core; requests are logged with secrets masked
(Softaware > Shipping Connect > API Log).

## Limitations

- Aras: the Aras test system returned no label for multi-piece orders, so one parcel per label.
- Yurtiçi: createShipment returns no carrier label; the printed label carries your cargo key, which the branch scans.
- MNG Kargo and DHL eCommerce Türkiye are not included.

## Tests

`Test/Mock/Scenario.php` holds the mocked scenario (documented / recorded responses in `Test/Fixtures/` where
present); it is run by the Shipping Connect test harness and not loaded by Magento.

## Compatibility

Magento Open Source / Adobe Commerce 2.4.7-2.4.9, PHP 8.2-8.5, Shipping Core ^1.0.
