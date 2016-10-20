<?php

class Mobile_homeApp extends MallbaseApp {
    function index() {
        $order_by = 'add_time DESC';
        $goods_mod =& m('goods');
        $goods_list = $goods_mod->find(array(
            'fields' => 'goods_id, goods_name, default_image, price, store_id',
            'index_key' => false,
            'order' => $order_by,
            'limit' => 20));
        $this->assign('goods_list', $goods_list);
        $this->display('mobile_home.html');
    }
}

?>