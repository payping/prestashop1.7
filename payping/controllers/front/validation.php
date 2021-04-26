<?php
/**
 * PayPing - A Payment Module for PrestaShop 1.7
 *
 * Order Validation Controller
 *
 * @author Mahdi Sarani <sarani@payping.ir>
 * @license https://opensource.org/licenses/afl-3.0.php
 */

class PayPingValidationModuleFrontController extends ModuleFrontController{

    /** @var array Controller errors */
    public $errors = [];

    /** @var array Controller warning notifications */
    public $warning = [];

    /** @var array Controller success notifications */
    public $success = [];

    /** @var array Controller info notifications */
    public $info = [];


    /**
     * set notifications on SESSION
     */
    public function notification(){

        $notifications = json_encode([
            'error' => $this->errors,
            'warning' => $this->warning,
            'success' => $this->success,
            'info' => $this->info,
        ]);

        if (session_status() == PHP_SESSION_ACTIVE) {
            $_SESSION['notifications'] = $notifications;
        } elseif (session_status() == PHP_SESSION_NONE) {
            session_start();
            $_SESSION['notifications'] = $notifications;
        } else {
            setcookie('notifications', $notifications);
        }


    }

    /**
     * register order and request to api
     */
    public function postProcess(){

        /**
         * Get current cart object from session
         */
        $cart = $this->context->cart;
        $authorized = false;

        /** @var CustomerCore $customer */
        $customer = new Customer($cart->id_customer);
		
        /**
         * Verify if this module is enabled and if the cart has
         * a valid customer, delivery address and invoice address
         */
        if (!$this->module->active || $cart->id_customer == 0 || $cart->id_address_delivery == 0 || $cart->id_address_invoice == 0) {
            Tools::redirect('index.php?controller=order&step=1');
        }

        /**
         * Verify if this payment module is authorized
         */
        foreach (Module::getPaymentModules() as $module){
            if ($module['name'] == 'payping') {
                $authorized = true;
                break;
            }
        }
		
        if(!$authorized){
            $this->errors[] = 'This payment method is not available.';
            $this->notification();
        }
		
        /**
         * Check if this is a vlaid customer account
         */
        if(!Validate::isLoadedObject($customer)){
            Tools::redirect('index.php?controller=order&step=1');
        }
		
		//call callBack function
        if( isset($_GET['do']) && $_GET['do'] == 'callback' ){
			if( isset( $_POST['clientrefid'] ) && !empty( $_POST['clientrefid'] ) ){
				$orderId = $_POST['clientrefid'];
				$verify_order_id = $_POST['clientrefid'];
			}else{
				$orderId = $_GET['clientrefid'];
				$verify_order_id = $_GET['clientrefid'];
			}
			if( isset( $_POST['refid'] ) && !empty( $_POST['refid'] ) ){
				$refId = $_POST['refid'];
			}else{
				$refId = $_GET['refid'];
			}
			if( isset( $_POST['hash'] ) && !empty( $_POST['hash'] ) ){
				$hash = $_POST['hash'];
			}else{
				$hash = $_GET['hash'];
			}
			$order = new Order((int)$orderId);
			$amount = intval( $order->total_paid );
			if(Configuration::get('payping_currency') == "toman"){
            	$amount *= 10;
        	}
            $order_id = $orderId;
			/* start verify */
			if( md5($amount.$order_id ) == $hash ){
				
				$arrgs = array('refId' => $refId , 'amount' => $amount);
				$curl = curl_init('https://api.payping.ir/v2/pay/verify');
				curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($arrgs));
				curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
				curl_setopt($curl, CURLOPT_HTTPHEADER, array(
					"Accept: application/json",
					"Content-Type: application/json",
					"Authorization: Bearer ". Configuration::get('payping_api_key')
				));
				$response = curl_exec($curl);
				$response = json_decode( $response, true );
				$http_status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
				curl_close($curl);
				
				if( $http_status == 200 ){
					$msgso = $this->otherStatusMessages(200) . "شناسه پیگیری:  $refId";
					$this->saveOrder($msgso, 2, $order_id);
					$this->success[] = $this->payping_get_success_message($refId, $order_id, 200);
					$this->notification();

					/**
					 * Redirect the customer to the order confirmation page
					 */
					Tools::redirect('index.php?controller=order-confirmation&id_cart=' . (int)$order->id_cart . '&id_module='.(int)$this->module->id.'&id_order=' . $order_id . '&key=' . $customer->secure_key);
				}else{
					echo 'خطا در تایید پرداخت!';
					Tools::redirect('index.php?controller=order-confirmation');
				}
			}else{
				echo 'خطای ناشناخته برای اعتبارسنجی!';
				Tools::redirect('index.php?controller=order-confirmation');
			}
			/* end verify */
        }
		
        $this->module->validateOrder(
            (int)$this->context->cart->id,
            13,
            (float)$this->context->cart->getOrderTotal(true, Cart::BOTH),
            "PayPing",
            null,
            null,
            (int)$this->context->currency->id,
            false,
            $customer->secure_key
        );
		
        //get order id
        $sql = ' SELECT  `id_order`  FROM `' . _DB_PREFIX_ . 'orders` WHERE `id_cart` = "' . $cart->id . '"';
        $order_id = Db::getInstance()->executeS($sql);
        $order_id = $order_id[0]['id_order'];

        $sandbox = Configuration::get('payping_sandbox') == 'yes' ? 'true' : 'false';
        $amount = $cart->getOrderTotal();
        if(Configuration::get('payping_currency') == "toman"){
            $amount *= 10;
        }

