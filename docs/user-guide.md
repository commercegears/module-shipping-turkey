# Shipping Connect: Türkiye for Magento 2: User Guide

Ship with **Aras Kargo**, **Yurtiçi Kargo** and **Geliver** from Magento: checkout methods for Turkish addresses,
labels with desi and cash on delivery, tracking in the admin and in the customer account, and cancelling at the
carrier. This package is a carrier package for **Shipping Connect** (`commercegears/module-shipping-core`, free),
which provides the checkout methods, label printing, tracking, return labels and the API log.

- Storefront demo (Luma): https://demo.commercegears.com/joust-duffle-bag.html
- Storefront demo (Hyvä): https://demo.commercegears.com/hyva/joust-duffle-bag.html
- Customer account demo: https://demo.commercegears.com/customer/account/login/ (sign in with the demo customer shown
  on the page, then My Orders)
- Admin demo: https://demo.commercegears.com/try-admin/shipping-turkey
- Changelog: [CHANGELOG.md](../CHANGELOG.md) · Questions: [FAQ](faq.md)

In the demo, the Aras Kargo and Yurtiçi Kargo connections are switched on without credentials: their checkout
methods are offered for addresses in Türkiye, but no carrier is contacted, so new labels cannot be created there. The
labels and tracking events you see on the demo orders are demo data.

## 1. Requirements

| | |
| --- | --- |
| Magento | Open Source or Adobe Commerce 2.4.7 to 2.4.9 |
| PHP | 8.2 to 8.5 |
| Themes | Luma checkout; Hyvä storefront through Hyva_LumaCheckout (customer account tested with Hyvä 1.5) |
| Other | `commercegears/module-shipping-core` ^2.0 and `commercegears/module-core` (both installed automatically) |
| Carrier | An Aras Kargo or Yurtiçi Kargo contract with web service access, or a Geliver account |

## 2. Installation

