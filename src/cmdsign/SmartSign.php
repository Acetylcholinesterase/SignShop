<?php

namespace cmdsign;

//system:
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\Server;
use pocketmine\utils\TextFormat;
use pocketmine\event\TranslationContainer;
use pocketmine\math\Vector3;
use pocketmine\level\Level;

use pocketmine\utils\Config;
use pocketmine\inventory\PlayerInventory;

//event:
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerInteractEvent;
//use pocketmine\event\block\BlockEvent;
use pocketmine\event\block\SignChangeEvent;
use pocketmine\event\block\BlockBreakEvent;


//block:
use pocketmine\block\Block;
use pocketmine\tile\Sign;
use pocketmine\tile\Tile;
use pocketmine\item\Item;
use pocketmine\tile\Chest;
use pocketmine\block\Air;

//Economy:
use onebone\economyapi\EconomyAPI;
//use sudo\SuPlrSender;
//use sudo\SuCmdSender;

class	SmartSign extends PluginBase implements Listener{
	
	/**
	 * $signs=new object[$sign_quantity][8]
	 * [][0]:string:Level name
	 * [][1-3]:int:x,y,z
	 * [][4]:int:type see @getSignType
	 * [][5]:int:item_id
	 * [][6]:int:item_data
	 * [][7]:int:buy price
	 * [][8]:int:sell price
	 * [][9]:string:object name
	 **/
	private $signs;
	const INDEX_LEVEL=0;
	const INDEX_X=1;
	const INDEX_Y=2;
	const INDEX_Z=3;
	const INDEX_TYPE=4;
	const INDEX_ITEM_ID=5;
	const INDEX_ITEM_DATA=6;
	const INDEX_BUY_PRICE=7;
	const INDEX_SELL_PRICE=8;
	const INDEX_NAME=9;
	
	/*The configure file instance of $signs*/
	private $signcfg;
	
	/**
	 * $exprocess =new int[$player_name=>?][4];
	 * $exprocess[$player_name]=new int[4];
	 * [][0]:Progress
	 *      0.No sign selected
	 *      1.Sign selected,wait for amount
	 *      2.wait for confirmation
	 *      8.Placing a SignShop
	 * [][1]:SignID(index of $signs) or new Instance of Sign(8)
	 * [][2]:0=Error,1=Buy,2=Sell
	 * [][3]:Amount
	 **/
	private $exprocess;
	const INDEX_PROGRESS=0;
	const INDEX_SIGN_ID=1;
	const INDEX_ACTION=2;
	const INDEX_AMOUNT=3;
	
	var $compList;

	
	
	/**
	 * @function getSignType
	 * @return int
	 * -1:An exception has occurred
	 *  0:Just a common sign(or not a sign)
	 *  1:It's a SignShop
	 *  2:It's a CommandSign
	 *  3:It's a SuperuserCommandSign
	 **/
	private function getSignType($level,int $x,int $y,int $z):int {
		$sig=$this->getSignIndex($level,$x,$y,$z);
		if($sig==-1)return 0;
		return $this->signs[$sig][self::INDEX_TYPE];
	}

	private function getSignIndex($level,int $x,int $y,int $z){
		if($level instanceof Level)$level=$level->getFolderName();
		foreach($this->signs as $i => $sig){
			/*$sig=$this->signs[$i];*/
			if($sig[0]===$level and $sig[1]===$x and $sig[2]===$y and $sig[3]===$z){
				return $i;
			}
		}
		return -1;
	}
	
