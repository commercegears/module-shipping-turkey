<?php
/**
 * @copyright Copyright (c) CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\ShippingTurkey\Model\Adapter;

use CommerceGears\ShippingCore\Api\Data\ConnectionInterface;
use CommerceGears\ShippingCore\Exception\CarrierException;
use CommerceGears\ShippingCore\Model\Carrier\AbstractAdapter;
use CommerceGears\ShippingCore\Model\Carrier\Data\Address;
use CommerceGears\ShippingCore\Model\Carrier\Data\Label;
use CommerceGears\ShippingCore\Model\Carrier\Data\ShipmentRequest;
use CommerceGears\ShippingCore\Model\Carrier\Data\ShipmentResult;
use CommerceGears\ShippingCore\Model\Carrier\Data\TrackingEvent;
use CommerceGears\ShippingCore\Model\Carrier\Data\TrackingResult;
use CommerceGears\ShippingTurkey\Model\TurkishProvinces;
use CommerceGears\ShippingCore\Api\CredentialsHelpInterface;

/**
 * Geliver (Türkiye aggregator: Aras, Yurtiçi, PTT, Sürat, HepsiJet, Kolay Gelsin) REST API v1.
 *
 * Sandbox switch = Geliver test shipments ("test": true) on the same account; test shipments are not charged and
 * each GET advances their tracking status one step. Flow: create shipment -> accept cheapest (or chosen provider)
 * offer -> label PDF from labelURL.
 */
class Geliver extends AbstractAdapter implements CredentialsHelpInterface
{
    public const CODE = 'geliver';
    private const BASE = 'https://api.geliver.io/api/v1';

    public function getCode(): string
    {
        return self::CODE;
    }

    public function getTitle(): string
    {
        return 'Geliver';
    }

    public function getCapabilities(): array
    {
        return [self::CAP_SHIPMENT, self::CAP_CANCEL, self::CAP_TRACKING, self::CAP_RETURNS, self::CAP_DESI];
    }

    public function getCredentialFields(): array
    {
        return [
            ['code' => 'api_token', 'label' => (string)__('API Token'), 'type' => 'password', 'required' => true,
                'notice' => (string)__('app.geliver.io > API tokens.')],
        ];
    }

    public function getCredentialsHelp(): string
    {
        return (string)__('app.geliver.io > API tokens (the account needs your tax plate or e-Devlet residence document and the signed agreement). The sender address ID is under Carrier Settings.');
    }

    public function getSandboxHelp(): string
    {
        return (string)__('On: Geliver test shipments on the same account (not charged; their tracking moves on one step per check). Off: real shipments.');
    }

    public function getOptionFields(): array
    {
        return [
            ['code' => 'sender_address_id', 'label' => (string)__('Sender Address ID'), 'type' => 'text', 'required' => true,
                'notice' => (string)__('ID of the sender address created in Geliver (Addresses).')],
            ['code' => 'provider_service_code', 'label' => (string)__('Carrier Service Code'), 'type' => 'text',
                'notice' => (string)__('E.g. YURTICI_STANDART, ARAS_STANDART, SURAT_STANDART. Empty = cheapest offer.')],
            ['code' => 'source_identifier', 'label' => (string)__('Shop Address (Source Identifier)'), 'type' => 'text',
                'notice' => (string)__('Your shop URL, e.g. https://www.example.com.tr')],
        ];
    }

    public function getServices(): array
    {
        return ['standard' => (string)__('Carrier from the connection (or cheapest offer)')];
    }

    public function getTrackingUrl(string $trackingNumber, string $countryId = '', string $locale = ''): string
    {
        return '';
    }

    public function testConnection(ConnectionInterface $connection): string
    {
        $response = $this->jsonRequest($this->call('test_connection', $connection), 'GET', self::BASE . '/shipments?limit=1', null,
            $this->auth($connection));
        $this->unwrap($this->assertOk($response, __('reading shipments'))->json());
        return (string)__('Geliver accepted the API token (%1 shipments).', $connection->isSandbox() ? 'test' : 'live');
    }

