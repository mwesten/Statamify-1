<?php

namespace Statamic\Addons\Statamify\Models;

use Statamic\Addons\Statamify\Models\Cart;
use Statamic\Addons\Statamify\Statamify;
use Statamic\API\Storage;
use Omnipay\Omnipay;

class Gateway
{

  protected $data;
  protected $redirect;
  protected $status;

  public function __construct($order, $cart = [])
  {

    $this->order = $order;
    $this->cart = $cart;
    $this->config = collect(Statamify::config('gateways'));

    switch ($order['payment_method']) {

      case 'stripe': return $this->stripe(); break;

      default: return $this->cheque(); break;

    }

  }

  private function cheque()
  {

    $this->status = 'awaiting_payment';
    $this->data = ['name' => 'Cheque', 'fee' => 0];

  }

  public function errors()
  {

    if (isset($this->errors)) {

      return $this->errors;

    } else {

      return false;

    }

  }

  public function getData()
  {

    return $this->data;

  }

  public function getStatus()
  {

    return $this->status;

  }

  public function redirect()
  {

    if (isset($this->redirect)) {

      return $this->redirect;

    } else {

      return false;

    }

  }

  public function refund($amount, $reason = '')
  {

    $this->amount_to_refund = $amount;

    switch ($this->order['payment_method']['name']) {
      case 'Stripe':
        $response = $this->stripeRefund();
        break;
      
      default:
        $response = 'cheque';
        break;
    }

    if ($response == 'cheque' || $response->isSuccessful()) {

      $old_refund = isset($this->order['summary']['total']['refunded']) ? (float) $this->order['summary']['total']['refunded'] : 0;

      $this->order['summary']['total']['refunded'] = ($this->amount_to_refund + ($old_refund * -1)) * -1;

      if (($this->order['summary']['total']['refunded'] * -1) == $this->order['summary']['total']['grand']) {

        $this->order['status'] = 'refunded';

      } else {

        $this->order['status'] = 'refunded_partially';

      }

      $this->order['listing_status'] = '<span class="order-status ' . $this->order['status'] . '">' . Statamify::t('status.' . $this->order['status']) . '</span>';

      if ($reason) {

        $this->order['payment_method']['refund_reason'] = $reason;

      }

      Storage::putYAML('statamify/orders/' . $this->order['id'], $this->order);

      return ['success' => true, 'data' => $this->order];

    } else {

      return $response->getMessage();

    }

  }

  private function stripe()
  {

    $gateway = Omnipay::create('Stripe');
    $index = $this->config->search(function($gateway) { return $gateway['type'] == 'stripe'; });
    $config = $this->config->get($index);

    $gateway->setApiKey($config[(isset($config['test']) && $config['test'] ? 'test_keys' : 'keys')]['secret']);
    
    $token = $this->order['payment_token'];

    /*
    $client = new \GuzzleHttp\Client();
    $client_res = $client->get('https://api.stripe.com/v1/customers?email=' . $this->order['email'], ['headers' => [ 'Authorization' => 'Basic ' . base64_encode($gateway->getApiKey() . ':') ]  ]);
    $customers = json_decode($client_res->getBody()->getContents(), true);

    if (count($customers['data'])) {

      $customer = $customers['data'][0]['id'];

    } else {

      if (isset($this->order['billing_diff']) && $this->order['billing_diff']) {
        $description = $this->order['billing']['first_name'] . ' ' . $this->order['billing']['last_name'];
      } else {
        $description = $this->order['shipping']['first_name'] . ' ' . $this->order['shipping']['last_name'];
      }

      $response = $gateway->createCustomer(array(
       'description' => $description,
       'email' => $this->order['email'],
       'source'  => $token
      ))->send();

      $customer_data = $response->getData();
      $customer = $customer_data['id'];

    }*/

    $currency = session('statamify.currency');
    $amount = $this->cart['total']['grand'];

    if ($currency['rate'] != '1') {

      $amount *= (float) $currency['rate'];

    }

    $request = $gateway->purchase([
      'amount' => number_format($amount, 2, '.', ''),
      'currency' => $currency['code'],
      'description' => $this->order['title'] . ' ' . env('STATAMIFY_PAYMENT_DESC', ''),
      'source' => $token
    ]);

    $request_data = $request->getData();
    // $request_data['customer'] = $customer;
    $request_data['expand[]'] = 'balance_transaction';

    $response = $request->sendData($request_data);

    if ($response->isSuccessful()) {

      $data = $response->getData();

      $this->status = 'pending';
      $this->data = [
        'name' => 'Stripe', 
        'fee' => isset($data['balance_transaction']['fee']) ? $data['balance_transaction']['fee']/100 : 0, 
        'id' => $data['id'],
        // 'customer_id' => $customer
      ];

    } else {

      $this->errors = [$response->getMessage()];

    }

  }

  private function stripeRefund()
  {

    $gateway = Omnipay::create('Stripe');
    $index = $this->config->search(function($gateway) { return $gateway['type'] == 'stripe'; });
    $config = $this->config->get($index);

    $gateway->setApiKey($config[($config['test'] ? 'test_keys' : 'keys')]['secret']);

    $refund = $gateway->refund([
      'transactionReference' => $this->order['payment_method']['id'],
      'amount' => $this->amount_to_refund
    ]);

    return $refund->send();
    
  }

}