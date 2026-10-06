<?php
/**
 * @copyright Copyright (c) Softaware Commerce (https://www.softawarecommerce.co.uk/)
 *
 * Mock scenario for this carrier package: responses written from the carrier's official API documentation (and
 * recorded test-system responses in ../Fixtures), with the assertions run by the Shipping Connect test harness
 * (ShippingCore-carrier-tests.php: mock transport, check(), sc_connection(), sc_request(), sc_address()).
 * Not loaded by Magento.
 */
declare(strict_types=1);

use Softaware\ShippingCore\Model\Carrier\AdapterPool;
use Softaware\ShippingCore\Model\Carrier\Data\PickupPoint;
use Softaware\ShippingCore\Model\Carrier\Data\PickupPointQuery;
use Softaware\ShippingCore\Model\Carrier\Data\RateRequest;
use Softaware\ShippingTurkey\Model\TurkishProvinces;

function sc_test_turkey(ScMockTransport $mock, AdapterPool $pool): void
{
    $b64 = base64_encode(SC_PDF);
    $uk = sc_address(['name' => 'Ada Lovelace', 'street' => ['10 Downing Street'], 'postcode' => 'SW1A 2AA', 'city' => 'London', 'country' => 'GB', 'phone' => '07700900123']);
    $pl = sc_address(['name' => 'Jan Kowalski', 'street' => ['ul. Marszałkowska 10'], 'postcode' => '00-001', 'city' => 'Warszawa', 'country' => 'PL', 'phone' => '+48 600 700 800']);
    $tr = sc_address(['name' => 'Ayşe Yılmaz', 'street' => ['Caferağa Mah. Moda Cad. No 12'], 'postcode' => '34710', 'city' => 'Kadıköy', 'region' => 'İstanbul', 'country' => 'TR', 'phone' => '0532 000 00 00']);
    echo "Geliver (beta)\n";
    $gl = $pool->get('geliver');
    $c = sc_connection('geliver', ['api_token' => 'gel-token-1'], ['sender_address_id' => 'addr_1', 'source_identifier' => 'https://shop.example'], true,
        ['sender_country_id' => 'TR']);
    $mock->reset();
    $mock->on('POST', '#/api/v1/shipments$#', 200, json_encode(['result' => true, 'data' => ['id' => 'shp_tr_1', 'offers' => ['cheapest' => ['id' => 'off_1', 'amount' => '84.00']]]]))
        ->on('POST', '#/api/v1/transactions$#', 200, json_encode(['result' => true, 'data' => ['id' => 'tx_1', 'shipment' => ['id' => 'shp_tr_1', 'barcode' => '1234567890',
            'trackingNumber' => 'YK1234567890', 'labelURL' => 'https://labels.geliver.example/l/shp_tr_1.pdf', 'trackingUrl' => 'https://tracking.example/YK1234567890', 'providerServiceCode' => 'YURTICI_STANDART']]]))
        ->on('GET', '#labels\.geliver\.example#', 200, SC_PDF, ['Content-Type' => 'application/pdf']);
    $r = $gl->createShipment(sc_request($c, [1.0], 'standard', null, $tr, false, 'pdf', null, 'TRY'));
    $sent = json_decode($mock->bodies[0], true);
    check($r->carrierReference === 'shp_tr_1' && $r->trackingNumbers === ['YK1234567890'] && ($r->extra['tracking_url'] ?? '') !== '' && str_starts_with($r->labels[0]->content, '%PDF'), 'shipment -> cheapest offer -> label');
    check($sent['test'] === true && $sent['recipientAddress']['cityCode'] === '34' && $sent['recipientAddress']['districtName'] === 'Kadıköy'
        && $sent['recipientAddress']['phone'] === '+905320000000' && $sent['weight'] === '1.00' && json_decode($mock->bodies[1], true) === ['offerID' => 'off_1'], 'request: test flag, city code, district, phone, strings');
    $mock->reset();
    $mock->on('POST', '#/shipments$#', 200, json_encode(['result' => false, 'message' => 'Alıcı adresi geçersiz', 'additionalMessage' => 'cityCode']));
    sc_expect_error(fn () => $gl->createShipment(sc_request($c, [1.0], 'standard', null, $tr)), 'geçersiz', 'result=false error shown');
    $t = $gl->parseTracking('YK1234567890', ['trackingStatus' => ['trackingStatusCode' => 'DELIVERED', 'statusDate' => '2026-10-04T14:00:00', 'locationName' => 'Kadıköy']]);
    check($t->status === 'delivered' && $t->deliveredAt === '2026-10-04 11:00:00', 'tracking (Istanbul time to UTC)');
    $mock->reset();
    $mock->on('GET', '#/shipments/shp_tr_1$#', 200, json_encode(['result' => true, 'data' => ['id' => 'shp_tr_1', 'trackingStatus' => ['trackingStatusCode' => 'TRANSIT']]]));
    $res = $gl->track($c, ['YK1234567890'], ['YK1234567890' => 'shp_tr_1']);
    check(($res['YK1234567890']->status ?? '') === 'in_transit', 'track() uses the shipment ID reference');
    echo "Yurtiçi Kargo (beta)\n";
    $yk = $pool->get('yurtici');
    $c = sc_connection('yurtici', ['username' => 'YKTEST', 'password' => 'YK'], [], true, ['sender_country_id' => 'TR', 'sender_city' => 'İstanbul']);
    $ok = '<?xml version="1.0"?><S:Envelope xmlns:S="http://schemas.xmlsoap.org/soap/envelope/"><S:Body><ns2:createShipmentResponse xmlns:ns2="http://yurticikargo.com.tr/ShippingOrderDispatcherServices"><ShippingOrderResultVO><outFlag>0</outFlag><outResult>Başarılı</outResult><count>1</count><jobId>12345</jobId><shippingOrderDetailVO><cargoKey>X</cargoKey><errCode>0</errCode></shippingOrderDetailVO></ShippingOrderResultVO></ns2:createShipmentResponse></S:Body></S:Envelope>';
    $mock->reset();
    $mock->on('POST', '#testws\.yurticikargo\.com#', 200, $ok, ['Content-Type' => 'text/xml']);
    $r = $yk->createShipment(sc_request($c, [2.0], 'standard', null, $tr, false, 'pdf', 250.0, 'TRY'));
    $body = $mock->bodies[0];
    check(str_starts_with($r->labels[0]->content, '%PDF') && strlen($r->trackingNumbers[0]) <= 20 && str_starts_with($r->trackingNumbers[0], 'SW'), 'registered + own Code 128 label: ' . $r->trackingNumbers[0]);
    file_put_contents(SC_OUT . '/yurtici-own-label.pdf', $r->labels[0]->content);
    file_put_contents(SC_OUT . '/yurtici-own-label.txt', $r->trackingNumbers[0]);
    check(str_contains($body, '<cityName>İstanbul</cityName>') && str_contains($body, '<townName>Kadıköy</townName>') && str_contains($body, '<receiverPhone1>5320000000</receiverPhone1>')
        && str_contains($body, '<ttInvoiceAmount>250.00</ttInvoiceAmount>') && str_contains($body, '<desi>2.000</desi>') && str_contains($body, '<wsUserName>YKTEST</wsUserName>'), 'request: city/town, 10-digit phone, COD, desi');
    $mock->reset();
    $mock->on('POST', '#yurticikargo#', 200, str_replace(['<outFlag>0</outFlag>', '<errCode>0</errCode>'], ['<outFlag>1</outFlag>', '<errCode>60020</errCode><errMessage>Kargo anahtarı daha önce kullanılmış</errMessage>'], $ok), ['Content-Type' => 'text/xml']);
    sc_expect_error(fn () => $yk->createShipment(sc_request($c, [2.0], 'standard', null, $tr)), '60020', 'duplicate cargo key error');
    $detail = simplexml_load_string('<shippingDeliveryDetailVO><cargoKey>SW1</cargoKey><errCode>0</errCode><operationStatus>DLV</operationStatus><shippingDeliveryItemDetailVO><docId>123</docId><deliveryDate>20261005</deliveryDate><deliveryTime>143000</deliveryTime><invDocCargoVOArray><eventId>1</eventId><eventName>Kargo teslim alındı</eventName><unitName>Kadıköy</unitName><cityName>İstanbul</cityName><eventDate>20261004</eventDate><eventTime>101500</eventTime></invDocCargoVOArray></shippingDeliveryItemDetailVO></shippingDeliveryDetailVO>');
    $t = $yk->parseDetail($detail);
    check($t->status === 'delivered' && $t->deliveredAt === '2026-10-05 11:30:00' && count($t->events) === 2, 'queryShipment parsed (DLV, Istanbul time)');
    echo "Aras tracking (beta part)\n";
    $ar = $pool->get('aras');
    $t = $ar->parseTracking('6843845504565', json_encode(['QueryResult' => ['Cargo' => ['DURUM_KODU' => '6', 'DURUMU' => 'TESLİM EDİLDİ', 'TESLIM_TARIHI' => '05.10.2026', 'TESLIM_SAATI' => '16:20', 'VARIS_BIRIM' => 'KADIKÖY']]]));
    check($t->status === 'delivered' && $t->deliveredAt === '2026-10-05 13:20:00', 'delivered (Istanbul time)');
    $t2 = $ar->parseTracking('1', json_encode(['QueryResult' => ['Cargo' => [['DURUM_KODU' => '7', 'DURUMU' => 'İADE SÜRECİNDE']]]]));
    check($t2->status === 'returned', 'return detected from status text');
    echo "Aras (recorded test-system response) and helpers\n";
    $raw = (string)file_get_contents(dirname(__DIR__) . '/Fixtures/aras-get-aras-barcode.xml');
    $raw = preg_replace(['/\sxmlns(:[\w-]+)?="[^"]*"/', '/(<\/?)[\w-]+:/', '/^<\?xml[^>]*\?>/'], ['', '$1', ''], $raw);
    $body = simplexml_load_string($raw);
    $res = $pool->get('aras')->parseBarcode($body->Body, 'SWT1791308033');
    check($res->trackingNumbers === ['6843845504565'] && str_starts_with($res->labels[0]->content, "\xFF\xD8"), 'GetArasBarcode: tracking number + JPEG label');
    check(TurkishProvinces::code('Şanlıurfa') === '63' && TurkishProvinces::code('İstanbul') === '34' && TurkishProvinces::code('Düzce') === '81', 'province plate codes');
}
