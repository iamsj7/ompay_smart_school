<?php

if (!defined('BASEPATH'))
    exit('No direct script access allowed');

class Ompay extends Studentgateway_Controller
{

    public $api_config = "";

    public function __construct()
    {
        parent::__construct();

        $api_config = $this->paymentsetting_model->getActiveMethod();
        $this->setting = $this->setting_model->get();
        $this->setting[0]['currency_symbol'] = $this->customlib->getSchoolCurrencyFormat();
        $this->load->library('mailsmsconf');
    }

    public function index()
    {

        $data = array();
        $data['params'] = $this->session->userdata('params');
        $data['setting'] = $this->setting;
        $data['api_error'] = array();
        $data['student_data'] = $this->student_model->get($data['params']['student_id']);
        $data['student_fees_master_array'] = $data['params']['student_fees_master_array'];
        $this->load->view('user/gateway/ompay/index', $data);
    }

    public function pay()
    {
        $this->form_validation->set_rules('phone', $this->lang->line('phone'), 'trim|required|xss_clean');
        $this->form_validation->set_rules('email', $this->lang->line('email'), 'trim|required|xss_clean');

        if ($this->form_validation->run() == false) {
            $data = array();
            $data['params'] = $this->session->userdata('params');
            $data['setting'] = $this->setting;
            $data['api_error'] = array();
            $data['student_data'] = $this->student_model->get($data['params']['student_id']);
            $data['student_fees_master_array'] = $data['params']['student_fees_master_array'];
            $this->load->view('user/gateway/ompay/index', $data);
        } else {

            $details = $this->paymentsetting_model->getActiveMethod();
            $api_secret_key = $details->api_secret_key;
            $api_publishable_key = $details->api_publishable_key;

            $params = $this->session->userdata('params');
            $data = array();
            $student_id = $params['student_id'];
            $data['total'] = number_format((float) ($params['fine_amount_balance'] + $params['total']), 2, '.', '');
            $data['symbol'] = $params['invoice']->symbol;
            $data['currency_name'] = $params['invoice']->currency_name;
            $data['name'] = $params['name'];
            $data['guardian_phone'] = $params['guardian_phone'];

            $curl = curl_init();
            $customer_email = $_POST['email'];
            $currency = $data['currency_name'];
            $txref = "rave" . uniqid(); // ensure you generate unique references per transaction.
            // get your public key from the dashboard.
            $PBFPubKey = $api_publishable_key;
            $redirect_url = base_url() . 'user/gateway/ompay/success'; // Set your own redirect URL

            // Combine the username and password in the required format
            $auth = base64_encode("$api_publishable_key:$api_secret_key");
            $merchant_id = 'rdwjzf4q5v17k9iq';

            curl_setopt_array($curl, array(
                CURLOPT_URL => "https://api.sandbox.ompay.com/v1/merchants/$merchant_id/hosted-payment",
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST => "POST",
                CURLOPT_POSTFIELDS => json_encode([
                    "intent" => "sale",
                    "payer" => [
                        "payment_type" => "CC",
                        "payer_info" => [
                            "email" => $customer_email,
                            "billing_address" => [
                                "line1" => "18 Avenue",
                                "line2" => "cassidy",
                                "city" => "Rose-Hill",
                                "country_code" => "mu",
                                "postal_code" => "72101",
                                "state" => "",
                                "phone" => [
                                    "country_code" => "968",
                                    "number" => "57976041"
                                ]
                            ]
                        ]
                    ],
                    "transaction" => [
                        "amount" => [
                            "currency" => $currency,
                            "total" => convertBaseAmountCurrencyFormat($data['total']),
                        ],
                        "description" => "purchase",
                        "items" => [
                            [
                                "sku" => "100269S",
                                "name" => "Drone",
                                "description" => "drone x",
                                "quantity" => "1",
                                "price" => convertBaseAmountCurrencyFormat($data['total']),
                                "shipping" => "20",
                                "currency" => $currency,
                                "tangible" => true
                            ]
                        ],
                        "invoice_number" => $txref,
                        "return_url" => $redirect_url,
                    ],
                    "metadata" => [
                        "customerip" => "145.239.223.178"
                    ]
                ]),
                CURLOPT_HTTPHEADER => [
                    "authorization: Basic $auth",
                    "content-type: application/json",
                ],
            )
            );

            // Execute the cURL request
            $response = curl_exec($curl);

            // Check for cURL errors
            if (curl_errno($curl)) {
                die('Curl returned error: ' . curl_error($curl));
            }

            // Close the cURL session
            curl_close($curl);

            // Decode the JSON response
            $responseData = json_decode($response, true);

            // Check if the response contains the session_id
            // Check if the response contains the session_id
            if (isset($responseData['session_id'])) {
                // Store the hosted_session_id in the session for later use
                $this->session->set_userdata('hosted_session_id', $responseData['session_id']);

                // The session_id is available, construct the hosted_page_link
                $session_id = $responseData['session_id'];
                $merchant_id = 'rdwjzf4q5v17k9iq';
                $hosted_page_link = "https://api.sandbox.ompay.com/v1/merchants/$merchant_id/hosted-payment/page/" . $session_id;

                // Redirect the user to the hosted_page_link
                redirect($hosted_page_link);
            } else {
                // Session ID not found in the response, handle the error
                die('Session ID not found in the response');
            }
        }
    }

