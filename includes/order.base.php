<?php

!defined('ROOT_PATH') && exit('Forbidden');

/**
 *    订单类型基类
 *
 *    @author    Garbin
 *    @usage    none
 */
class BaseOrder extends Object
{
    function __construct($params)
    {
        $this->BaseOrder($params);
    }
    function BaseOrder($params)
    {
        if (!empty($params))
        {
            foreach ($params as $key => $value)
            {
                $this->$key = $value;
            }
        }
    }

    /**
     *    获取订单类型名称
     *
     *    @author    Garbin
     *    @return    string
     */
    function get_name()
    {
        return $this->_name;
    }

    /**
     *    获取订单详情
     *
     *    @author    Garbin
     *    @param     int $order_id
     *    @param     array $order_info
     *    @return    array
     */
    function get_order_detail($order_id, $order_info)
    {
        if (!$order_id)
        {
            return array();
        }

        /* 订单基本信息 */
        $data['order_info'] =   $order_info;

        return array('data' => $data, 'template' => 'normalorder.view.html');
    }
    /**
     *    获取该商品类型在购物流程中的表单模板及数据
     *
     *    @author    Garbin
     *    @return    array
     */
    function get_order_form($store_id)
    {
        return array();
    }

    /**
     *    处理表单提交上来后的数据，并插入订单表
     *
     *    @author    Garbin
     *    @param     array $data
     *    @return    int
     */
    function submit_order($data)
    {
        return 0;
    }

    /**
     *    响应支付通知
     *
     *    @author    Garbin
     *    @param     int    $order_id
     *    @param     array  $notify_result
     *    @return    bool
     */
    function respond_notify($order_id, $notify_result)
    {
        $model_order =& m('order');
        $where = "order_id = {$order_id}";
        $data = array('status' => $notify_result['target']);
        switch ($notify_result['target'])
        {
            case ORDER_ACCEPTED:
                $where .= ' AND status=' . ORDER_PENDING;   //只有待付款的订单才会被修改为已付款
                $data['pay_time']   =   gmtime();
            break;
            case ORDER_SHIPPED:
                $where .= ' AND status=' . ORDER_ACCEPTED;  //只有等待发货的订单才会被修改为已发货
                $data['ship_time']  =   gmtime();
            break;
            case ORDER_FINISHED:
                $where .= ' AND status=' . ORDER_SHIPPED;   //只有已发货的订单才会被自动修改为交易完成
                $data['finished_time'] = gmtime();
            break;
            case ORDER_CANCLED:                             //任何情况下都可以关闭
                /* 加回商品库存 */
                $model_order->change_stock('+', $order_id);
            break;
        }

        return $model_order->edit($where, $data);
    }

    /**
     *    获取收货人信息
     *
     *    @author    Garbin
     *    @param     int $user_id
     *    @return    array
     */
    function _get_my_address($user_id)
    {
        if (!$user_id)
        {
            return array();
        }
        $address_model =& m('address');

        return $address_model->find('user_id=' . $user_id);
    }

    /**
     *    获取配送方式
     *
     *    @author    Garbin
     *    @param     int $store_id
     *    @return    array
     */
    function _get_shipping_methods($store_id)
    {
        if (!$store_id)
        {
            return array();
        }
        $shipping_model =& m('shipping');

        return $shipping_model->find('enabled=1 AND store_id=' . $store_id);
    }

    /**
     *    获取支付方式
     *
     *    @author    Garbin
     *    @param     int $store_id
     *    @return    array
     */
    function _get_payments($store_id)
    {
        if (!$store_id)
        {
            return array();
        }
        $payment_model =& m('payment');

        return $payment_model->get_enabled($store_id);
    }

    /**
     *    生成订单号
     *
     *    @author    Garbin
     *    @return    string
     */
    function _gen_order_sn()
    {
        /* 选择一个随机的方案 */
        mt_srand((double) microtime() * 1000000);
        $timestamp = gmtime();
        $y = date('y', $timestamp);
        $z = date('z', $timestamp);
        $order_sn = $y . str_pad($z, 3, '0', STR_PAD_LEFT) . str_pad(mt_rand(1, 99999), 5, '0', STR_PAD_LEFT);

        $model_order =& m('order');
        $orders = $model_order->find('order_sn=' . $order_sn);
        if (empty($orders))
        {
            /* 否则就使用这个订单号 */
            return $order_sn;
        }

        /* 如果有重复的，则重新生成 */
        return $this->_gen_order_sn();
    }

