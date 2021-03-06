<?php

class Pay_notifyApp extends Mobile_frontendApp {
    private $_order_mod = null;
    private $_paylog_mod = null;
    private $_my_money_mod = null;
    private $_my_moneylog_mod = null;

    function __construct($order_mod = null, $paylog_mod = null,
                         $my_money_mod = null, $my_moneylog_mod = null) {
        $this->_order_mod = $order_mod;
        if ($this->_order_mod === null) {
            $this->_order_mod =& m('order');
        }
        $this->_paylog_mod = $paylog_mod;
        if ($this->_paylog_mod === null) {
            $this->_paylog_mod =& m('paylog');
        }
        $this->_my_money_mod = $my_money_mod;
        if ($this->_my_money_mod === null) {
            $this->_my_money_mod =& m('my_money');
        }
        $this->_my_moneylog_mod = $my_moneylog_mod;
        if ($this->_my_moneylog_mod === null) {
            $this->_my_moneylog_mod =& m('my_moneylog');
        }
    }

    function accept() {
        $trade_no = $this->_make_sure_string('trade_no', 64, '');
        $order_sn = $this->_make_sure_string('out_trade_no', 64, '');
        $total_amount = $this->_make_sure_numeric('total_amount', 0);
        $app_id = $this->_make_sure_string('app_id', 32, '');
        $trade_status = $this->_make_sure_string('trade_status', 32, '');
        $gmt_payment = $this->_make_sure_string('gmt_payment', 32, '');
        $this->_accept($trade_no, $order_sn, $total_amount,
                       $app_id, $trade_status, $gmt_payment, '支付宝');
    }

    function accept_wechat() {
        $trade_no = $this->_make_sure_string('transaction_id', 64, '');
        $order_sn = $this->_make_sure_string('out_trade_no', 64, '');
        $total_amount = ($this->_make_sure_numeric('total_fee', 0))/100;
        $app_id = $this->_make_sure_string('appid', 32, '');
        $trade_status = 'TRADE_SUCCESS';
        $time_end = $this->_make_sure_string('time_end', 14, '');
        $gmt_payment = date('Y-m-d H:i:s', strtotime($time_end));
        $this->_accept($trade_no, $order_sn, $total_amount,
                       $app_id, $trade_status, $gmt_payment, '微信');
    }

    function _accept($trade_no, $order_sn, $total_amount,
                     $app_id, $trade_status, $gmt_payment, $pay_type) {
        $order_info = $this->_order_mod->get(array(
            'conditions' => "order_sn = '{$order_sn}'"));
        if ($order_info &&
            isset($order_info['order_id']) &&
            isset($order_info['order_amount']) &&
            $order_info['order_amount'] == $total_amount &&
            ($app_id == MOBILE_ALIPAY_APP_ID || $app_id == MOBILE_WECHAT_APP_ID) &&
            $trade_status == 'TRADE_SUCCESS') {
            if ($order_info['status'] == ORDER_PENDING) {
                // start transaction
                $db_transaction_begin = db()->query('START TRANSACTION');
                if ($db_transaction_begin === false) {
                    Log::write("fail to start transaction, ".
                       "pay_type:{$pay_type} ".
                       "order_sn:{$order_sn} ".
                       "total_amount:{$total_amount} ".
                       "app_id:{$app_id} ".
                       "trade_status:{$trade_status}");
                    exit('fail to start transaction');
                }

                $user_id = $order_info['buyer_id'];
                $user_name = $order_info['buyer_name'];
                $top_up_result = $this->_top_up($user_id, $user_name, $trade_no, $total_amount, $gmt_payment, $pay_type); // 充值
                if ($top_up_result === false) {
                    db()->query("ROLLBACK");
                    Log::write("fail to top up, ".
                       "pay_type:{$pay_type} ".
                       "order_sn:{$order_sn} ".
                       "total_amount:{$total_amount} ".
                       "app_id:{$app_id} ".
                       "trade_status:{$trade_status}");
                    exit("fail to top up");
                }

                // 冻结资金
                if(empty($order_info["bh_id"])) {
                    $seller_id = $order_info['seller_id'];
                } else {
                    $seller_id = $order_info['bh_id'];
                }
                $order_id = $order_info['order_id'];
                $this->_payment($user_id, $seller_id, $total_amount, $order_id, $order_sn, $pay_type);

                if ($pay_type == '支付宝') {
                    $payment_code = 'alipay-mobile';
                } else {
                    $payment_code = 'wechat-mobile';
                }

                $order_edit_array = array(
                    'payment_name' => "{$pay_type}手机端",
                    'payment_code' => $payment_code,
                    'pay_time' => @local_strtotime($gmt_payment) - 16*60*60, // 由于ecmall记录的是格林威治时间再减去8小时，所以做减去16小时的特殊处理
                    'status' => ORDER_ACCEPTED);
                $this->_order_mod->edit($order_info['order_id'], $order_edit_array);

                //  commit
                db()->query('COMMIT');

                //zjh 2017/12/13
                $noreply_info = $this->getNoreply();
                //滴滴代发订单推送
                $didi_data = array('uri'=>'pushorder','bh_id'=>$seller_id,'order_id'=>$order_id);
                didiOrder($noreply_info['token'],$didi_data);

                Log::write("accept {$pay_type} notify, order_sn:{$order_sn} paid",
                           Log::INFO);
            } else {
                Log::write("accept {$pay_type} notify, order_sn:{$order_sn} not paid, ".
                           "status:{$order_info['status']}",
                           Log::INFO);
            }
            echo('success');
        } else {
            Log::write("fail to verify notify params, ".
                       "pay_type:{$pay_type} ".
                       "order_sn:{$order_sn} ".
                       "total_amount:{$total_amount} ".
                       "app_id:{$app_id} ".
                       "trade_status:{$trade_status}");
            echo('fail to verify notify params');
        }
    }

