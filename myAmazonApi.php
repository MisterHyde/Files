<?php

$amazon = new MyAmazonApi();

$orders = $amazon->getOrders(1,2);
print_r($orders);

class MyAmazonApi
{
	private $baseUrl = "";
	private $baseParam = array();
	private $actions = array();

	/**
	 * Update the quantity of an item specified throu $sku
	 * @param string $sku Identifier of the item
	 * @param string $update Value of the new quantity
	 */
	function updateInventory($sku, $upate)
	{
		$param = $this->baseParam;
		$param[] = "FeedType=_POST_INVENTORY_AVAILABILITY_DATA_";

		// If you like to update more than one quantity in one call just add another message with continuous MessageID
		// The new quantity overrides the old one
		$xml = '<?xml version="1.0" encoding="utf-8" ?>
			<AmazonEnvelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="amznenvelope.xsd">
			<Header>
				<DocumentVersion>1.01</DocumentVersion>
				<MerchantIdentifier>M_SELLER_354577</MerchantIdentifier>
			</Header>
			<MessageType>Inventory</MessageType>
			<Message>
				<MessageID>1</MessageID>
				<OperationType>Update</OperationType>
				<Inventory>
					<SKU>' . $sku . '</SKU>
					<Quantity>' . $update . '</Quantity>
					<!-- The time between oder date and ship date -->
					<FulfillmentLatency>3</FulfillmentLatency>
				</Inventory>
			</Message>
			</AmazonEnvelope>';
	}

	/**
	 * Tell Amazon that the specified order is shipped
	 * @param string $orderId The AmazonOrderID
	 * @param string $carrier The carrier of the shipping
	 * @param string $trackingId Tracking ID
	 * @param string $methode A methode from amazon for the shipping?
	 * @param string $merchantFulfillmentID A seller supplied ID for the shipment
	 */
	function setShipping($orderId, $carrier, $trackingId="", $methode="")
	{
		$param = $this->baseParam;
		$param[] = "FeedType=_POST_ORDER_FULFILLMENT_DATA_";

		$xml = '<?xml version="1.0" encoding="UTF-8"?>
			<AmazonEnvelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="amznenvelope.xsd">
			<Header>
				<DocumentVersion>1.01</DocumentVersion>
				<MerchantIdentifier>My Store</MerchantIdentifier>
			</Header>
			<MessageType>OrderFulfillment</MessageType>
			<Message>
				<MessageID>1</MessageID>
				<OrderFulfillment>
					<MerchantOrderID>1234567</MerchantOrderID>
					<!-- Seller-supplied unique identifier for the shipment -->
					<MerchantFulfillmentID>1234567</MerchantFulfillmentID>
					<FulfillmentDate>2002-05-01T15:36:33-08:00</FulfillmentDate>
					<FulfillmentData>
						<!-- Instead of the CarrierCode one can use CarrierName if the code is not supplied -->
						<CarrierCode>UPS</CarrierCode>
						<ShippingMethod>Second Day</ShippingMethod>
						<ShipperTrackingNumber>1234567890</ShipperTrackingNumber>
					</FulfillmentData>
					<!-- I guess it is not required if the AmazonOrderID is set -->
					<Item>
						<MerchantOrderItemID>1234567</MerchantOrderItemID>
						<MerchantFulfillmentItemID>1234567</MerchantFulfillmentItemID>
						<Quantity>2</Quantity>
					</Item>
				</OrderFulfillment>
			</Message>
			</AmazonEnvelope>';
	}

	function getOrders($from, $to)
	{
		//...
		$request = array();
		$response = $this->sendApiCall($request, "ListOrders");
		//print_r($response);
		foreach($response["ListOrdersResult"]["Orders"]["Order"] as $order){
			if($order["OrderStatus"] == "Unshipped"){
				//print_r($order);
				$orderItems = $this->getOrderItems($order["AmazonOrderId"]);
				$warenkorb = $this->amazonOrderToWaWi($order, $orderItems);
			}
		}

		return $warenkorb;
	}

	function getOrderItems($amazonOrderId)
	{
		//...
		$request = "";
		//$response = $this->sendApiCall($request, "ListOrderItems");
		$response = json_decode(json_encode(simplexml_load_file("src/ListOrderItems.xml")), True);
		$orderItems = array();
		foreach($response["ListOrderItemsResult"]["OrderItems"]["OrderItem"] as $orderItem){
			$orderItems[] = $orderItem;
		}

		while(!empty($response["ListOrderItemsResult"]["NextToken"])){
			$request = "";
			//$response = $this->sendApiCall($request, "ListOrderItemsByNextToken");
			$response = json_decode(json_encode(simplexml_load_file("src/ListOrderItemsByNextToken.xml")), True);
			foreach($response["ListOrderItemsByNextTokenResult"]["OrderItems"]["OrderItem"] as $orderItem){
				$orderItems[] = $orderItem;
			}
		}
		//echo "\nOrderItems:\n";
		//print_r($orderItems);
		return $orderItems;
	}

