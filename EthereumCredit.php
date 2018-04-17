<?php

namespace app\index\services;

use think\Db;
use think\Log;
use think\Controller;
use app\index\model\Account;
use app\index\model\UserWallet;
use app\index\model\CoinMyzr;
use app\index\model\CoinMyzc;
use app\index\model\CreditRecord;
use app\index\enum\CreditEnum;
use app\common\exception\ParameterException;

class EthereumCredit extends Controller{


	// 保存以太坊的操作类实例
	private $instance;


	// 构造方法
	public function __construct(){
		$this->instance = EthereumHelper::getInstance();
	}



	// 处理交易信息
	public function processTransaction($list){		
		if($list){			
			// 读取系统中目前的所有钱包地址
			$allWallet = $this->getAllWallet();			
			foreach ($list as $key => $value){				
				if(is_object($value)){					
					// 1、如果是代币，to字段一定是代币的合约地址
					if($value->to != config('system.contract')){
						// 2、Input Data 中的method_id,一定要是合约中的方法id,[0]转入地址，[1]币数量
						if(strpos($value->input, config('system.method_id')) === false) continue;
						$inputData = $this->unlockInputData($value->input);
						// 钱包地址必须是平台生成的钱包					
						if(in_array($inputData['to'], $allWallet)){						
							// 进行充值操作,首先判断这笔订单是否操作过了
							$order = CoinMyzr::getByHash($value->hash);
							if($order) continue;					
							// 交易状态必须是success
							$trasnStatus = $this->instance->eth_getTransactionReceipt($value->hash);						
							if(!$this->instance->decode_hex($trasnStatus->status)) continue;
							// 获取个人信息
							$userMsg = $this->getByAddress($inputData['to']);
							Db::startTrans();
							try{
								// 增加筹码
								Account::where(['id'=>$userMsg['id']])->setInc('credit', $this->coinToCredit($inputData['value']));

								// 充值记录
								CreditRecord::create([
									'user_id'	=>	$userMsg['id'],
									'credit'	=>	$this->coinToCredit($inputData['value']),
									'info'		=>	CreditEnum::RECHARGE,
								]);

								// 写入记录
								CoinMyzr::create([
									'userid'	=>	$userMsg['id'],
									'username'	=>	$userMsg['unionid'],
									'coinname'	=>	1,
									'hash'		=>	$value->hash,
									'num'		=>	$inputData['value'],
									'mum'		=>	$this->instance->decode_hex($value->gasPrice),
									'fee'		=>	$this->instance->decode_hex($value->gas),
									'fromaddress' => $value->from,
									'toaddress'	  => $inputData['to'],
									'addtime'	  => date('Y-m-d H:i:s', time()),
								]);
								Db::commit();
							}catch (Exception $e){
								Log::write($e->getMessage(),'error');
								Db::rollback();
							}	
						}
					}else{
						// 不是代币地址，to字段就一定是系统生成出来的钱包地址
						if(in_array($value->to, $allWallet)){
							// 进行充值操作,首先判断这笔订单是否操作过了
							$order = CoinMyzr::getByHash($value->hash);
							if($order) continue;					
							// 交易状态必须是success
							$trasnStatus = $this->instance->eth_getTransactionReceipt($value->hash);						
							if(!$this->instance->decode_hex($trasnStatus->status)) continue;
							// 获取个人信息
							$userMsg = $this->getByAddress($value->to);

							// 计算充值筹码数量
							$ethToCoinValue = $this->instance->decode_hex($value->value) * config('system.eth2coin');

							Db::startTrans();
							try{
								// 增加筹码
								Account::where(['id'=>$userMsg['id']])->setInc('credit', $ethToCoinValue);

								// 充值记录
								CreditRecord::create([
									'user_id'	=>	$userMsg['id'],
									'credit'	=>	$ethToCoinValue,
									'info'		=>	CreditEnum::ETH_RECHARGE,
								]);

								// 写入记录
								CoinMyzr::create([
									'userid'	=>	$userMsg['id'],
									'username'	=>	$userMsg['unionid'],
									'coinname'	=>	1,
									'hash'		=>	$value->hash,
									'num'		=>	$ethToCoinValue,
									'mum'		=>	$this->instance->decode_hex($value->gasPrice),
									'fee'		=>	$this->instance->decode_hex($value->gas),
									'fromaddress' => $value->from,
									'toaddress'	  => $value->to,
									'addtime'	  => date('Y-m-d H:i:s', time()),
								]);
								Db::commit();
							}catch (Exception $e){
								Log::write($e->getMessage(),'error');
								Db::rollback();
							}
						}
					}
				}
			}
		}		
	}