    /**
     *    验证收货人信息是否合法
     *
     *    @author    Garbin
     *    @param     array $consignee
     *    @return    void
     */
    function _valid_consignee_info($consignee)
    {
        if (!$consignee['consignee'])
        {
            $this->_error('consignee_empty');
            return false;
        }
       /*  $model_region =& m('region');
        if(!$model_region->isleaf($consignee['region_id']))
        {
            $this->_error('region_isnotleaf');
            return false;
        } */
        if (!$consignee['region_id'])
        {
            $this->_error('region_empty');
            return false;
        }
        if (!$consignee['address'])
        {
            $this->_error('address_empty');
            return false;
        }
        if (!$consignee['phone_tel'] && !$consignee['phone_mob'])
        {
            $this->_error('phone_required');
            return false;
        }


        if((isset($consignee['shipping_choice'])) && (intval($consignee['shipping_choice']) == 1))
        {
            if (!$consignee['logist_fees'])
            {
                $this->_error('shipping_required');
                return false;
            }
        }
        else
        {
            if(!isset($consignee['shipping_choice']) || (intval($consignee['shipping_choice']) != 2))
            {
                /*hack*/
                $this->_error('behalf_required');
                return false;
            }
            if (!$consignee['behalf'])
            {
                $this->_error('behalf_required');
                return false;
            }
            if (!$consignee['delivery'])
            {
                $this->_error('delivery_required');
                return false;
            }
        }

        return $consignee;
    }

    /**
     *    获取商品列表
     *
     *    @author    Garbin
     *    @param     int $order_id
     *    @return    array
     */
    function _get_goods_list($order_id)
    {
        if (!$order_id)
        {
            return array();
        }
        $ordergoods_model =& m('ordergoods');

        return $ordergoods_model->find("order_id={$order_id}");
    }

    /**
     *    获取扩展信息
     *
     *    @author    Garbin
     *    @param     int $order_id
     *    @return    array
     */
    function _get_order_extm($order_id)
    {
        if (!$order_id)
        {
            return array();
        }

        $orderextm_model =& m('orderextm');

        return $orderextm_model->get($order_id);
    }

    /**
     *    获取订单操作日志
     *
     *    @author    Garbin
     *    @param     int $order_id
     *    @return    array
     */
    function _get_order_logs($order_id)
    {
        if (!$order_id)
        {
            return array();
        }

        $model_orderlog =& m('orderlog');

        return $model_orderlog->find("order_id = {$order_id}");
    }

    /**
     *    处理订单基本信息,返回有效的订单信息数组
     *
     *    @author    Garbin
     *    @param     array $goods_info
     *    @param     array $post
     *    @return    array
     */
    function _handle_order_info($goods_info, $post)
    {
        /* 默认都是待付款 */
        $order_status = ORDER_PENDING;

        /* 买家信息 */
        $visitor     =& env('visitor');
        $user_id     =  $visitor->get('user_id');
        $user_name   =  $visitor->get('user_name');

        /* 返回基本信息 */
        return array(
            'order_sn'      =>  $this->_gen_order_sn(),
            'type'          =>  $goods_info['type'],
            'extension'     =>  $this->_name,//order_type
            'seller_id'     =>  $goods_info['store_id'],
            'seller_name'   =>  addslashes($goods_info['store_name']),
            'buyer_id'      =>  $user_id,
            'buyer_name'    =>  addslashes($user_name),
            'buyer_email'   =>  $visitor->get('email'),
            'status'        =>  $order_status,
            'add_time'      =>  gmtime() - 8*60*60, // // 由于ecmall记录的是格林威治时间再减去8小时，所以做减去8小时的特殊处理
            'goods_amount'  =>  $goods_info['amount'],
            'discount'      =>  isset($goods_info['discount']) ? $goods_info['discount'] : 0,
            'anonymous'     =>  intval(@$post['anonymous']),
            'postscript'    =>  trim($post['postscript']),
        );
    }