    public function success()
    {
        // Retrieve the hosted_session_id from the session
        $hosted_session_id = $this->session->userdata('hosted_session_id');

        // Check if hosted_session_id is available
        if (!$hosted_session_id) {
            // Handle the case where hosted_session_id is not available
            die('hosted_session_id not found');
        }
        $details = $this->paymentsetting_model->getActiveMethod();
        $api_secret_key = $details->api_secret_key;
        $api_publishable_key = $details->api_publishable_key;

        $params = $this->session->userdata('params');

        if (isset($_GET['payment-session-id'])) {
            $payment_session_id = $_GET['payment-session-id'];
            $merchant_id = 'rdwjzf4q5v17k9iq';

            $verify_url = "https://api.sandbox.ompay.com/v1/merchants/$merchant_id/hosted-payment/session/$hosted_session_id";

            // Combine the username and password in the required format
            $auth = base64_encode("$api_publishable_key:$api_secret_key");

            // Set up cURL for the API request
            $ch = curl_init($verify_url);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Authorization: Basic $auth",
                "Content-Type: application/json",
            ]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

            // Execute the cURL request
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            // Check if the API request was successful (HTTP status 200)
            if ($http_code === 200) {
                $api_response = json_decode($response, true);

                // Check the session_state from the API response
                $session_state = $api_response['session_state'];

                if ($session_state === 'used') {
                    // Payment was successfully captured
                    // You can access payment details in $api_response
                    $this->handleSuccessfulPayment($api_response);
                } elseif ($session_state === 'pending' || $session_state === 'processing') {
                    // Payment is still pending or processing
                    // You can handle this state as needed
                    redirect(base_url("user/gateway/payment/pending"));
                } elseif ($session_state === 'expired') {
                    // Payment session has expired
                    // You can handle this state as needed
                    redirect(base_url("user/gateway/payment/expired"));
                } else {
                    // Handle other possible states as needed
                    redirect(base_url("user/gateway/payment/paymentfailed"));
                }
            } else {
                // Handle API request failure, e.g., log the error
                // Redirect or display an appropriate error message
                redirect(base_url("user/gateway/payment/paymentfailed"));
            }
        } else {
            // No reference supplied
            die('No reference supplied');
        }
    }

    // Function to handle successful payment
    private function handleSuccessfulPayment($api_response)
    {
        // Extract and process payment details from $api_response
        $payment_id = $api_response['session_id'];
        $bulk_fees = array();
        $params = $this->session->userdata('params');

        foreach ($params['student_fees_master_array'] as $fee_key => $fee_value) {

            $json_array = array(
                'amount' => $fee_value['amount_balance'],
                'date' => date('Y-m-d'),
                'amount_discount' => 0,
                'amount_fine' => $fee_value['fine_balance'],
                'description' => $this->lang->line('online_fees_deposit_through_ompay_txn_id') . $payment_id,
                'received_by' => '',
                'payment_mode' => $this->lang->line('Ompay_payment'),
            );

            $insert_fee_data = array(
                'fee_category' => $fee_value['fee_category'],
                'student_transport_fee_id' => $fee_value['student_transport_fee_id'],
                'student_fees_master_id' => $fee_value['student_fees_master_id'],
                'fee_groups_feetype_id' => $fee_value['fee_groups_feetype_id'],
                'amount_detail' => $json_array,
            );
            $bulk_fees[] = $insert_fee_data;
            //========
        }
        $send_to = $params['guardian_phone'];
        $response = $this->studentfeemaster_model->fee_deposit_bulk($bulk_fees, $send_to);
        //========================
        $student_id = $this->customlib->getStudentSessionUserID();
        $student_current_class = $this->customlib->getStudentCurrentClsSection();
        $student_session_id = $student_current_class->student_session_id;
        $fee_group_name = [];
        $type = [];
        $code = [];

        $amount = [];
        $fine_type = [];
        $due_date = [];
        $fine_percentage = [];
        $fine_amount = [];

        $invoice = [];

        $student = $this->student_model->getStudentByClassSectionID($student_current_class->class_id, $student_current_class->section_id, $student_id);

        if ($response && is_array($response)) {
            foreach ($response as $response_key => $response_value) {
                $fee_category = $response_value['fee_category'];
                $invoice[] = array(
                    'invoice_id' => $response_value['invoice_id'],
                    'sub_invoice_id' => $response_value['sub_invoice_id'],
                    'fee_category' => $fee_category,
                );


                if ($response_value['student_transport_fee_id'] != 0 && $response_value['fee_category'] == "transport") {

                    $data['student_fees_master_id'] = null;
                    $data['fee_groups_feetype_id'] = null;
                    $data['student_transport_fee_id'] = $response_value['student_transport_fee_id'];

                    $mailsms_array = $this->studenttransportfee_model->getTransportFeeMasterByStudentTransportID($response_value['student_transport_fee_id']);
                    $fee_group_name[] = $this->lang->line("transport_fees");
                    $type[] = $mailsms_array->month;
                    $code[] = "-";
                    $fine_type[] = $mailsms_array->fine_type;
                    $due_date[] = $mailsms_array->due_date;
                    $fine_percentage[] = $mailsms_array->fine_percentage;
                    $fine_amount[] = $mailsms_array->fine_amount;
                    $amount[] = $mailsms_array->amount;



                } else {

                    $mailsms_array = $this->feegrouptype_model->getFeeGroupByIDAndStudentSessionID($response_value['fee_groups_feetype_id'], $student_session_id);

                    $fee_group_name[] = $mailsms_array->fee_group_name;
                    $type[] = $mailsms_array->type;
                    $code[] = $mailsms_array->code;
                    $fine_type[] = $mailsms_array->fine_type;
                    $due_date[] = $mailsms_array->due_date;
                    $fine_percentage[] = $mailsms_array->fine_percentage;
                    $fine_amount[] = $mailsms_array->fine_amount;

                    if ($mailsms_array->is_system) {
                        $amount[] = $mailsms_array->balance_fee_master_amount;
                    } else {
                        $amount[] = $mailsms_array->amount;
                    }

                }

            }
            $obj_mail = [];
            $obj_mail['student_id'] = $student_id;
            $obj_mail['student_session_id'] = $student_session_id;

            $obj_mail['invoice'] = $invoice;
            $obj_mail['contact_no'] = $student['guardian_phone'];
            $obj_mail['email'] = $student['email'];
            $obj_mail['parent_app_key'] = $student['parent_app_key'];
            $obj_mail['amount'] = "(" . implode(',', $amount) . ")";
            $obj_mail['fine_type'] = "(" . implode(',', $fine_type) . ")";
            $obj_mail['due_date'] = "(" . implode(',', $due_date) . ")";
            $obj_mail['fine_percentage'] = "(" . implode(',', $fine_percentage) . ")";
            $obj_mail['fine_amount'] = "(" . implode(',', $fine_amount) . ")";
            $obj_mail['fee_group_name'] = "(" . implode(',', $fee_group_name) . ")";
            $obj_mail['type'] = "(" . implode(',', $type) . ")";
            $obj_mail['code'] = "(" . implode(',', $code) . ")";
            $obj_mail['fee_category'] = $fee_category;
            $obj_mail['send_type'] = 'group';


            $this->mailsmsconf->mailsms('fee_submission', $obj_mail);

        }
        // Your payment processing code here
        // After processing, redirect to the successinvoice page
        redirect(base_url("user/gateway/payment/successinvoice"));
    }
}