    public function createShipment(ShipmentRequest $request): ShipmentResult
    {
        $connection = $request->connection;
        $parcel = $request->parcels[0];
        $payload = [
            'senderAddressID' => $this->requireOption($connection, 'sender_address_id', 'Sender address ID'),
            'recipientAddress' => $this->address($request->recipient),
            'length' => number_format($parcel->lengthCm ?: 10, 1, '.', ''),
            'width' => number_format($parcel->widthCm ?: 10, 1, '.', ''),
            'height' => number_format($parcel->heightCm ?: 10, 1, '.', ''),
            'distanceUnit' => 'cm',
            'weight' => number_format(max(0.1, $request->getTotalWeight()), 2, '.', ''),
            'massUnit' => 'kg',
            'order' => [
                'orderNumber' => $this->clean($request->reference, 50),
                'sourceIdentifier' => $connection->getOption('source_identifier'),
                'totalAmount' => number_format($request->orderValue, 2, '.', ''),
                'totalAmountCurrency' => $request->currency,
                'sourceCode' => 'API',
            ],
        ];
        if ($connection->isSandbox()) {
            $payload['test'] = true;
        }
        $provider = $connection->getOption('provider_service_code');
        if ($provider !== '') {
            $payload['providerServiceCode'] = $provider;
            $transaction = $this->unwrap($this->assertOk($this->jsonRequest($this->call('create_shipment', $connection, $request->reference),
                'POST', self::BASE . '/transactions', ['shipment' => $payload], $this->auth($connection), ['retries' => 0]),
                __('buying the label'))->json());
        } else {
            $shipment = $this->unwrap($this->assertOk($this->jsonRequest($this->call('create_shipment', $connection, $request->reference),
                'POST', self::BASE . '/shipments', $payload, $this->auth($connection), ['retries' => 0]), __('creating the shipment'))->json());
            $offer = (string)($shipment['offers']['cheapest']['id'] ?? '');
            for ($attempt = 0; $offer === '' && $attempt < 5; $attempt++) {
                usleep(1000000);
                $shipment = $this->unwrap($this->assertOk($this->jsonRequest($this->call('get_offers', $connection, $request->reference), 'GET',
                    self::BASE . '/shipments/' . rawurlencode((string)$shipment['id']), null, $this->auth($connection)), __('reading offers'))->json());
                $offer = (string)($shipment['offers']['cheapest']['id'] ?? '');
            }
            if ($offer === '') {
                throw new CarrierException(__('Geliver has no carrier offer for this shipment yet. Try again in a minute.'));
            }
            $transaction = $this->unwrap($this->assertOk($this->jsonRequest($this->call('accept_offer', $connection, $request->reference),
                'POST', self::BASE . '/transactions', ['offerID' => $offer], $this->auth($connection), ['retries' => 0]), __('buying the label'))->json());
        }
        return $this->fromTransaction($connection, $transaction, $request->reference);
    }

    public function createReturnLabel(ShipmentRequest $request): ShipmentResult
    {
        $connection = $request->connection;
        if ($request->originalCarrierReference === '') {
            throw new CarrierException(__('Geliver return labels need the original Geliver shipment of the order.'));
        }
        $payload = ['isReturn' => true, 'willAccept' => true, 'count' => 1];
        if ($connection->getOption('provider_service_code') !== '') {
            $payload['providerServiceCode'] = $connection->getOption('provider_service_code');
        }
        $transaction = $this->unwrap($this->assertOk($this->jsonRequest($this->call('create_return', $connection, $request->reference), 'POST',
            self::BASE . '/shipments/' . rawurlencode($request->originalCarrierReference), $payload, $this->auth($connection), ['retries' => 0]),
            __('creating the return'))->json());
        return $this->fromTransaction($connection, $transaction, $request->reference);
    }

    /**
     * @param array<string, mixed> $transaction
     */
    public function fromTransaction(ConnectionInterface $connection, array $transaction, string $reference): ShipmentResult
    {
        $shipment = (array)($transaction['shipment'] ?? $transaction);
        $id = (string)($shipment['id'] ?? '');
        $number = (string)($shipment['trackingNumber'] ?? $shipment['barcode'] ?? '');
        $url = (string)($shipment['labelURL'] ?? '');
        if ($id === '' || $url === '') {
            throw new CarrierException(__('Geliver did not return a label.'));
        }
        if (!str_starts_with($url, 'https://')) {
            throw new CarrierException(__('Geliver returned an unexpected label link.'));
        }
        $label = $this->context->http->request($this->call('get_label', $connection, $reference), 'GET', $url, ['Accept' => 'application/pdf']);
        $this->assertOk($label, __('downloading the label'));
        return new ShipmentResult($id, array_filter([$number !== '' ? $number : (string)($shipment['barcode'] ?? '')]),
            [new Label($label->body, Label::FORMAT_PDF)],
            array_filter(['tracking_url' => (string)($shipment['trackingUrl'] ?? ''), 'provider' => (string)($shipment['providerServiceCode'] ?? '')]));
    }