	private function updateSigns(){
		foreach($this->signs as $i => $sig){
			/*$sig=$this->signs[$i];*/
			$level=$this->getServer()->getLevelByName($sig[self::INDEX_LEVEL]);
			$x=$sig[self::INDEX_X];
			$y=$sig[self::INDEX_Y];
			$z=$sig[self::INDEX_Z];
			$itemid=$sig[self::INDEX_ITEM_ID];
			$meta=$sig[self::INDEX_ITEM_DATA];
			$objname=$sig[self::INDEX_NAME];
			$buy=$sig[self::INDEX_BUY_PRICE];
			$sell=$sig[self::INDEX_SELL_PRICE];
			$sign=$level->getTile(new Vector3($x,$y,$z));
			$sign->setText("§e[§2系统商店§e]","§6{$objname}§e（§2{$itemid}:{$meta}§e）",
						   "§6出售价格:§e {$buy} §6/§c个","§6回收价格: §e{$sell} §6/§c个");
		}
	}
	
	private function nextSignIndex(){
		$count=count($this->signs);
		if(isset($this->signs[$count])){
			//Will find a hole
			for($i=0;$i<$count+1;$i++){
				if(!isset($this->signs[$i])){
					return $i;
				}
			}
			echo "Fatal:Unexpected fell out\n";
			$this->saveSignCfg();
			stop();
		}else return $count;
	}
	
	
	
