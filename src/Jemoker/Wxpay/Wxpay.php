<?php namespace Jemoker\Wxpay;

use Jemoker\Wxpay\lib\Common;
use Jemoker\Wxpay\lib\UnifiedOrder;
use Jemoker\Wxpay\lib\Notify;

class Wxpay {

	use Common, UnifiedOrder, Notify;

	private $wxpay_config  = [
		'body' => '',
		'total_fee' => '',
		'out_trade_no' => '',
		'sub_mch_id' => '',
		'device_info' => '',
		'attach' => '',
		'time_start' => '',
		'time_expire' => '',
		'goods_tag' => '',
		'product_id' => '',
		'notify_url' => '',
	];
	private $url = "https://api.mch.weixin.qq.com/pay/unifiedorder";
	private $code;//code码，用以获取openid
	private $openid;//用户的openid
	private $parameters;//jsApi参数，格式为json
	private $prepay_id;//使用统一支付接口得到的预支付id
	private $curl_timeout = 30;//curl超时时间


	public function __construct($config)
	{
		$this->wxpay_config = array_merge($this->wxpay_config,$config);
	}

	function setParams($config){
		$this->wxpay_config = array_merge($this->wxpay_config, $config);
		return $this;
	}

	public function pay(){
		$jsApiParameters = $this->getOpenid()->jsApiParameters();
		if(!$jsApiParameters){
			return redirect($this->wxpay_config['call_back_url']);
		}
		$return_url = $this->wxpay_config['call_back_url'];
		return view('jemoker/wxpay::pay',compact('jsApiParameters','return_url'));
	}

	public function native(){
		$params = array(
			'appid' => $this->wxpay_config['appid'],
			'mch_id' => $this->wxpay_config['mch_id'],
			'product_id' => $this->wxpay_config['product_id'],
			'time_stamp' => time(),
			'nonce_str' => $this->createNonceStr(),
		);

		$sign_str = $this->getSign($params);
		$params['sign'] = $sign_str;
		$params['long_url'] = 'weixin://wxpay/bizpayurl?'.$this->ToUrlParams($params);
		unset($params['sign']);
		$sign_str = $this->getSign($params);
		$params['sign'] = $sign_str;
		return $this->shorturl($params);
	}

	public function verifyNotify(){
		$notify = $this->checkSign();
		return $notify;
	}

	public function jsApiParameters(){

		$this->setParameter("openid", $this->openid);//商品描述
		$this->setParameter("notify_url", $this->wxpay_config['notify_url']);//通知地址
		$this->setParameter("trade_type", "JSAPI");//交易类型

		//订单相关
		$this->setParameter("body", $this->wxpay_config['body']);//商品描述

		$this->setParameter("out_trade_no", $this->wxpay_config['out_trade_no']);//商户订单号
		$this->setParameter("total_fee", $this->wxpay_config['total_fee']);//总金额

		//非必填参数，商户可根据实际情况选填
		$this->setParameter("sub_mch_id",$this->wxpay_config['sub_mch_id']);//子商户号
		$this->setParameter("device_info",$this->wxpay_config['device_info']);//设备号
		$this->setParameter("attach",$this->wxpay_config['attach']);//附加数据
		$this->setParameter("time_start",$this->wxpay_config['time_start']);//交易起始时间
		$this->setParameter("time_expire",$this->wxpay_config['time_expire']);//交易结束时间
		$this->setParameter("goods_tag",$this->wxpay_config['goods_tag']);//商品标记
		$this->setParameter("product_id",$this->wxpay_config['product_id']);//商品ID

		$prepay_id = $this->getPrepayId();
		if(empty($prepay_id)){
			return false;
		}

		$this->setPrepayId($prepay_id);

		$jsApiParameters = $this->getParameters();
		//echo $jsApiParameters;exit;
		return $jsApiParameters;
	}

	public function nativeParameters(){
		$this->setParameter("openid", $this->openid);//商品描述
		$this->setParameter("notify_url", $this->wxpay_config['notify_url']);//通知地址
		$this->setParameter("trade_type", "NATIVE");//交易类型

		//订单相关
		$this->setParameter("body", $this->wxpay_config['body']);//商品描述

		$this->setParameter("out_trade_no", $this->wxpay_config['out_trade_no']);//商户订单号
		$this->setParameter("total_fee", $this->wxpay_config['total_fee']);//总金额

		//非必填参数，商户可根据实际情况选填
		$this->setParameter("sub_mch_id",$this->wxpay_config['sub_mch_id']);//子商户号
		$this->setParameter("device_info",$this->wxpay_config['device_info']);//设备号
		$this->setParameter("attach",$this->wxpay_config['attach']);//附加数据
		$this->setParameter("time_start",$this->wxpay_config['time_start']);//交易起始时间
		$this->setParameter("time_expire",$this->wxpay_config['time_expire']);//交易结束时间
		$this->setParameter("goods_tag",$this->wxpay_config['goods_tag']);//商品标记
		$this->setParameter("product_id",$this->wxpay_config['product_id']);//商品ID

		$prepay_id = $this->getPrepayId();
		if(empty($prepay_id)){
			return false;
		}
		$params = array(
			'return_code' => 'SUCCESS',
			'appid' => $this->wxpay_config['appid'],
			'mch_id' => $this->wxpay_config['mch_id'],
			'nonce_str' => $this->createNonceStr(),
			'prepay_id' => $prepay_id,
			'result_code' => 'SUCCESS',
		);

		$params['sign'] = $this->getSign($params);
		return  $this->arrayToXml($params);
	}


