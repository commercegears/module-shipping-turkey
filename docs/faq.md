# Shipping Connect: Türkiye for Magento 2: FAQ

**Which carriers are included?**
Aras Kargo, Yurtiçi Kargo and Geliver. Geliver is an aggregator: through one Geliver account you reach Aras, Yurtiçi,
PTT, Sürat, HepsiJet and Kolay Gelsin, with the cheapest offer or a fixed carrier service code. MNG Kargo and DHL
eCommerce Türkiye are not included.

**What do I need from the carrier?**
For Aras Kargo: the web service (SetOrder) user, password and customer code from your Aras branch, plus an XML
service user for tracking (esasweb.araskargo.com.tr > Entegrasyon > XML Servisleri). For Yurtiçi Kargo: a contract with
a web service user (wsUserName) and password. For Geliver: an account at app.geliver.io, an API token and the ID of
your sender address. The module does not create carrier accounts for you.

**Can I test before going live?**
Yes. Each connection has a **Sandbox / Test Mode** switch. Aras uses its test web service with the integration test
user from Aras, Yurtiçi its test web service with the test user `YKTEST`, and Geliver creates test shipments on your
account that are not charged. Test labels are not valid for shipping.

**Why does Yurtiçi not answer from my server?**
Yurtiçi Kargo's web services only answer requests from Turkish IP addresses or addresses Yurtiçi has whitelisted.
If your server is hosted outside Türkiye, ask Yurtiçi to whitelist its IP address.

**How are Turkish addresses handled?**
State/Province is used as the province (il) and City as the district (ilçe). When State/Province is empty, the City is
used as the province. Geliver also receives the province plate code, which the module fills in automatically.

**Does it support cash on delivery?**
Yes, for Aras Kargo and Yurtiçi Kargo, collected as cash or card (a setting per connection). The amount is pre-filled
in the label form for the payment methods you list under Cash on Delivery Payment Methods. Geliver labels have no
cash on delivery.

**What is desi and where is it used?**
Desi is the volumetric weight Turkish carriers charge by. The label form shows the desi of each parcel from its
dimensions, and the desi and weight are sent to the carrier with the shipment.

**How does the Yurtiçi label work?**
Yurtiçi's createShipment registers the shipment under your cargo key and returns no label. The module prints a PDF
label with the cargo key as a Code 128 barcode, which the Yurtiçi branch scans.

**Can customers track their parcel?**
Yes. A cron job reads the carrier's tracking and stores the status, the events and the delivery date. Customers see
the carrier, the tracking number with a link to the carrier's tracking page, a progress bar and the latest events on
their order page in My Account (Luma and Hyvä) and on the guest order view. You can also e-mail customers on delivery
milestones and set an order status when the parcel is delivered.

**Can I create return labels?**
With Geliver, yes: a return of the original Geliver shipment, with a signed download link for the customer. Aras Kargo
and Yurtiçi Kargo connections do not create return labels.

**What is Shipping Connect and why do I also need Shipping Core?**
Shipping Connect is CommerceGears's shipping framework for Magento. The free package
`commercegears/module-shipping-core` holds the checkout methods, labels, tracking, returns and the API log; this
package adds the Turkish carriers. Composer installs Shipping Core automatically, and you can add other carrier
packages, such as DPD Germany, next to it.

**Which carriers are tested?**
Aras Kargo order, label, cash on delivery and cancel are tested against the Aras test web service; Aras tracking is
beta. Yurtiçi Kargo and Geliver are beta: written from the carriers' documentation and tested with recorded
responses. Test with your own account before going live.