    public function cancelShipment(ConnectionInterface $connection, string $carrierReference, array $trackingNumbers): void
    {
        $response = $this->jsonRequest($this->call('cancel', $connection, $carrierReference), 'DELETE',
            self::BASE . '/shipments/' . rawurlencode($carrierReference), null, $this->auth($connection));
        $this->unwrap($this->assertOk($response, __('cancelling the shipment'))->json());
    }

    public function track(ConnectionInterface $connection, array $trackingNumbers, array $references = []): array
    {
        $results = [];
        foreach ($trackingNumbers as $number) {
            $id = $references[$number] ?? '';
            if ($id === '') {
                continue;
            }
            $response = $this->jsonRequest($this->call('track', $connection, (string)$number), 'GET',
                self::BASE . '/shipments/' . rawurlencode($id), null, $this->auth($connection));
            $result = $this->parseTracking((string)$number, $this->unwrap($this->assertOk($response, __('tracking'))->json()));
            if ($result) {
                $results[(string)$number] = $result;
            }
        }
        return $results;
    }

    /**
     * @param array<string, mixed> $shipment GET /shipments/{id} data
     */
    public function parseTracking(string $number, array $shipment): ?TrackingResult
    {
        $tracking = (array)($shipment['trackingStatus'] ?? []);
        $code = strtoupper((string)($tracking['trackingStatusCode'] ?? ''));
        if ($code === '') {
            return null;
        }
        $status = match ($code) {
            'PRE_TRANSIT', 'UNKNOWN' => TrackingResult::STATUS_PENDING,
            'TRANSIT' => strtoupper((string)($tracking['trackingSubStatusCode'] ?? '')) === 'OUT_FOR_DELIVERY'
                ? TrackingResult::STATUS_OUT_FOR_DELIVERY : TrackingResult::STATUS_IN_TRANSIT,
            'DELIVERED' => TrackingResult::STATUS_DELIVERED,
            'RETURNED' => TrackingResult::STATUS_RETURNED,
            'FAILURE' => TrackingResult::STATUS_EXCEPTION,
            default => TrackingResult::STATUS_IN_TRANSIT,
        };
        $time = $this->toUtc((string)($tracking['statusDate'] ?? $tracking['updatedAt'] ?? ''), 'Europe/Istanbul');
        $event = new TrackingEvent($time, $status, trim((string)($tracking['statusDetails'] ?? $code . ' ' . ($tracking['trackingSubStatusCode'] ?? ''))),
            $code, (string)($tracking['locationName'] ?? ''));
        return new TrackingResult($number, $status, [$event], $status === TrackingResult::STATUS_DELIVERED ? $time : '');
    }

    /**
     * @param array<string, mixed> $json
     * @return array<string, mixed>
     */
    private function unwrap(array $json): array
    {
        if (array_key_exists('result', $json) && $json['result'] === false) {
            throw new CarrierException(__('Geliver: %1', trim((string)($json['message'] ?? '') . ' ' . (string)($json['additionalMessage'] ?? ''))));
        }
        return array_key_exists('data', $json) && is_array($json['data']) ? $json['data'] : $json;
    }

    /**
     * @return array<string, string>
     */
    private function address(Address $address): array
    {
        $province = $address->region !== '' ? $address->region : $address->city;
        return array_filter([
            'name' => $this->clean($address->name, 100),
            'email' => $address->email,
            'phone' => $this->phone($address->phone),
            'address1' => $this->clean($address->getStreetFull(), 250),
            'countryCode' => $address->countryId,
            'cityName' => $this->clean($province, 50),
            'cityCode' => TurkishProvinces::code($province),
            'districtName' => $this->clean($address->region !== '' ? $address->city : '', 50),
            'zip' => $this->clean($address->postcode, 10),
        ], static fn (string $v): bool => $v !== '');
    }

    private function phone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (strlen($digits) === 10) {
            $digits = '90' . $digits;
        } elseif (strlen($digits) === 11 && str_starts_with($digits, '0')) {
            $digits = '90' . substr($digits, 1);
        }
        return $digits !== '' ? '+' . $digits : '';
    }

    private function requireOption(ConnectionInterface $connection, string $code, string $label): string
    {
        $value = $connection->getOption($code);
        if ($value === '') {
            throw new CarrierException(__('%1: "%2" is not set in the carrier connection "%3".', $this->getTitle(), $label, $connection->getName()));
        }
        return $value;
    }

    /**
     * @return array<string, string>
     */
    private function auth(ConnectionInterface $connection): array
    {
        return ['Authorization' => 'Bearer ' . $this->credential($connection, 'api_token', 'API token')];
    }
}
