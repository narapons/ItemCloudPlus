<?php

namespace onebone\itemcloud;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\Listener;
use pocketmine\item\Item;
use pocketmine\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\TextFormat;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\block\BlockIds;
use pocketmine\utils\Utils;
use pocketmine\utils\Config;


class MainClass extends PluginBase implements Listener{
	/**
	 * @var MainClass
	 */
	private static $instance;

	/**
	 * @var ItemCloud[]
	 */
	public $clouds;

	/**
	 * @return MainClass
	 */
	public static function getInstance(){
		return self::$instance;
	}

	/**
	 * @param Player|string $player
	 *
	 * @return ItemCloud|bool
	 */
	public function getCloudForPlayer($player){
		if($player instanceof Player){
			$player = $player->getName();
		}
		$player = strtolower($player);

		if(isset($this->clouds[$player])){
			return $this->clouds[$player];
		}
		return false;
	}

	/**************************   Non-API part   ***********************************/

	public function onLoad(){
		if(!self::$instance instanceof MainClass){
			self::$instance = $this;
		}
	}

	public function onEnable(){
		$this->breakdate = new Config($this->getDataFolder() ."BreakDate.yml", Config::YAML);
		$this->CheckID = new Config(
			$this->getDataFolder() . "CheckID.yml", Config::YAML, array(
				"ID"=> ["1:0","2:0","3:0","4:0","5:0","6:0","10:0","11:0","12:0","13:0","14:0","15:0","16:0","17:0","18:0","19:0","20:0"]));
		@mkdir($this->getDataFolder());
		if(!is_file($this->getDataFolder() . "ItemCloud.dat")){
			file_put_contents($this->getDataFolder() . "ItemCloud.dat", serialize([]));
		}
		$data = unserialize(file_get_contents($this->getDataFolder() . "ItemCloud.dat"));

		$this->saveDefaultConfig();
		if(is_numeric($interval = $this->getConfig()->get("auto-save-interval"))){
			$this->getScheduler()->scheduleDelayedRepeatingTask(new SaveTask($this), $interval * 1200, $interval * 1200);
		}

		$this->clouds = [];
		foreach($data as $datam){
			$this->clouds[$datam[1]] = new ItemCloud($datam[0], $datam[1]);
		}
		$this->getServer()->getPluginManager()->registerEvents($this,$this);
	}