	/**
	 * 	作用：生成可以获得code的url
	 */
	public function createOauthUrlForCode($redirectUrl)
	{
		if($redirectUrl === ''){
			$redirectUrl = \Request::url();
		}
		$urlObj["appid"] = $this->wxpay_config['appid'];
		$urlObj["redirect_uri"] = "$redirectUrl";
		$urlObj["response_type"] = "code";
		$urlObj["scope"] = "snsapi_base";
		$urlObj["state"] = "STATE"."#wechat_redirect";
		$bizString = $this->formatBizQueryParaMap($urlObj, false);
		return "https://open.weixin.qq.com/connect/oauth2/authorize?".$bizString;
	}

	/**
	 * 	作用：生成可以获得openid的url
	 */
	public function createOauthUrlForOpenid()
	{
		$urlObj["appid"] = $this->wxpay_config['appid'];
		$urlObj["secret"] = $this->wxpay_config['app_secret'];
		$urlObj["code"] = $this->code;
		$urlObj["grant_type"] = "authorization_code";
		$bizString = $this->formatBizQueryParaMap($urlObj, false);
		return "https://api.weixin.qq.com/sns/oauth2/access_token?".$bizString;
	}


	/**
	 * 	作用：通过curl向微信提交code，以获取openid
	 */
	public function getOpenid()
	{
		if (!isset($_GET['code']))
		{
			//触发微信返回code码
			$url = $this->createOauthUrlForCode($this->wxpay_config['js_api_call_url'].'?d='.base64_encode(\Request::url()));
			Header("Location: $url");
			exit;
		}else
		{
			//获取code码，以获取openid
			$code = $_GET['code'];
			$this->setCode($code);
		}

		$url = $this->createOauthUrlForOpenid();
		//初始化curl
		$ch = curl_init();
		//设置超时
		curl_setopt($ch, CURLOPT_TIMEOUT, $this->curl_timeout);
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch,CURLOPT_SSL_VERIFYPEER,FALSE);
		curl_setopt($ch,CURLOPT_SSL_VERIFYHOST,FALSE);
		curl_setopt($ch, CURLOPT_HEADER, FALSE);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
		//运行curl，结果以jason形式返回
		$res = curl_exec($ch);
		curl_close($ch);

		$data = json_decode($res,true);

		if(array_key_exists('errcode',$data)){
			$req_url = \Request::url();
			$req_url = preg_replace("/\?code=[a-z0-9A-Z]*\&*/is", "?", $req_url);
			$req_url = preg_replace("/\&code=[a-z0-9A-Z]*\&*/is", "&", $req_url);
			$req_url = preg_replace("/(\&|\?)$/is", "", $req_url);
			$url = $this->createOauthUrlForCode($this->wxpay_config['js_api_call_url'].'?d='.base64_encode($req_url));
			Header("Location: $url");
			exit;
		}

		$this->openid = $data["openid"];

		return $this;
	}

	/**
	 * 	作用：设置prepay_id
	 */
	public function setPrepayId($prepayId)
	{
		$this->prepay_id = $prepayId;
	}

	/**
	 * 	作用：设置code
	 */
	public function setCode($code_)
	{
		$this->code = $code_;
	}

	/**
	 * 	作用：设置jsApi的参数
	 */
	public function getParameters()
	{
		$jsApiObj["appId"] = $this->wxpay_config['appid'];
		$timeStamp = time();
		$jsApiObj["timeStamp"] = "$timeStamp";
		$jsApiObj["nonceStr"] = $this->createNonceStr();
		$jsApiObj["package"] = "prepay_id=$this->prepay_id";
		$jsApiObj["signType"] = "MD5";

		$jsApiObj["paySign"] = $this->getSign($jsApiObj);

		$this->parameters = json_encode($jsApiObj);

		return $this->parameters;
	}

	/**
	 *
	 * 参数数组转换为url参数
	 * @param array $urlObj
	 */
	private function ToUrlParams($urlObj)
	{
		$buff = "";
		foreach ($urlObj as $k => $v)
		{
			$buff .= $k . "=" . $v . "&";
		}

		$buff = trim($buff, "&");
		return $buff;
	}

	/**
	 * 转换短链接
	 */
	private function shorturl($params, $timeOut = 6)
	{
		$url = "https://api.mch.weixin.qq.com/tools/shorturl";

		$xml = $this->arrayToXml($params);
		$response = $this->postXmlCurl($xml, $url, $timeOut);
		$result = $this->xmlToArray($response);
		if(isset($result['short_url'])){
			return $result['short_url'];
		}

		return null;
	}

	public function setOpenid($openid){
		$this->openid = $openid;
	}
}
