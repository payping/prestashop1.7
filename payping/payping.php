<?php
/**
 * PayPing - A Payment Module for PrestaShop 1.7
 *
 * This file is the declaration of the module.
 *
 * @author Mahdi Sarani <sarani@payping.ir>
 * @license https://opensource.org/licenses/afl-3.0.php
 */
if (!defined('_PS_VERSION_')){
    exit;
}

class PayPing extends PaymentModule{

    private $_html = '';
    private $_postErrors = array();

    public $address;

    /**
     * PayPing constructor.
     *
     * Set the information about this module
     */
    public function __construct(){
        $this->name = 'payping';
        $this->tab = 'payments_gateways';
        $this->version = '2.0';
        $this->author = 'PayPing';
        $this->controllers = array('payment', 'validation');
        $this->currencies = true;
        $this->currencies_mode = 'checkbox';
        $this->bootstrap = true;
        $this->displayName = 'PayPing';
        $this->description = 'درگاه پرداخت پی‌پینگ';
        $this->confirmUninstall = 'Are you sure you want to uninstall this module?';
        $this->ps_versions_compliancy = array('min' => '1.7.0', 'max' => _PS_VERSION_);
        parent::__construct();
    }

    /**
     * Install this module and register the following Hooks:
     *
     * @return bool
     */
    public function install(){
        return parent::install()
            && $this->registerHook('paymentOptions')
            && $this->registerHook('paymentReturn');
    }

    /**
     * Uninstall this module and remove it from all hooks
     *
     * @return bool
     */
    public function uninstall(){
        return parent::uninstall();
    }

    /**
     * Returns a string containing the HTML necessary to
     * generate a configuration screen on the admin
     *
     * @return string
     */
    public function getContent(){

        if (Tools::isSubmit('payping_submit')) {
            Configuration::updateValue('payping_api_key', $_POST['payping_api_key']);
            Configuration::updateValue('payping_sandbox', $_POST['payping_sandbox']);
            Configuration::updateValue('payping_currency', $_POST['payping_currency']);
            Configuration::updateValue('payping_success_massage', $_POST['payping_success_massage']);
            Configuration::updateValue('payping_failed_massage', $_POST['payping_failed_massage']);
            $this->_html .= '<div class="conf confirm">' . $this->l('Settings updated') . '</div>';
        }

        $this->_generateForm();
        return $this->_html;

    }

    /**
     * generate setting form for admin
     */
    private function _generateForm(){
        $this->_html .= '<div align="center"><form action="' . $_SERVER['REQUEST_URI'] . '" method="post">';
        $this->_html .= $this->l('API KEY :') . '<br><br>';
        $this->_html .= '<input type="text" name="payping_api_key" value="' . Configuration::get('payping_api_key') . '" ><br><br>';
        $this->_html .= $this->l('Currency :') . '<br><br>';
        $this->_html .= '<select name="payping_currency"><option value="rial"' . (Configuration::get('payping_currency') == "rial" ? 'selected="selected"' : "") . '>' . $this->l('Rial') . '</option><option value="toman"' . (Configuration::get('payping_currency') == "toman" ? 'selected="selected"' : "") . '>' . $this->l('Toman') . '</option></select><br><br>';
        $this->_html .= $this->l('Success Massage :') . '<br><br>';
        $this->_html .= '<textarea dir="auto" name="payping_success_massage" style="margin: 0px; width: 351px; height: 57px;">' . (!empty(Configuration::get('payping_success_massage')) ? Configuration::get('payping_success_massage') : "پرداخت شما با موفقیت انجام شد. کد رهگیری: {track_id}") . '</textarea><br><br>';
        $this->_html .= 'متن پیامی که می خواهید بعد از پرداخت به کاربر نمایش دهید را وارد کنید. همچنین می توانید از شورت کدهای {order_id} برای نمایش شماره سفارش و {track_id} برای نمایش کد رهگیری پی‌پینگ استفاده نمایید.<br><br>';
        $this->_html .= $this->l('Failed Massage :') . '<br><br>';
        $this->_html .= '<textarea dir="auto" name="payping_failed_massage" style="margin: 0px; width: 351px; height: 57px;">' . (!empty(Configuration::get('payping_failed_massage')) ? Configuration::get('payping_failed_massage') : "پرداخت شما ناموفق بوده است. لطفا مجددا تلاش نمایید یا در صورت بروز اشکال با مدیر سایت تماس بگیرید.") . '</textarea><br><br>';
        $this->_html .= 'متن پیامی که می خواهید بعد از پرداخت به کاربر نمایش دهید را وارد کنید. همچنین می توانید از شورت کدهای {order_id} برای نمایش شماره سفارش و {track_id} برای نمایش کد رهگیری پی‌پینگ استفاده نمایید.<br><br>';
        $this->_html .= '<input type="submit" name="payping_submit" value="' . $this->l('Save it!') . '" class="button">';
        $this->_html .= '</form><br></div>';
    }

    /**
     * Display this module as a payment option during the checkout
     *
     * @param array $params
     * @return array|void
     */
    public function hookPaymentOptions($params){
        /*
         * Verify if this module is active
         */
        if (!$this->active) {
            return;
        }


        /**
         * Form action URL. The form data will be sent to the
         * validation controller when the user finishes
         * the order process.
         */
        $formAction = $this->context->link->getModuleLink($this->name, 'validation', array(), true);

        /**
         * Assign the url form action to the template var $action
         */
        $this->smarty->assign(['action' => $formAction]);

        /**
         *  Load form template to be displayed in the checkout step
         */
        $paymentForm = $this->fetch('module:payping/views/templates/hook/payment_options.tpl');

        /**
         * Create a PaymentOption object containing the necessary data
         * to display this module in the checkout
         */
        $displayName = ' پرداخت امن با پی‌پینگ';
        $newOption = new PrestaShop\PrestaShop\Core\Payment\PaymentOption;
        $newOption->setModuleName($this->displayName)
            ->setCallToActionText($displayName)
            ->setAction($formAction)
            ->setForm($paymentForm);

        $payment_options = array(
            $newOption
        );

        return $payment_options;
    }


    /**
     * Display a message in the paymentReturn hook
     *
     * @param array $params
     * @return string
     */
    public function hookPaymentReturn($params){
        /**
         * Verify if this module is enabled
         */
        if (!$this->active) {
            return;
        }

        return $this->fetch('module:payping/views/templates/hook/payment_return.tpl');
    }


    public function hash_key(){
        $en = array('a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k', 'l', 'm', 'n', 'o', 'p', 'q', 'r', 's', 't', 'u', 'v', 'w', 'x', 'y', 'z');
        $one = rand(1, 26);
        $two = rand(1, 26);
        $three = rand(1, 26);
        return $hash = $en[$one] . rand(0, 9) . rand(0, 9) . $en[$two] . $en[$three] . rand(0, 9) . rand(10, 99);
    }

}