        // Customer information
        $details = $cart->getSummaryDetails();
        $delivery = $details['delivery'];
        $name = $delivery->firstname . ' ' . $delivery->lastname;
        $phone = $delivery->phone_mobile;

        if(empty($phone_mobile)){
            $phone = $delivery->phone;
        }

        // There is not any email field in the cart details.
        // So we gather the customer email from this line of code:
        $mail = Context::getContext()->customer->email;
		$url = Context::getContext()->link->getModuleLink(
			'payping',
			'validation',
			array(),
			null,
			null,
			Configuration::get('PS_SHOP_DEFAULT')
		);
        $desc = 'پرداخت سفارش شماره: '.$cart->id;
		$hash = md5( $amount.$order_id );
        $callback = $url.'?do=callback&hash='.$hash;
		
        if( empty($amount) ){
            $this->errors[] = $this->otherStatusMessages(404);
            $this->notification();
            Tools::redirect('index.php?controller=order-confirmation');
        }
		
		$amount = $amount;
		$callbackUrl = $callback;
		$orderId = $order_id;
		$Description = $desc;
		$arrgs = array( 
							'amount'        => $amount,
							'payerName'     => $name,
							'payerIdentity' => $phone,
							'returnUrl'     => $callbackUrl,
							'Description'   => $Description,
							'clientRefId'   => $orderId 
						);
		try{
			$curl = curl_init('https://api.payping.ir/v2/pay');
			curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($arrgs));
			curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
			curl_setopt($curl, CURLOPT_HTTPHEADER, array(
				"Accept: application/json",
				"Content-Type: application/json",
				"Authorization: Bearer ". Configuration::get('payping_api_key')
			));
			$response = curl_exec($curl);
			$response = json_decode( $response, true );
			$http_status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
			curl_close($curl);
			$ppPayCode = $response['code'];

			$msg = [
				'CodePay' => $ppPayCode,
				'Message' => "در انتظار پرداخت",
			];
			$msg = json_encode($msg, JSON_UNESCAPED_UNICODE);
			$sql = ' UPDATE `' . _DB_PREFIX_ . 'orders` SET `current_state` = "' . 13 . '", `payment` = ' . "'" . $msg . "'" . ' WHERE `id_order` = "' . $order_id . '"';
			Db::getInstance()->Execute($sql);
			
			if ($http_status == 200){

				if( isset( $ppPayCode ) and $ppPayCode != '') {
					header("Location: https://api.payping.ir/v2/pay/gotoipg/".$ppPayCode);
					exit;
				}else{
					echo 'خطایی در دریافت کد پرداخت وجود دارد.';
				}
			} elseif ($http_status == 400) {
				echo 'خطای اتصال به وبسرویس'. implode('. ',array_values (json_decode($response,true))) ;
			} else {
				echo 'خطا در هنگام اتصال به بانک'.$this->otherStatusMessages($http_status).'(' . $http_status . ')';
			}
		}catch(Exception $e){
			echo 'خطای وبسرویس'.$e->getMessage();
		}
    }
    /**
     * @param $msgForSaveDataTDataBase
     * @param $paymentStatus
     * @param $order_id
     * 13 for waiting ,8 for payment error and Configuration::get('PS_OS_PAYMENT') for payment is OK
     */
    public function saveOrder($msgForSaveDataTDataBase, $paymentStatus, $order_id){

        $sql = 'SELECT payment FROM `' . _DB_PREFIX_ . 'orders` WHERE id_order  = "' . $order_id . '"';
        $payment = Db::getInstance()->executes($sql);

        $payment = json_decode($payment[0]['payment'], true);
        $payment['msg'] = $msgForSaveDataTDataBase;
        $data = json_encode($payment, JSON_UNESCAPED_UNICODE);
        $sql = ' UPDATE `' . _DB_PREFIX_ . 'orders` SET `current_state` = "' . $paymentStatus .
            '", `payment` = ' . "'" . $data . "'" .
            ' WHERE `id_order` = "' . $order_id . '"';

        Db::getInstance()->Execute($sql);
    }

    /**
     * @param $track_id
     * @param $order_id
     * @param null $msgNumber
     * @return string
     */
    function payping_get_success_message($track_id, $order_id, $msgNumber = null){
        $msg = $this->otherStatusMessages($msgNumber);
        return str_replace(["{track_id}", "{order_id}"], [$track_id, $order_id], Configuration::get('payping_success_massage')) . "<br>" . $msg;
    }

    /**
     * @param $track_id
     * @param $order_id
     * @param null $msgNumber
     * @return mixed
     */
    public function payping_get_failed_message($track_id, $order_id, $msgNumber = null){
        $msg = $this->otherStatusMessages($msgNumber);
        return str_replace(["{track_id}", "{order_id}"], [$track_id, $order_id], Configuration::get('payping_failed_massage') . "<br>" . $msg);

    }

    /**
     * @param $msgNumber
     * @get status from $_POST['status]
     * @return string
     */
    public function otherStatusMessages($msgNumber = null){
        switch ($msgNumber) {
            case 200:
                $msg = "پرداخت موفق";
                break;
            case 400:
                $msg = "پرداخت ناموفق بوده است";
                break;
            case 500:
                $msg = "خطا رخ داده است";
                break;
            case 401:
                $msg = "عدم دسترسی";
                break;
            case null:
                $msg = "خطا دور از انتظار";
                $msgNumber = '1000';
                break;
        }
        return $msg . ' -وضعیت: ' . "$msgNumber";
    }
}

function dd( $res ){
	echo '<pre dir="ltr">';
	var_dump( $res );
	die();
}