	public function onCommand(CommandSender $sender, Command $command, string $label, array $params) : bool{
		switch($command->getName()){
			case "itemcloud":
				if(!$sender instanceof Player){
					$sender->sendMessage("Please run this command in-game");
					return true;
				}
				$sub = array_shift($params);
				switch($sub){
					case "register":
					case "reg":
						if(!$sender->hasPermission("itemcloud.command.register")){
							$sender->sendMessage(TextFormat::RED . "You don't have permission to use this command.");
							return true;
						}
						if(isset($this->clouds[strtolower($sender->getName())])){
							$sender->sendMessage("[ItemCloud] You already have an ItemCloud account");
							break;
						}
						$this->clouds[strtolower($sender->getName())] = new ItemCloud([], $sender->getName());
						$sender->sendMessage("[ItemCloud] Registered with ItemCloud");
						break;
					case "upload":
					case "up":
						if(!$sender->hasPermission("itemcloud.command.upload")){
							$sender->sendMessage(TextFormat::RED . "You don't have permission to use this command.");
							return true;
						}
						if(!isset($this->clouds[strtolower($sender->getName())])){
							$sender->sendMessage("[ItemCloud] Please register with ItemCloud first.");
							break;
						}
						$item = array_shift($params);
						$amount = array_shift($params);
						if(trim($item) === "" or !is_numeric($amount)){
							$sender->sendMessage("Usage: /itemcloud upload <item ID[:item damage]> <count>");
							break;
						}
						$amount = (int) $amount;
						if($amount < 1){
							$sender->sendMessage("Wrong amount");
							break;
						}
						$item = Item::fromString($item);
						$item->setCount($amount);

						$count = 0;
						foreach($sender->getInventory()->getContents() as $i){
							if($i->getID() == $item->getID() and $i->getDamage() == $item->getDamage()){
								$count += $i->getCount();
							}
						}
						if($amount <= $count){
							$this->clouds[strtolower($sender->getName())]->addItem($item->getID(), $item->getDamage(), $amount, true);
							$sender->sendMessage("[ItemCloud] Uploaded your items to ItemCloud");
						}else{
							$sender->sendMessage("[ItemCloud] You don't have enough items to upload.");
						}
						break;
					case "download":
					case "down":
						if(!$sender->hasPermission("itemcloud.command.download")){
							$sender->sendMessage(TextFormat::RED . "You don't have permission to use this command.");
							return true;
						}
						$name = strtolower($sender->getName());
						if(!isset($this->clouds[$name])){
							$sender->sendMessage("[ItemCloud] Please register with ItemCloud first.");
							break;
						}
						$item = array_shift($params);
						$amount = array_shift($params);
						if(trim($item) === "" or !is_numeric($amount)){
							$sender->sendMessage("Usage: /itemcloud download <item ID[:item damage]> <count>");
							break;
						}
						$amount = (int) $amount;
						if($amount < 1){
							$sender->sendMessage("Wrong amount");
							break;
						}
						$item = Item::fromString($item);
						$item->setCount($amount);

						if(!$this->clouds[$name]->itemExists($item->getID(), $item->getDamage(), $amount)){
							$sender->sendMessage("[ItemCloud] You don't have enough items in your account.");
							break;
						}

						if($sender->getInventory()->canAddItem($item)){
							$this->clouds[$name]->removeItem($item->getID(), $item->getDamage(), $amount);
							$sender->getInventory()->addItem($item);
							$sender->sendMessage("[ItemCloud] You have downloaded items from the ItemCloud account.");
						}else{
							$sender->sendMessage("[ItemCloud] You have no space to download items.");
						}
						break;
					case "list":
						if(!$sender->hasPermission("itemcloud.command.list")){
							$sender->sendMessage(TextFormat::RED . "You don't have permission to use this command.");
							return true;
						}
						$name = strtolower($sender->getName());
						if(!isset($this->clouds[$name])){
							$sender->sendMessage("[ItemCloud] Please register with ItemCloud first.");
							break;
						}
						$output = "[ItemCloud] Item list : \n";
						foreach($this->clouds[$name]->getItems() as $item => $count){
							$output .= "$item : $count\n";
						}
						$sender->sendMessage($output);
						break;
					case "count":
						if(!$sender->hasPermission("itemcloud.command.count")){
							$sender->sendMessage(TextFormat::RED . "You don't have permission to use this command.");
							return true;
						}
						$name = strtolower($sender->getName());
						if(!isset($this->clouds[$name])){
							$sender->sendMessage("[ItemCloud] Please register with ItemCloud first.");
							return true;
						}
						$item = array_shift($params);
						if(trim($item) === ""){
							$sender->sendMessage("Usage: /itemcloud count <item>");
							return true;
						}

						$item = Item::fromString($item);

						if(($count = $this->clouds[$name]->getCount($item->getId(), $item->getDamage())) === false){
							$sender->sendMessage("[ItemCloud] There are no " . $item->getName() . " in your account.");
							break;
						}else{
							$sender->sendMessage("[ItemCloud] Count of " . $item->getName() . " = " . $count);
						}
						break;
					case "onbreak":
						$user_name = $sender->getName();
						if(!$this->breakdate->exists("allbreakdate")){
							if($this->breakdate->exists($user_name)){
								$sender->sendMessage("[ItemCloud] 既に有効です。");
						        }else{
								$this->breakdate->set($user_name,count($this->breakdate->getAll())+1);
							        $this->breakdate->save();
							        $this->breakdate->reload();
						                $sender->sendMessage("[ItemCloud] ブロックを壊すと直接アイテムクラウドに行くようになりました。");
						        }
						}else{
							$sender->sendMessage("[ItemCloud] 管理者によって設定が固定されているため変更できません");
						}
						break;
					case "offbreak":
						$user_name = $sender->getName();
						if(!$this->breakdate->exists("allbreakdate")){
							if(!$this->breakdate->exists($user_name)){
								$sender->sendMessage("[ItemCloud] 既に無効です。");
						        }else{
							        $this->breakdate->remove($user_name);
								$sender->sendMessage("[ItemCloud] ブロックを壊しても直接アイテムクラウドに行かなくなりました。");
						        }
						}else{
							$sender->sendMessage("[ItemCloud] 管理者によって設定が固定されているため変更できません");
						}
						break;
					case "allonbreak":
						if($sender->isOp()){
							if($this->breakdate->exists("allbreakdate")){
								$sender->sendMessage("[Itemcloud] 既に有効です。");
					                }else{
							        $this->breakdate->set("allbreakdate", "allbreak");
							        $this->breakdate->save();
							        $this->breakdate->reload();
						                $sender->sendMessage("[ItemCloud] 全員を対象にブロックを壊すと直接アイテムクラウドに行くようになりました。");
							}
	     			  	        }else{
							$sender->sendMessage("§cこのコマンドを実行する権限がありません。");
						}
						break;
					case "playerbreak":
						if($sender->isOp()){
							if(!$this->breakdate->exists("allbreakdate")){
								$sender->sendMessage("[Itemcloud] 既に無効です。");
					                }else{
							        $this->breakdate->remove("allbreakdate");
						      	        $this->breakdate->save();
							        $this->breakdate->reload();
						                $sender->sendMessage("[ItemCloud] 全員を対象とする設定が無効になりました。");
							}
	     			  	        }else{
							$sender->sendMessage("§cこのコマンドを実行する権限がありません。");
						}
						break;
			  	        default:
						$sender->sendMessage("[ItemCloud] Usage: " . $command->getUsage());
				}
				return true;
		}
		return true;
	}
	