    function test()
    {
        $seller_id = 167256;
        $order_id = 11;
        //zjh 2017/12/13
        $noreply_info = $this->getNoreply();

        //滴滴代发订单推送
        $didi_data = array('uri'=>'pushorder','bh_id'=>$seller_id,'order_id'=>$order_id);
        $result = didiOrder($noreply_info['token'],$didi_data);
        echo $result;
    }

    function _top_up($user_id, $user_name, $trade_no, $total_amount, $gmt_payment, $pay_type) {
        $exist_pay = $this->_paylog_mod->get(array(
            'conditions' => "out_trade_no='$trade_no'"));
        if (empty($exist_pay)) {
            $pay = array(
                'out_trade_no' => $trade_no,
                'total_fee' => $total_amount,
                'createtime' => $gmt_payment,
                'endtime' => $gmt_payment,
                'trade_status' => 1,
                'type' => 0,
                'customer_id' => $user_id,
                'customer_name' => $user_name);
            $this->_paylog_mod->add($pay);

            $user_row = $this->_my_money_mod->get(array(
                'conditions' => "user_id='$user_id'"));
            $user_money = $user_row['money'];
            $user_jifen = $user_row['jifen'];
            $my_money_dj = $user_row['money_dj'];
            $user_name = $user_row['user_name']; //当稽核时,却只有user_id 20150916

            $new_money = $user_money + $total_amount;
            $new_jifen = $user_jifen + $total_amount;
            $edit_mymoney = array(
                'money' => $new_money);
            $edit_myjifen = array(
                'jifen' => $new_jifen);
            $this->_my_money_mod->edit('user_id=' . $user_id, $edit_mymoney);
            $this->_my_money_mod->edit('user_id=' . $user_id, $edit_myjifen);

            //添加日志
            $add_mymoneylog = array(
                'user_id' => $user_id,
                'user_name' => $user_name,
                'buyer_name' => $pay_type,
                'seller_id' => $user_id,
                'seller_name' => $user_name,
                'order_sn ' => $trade_no,
                'add_time' => gmtime() - 8*60*60,
                'admin_time' => gmtime() - 8*60*60,
                'leixing' => 30,
                'money_zs' => $total_amount,
                'money' => $total_amount,
                'log_text' => "{$pay_type}手机端充值",
                'caozuo' => 4,
                's_and_z' => 1,
                'moneyleft' => $new_money + $my_money_dj);
            $this->_my_moneylog_mod->add($add_mymoneylog);
            return true;
        } else {
            Log::write("fail to top up 51fa, there is a duplicated one. ".
                       "pay_type:{$pay_type} ".
                       "trade_no:{$trade_no} ".
                       "total_amount:{$total_amount}");
            return false;
        }
    }

    function _payment($user_id, $seller_id, $total_amount, $order_id, $order_sn, $pay_type) {
        $buyer_row = $this->_my_money_mod->get(array(
            'conditions' => "user_id='$user_id'"));
        $buyer_name = $buyer_row['user_name'];
        $buyer_money = $buyer_row['money'];
        $buyer_money_dj = $buyer_row['money_dj'];

        $seller_row = $this->_my_money_mod->get(array(
            'conditions' => "user_id='$seller_id'"));
        $seller_name = $seller_row['user_name'];
        $seller_money = $seller_row['money'];
        $seller_money_dj = $seller_row['money_dj'];

        $this->_my_money_mod->edit('user_id=' . $user_id, 'money = money -'.$total_amount);
        $this->_my_money_mod->edit('user_id=' . $seller_id, 'money_dj = money_dj +'.$total_amount);

        $buyer_add_array = array(
            'user_id' => $user_id,
            'user_name' => $buyer_name,
            'order_id ' => $order_id,
            'order_sn ' => $order_sn,
            'seller_id' => $seller_id,
            'seller_name' => $seller_name,
            'buyer_id' => $user_id,
            'buyer_name' => $buyer_name,
            'add_time' => gmtime() - 8*60*60,
            'admin_time' => gmtime() - 8*60*60,
            'leixing' => 20,
            'money_zs' => "-" . $total_amount,
            'money' => $total_amount,
            'log_text' => "{$pay_type}手机端购买商品",
            'caozuo' => 10,
            's_and_z' => 2,
            'moneyleft' => $buyer_money - $total_amount + $buyer_money_dj);
        $this->_my_moneylog_mod->add($buyer_add_array);

        $seller_add_array = array(
            'user_id' => $seller_id,
            'user_name' => $seller_name,
            'order_id ' => $order_id,
            'order_sn ' => $order_sn,
            'seller_id' => $seller_id,
            'seller_name' => $seller_name,
            'buyer_id' => $user_id,
            'buyer_name' => $buyer_name,
            'add_time' => gmtime() - 8*60*60,
            'admin_time' => gmtime() - 8*60*60,
            'leixing' => 10,
            'money_zs' => $total_amount,
            'money' => $total_amount,
            'log_text' => "{$pay_type}手机端卖家收入",
            'caozuo' => 10,
            's_and_z' => 1,
            'moneyleft' => $seller_money_dj + $total_amount + $seller_money);
        $this->_my_moneylog_mod->add($seller_add_array);
    }
}

?>