	public function onCommand(CommandSender $sender, Command $command,string $label, array $args) :bool{
		if($args==null)$len=0;
		else $len=count($args);
		if($command->getName()=="signshop"||$command->getName()=="sshop"){
			if(!$sender->hasPermission("commandsign.shop")){
				$sender->sendMessage(new TranslationContainer(TextFormat::RED . "%commands.generic.permission"));
				return true;
			}
			if($len==1){
				if($len==1){
					if($args[0]=="rearrange"){
						$this->rearrangeSigns();
						$sender->sendMessage("§e[SignShop]§f已完成重排§e[§6".count($this->signs)."§e]§f个木牌商店");
						return true;
					}else if($args[0]=="update"){
						$this->updateSigns();
						$sender->sendMessage("§e[SignShop]§f成功更新§e[§6".count($this->signs)."§e]§f个木牌商店");
						return true;
					}else if($args[0]=="save"){
						$this->saveSignCfg();
						$sender->sendMessage("§e[SignShop]§f保存成功,当前木牌商店数量:§e[§6".count($this->signs)."§e]§f个");
						return true;
					}else if($args[0]=="comp"){
						if(!$sender instanceof Player){
							$sender->sendMessage("§e[SignShop]§c请在游戏中使用该指令");
							return true;
						}
						$name=$sender->getName();
						if(isset($this->compList[$name])){
							$sender->sendMessage("§e[SignShop]§f[OFF]感受态模式已关闭");
							unset($this->compList[$name]);
							return true;
						}else{
							$sender->sendMessage("§e[SignShop]§f[§cON§f]感受态模式已激活");
							$this->compList[$name]=true;
							return true;
						}
					}
				}
			}
		}
		if(!$sender instanceof Player){
			$sender->sendMessage("§e[SignShop]§c请在游戏中使用该指令");
			return true;
		}
		switch($command->getName()){
			case "sshop":
			case "signshop":
				if($len!=3&&$len!=4){
					$sender->sendMessage("§eUsage:§f/sshop <ID[:data]> <买入单价(不允许买可填-1)> <回收单价(不允许卖可填-1)> [商品名称]\n重排命令:/sshop rearrange  更新木牌指令:/sshop update  保存命令:/sshop save  切换感受态指令:/sshop comp");
					return true;
				}
				$name=$sender->getName();
				$tmp=explode(":",$args[0]);
				$itemid=(int)$tmp[0];
				$meta=isset($tmp[1])?((int)$tmp[1]):0;
				$buy=round((float)$args[1],2);
				$sell=round((float)$args[2],2);
				if(isset($args[3])){
					$objname=$args[3];
				}else{
					$objname=Item::get($itemid,$meta)->getName();
				}
				$this->exprogress[$name][self::INDEX_PROGRESS]=8;
				$this->exprogress[$name][1]=["",0,0,0,1,$itemid,$meta,$buy,$sell,$objname];
				$sender->sendMessage("§e[SignShop]现在请点击一个木牌来建立商店");

			case "buy":
				if(!isset($args[0])||count($args)!=1){
					$sender->sendMessage(new TranslationContainer("commands.generic.usage", ["/buy <数量>"]));
					return true;
				}
				$name=$sender->getName();
				$sid=$this->exprogress[$name][self::INDEX_SIGN_ID];
				$amount=(int)$args[0];
					if($amount<1){
					$sender->sendMessage("§e[SignShop]§c买卖数量必须是不小于1的整数");
					return true;
				}
				if(!isset($this->exprogress[$name])||$this->exprogress[$name][self::INDEX_PROGRESS]==0){
					$sender->sendMessage("§e[SignShop]§c请先点击商店中的木牌选择商品!");
				}
				$objname=$this->signs[$sid][self::INDEX_NAME];
				$itemid=$this->signs[$sid][self::INDEX_ITEM_ID];
				$meta=$this->signs[$sid][self::INDEX_ITEM_DATA];
				//$have=$this->countItem($sender,$itemid,$meta);
				$mymoney=EconomyAPI::getInstance()->myMoney($sender);
				$spc=$this->getFreeSpace($sender,$itemid,$meta);
				$price=$this->signs[$sid][self::INDEX_BUY_PRICE];
				$cost=$amount*$price;
				if($cost>$mymoney){
					$sender->sendMessage("§c你的余额不足,购买§e[§6{$amount}§e]§c个§6{$objname}§c需要§e[§6{$cost}§e]§c金币,而您只有§e[§6{$mymoney}§e]§c金币");
					return true;
				}
				if($amount>$spc){
					$sender->sendMessage("§c你的背包最多还能容纳§e[§6{$spc}§e]§c个§6{$objname}§c,塞不了§e[§6{$amount}§e]§c这么多");
					return true;
				}
				$this->exprogress[$name][self::INDEX_ACTION]=1;
				$this->exprogress[$name][self::INDEX_PROGRESS]=2;
				$this->exprogress[$name][self::INDEX_AMOUNT]=$amount;
				$sender->sendMessage("§e您将花费[§6{$cost}§e]金钱购买[§6".$amount."§e]个§6{$objname}§e,\n§2请再次点击木牌确认购买");
				break;
			case "sell":
				if(!isset($args[0])||count($args)!=1){
					$sender->sendMessage(new TranslationContainer("commands.generic.usage", ["/sell <数量>"]));
					return true;
				}
				$name=$sender->getName();
				$sid=$this->exprogress[$name][self::INDEX_SIGN_ID];
				$amount=(int)$args[0];
					if($amount<1){
					$sender->sendMessage("§e[SignShop]§c买卖数量必须是不小于1的整数");
					return true;
				}
				if(!isset($this->exprogress[$name])||$this->exprogress[$name][self::INDEX_PROGRESS]==0){
					$sender->sendMessage("§e[SignShop]§c请先点击商店中的木牌选择商品!");
				}
				$objname=$this->signs[$sid][self::INDEX_NAME];
				$itemid=$this->signs[$sid][self::INDEX_ITEM_ID];
				$meta=$this->signs[$sid][self::INDEX_ITEM_DATA];
				$have=$this->countItem($sender,$itemid,$meta);
				$mymoney=EconomyAPI::getInstance()->myMoney($sender);
				//$spc=$this->getFreeSpace($sender,$itemid,$meta);
				$price=$this->signs[$sid][self::INDEX_SELL_PRICE];
				$gain=$amount*$price;
				if($amount>$have){
					$sender->sendMessage("§c你的6{$objname}§c不足,你只有§e[§6{$have}§e]§c个§6{$objname}§c,不能卖出§e[§6{$amount}§e]§c个");
					return true;
				}
				$this->exprogress[$name][self::INDEX_ACTION]=2;
				$this->exprogress[$name][self::INDEX_PROGRESS]=2;
				$this->exprogress[$name][self::INDEX_AMOUNT]=$amount;
				$sender->sendMessage("§e您将用[§6".$amount."§e]个§6{$objname}§e换取[§6{$mymoney}§e]金钱,\n§2请再次点击木牌确认出售");
				break;
		}
		return true;
	}
	