	public function onBreak(BlockBreakEvent $event){
		$player = $event->getPlayer();
		$name = $player->getName();
		$block = $event->getBlock()
		$IDs = $block->getID();
		$Dam = $block->getDamage();
                $data = $this->CheckID->get("ID");
                $item = explode(":",$data[0]);
                $ID1 = var_dump($item[0]);//アイテムID
                $IDD1 = var_dump($item[1]);//ダメージID
                $item = explode(":",$data[1]);
                $ID2 = var_dump($item[0]);
                $IDD2 = var_dump($item[1]);
		$item = explode(":",$data[2]);
		$ID3 = var_dump($item[0]);
		$IDD3 = var_dump($item[1]);
		$item = explode(":",$data[3]);
                $ID4 = var_dump($item[0]);
                $IDD4 = var_dump($item[1]);
		$item = explode(":",$data[4]);
                $ID5 = var_dump($item[0]);
                $IDD5 = var_dump($item[1]);
		$item = explode(":",$data[5]);
                $ID6 = var_dump($item[0]);
                $IDD6 = var_dump($item[1]);
		$item = explode(":",$data[6]);
                $ID7 = var_dump($item[0]);
                $IDD7 = var_dump($item[1]);
		$item = explode(":",$data[7]);
                $ID8 = var_dump($item[0]);
                $IDD8 = var_dump($item[1]);
		$item = explode(":",$data[8]);
                $ID9 = var_dump($item[0]);
                $IDD9 = var_dump($item[1]);
		$item = explode(":",$data[9]);
                $ID10 = var_dump($item[0]);
                $IDD10 = var_dump($item[1]);
		$item = explode(":",$data[10]);
                $ID11 = var_dump($item[0]);
                $IDD11 = var_dump($item[1]);
		$item = explode(":",$data[11]);
                $ID12 = var_dump($item[0]);
                $IDD12 = var_dump($item[1]);
		$item = explode(":",$data[12]);
                $ID13 = var_dump($item[0]);
                $IDD13 = var_dump($item[1]);
		$item = explode(":",$data[13]);
                $ID14 = var_dump($item[0]);
                $IDD14 = var_dump($item[1]);
		$item = explode(":",$data[14]);
                $ID15 = var_dump($item[0]);
                $IDD15 = var_dump($item[1]);
		$item = explode(":",$data[15]);
                $ID16 = var_dump($item[0]);
                $IDD16 = var_dump($item[1]);
		$item = explode(":",$data[16]);
                $ID17 = var_dump($item[0]);
                $IDD17 = var_dump($item[1]);
		$item = explode(":",$data[17]);
                $ID18 = var_dump($item[0]);
                $IDD18 = var_dump($item[1]);
		$item = explode(":",$data[18]);
                $ID19 = var_dump($item[0]);
                $IDD19 = var_dump($item[1]);
		$item = explode(":",$data[19]);
                $ID20 = var_dump($item[0]);
                $IDD20 = var_dump($item[1]); //CheckID.ymlの取得
		if (!$player->isOp()){
			if(!$event->isCancelled()){
			   if($ID1 == $IDs, $IDD1 == $Dam or $ID2 == $IDs, $IDD2 == $Dam or $ID3 == $IDs, $IDD3 == $Dam or $ID4 == $IDs, $IDD4 == $Dam or $ID5 == $IDs, $IDD5 == $Dam or $ID6 == $IDs, $IDD6 == $Dam or $ID7 == $IDs, $IDD7 == $Dam or $ID8 == $IDs, $IDD8 == $Dam or $ID9 == $IDs, $IDD9 == $Dam or $ID10 == $IDs, $IDD10 == $Dam or $ID11 == $IDs, $IDD11 == $Dam or $ID12 == $IDs, $IDD12 == $Dam or $ID13 == $IDs, $IDD13 == $Dam or $ID14 == $IDs, $IDD14 == $Dam or $ID15 == $IDs, $IDD15 == $Dam or $ID16 == $IDs, $IDD16 == $Dam or $ID17 == $IDs, $IDD17 == $Dam or $ID18 == $IDs, $IDD18 == $Dam or $ID19 == $IDs, $IDD19 == $Dam or $ID20 == $IDs, $IDD20 == $Dam){
				if($this->breakdate->exists($name)){
					if(!isset($this->clouds[strtolower($name)])){
						$player->sendMessage("[ItemCloud] ItemCloudのアカウントがありません。作成してください。");
				                $event->setCancelled();
				        }else{
					        $event->setDrops([]);
			                        $this->clouds[strtolower($name)]->addItemBreak($block->getID(), $block->getDamage(), 1, true);
					}
				}
				if($this->breakdate->exists("allbreakdate")){
					if(!isset($this->clouds[strtolower($name)])){
						$player->sendMessage("[ItemCloud] ItemCloudのアカウントがありません。作成してください。");
				                $event->setCancelled();
				        }else{
					        $event->setDrops([]);
			                        $this->clouds[strtolower($name)]->addItemBreak($block->getID(), $block->getDamage(), 1, true);
					}
				}
			   }
	                }
		}
	}

	public function save(){
		$save = [];
		foreach($this->clouds as $cloud){
			$save[] = $cloud->getAll();
		}
		file_put_contents($this->getDataFolder() . "ItemCloud.dat", serialize($save));
	}

	public function onDisable(){
		$this->save();
		$this->clouds = [];
	}
}