    /**
     *    处理收货人信息，返回有效的收货人信息
     *
     *    @author    Garbin
     *    @param     array $goods_info
     *    @param     array $post
     *    @return    array
     */
    function _handle_consignee_info($goods_info, $post)
    {
        /* 验证收货人信息填写是否完整 */
        $consignee_info = $this->_valid_consignee_info($post);
        if (!$consignee_info)
        {
            return false;
        }

        if(isset($consignee_info['shipping_choice']) && (intval($consignee_info['shipping_choice']) == 1))
        {
            /* 计算配送费用 */
            /* $shipping_model =& m('shipping');
            $shipping_info  = $shipping_model->get("shipping_id={$consignee_info['shipping_id']} AND store_id={$goods_info['store_id']} AND enabled=1");
            if (empty($shipping_info))
            {
                $this->_error('no_such_shipping');

                return false;
            } */

            /* 配送费用=首件费用＋超出的件数*续件费用 */
            //$shipping_fee = $shipping_info['first_price'] + ($goods_info['quantity'] - 1) * $shipping_info['step_price'];
            $shipping_info = explode(':',$consignee_info['logist_fees']);
            $shipping_name = LANG::get($shipping_info[0]);
            $shipping_fee = abs(floatval($shipping_info[1]));


        }
        elseif(isset($consignee_info['shipping_choice']) && (intval($consignee_info['shipping_choice']) == 2))
        {
            /*采用代发，目前代发免费，快递费从数据库查找*/
            //$shipping_fee = 12;
            $behalf_mod =& m('behalf');
            /* $deliveries = $behalf_mod->getRelatedData('has_delivery',$consignee_info['behalf']);
            foreach($deliveries as $delivery)
            {

                if(intval($delivery['dl_id']) == intval($consignee_info['delivery']))
                {
                    $shipping_fee = intval($delivery['dl_fee']);
                }
            }
            if(empty($shipping_fee))
            {
                $this->_error('hack attemp;no shipping fee');
                return false;
            }            */
            if(empty($goods_info['quantity']))
            {
                $this->_error('hack attemp;');
                return false;
            }
            $shipping_fee = $behalf_mod->calculate_shipping_fee($consignee_info['behalf'],$consignee_info['delivery'],$goods_info['quantity']);
        }
        else
        {
            return false;
        }

        return array(
                'consignee'     =>  $consignee_info['consignee'],
                'region_id'     =>  $consignee_info['region_id'],
                'region_name'   =>  $consignee_info['region_name'],
                'address'       =>  $consignee_info['address'],
                'zipcode'       =>  $consignee_info['zipcode'],
                'phone_tel'     =>  $consignee_info['phone_tel'],
                'phone_mob'     =>  $consignee_info['phone_mob'],
                'shipping_id'   =>  (isset($consignee_info['shipping_choice']) && (intval($consignee_info['shipping_choice']) == 1))?$consignee_info['shipping_id']:0,
                'shipping_name' =>  (isset($consignee_info['shipping_choice']) && (intval($consignee_info['shipping_choice']) == 1))?addslashes($shipping_name):"",
                'shipping_fee'  =>  $shipping_fee,
                'bh_id' => (isset($consignee_info['shipping_choice']) && (intval($consignee_info['shipping_choice']) == 2))?$consignee_info['behalf']:0,
                'dl_id' => (isset($consignee_info['shipping_choice']) && (intval($consignee_info['shipping_choice']) == 2))?$consignee_info['delivery']:0,
        );


    }

    /**
     *    获取一级地区
     *
     *    @author    Garbin
     *    @param    none
     *    @return    void
     */
    function _get_regions()
    {
        $model_region =& m('region');
        $regions = $model_region->get_list(0);
        if ($regions)
        {
            $tmp  = array();
            foreach ($regions as $key => $value)
            {
                $tmp[$key] = $value['region_name'];
            }
            $regions = $tmp;
        }

        return $regions;
    }
}

?>