	public function onTouch(PlayerInteractEvent $event){
		$block = $event->getBlock();
		$id=$block->getId();
		if($id!=63 && $id!=68)return;
		$player=$event->getPlayer();
		$level=$block->getLevel();
		$name=$player->getName();
		$sid=$this->getSignIndex($block->getLevel(),$block->getX(),$block->getY(),$block->getZ());
		if(isset($this->compList[$name])){
			try{
				$sign=$block->getLevel()->getTile($block);
				$strs=$sign->getText();
				preg_match("/[0-9\\.]+/",preg_replace("/§./","",$strs[2]),$tmp);
				$buy=(float)$tmp[0];
				preg_match("/[0-9\\.]+/",preg_replace("/§./","",$strs[3]),$tmp);
				$sell=(float)$tmp[0];
				$tmp=preg_replace("/§./","",$strs[1]);
				$tmp=str_replace("（","(",$tmp);
				$tmp=str_replace("）",")",$tmp);
				$tmp=explode("(",$tmp);
				$objname=$tmp[0];
				$tmp=str_replace(")","",$tmp[1]);
				$tmp=str_replace("(","",$tmp);
				$tmp=explode(":",$tmp);
				$itemid=(int)$tmp[0];
				$meta=(int)$tmp[1];
				$sid=$this->getSignIndex($block->getLevel(),$block->getX(),$block->getY(),$block->getZ());
				if($sid==-1)$sid=$this->nextSignIndex();
				$this->signs[$sid]=[$level->getFolderName(),$block->getX(),$block->getY(),$block->getZ(),1,$itemid,$meta,$buy,$sell,$objname];
				/*$player->sendMessage("§6{$objname}§e（§2{$itemid}:{$meta}§e）");
				$player->sendMessage("§6出售价格:§e {$buy} §6/§c个");
				$player->sendMessage("§6回收价格: §e{$sell} §6/§c个");
				*/
				$sign->setText("§e[§2系统商店§e]","§6{$objname}§e（§2{$itemid}:{$meta}§e）",
							"§6出售价格:§e {$buy} §6/§c个","§6回收价格: §e{$sell} §6/§c个");
				$event->setCancelled(true);
				$player->sendMessage("§e[SignShop]§f操作成功");
			}catch(Exception $e){
				$player->sendMessage("§e[SignShop]§c".$e);
			}
			return;
		}
		if(isset($this->exprogress[$name])&&$this->exprogress[$name][0]==8){
			$event->setCancelled(true);
			if($sid==-1)$sid=$this->nextSignIndex();
			$this->signs[$sid]=$this->exprogress[$name][1];
			$this->signs[$sid][0]=$block->getLevel()->getFolderName();
			$this->signs[$sid][1]=$block->getX();
			$this->signs[$sid][2]=$block->getY();
			$this->signs[$sid][3]=$block->getZ();
			$objname=$this->signs[$sid][self::INDEX_NAME];
			$itemid=$this->signs[$sid][self::INDEX_ITEM_ID];
			$meta=$this->signs[$sid][self::INDEX_ITEM_DATA];
			$buy=$this->signs[$sid][self::INDEX_BUY_PRICE];
			$sell=$this->signs[$sid][self::INDEX_SELL_PRICE];
			$sign=$block->getLevel()->getTile($block);
			$sign->setText("§e[§2系统商店§e]","§6{$objname}§e（§2{$itemid}:{$meta}§e）",
						   "§6出售价格:§e {$buy} §6/§c个","§6回收价格: §e{$sell} §6/§c个");
			$player->sendMessage("§e[SignShop]商店建立成功!");
			//$this->saveSignCfg();
			unset($this->exprogress[$name]);
		}else if($sid!=-1&&$this->signs[$sid][self::INDEX_TYPE]==1){
			$sig=$this->signs[$sid];
			$event->setCancelled(true);
			//It's a SignShop
			if($player->getGamemode()===1){
				$player->sendMessage("§e[SignShop]§c创造一边凉快去");
				return;
			}
			if(!isset($this->exprogress[$name]))$this->exprogress[$name]=[0,0,0,0];
			if($this->exprogress[$name][self::INDEX_PROGRESS]==2){
				$sid_old=$this->exprogress[$name][self::INDEX_SIGN_ID];
				$amount=$this->exprogress[$name][self::INDEX_AMOUNT];
				$itemid=$this->signs[$sid][self::INDEX_ITEM_ID];
				$meta=$this->signs[$sid][self::INDEX_ITEM_DATA];
				if($sid!=$sid_old){
					unset($this->exprogress[$name]);
					$player->sendMessage("§e[SignShop]§c两次点击木牌不一致,取消操作");
					return;
				}
				switch($this->exprogress[$name][self::INDEX_ACTION]){
					case 1://Buy
						$price=$this->signs[$sid][self::INDEX_BUY_PRICE];
						$money=$amount*$price;
						$this->buyItem($player,$itemid,$meta,$amount,$money);
						break;
					case 2://Sell
						$price=$this->signs[$sid][self::INDEX_SELL_PRICE];
						$money=$amount*$price;
						$this->sellItem($player,$itemid,$meta,$amount,$money);
						break;
					default:
						$player->sendMessage("§e[SignShop]§cFATAL:Segmentational fault(core dumped)");
				}
				unset($this->exprogress[$name]);
			}else{
				$this->exprogress[$name][self::INDEX_PROGRESS]=1;
				$this->exprogress[$name][self::INDEX_SIGN_ID]=$sid;
				$objname=$this->signs[$sid][self::INDEX_NAME];
				$itemid=$this->signs[$sid][self::INDEX_ITEM_ID];
				$meta=$this->signs[$sid][self::INDEX_ITEM_DATA];
				$player->sendMessage("§e[SignShop]§f您已选中商品§e[§6{$objname}(§2{$itemid}:{$meta}§6)§e]");
				$have=$this->countItem($player,$itemid,$meta);
				$mymoney=EconomyAPI::getInstance()->myMoney($player);
				$price=$this->signs[$sid][self::INDEX_BUY_PRICE];
				if($mymoney>=$price){
					$cnt=($price==0)?"许多":(int)($mymoney/$price);
					$player->sendMessage("§f您有§e[§6{$mymoney}§e]§f金钱,最多可以买§e[§6".$cnt."§e]§f个§6{$objname}§f\n§2输入§e/buy 数量§2可进行购买");
					//$player->sendMessage("§e您有[§6{$have}§e]个§6{$objname}§e,全部卖出可获得[§6{$all}§e]金币,输入§f/sell 数量 §e可卖出");
				}else{
					$player->sendMessage("§f看什么看,你连一个§6{$objname}§f都买不起");
				}
				if($have>0){
					$price=$this->signs[$sid][self::INDEX_SELL_PRICE];
					$all=$have*$price;
					$player->sendMessage("§f您有§e[§6{$have}§e]§f个§6{$objname}§f,全部卖出可获得§e[§6{$all}§e]§f金币\n§2输入§e/sell 数量§2可卖出");
				}else{
					$player->sendMessage("§f你没有§6{$objname}§f,无法出售");
				}
			}
		}
	}
	