With Composer. Use the Composer keys from your account on commercegears.com (see
https://commercegears.com/composer-access/):

```bash
composer config repositories.commercegears composer https://repo.commercegears.com
composer config --auth http-basic.repo.commercegears.com PUBLIC_KEY PRIVATE_KEY
composer require commercegears/module-shipping-turkey
bin/magento setup:upgrade
bin/magento setup:di:compile            # production mode only
bin/magento setup:static-content:deploy # production mode only
bin/magento cache:flush
```

Shipping Core is installed with it. To update later: `composer update commercegears/module-shipping-turkey`, then the
same `bin/magento` commands.

## 3. Quick start

1. **Stores > Configuration > CommerceGears > Shipping Connect > General > Enabled = Yes.**
2. **CommerceGears > Shipping Connect > Carrier Connections > Add Carrier Connection**, choose **Aras Kargo**,
   **Yurtiçi Kargo** or **Geliver**.
3. Enter a **Name** (for you; customers see the **Carrier Title at Checkout**, empty = carrier name), switch
   **Enabled** on and choose the **Website**.
4. Leave **Sandbox / Test Mode** on while you test. Enter the credentials (section 4) and click **Test Connection**.
5. Under **Checkout Methods** add a method, for example "Yurtiçi Kargo", with a price and **Countries** = `TR`.
6. Fill in the **Sender Address** and **Parcel Defaults** and click **Save**.

The method now appears at checkout for addresses in Türkiye. When your tests are done, switch **Sandbox / Test Mode**
off and enter your live credentials.

## 4. Credentials and test access

### Aras Kargo

- **Web Service User Name** and **Web Service Password**: the SetOrder user from your Aras branch. With Sandbox / Test
  Mode on, the Aras test web service is used with the integration test user you receive from Aras; test shipments
  are not collected.
- **Customer Code (Tracking)**, **XML Service User (Tracking)** and **XML Service Password**: needed for tracking.
  The XML service user is created in esasweb.araskargo.com.tr > Entegrasyon > XML Servisleri.
- Carrier settings: **Shipping Paid By** (sender on contract, or recipient) and **Cash on Delivery Collected As**
  (cash or card).

### Yurtiçi Kargo

- **Web Service User (wsUserName)** and **Web Service Password** from your Yurtiçi Kargo contract. With Sandbox /
  Test Mode on, the Yurtiçi test web service is used; the form shows the test user (`YKTEST`) under the field.
- Yurtiçi web services only answer requests from Turkish IP addresses (or addresses Yurtiçi has whitelisted). If
  your server is outside Türkiye, ask Yurtiçi to whitelist its IP address.
- Carrier setting: **Cash on Delivery Collected As** (cash or card).

### Geliver

- **API Token**: app.geliver.io > API tokens. A Geliver account needs your tax plate or e-Devlet residence document
  and the signed agreement.
- Carrier settings: **Sender Address ID** (the ID of the sender address created in Geliver), **Carrier Service Code**
  (for example `YURTICI_STANDART`, `ARAS_STANDART` or `SURAT_STANDART`; empty = cheapest offer) and **Shop Address
  (Source Identifier)** (your shop URL).
- With Sandbox / Test Mode on, Geliver test shipments are created on the same account; they are not charged and
  their tracking moves on one step per check.

Credentials are stored encrypted and are never shown again after saving. **Test Connection** uses the values in the
form, including the sandbox switch, without saving them.

## 5. Turkish addresses

Shipping Connect reads the address as follows for the Turkish carriers:

- **State/Province** = province (il), **City** = district (ilçe).
- When State/Province is empty, the City is used as the province. For Aras, a City written as "Kadıköy / İstanbul"
  or "Kadıköy, İstanbul" is split into district and province.
- Geliver receives the province plate code (for example `34` for İstanbul) next to the province name.
- Phone numbers are normalised to the format each carrier expects.

Data sent to the carrier: recipient name, address (il and ilçe), phone, e-mail (Yurtiçi and Geliver), desi and
weight, cash on delivery amount and the order number.

## 6. Checkout methods

Each connection offers its own checkout methods. Per method: title, carrier product, price (flat, weight table or
subtotal table), free shipping from a subtotal, allowed countries and sort order. Price tables are `limit:price`
pairs, for example `2:49.90, 10:79.90, 30:129.90` (above the last limit the method is not offered). Cart rules with
free shipping make the methods free. Prices are in the base currency of the website.

The Turkish carriers deliver to the address (home delivery); they have no pickup point search.

## 7. Labels and shipments

- **One order:** order view > **Shipping Connect** > **Create Shipment and Label**. Check the connection, carrier
  product, number of parcels, weight and dimensions per parcel (the desi of each parcel is shown) and the cash on
  delivery amount (pre-filled for the payment methods set in **Cash on Delivery Payment Methods**).
- **Many orders:** order grid > select orders > Actions > **Create Shipments and Labels** (up to 50 orders), then
  **Print All Labels** for one merged PDF.
- The Magento shipment is created with the tracking number. Its title is the carrier title at checkout.

Per carrier:

| | Aras Kargo | Yurtiçi Kargo | Geliver |
| --- | --- | --- | --- |
| Label | Aras label image, merged into a PDF | PDF printed by the module with your cargo key as a Code 128 barcode, which the branch scans | PDF from the chosen carrier |
| Parcels | one parcel per label | parcel count on one label | one parcel |
| Cash on delivery | cash or card | cash or card | no |
| Return labels | no | no | yes |
| Cancel | yes | yes | yes |

**Shipments and Labels** (CommerceGears > Shipping Connect > Shipments and Labels) lists every label with status, last
event, delivery date and mode (sandbox or live). The label page shows all details and tracking events, with
**Download Label**, **Update Tracking** and **Cancel Label**.

## 8. Tracking

With **Stores > Configuration > CommerceGears > Shipping Connect > Tracking > Track Parcels Automatically** on, a cron
job checks open parcels every 15 minutes (each parcel every 1 to 24 hours depending on its age and status) and stores
the status, the events and the delivery date. It stops after delivery or after **Stop Tracking After (Days)**.

- Aras tracking needs the XML service user and the customer code (section 4).
- Yurtiçi tracking uses queryShipment with the same web service user.
- Geliver tracking reads the Geliver shipment.

Optional: **Order Status When Delivered** and **E-mail Customer When** (statuses) with your own e-mail template.

Customers see the carrier, the tracking number with a link to the carrier's tracking page (Aras and Yurtiçi), a
progress bar and the latest events on their order page in My Account, on Luma and Hyvä, and on the guest order view.

## 9. Return labels (Geliver)

Order view > **Create Return Label** creates a Geliver return of the original Geliver shipment. The label page has a
signed download link for the customer (30 days). CommerceGears Returns can create it from a return request.

## 10. CLI

```bash
bin/magento commercegears:shipping:adapters                     # installed carriers and what they support
bin/magento commercegears:shipping:test-connection [id]          # test the credentials
bin/magento commercegears:shipping:create-label <order number> [-c connection] [-p parcels] [-w kg] [--cod amount]
bin/magento commercegears:shipping:track [--order number] [--label id] [--limit n]
```

## 11. Permissions (ACL)

System > Permissions > User Roles > Role Resources > CommerceGears > Shipping Connect: **View Shipments and Labels**,
**Create Shipments and Labels**, **Cancel Labels**, **Create Return Labels**, **Manage Carrier Connections**, **View
API Log**, **Settings**. Creating labels also creates the Magento shipment, so it needs Sales > Operations > Orders >
Actions > Ship as well.

## 12. Troubleshooting

- **No Turkish method at checkout:** Shipping Connect must be enabled for the store view, the connection enabled and
  assigned to the website, and the method's **Countries** must include `TR` (or be empty).
- **"... is not set in the carrier connection":** a credential is missing in the connection.
- **Yurtiçi does not answer or refuses the connection:** the request did not come from a Turkish or whitelisted IP
  address.
- **Aras label without tracking:** enter the customer code and the XML service user for tracking.
- **Geliver: "no carrier offer for this shipment yet":** try again a minute later, or set a Carrier Service Code.
- Every request and response is in **CommerceGears > Shipping Connect > API Log**, with passwords, keys and tokens
  masked.

## 13. Limitations

- Aras: the Aras test system returned no label for multi-piece orders, so one parcel per label.
- Yurtiçi: createShipment returns no carrier label; the module prints its own label with your cargo key.
- Yurtiçi Kargo and Geliver are beta: written from the carrier documentation and tested with recorded responses. Test
  with your own account before going live.
- MNG Kargo and DHL eCommerce Türkiye are not included.

## 14. Uninstall

Disable or delete the Turkish connections first, then:

```bash
composer remove commercegears/module-shipping-turkey
bin/magento setup:upgrade
```

Labels and tracking data stay in the Shipping Core tables.