	private function sendApiCall($request, $action, $xml='')
	{
		//$request[] = $this->actions[$action]['Version'];
		$secret = "";

		$urlAr = array();
		foreach($request as $param => $val){
			$urlAr[] = str_replace("%7E", "~", rawurlencode($param)) . "=" . str_replace("%7E", "~", rawurlencode($val));
		}

		sort($urlAr);
		$url = implode("&", $urlAr);
		$http = "POST\n"
			. "mws.amazonservices.de\n"
			. "/Feeds/2009-01-01\n"
			. "$url\n";
		$signature = rawurlencode(hash_hmac("sha256", $http, $secret, True));
		$httpHeader = array();
		$httpHeader[] = 'Transfer-Encoding: chunked';
		$httpHeader[] = 'Content-Type: application/xml';
		$httpHeader[] = 'Content-MD5: ' . base64_encode(md5($xml, True));
		$httpHeader[] = 'Expect:';
		$httpHeader[] = 'Accept:';

		$ch = curl_init($this->baseUrl . "?$url");
		curl_setopt($ch, CURLOPT_HTTPHEADER, $httpHeader);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, True);
		if(!empty($xml)){
			curl_setopt($ch, CURLOPT_POST, True);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $xml);
		}

		//$responseXml = curl_exec($ch);
		$curlInfo = curl_getinfo($ch);
		$curlError = curl_error($ch);
		curl_close($ch);

		// Konvertiere die Antwort zu einem php Array
		//$xml = simplexml_load_string($responseXml);
		$xml = simplexml_load_file("src/ListOrdersResponse.xml");
		$json = json_encode($xml);
		return json_decode($json, True);
	}

	function amazonOrderToWaWi($order, $orderItems)
	{
		// Es ist möglich dass mehrere Payments angegeben sind
		//print_r($order);
		$money = 0.0;
		$currencyCode = "";
		foreach($order["PaymentExecutionDetail"]["PaymentExecutionDetailItem"] as $payment){
			$money += (double)$payment["Payment"]["Amount"];
			$currencyCode = $payment["Payment"]["CurrencyCode"];
		}

		$warenkorb = array();

		// Array zum mappen der Zahlungsweise
		$zahlungsweise = array('bar', 'rechnung', 'vorkasse', 'kreditkarte', 'lastschrift', 'paypal',
			'nachnahme', 'Amazoncba', 'sofortueberweisung', 'secupay', 'billsafe', 'unbekannt');

		$warenkorb['amazonOrderId'] = $order["AmazonOrderId"];
		$warenkorb['sellerOrderId'] = $order["SellerOrderId"];
		// Kann es sein das es bei Amazon keine Rechnungsadresse mehr gibt?
		$warenkorb['name'] = $order["BuyerName"];
		$warenkorb['email'] = $order["BuyerEmail"];
		$warenkorb['anrede'] = "";
		$warenkorb['ansprechpartner'] = "";
		$warenkorb['abteilung'] = "";
		$warenkorb['strasse'] = $order["ShippingAddress"]["AddressLine1"];
		// Wenn Adresszusätze vorhanden dann hänge diese an die Adresse an
		$warenkorb['strasse'] .= isset($order["ShippingAddress"]["AddressLine2"]) ? $order["ShippingAddress"]["AdressLine2"] : "";
		$warenkorb['strasse'] .= isset($order["ShippingAddress"]["AddressLine3"]) ? $order["ShippingAddress"]["AdressLine3"] : "";
		$warenkorb['plz'] = $order["ShippingAddress"]["PostalCode"];
		$warenkorb['ort'] = $order["ShippingAddress"]["City"];
		$warenkorb['land'] = $order["ShippingAddress"]["CountryCode"];
		$warenkorb['bestelldatum'] = $order["PurchaseDate"]; // Soll das das Datum sein, als die Bestellung bezahlt wurde?
		$warenkorb['gesamtsumme'] = $money . " " . $currencyCode; // Endsumme die gezahlt wird
		$warenkorb['transaktionsnummer'] = ""; // Paypal, iPayment oder Billsafe Nummer
		$warenkorb['onlinebestellnummer'] = $order["SellerOrderId"]; // Interne Shop-Bestellnummer
		$warenkorb['versandkostennetto'] = "";
		$warenkorb['versandkostenbrutto'] = "";
		$warenkorb['freitext'] = ""; // Freitext auf Belegen wie Auftrag, Rechnung und Lieferschein
		$warenkorb['steuerfrei'] = 0; // 0 wenn nicht steuerfrei; 1 wenn steuerfrei
		$warenkorb['vorabbezahltmarkieren'] = ""; // Falls kein Zahlungseingang von WaWision aus gemacht wird
		$warenkorb['lieferdatum'] = $order["LatestDeliveryDate"]; // Wunschlieferdatum des Kunden; Bei amazon angegebenes spätestes Lieferdatum
		$warenkorb['lieferung'] = ""; // Versandunternehmen
		$warenkorb2 = array();
		if(False/* Abweichende Lieferadresse*/){
			$warenkorb2['lieferadresse_name'] = "";
			$warenkorb2['lieferadresse_ansprechpartner'] = "";
			$warenkorb2['lieferadresse_strasse'] = "";
			$warenkorb2['lieferadresse_plz'] = "";
			$warenkorb2['lieferadresse_ort'] = "";
			$warenkorb2['lieferadresse_land'] = "";
			$warenkorb2['lieferadresse_abteilung'] = "";
		}

		$articleArray = array();
		foreach($orderItems as $orderItem){
			$articleArray[] = array(
				'articleid' => $orderItem["SellerSKU"],
				'name' => $orderItem["Title"],
				'price' => $orderItem["ItemPrice"]["Amount"], // Nettopreis des Artikels
				'quantity' => $orderItem["QuantityOrdered"] // - $orderItem["QuantityShipped"]
			);
		}

		$warenkorb['articlelist'] = $articleArray;

		foreach($warenkorb as $key => $value){
			if(!is_array($value)){
				$warenkorb[$key] = rawurldecode($value);
			}
		}

		return $warenkorb;
	}

	// Handles one OrderArray.Order out of the response of GetOrders
	// It seems that the ebay API works with xml attributes which are not recognized by my code
	function ebayOrderToWaWi($order)
	{
		$warenkorb = array();

		// Array zum mappen der Zahlungsweise
		$zahlungsweise = array('bar', 'rechnung', 'vorkasse', 'kreditkarte', 'lastschrift', 'paypal',
			'nachnahme', 'Amazoncba', 'sofortueberweisung', 'secupay', 'billsafe', 'unbekannt');

		$warenkorb['name'] = $order[""];
		$warenkorb['email'] = $order[""];
		$warenkorb['anrede'] = "";
		$warenkorb['ansprechpartner'] = "";
		$warenkorb['abteilung'] = "";
		$warenkorb['strasse'] = "";
		$warenkorb['plz'] = "";
		$warenkorb['ort'] = "";
		$warenkorb['land'] = "";
		$warenkorb['bestelldatum'] = $order["CheckoutStatus"]["LastModifiedTime"];
		$warenkorb['gesamtsumme'] = $order["AmountPaid"]; // Endsumme die gezahlt wird
		$warenkorb['transaktionsnummer'] = ""; // Paypal, iPayment oder Billsafe Nummer
		$warenkorb['onlinebestellnummer'] = ""; // Interne Shop-Bestellnummer
		$warenkorb['versandkostennetto'] = "";
		$warenkorb['versandkostenbrutto'] = "";
		$warenkorb['freitext'] = ""; // Freitext auf Belegen wie Auftrag, Rechnung und Lieferschein
		$warenkorb['steuerfrei'] = ""; // 0 wenn nicht steuerfrei; 1 wenn steuerfrei
		$warenkorb['vorabbezahltmarkieren'] = ""; // Falls kein Zahlungseingang von WaWision aus gemacht wird
		$warenkorb['lieferdatum'] = ""; // Wunschlieferdatum des Kunden
		$warenkorb['lieferung'] = ""; // Versandunternehmen
		$warenkorb2 = array();
		if(False/* Abweichende Lieferadresse*/){
			$warenkorb2['lieferadresse_name'] = "";
			$warenkorb2['lieferadresse_ansprechpartner'] = "";
			$warenkorb2['lieferadresse_strasse'] = "";
			$warenkorb2['lieferadresse_plz'] = "";
			$warenkorb2['lieferadresse_ort'] = "";
			$warenkorb2['lieferadresse_land'] = "";
			$warenkorb2['lieferadresse_abteilung'] = "";
		}

		$articleArray = array();
		//foreach( as )
			//$articleArray[] = array(
				//'articleid' => "",
				//'name' => "",
				//'price' => "", // Nettopreis des Artikels
				//'quantity' => ""
			//);

		$warenkorb['articlelist'] = $articleArray;
	}
}

?>