	public function saveSignCfg(){
		$this->signcfg->setAll($this->signs);
		$this->signcfg->save();
	}
	
	public function onLoad(){
		$this->exprogress=[];
		$this->compList=[];
		@mkdir($this->getDataFolder());
		$this->signcfg=new Config($this->getDataFolder()."/signs.json",Config::JSON);
		$this->signs=$this->signcfg->getAll();
		//DONE:重排signs以增加稳定性
		$this->rearrangeSigns();
	}
	
	public function rearrangeSigns(){
		if($this->signs===null)$this->signs=[];
		$well=[];
		$i=0;
		foreach($this->signs as $sig){
			$well[$i++]=$sig;
		}
		$this->signs=$well;
		$this->exprogress=[];
		$this->saveSignCfg();
	}
	
	public function onEnable(){
		$this->getServer()->getPluginManager()->registerEvents($this,$this);
	}
	
	public function onDisable(){
		$this->saveSignCfg();
	}
	
//--------Parts of SignCommand
	public function runNrCmd($sender,$cmd){
		$this->getServer()->getCommandMap()->dispatch($sender,$cmd);
	}
	
	public function runSuCmd($sender,$cmd){
		$susender=new SuCmdSender($sender);
		$susender->init();
		$this->getServer()->getCommandMap()->dispatch($sender,$cmd);
	}
	
	
	