	// 玩家提现,筹码兑换代币
	public function processWithdraw($data){		
		// 平台转出账户		
		$from = config('system.from');
		// 合约地址
		$contract = config('system.contract');	
		// 解锁账户
		$result = $this->instance->personal_unlockAccount($from, config('system.pwd'));		
		if(!$result){			
			throw new ParameterException(['msg'=>'解锁账户失败']);
		}
		// 拼接数据input Data
		$inputData = $this->lockInputData($data);
		// 拉取个人信息		
		$user = $this->getByUserId(session(config('account.account_auth_key')));		
		$txHash = $this->instance->eth_sendTransaction($from, 0, $contract, $inputData);
		if(!$txHash){			
			throw new ParameterException(['msg'=>'打款失败']);
		}	
		Db::startTrans();		
		try{
			// 减少筹码
			Account::where(['id'=>$user['id']])->setDec('credit', $data['chips']);
			// 充值记录
			CreditRecord::create([
				'user_id'	=>	$user['id'],
				'credit'	=>	'-'.$data['chips'],
				'info'		=>	CreditEnum::WITHDRAW,
			]);
			// 写入记录
			CoinMyzc::create([
				'userid'	=>	$user['id'],
				'username'	=>	$user['nickname'],
				'coinname'	=>	1,
				'hash'		=>	$txHash,
				'num'		=>	$data['chips'] / config('system.rate'),
				'mum'		=>	$data['chips'],
				'fee'		=>	'',
				'fromaddress' => $from,
				'toaddress'	  => $data['wallet'],
				'addtime'	  => date('Y-m-d H:i:s', time()),
			]);
			Db::commit();			
			return json(['msg'=>'提现成功','status'=>1]);
		}catch (Exception $e){
			Log::write($e->getMessage(),'error');
			Db::rollback();
			return json(['msg'=>'提现失败','status'=>0]);
		}
	}


	// 系统钱包代币归整
	public function allInWallet(){
		//
	}


	// 通过用户ID来获取用户信息
	private function getByUserId($user_id){
		// 
	}



	// 通过钱包地址获取个人信息
	private function getByAddress($walletAddress){
		//
	}


	// 代币换算筹码
	public static function coinToCredit($coin = 0){
		return intval($coin * config('system.rate'));
	}


	// 筹码换算代币
	public static function creditToCoin($credit = 0){
		return intval($credit / config('system.rate'));
	}


	// 读取系统中目前的钱包地址
	private function getAllWallet(){
		$userWallet = new UserWallet;
		return $userWallet->column('address');
	}


	// 将16进制的金额转成十进制
	private function hexToNumber($value){		
		return $this->instance->real_banlance($this->instance->decode_hex($value));
	}


	// 分解inputData
	private function unlockInputData($data){		
		$result = [];
		// 去除Input字段中的Method_id
		$input = str_replace(config('system.method_id'),'',$data);	
		// 截取出入账地址,去除前导0，
		$result['to']    = $this->instance->getridof_zero(substr($input,0,strlen($input) / 2), true);		
		// 截取出入账金额	,除以unit
		$moneyValue = $this->instance->getridof_zero(substr($input,strlen($input) / 2), false);
		$result['value'] = $this->instance->decode_hex($moneyValue) / config('system.unit');
		return $result;
	}


	// 拼接inputData
	private function lockInputData($data){
		$method_id = config('system.method_id');		
		$to = $this->instance->fill_zero($data['wallet']);
		$value = $this->instance->fill_zero($this->instance->encode_dec(($data['chips'] / config('system.rate'))  * config('system.unit')));
		return $method_id . $to . $value;
	}


	// 得出当前网络拥堵情况的佣金价格
	private function getEthgas(){
		$url = config('system.ethgasapi');
		$data = json_decode(httpsRequest($url), true);
		return $data['average'] / 100;
	}


}