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
            /**
             * Redirect the customer to the order confirmation page
             */
            Tools::redirect('index.php?controller=order-confirmation&id_cart=' . (int)$cart->id . '&id_module=' . (int)$this->module->id . '&id_order=' . $this->module->currentOrder . '&key=' . $customer->secure_key);
        }

        /**
         * Check if this is a vlaid customer account
         */
        if(!Validate::isLoadedObject($customer)){
            Tools::redirect('index.php?controller=order&step=1');
        }


        //call callBack function
        if(isset($_GET['do'])){
            $this->callBack($customer);
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

        $api_key = Configuration::get('payping_api_key');
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

        $desc = $Description = 'پرداخت سفارش شماره: ' . $cart->id;
        $url = $this->context->link->getModuleLink('payping', 'validation', array(), true);
        $callback = $url . '?do=callback&hash=' . md5($amount . $order_id . Configuration::get('payping_HASH_KEY'));

        if(empty($amount)){
            $this->errors[] = $this->otherStatusMessages(404);
            $this->notification();
            Tools::redirect('index.php?controller=order-confirmation');
        }

		$amount = $amount;
		$callbackUrl = $callback;
		$orderId = $order_id;
		$Description = $desc;
		try{
			$curl = curl_init();
			curl_setopt_array($curl, array(
					CURLOPT_URL => "https://api.payping.ir/v2/pay",
					CURLOPT_RETURNTRANSFER => true,
					CURLOPT_ENCODING => "",
					CURLOPT_MAXREDIRS => 10,
					CURLOPT_TIMEOUT => 30,
					CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
					CURLOPT_CUSTOMREQUEST => "POST",
					CURLOPT_POSTFIELDS => json_encode(array('Amount' => $amount, 'returnUrl' => $callbackUrl, 'Description' => $Description , 'clientRefId' => $orderId )),
					CURLOPT_HTTPHEADER => array(
						"accept: application/json",
						"authorization: Bearer " . Configuration::get('payping_api_key'),
						"cache-control: no-cache",
						"content-type: application/json"),
				)
			);
			$response = curl_exec($curl);
			$header = curl_getinfo($curl);
			$err = curl_error($curl);
			curl_close($curl);
			
			$msg = [
				'payping_id' => $response['code'],
				'msg' => "در انتظار پرداخت...",
			];
			$msg = json_encode($msg, JSON_UNESCAPED_UNICODE);
			$sql = ' UPDATE `' . _DB_PREFIX_ . 'orders` SET `current_state` = "' . 13 . '", `payment` = ' . "'" . $msg . "'" . ' WHERE `id_order` = "' . $order_id . '"';
			Db::getInstance()->Execute($sql);
			
			if ($err) {
				echo "cURL Error #:" . $err;
			} else {
				if ($header['http_code'] == 200) {
					$response = json_decode($response, true);
					if (isset($response["code"]) and $response["code"] != '') {
						echo $this->success($this->l('Redirecting...'));
						echo '<script>window.location=("https://api.payping.ir/v2/pay/gotoipg/' . $response['code'] . '");</script>';
						exit;
					} else {
						echo $this->error($this->l('There is a problem in get code.').$e->getMessage());
					}
				} elseif ($header['http_code'] == 400) {
					echo $this->error($this->l('There is a problem.'). implode('. ',array_values (json_decode($response,true)))) ;
				} else {
					echo $this->error($this->l('There is a problem.').$this->status_message($header['http_code']) . '(' . $header['http_code'] . ')' );
				}
			}
		}catch(Exception $e){
			echo $this->error($this->l('There curl is a problem.').$e->getMessage());
		}
    }

    /**
     * @param $customer
     */
    public function callBack($customer){
		if( isset( $_POST['clientrefid'] ) && !empty( $_POST['clientrefid'] ) ){
			$orderId = $_POST['clientrefid'];
			$verify_order_id = $_POST['clientrefid'];
		}else{
			$orderId = $_GET['clientrefid'];
			$verify_order_id = $_GET['clientrefid'];
		}
		if( isset( $_POST['refid'] ) && !empty( $_POST['refid'] ) ){
			$orderId = $_POST['refid'];
		}else{
			$orderId = $_GET['refid'];
		}
		if( isset( $_POST['amount'] ) && !empty( $_POST['amount'] ) ){
			$orderId = $_POST['amount'];
		}else{
			$orderId = $_GET['amount'];
		}
		if( isset( $_POST['hash'] ) && !empty( $_POST['hash'] ) ){
			$orderId = $_POST['hash'];
		}else{
			$orderId = $_GET['hash'];
		}
		$order_id = $orderId;
		if( md5($amount.$orderId.Configuration::get('payping_HASH_KEY') ) == $hash ){
			$curl = curl_init();
			curl_setopt_array($curl, array(
				CURLOPT_URL => "https://api.payping.ir/v2/pay/verify",
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_ENCODING => "",
				CURLOPT_MAXREDIRS => 10,
				CURLOPT_TIMEOUT => 45,
				CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
				CURLOPT_CUSTOMREQUEST => "POST",
				CURLOPT_POSTFIELDS => json_encode(array('refId' => $refId , 'amount' => $amount)),
				CURLOPT_HTTPHEADER => array(
					"accept: application/json",
					"authorization: Bearer ".Configuration::get('payping_api_key'),
					"cache-control: no-cache",
					"content-type: application/json",
				),
			));
			$response = curl_exec($curl);
			$err = curl_error($curl);
			$header = curl_getinfo($curl);
			curl_close($curl);

			if($err){
				$Status = 'failed';
				$Fault = 'Curl Error.';
				echo $Message = 'خطا در ارتباط به پی‌پینگ : شرح خطا '.$err;
			}elseif( $header['http_code'] == 200 ){
				//check double spending
				$sql = 'SELECT JSON_EXTRACT(payment, "$.payping_id") FROM `' . _DB_PREFIX_ . 'orders` WHERE id_order  = "' . $order_id . '" AND JSON_EXTRACT(payment, "$.payping_id")   = "' . $order_id . '"';
				$exist = Db::getInstance()->executes($sql);
				if($verify_order_id !== $order_id or count($exist) == 0){
					$msgForSaveDataTDataBase = $this->otherStatusMessages(0) . "کد پیگیری: $refId";
					$this->saveOrder($msgForSaveDataTDataBase, 8, $order_id);
					$msg = $this->payping_get_failed_message($refId, $verify_order_id, 0);
					$this->errors[] = $msg;
					$this->notification();
					Tools::redirect('index.php?controller=order-confirmation');
				}

				if(Configuration::get('payping_currency') == "toman"){
					$amount /= 10;
				}

				$msgForSaveDataTDataBase = $this->otherStatusMessages(200) . "کد پیگیری:  $refId";
				$this->saveOrder($msgForSaveDataTDataBase,Configuration::get('PS_OS_PAYMENT'),$order_id);

				$this->success[] = $this->payping_get_success_message($refId, $verify_order_id, 200);
				$this->notification();
				/**
				 * Redirect the customer to the order confirmation page
				 */
				Tools::redirect('index.php?controller=order-confirmation&id_cart=' . (int)$order->id_cart . '&id_module=' . (int)$this->module->id . '&id_order=' . $this->module->currentOrder . '&key=' . $customer->secure_key);
			}else{
				echo $payping -> error($payping -> l('There is a problem.'));
			}
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