	public function getFreeSpace($player,$itemid,$meta){
		$spc = 0;
		for($index = 0; $index < $player->getInventory()->getSize(); $index ++){
			$item=$player->getInventory()->getItem($index);
			if ($item->getID() ==$itemid and $item->getDamage() == $meta){
				$spc += 64-$item->getCount();
			}else if ($item->getID() ==0){
				$spc+=64;
			}
		}
		return $spc;
	}
	
	public function countItem($player,$itemid,$meta){
		$cnt = 0;
		foreach($player->getInventory()->getContents() as $item){
			if ($item->getID() ==$itemid and $item->getDamage() == $meta){
				$cnt += $item->getCount();
			}
		}
		return $cnt;
	}
	
	
	
//Parts of Shop interface
	public function buyItem($player,$itemid,$meta,$count,$money){
		$have=EconomyAPI::getInstance()->myMoney($player);
		if($have<$money){
			$player->sendMessage("§e你的金钱[ $have ]不足, 还需要 ".($money-$have)." 金钱!");
			return false;
		}
		$spc = 0;
		for($index = 0; $index < $player->getInventory()->getSize(); $index ++){
			$item=$player->getInventory()->getItem($index);
			if ($item->getID() ==$itemid and $item->getDamage() == $meta){
				$spc += 64-$item->getCount();
			}else if ($item->getID() ==0){
				$spc+=64;
			}
			if($spc>$count)break;
		}
		if($spc<$count){
			$player->sendMessage("§e你的背包空间[$spc]不足, 至少还需要 ".($count-$spc)." 个有效空间!");
			return false;
		}
		$addcount=$count;
		for($index = 0; $index < $player->getInventory()->getSize(); $index ++){
			$pitem = $player->getInventory()->getItem($index);
			if($itemid == $pitem->getID() and $meta == $pitem->getDamage()){
				$pn=$pitem->getCount();
				if(64-$pn>0){
					if(64-$pn >=$addcount){
						$player->getInventory()->setItem($index, Item::get($itemid,$meta,$pn+$addcount));
						$addcount=0;
						break;
					}else{
						$player->getInventory()->setItem($index, Item::get($itemid,$meta,64));
						$addcount-=64-$pn;
					}
				}
			}else if($pitem->getID()==0){
				if($addcount>=64){
					$player->getInventory()->setItem($index, Item::get($itemid,$meta,64));
						$addcount-=64;
				}else{
					$player->getInventory()->setItem($index, Item::get($itemid,$meta,$addcount));
					break;
				}
			}
		}
		EconomyAPI::getInstance()->reduceMoney($player, $money, true, "SignShop");
		$player->sendMessage("§e你成功花了 $money 金钱买来 $count 个 [ $itemid : $meta ]");
	}
	
