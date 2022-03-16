<?php
defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

JLoader::import( 'adapter.payment.payment' );

require VIKSSLCOMMERZ_DIR . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'SslCommerzNotification.php';

class AbstractSslCommerzPayment extends JPayment {
	public function __construct( $alias, $order, $params = array() ) {
		parent::__construct( $alias, $order, $params );
	}

	protected function buildAdminParameters(): array {
		$logo_img = VIKSSLCOMMERZ_URI . 'sslcommerz.png';

		return [
			'logo'         => [
				'label' => __( '', 'vikbooking' ),
				'type'  => 'custom',
				'html'  => '<img src="' . $logo_img . '"/>'
			],
			'sandbox'      => [
				'label'   => __( 'Test Mode', 'vikbooking' ),
				'type'    => 'select',
				'options' => [ 'Yes', 'No' ],
			],
			'store_id'     => [
				'label' => __( 'Store ID', 'vikbooking' ),
				'type'  => 'text',
			],
			'store_passwd' => [
				'label' => __( 'Store Password', 'vikbooking' ),
				'type'  => 'text',
			],
		];
	}

	protected function doRefund( JPaymentStatus &$status ) {
		parent::doRefund( $status ); // TODO: Change the autogenerated stub
	}

	protected function beginTransaction() {

		$sslcommerz_url  = $this->getParam( 'sandbox' ) === 'Yes' ? "https://sandbox.sslcommerz.com" : "https://securepay.sslcommerz.com";
		$details         = $this->get( 'details' );
		$customer_values = $this->prepareCustomerData( $details['custdata'] );
		$post_data       = [];
//Integration Required Parameters
		$post_data['store_id']     = $this->getParam( 'store_id' );
		$post_data['store_passwd'] = $this->getParam( 'store_passwd' );
		$post_data['total_amount'] = $this->get( 'total_to_pay' );
		$post_data['currency']     = $this->get( 'transaction_currency' );
		$post_data['tran_id']      = $this->get( 'sid' ) . "-" . $this->get( 'ts' );
		$post_data['success_url']  = JUri::getInstance( $this->get( 'notify_url' ) );
		$post_data['fail_url']     = JUri::getInstance( $this->get( 'notify_url' ) );
		$post_data['cancel_url']   = JUri::getInstance( $this->get( 'notify_url' ) );
//Customer Information
		$post_data['cus_name']     = $customer_values['Name'] . ' ' . $customer_values['Last Name'];
		$post_data['cus_email']    = $this->get( 'customer_email' );
		$post_data['cus_add1']     = $customer_values['Address'];
		$post_data['cus_city']     = $customer_values['City'];
		$post_data['cus_country']  = $customer_values['Country'];
		$post_data['cus_phone']    = $customer_values['Phone'] ?? '';
		$post_data['cus_postcode'] = $customer_values['Zip Code'] ?? '';
//Shipment Information
		$post_data['shipping_method'] = 'No';
		$post_data['num_of_item']     = $details['roomsnum'];
//Product Information
		$post_data['product_name']     = $this->order->rooms_name;
		$post_data['product_category'] = 'ecommerce';
		$post_data['product_profile']  = 'general';
		$sslCommerzN                   = new SslCommerzNotification( $post_data['store_id'], $post_data['store_passwd'], $sslcommerz_url );
		$sslCommerzN->makePayment( $post_data, 'hosted' );
	}

	private function prepareCustomerData( $customer_data ): array {
		$customer_data_parts = explode( "\n", $customer_data );
		$customer_values     = array();
		if ( str_contains( $customer_data_parts[0], ':' ) && str_contains( $customer_data_parts[1], ':' ) ) {
			foreach ( $customer_data_parts as $custdet ) {
				if ( $custdet === '' ) {
					continue;
				}
				$customer_det_parts = explode( ':', $custdet );
				if ( count( $customer_det_parts ) >= 2 ) {
					$key = $customer_det_parts[0];
					unset( $customer_det_parts[0] );
					$customer_values[ $key ] = trim( implode( ':', $customer_det_parts ) );
				}
			}
		}

		return $customer_values;
	}

	protected function validateTransaction( JPaymentStatus &$status ): bool {
		$sslcommerz_url                  = $this->getParam( 'sandbox' ) === 'Yes' ? "https://sandbox.sslcommerz.com" : "https://securepay.sslcommerz.com";
		$sslv                            = new SslCommerzNotification( $this->getParam( 'store_id' ), $this->getParam( 'store_passwd' ), $sslcommerz_url );
		$tran_id                         = $_POST['tran_id'];
		$bank_tran_id                    = $_POST['bank_tran_id'];
		$amount                          = $_POST['amount'];
		$currency                        = $_POST['currency'];
		$_POST['connect_from_localhost'] = false;
		if ($_POST['status']=='VALID'){
			$validated                       = $sslv->orderValidate( $tran_id, $amount, $currency, $_POST );
			if ( $validated ) {
				$status->verified();
				$status->paid( $amount );
				$status->setData( 'TransactionId', $tran_id );
				$status->setData( 'merchantOrderId', $bank_tran_id );
				$status->appendLog( "Successful payment" );
			} else {
				$status->setData( 'TransactionId', $tran_id );
				$status->setData( 'merchantOrderId', $bank_tran_id );
				$status->appendLog(  "Failure payment" );
				$status->appendLog( ( "Failure Reason	" . ( isset( $_POST['failedreason'] ) ) ) ? $_POST['failedreason'] : $_POST['error'] );
			}
		}
		$status->appendLog( 'STATUS:- ' . $_POST['status'] );
		$status->appendLog( "Transaction ID:- " . $tran_id );
		$status->appendLog( "Transaction Time:- " . $_POST['tran_date'] );
		$status->appendLog( "Payment Method:- " . $_POST['card_issuer'] );
		$status->appendLog( "Bank Transaction ID:- " . $_POST['bank_tran_id'] );
		$status->appendLog( "Amount:- " . $_POST['amount'] . ' ' . $_POST['currency'] );

		return true;
	}

	protected function complete( $res = 0 ) {
		$app = JFactory::getApplication();
		if ( $res ) {
			$url = $this->get( 'return_url' );
			$app->enqueueMessage( __( 'Thank you! Payment successfully received.', 'viksslcommerz' ) );
		} else {
			$url = $this->get( 'error_url' );
			$app->enqueueMessage( __( 'It was not possible to verify the payment. Please, try again.', 'viksslcommerz' ) );
		}
		JFactory::getApplication()->redirect( $url );
		exit;
	}
}