	public function sellItem($player,$itemid,$meta,$count,$money){
		$cnt = 0;
		foreach($player->getInventory()->getContents() as $item){
			if ($item->getID() ==$itemid and $item->getDamage() == $meta){
				$cnt += $item->getCount();
			}
		}
		if($cnt<$count){
			$player->sendMessage("§e你的 [ $itemid : $meta ] 不足, 还需要 ".($count-$cnt)." 个!");
			return false;
		}
		$rmcount=$count;
		for($index = 0; $index < $player->getInventory()->getSize(); $index ++){
			$pitem = $player->getInventory()->getItem($index);
			if ($itemid == $pitem->getID() and $meta == $pitem->getDamage()){
				if ($rmcount >= $pitem->getCount()){
					$rmcount -= $pitem->getCount();
					$player->getInventory()->setItem($index, Item::get(Item::AIR, 0, 1));
				} else if ($rmcount < $pitem->getCount()){
					$player->getInventory()->setItem($index, Item::get($itemid, $meta, $pitem->getCount() - $rmcount));
					break;
				}
			}
		}
		EconomyAPI::getInstance()->addMoney($player, $money, true, "SignShop");
		$player->sendMessage("§e你成功出售 $count 个 [ $itemid : $meta ] 换来 $money 金钱");
	}
	
	public function toHalfSpc($str){
		return str_replace("，",",",str_replace("：",":",str_replace("（","(",str_replace("）",")",str_replace("；",";",str_replace("［","[",str_replace("］","]",str_replace("｝","}",str_replace("｛","{",$str)))))))));
	}
	
	
	
	
	
	/*public function onTouch(PlayerInteractEvent $event){
		$block = $event->getBlock();
		$id=$block->getId();
			if($id==63 or $id==68){
			$player=$event->getPlayer();
			$args=$this->getSignText(new Vector3($block->getX(),$block->getY(),$block->getZ()),$block->getLevel());
			if(strpos($args[0],"[系统出售]")!==false){
				$event->setCancelled(true);
				try{
					$infos=explode(",",str_replace("{","",str_replace("}","",$this->toHalfSpc($args[3]))));
					$bid=(int)$infos[0];
					$dm=(int)$infos[1];
					$amo=(int)$infos[2];
					$mon=(int)$infos[3];
					$this->buyItem($player,$bid,$dm,$amo,$mon);
				}catch(Exception $e){
					$player->sendMessage("§c[木牌商店]操作失败:".e);
				}
			}else if(strpos($args[0],"[系统回收]")!==false){
				$event->setCancelled(true);
				try{
					$infos=explode(",",str_replace("{","",str_replace("}","",$this->toHalfSpc($args[3]))));
					$bid=(int)$infos[0];
					$dm=(int)$infos[1];
					$amo=(int)$infos[2];
					$mon=(int)$infos[3];
					$this->sellItem($player,$bid,$dm,$amo,$mon);
				}catch(Exception $e){
					$player->sendMessage("§c[木牌商店]操作失败:".e);
				}
			}else if(strpos($args[0],"[命令木牌]")!==false){
				$event->setCancelled(true);
				try{
					$this->getServer()->getCommandMap()->dispatch($player,$args[1]);
				}catch(Exception $e){
					$player->sendMessage("§c[命令木牌]操作失败:".e);
				}
			}else if(strpos($args[0],"[命令木牌]")!==false){
				$event->setCancelled(true);
				$this->runNrCmd($player,$args[1]);
			}else if(strpos($args[0],"[超级命令]")!==false){
				$event->setCancelled(true);
				$this->runSuCmd($player,$args[1]);
			}
		}
	}*/
	
	public function onBreak(BlockBreakEvent $event){
		$block = $event->getBlock();
		$id=$block->getId();
		if($id==63 or $id==68){
			$player=$event->getPlayer();
			if($this->getSignType($block->getLevel(),$block->getX(),$block->getY(),$block->getZ())===1){
				if(!$player->hasPermission("commandsign.shop")){
					$player->sendMessage("§e[SignShop]§c你没有权限移除这个木牌商店");
					$event->setCancelled(true);
					return;
				}else{
					if(!$event->isCancelled()){
						$player->sendMessage("§e[SignShop]§c木牌商店已移除");
						$sid=$this->getSignIndex($block->getLevel(),$block->getX(),$block->getY(),$block->getZ());
						unset($this->signs[$sid]);
						//$event->getLevel()->setBlock($block->getX(),$block->getY(),$block->getZ(),0,0);
						$this->saveSignCfg();
						return;
					}
				}
			}
			/*$args=$this->getSignText(new Vector3($block->getX(),$block->getY(),$block->getZ()),$block->getLevel());
			if(strpos($args[0],"[系统出售]")!==false or
				strpos($args[0],"[系统回收]")!==false
			){
				if($player->hasPermission("commandsign.shop")){
					if(!$event->isCancelled())
						$player->sendMessage("§e你移除了一个木牌商店");
				}else{
					$player->sendMessage("§c你没有权限移除该木牌商店");
					$event->setCancelled(true);
				}
			}else if(strpos($args[0],"[命令木牌]")!==false){
				if($player->hasPermission("commandsign.normal")){
					if(!$event->isCancelled())
						$player->sendMessage("§e你移除了一个命令木牌");
				}else{
					$player->sendMessage("§c你没有权限移除该命令木牌");
					$event->setCancelled(true);
				}
			}else if(strpos($args[0],"[超级命令]")!==false){
				if($player->hasPermission("commandsign.super")){
					if(!$event->isCancelled())
						$player->sendMessage("§e你移除了一个超级命令木牌");
				}else{
					$player->sendMessage("§c你没有权限移除该超级命令木牌");
					$event->setCancelled(true);
				}
			}*/
		}
	}
	
	public function getSignText(Vector3 $pos,Level $level){
		$sign=$level->getTile($pos);
		if($sign==null){
			$this->getServer()->getLogger()->info("[".pos."-".level."]找不到Tile");
			return null;
		}else{
			return $sign->getText();
		}
	}
	/*
	public function onSignChange(SignChangeEvent $event){
		 $tag=str_replace("\n","",$event->getLine(0));
		 $player=$event->getPlayer();
		 var_dump($event->getLines());
		 if((strpos($tag,"[系统回收]")!==false) or ($tag==="系统回收")){
			 if($player->hasPermission("commandsign.shop")){
				 $event->setLine(0,"§e[系统回收]\n");
				 $player->sendMessage("§e你创建了一个回收木牌商店");
			 }else{
				 $player->sendMessage("§c你没有权限创建一个回收木牌商店");
				 $event->setCancelled(true);
			 }
		 }
		 
		 if((strpos($tag,"[系统出售]")!==false) or ($tag==="系统出售")){
			 if($player->hasPermission("commandsign.shop")){
				 $event->setLine(0,"§e[系统出售]\n");
				 $player->sendMessage("§e你创建了一个出售木牌商店");
			 }else{
				 $player->sendMessage("§c你没有权限创建一个出售木牌商店");
				 $event->setCancelled(true);
			 }
		 }
		 
		 if((strpos($tag,"[命令木牌]")!==false) or ($tag==="命令") or ($tag==="命令木牌")){
			 if($player->hasPermission("commandsign.normal")){
				 $event->setLine(0,"§e[命令木牌]\n");
				 $player->sendMessage("§e你创建了一个命令木牌");
			 }else{
				 $player->sendMessage("§c你没有权限创建一个命令木牌");
				 $event->setCancelled(true);
			 }
		 }
		 
		 if((strpos($tag,"[超级命令]")!==false) or ($tag==="超级命令")){
			 if($player->hasPermission("commandsign.super")){
				 $event->setLine(0,"§e[超级命令]\n");
				 $player->sendMessage("§e你创建了一个超级命令木牌");
			 }else{
				 $player->sendMessage("§c你没有权限创建一个超级命令木牌");
				 $event->setCancelled(true);
			 }
		 }
	}*/
}
