<?php
#    _    _   _       _                                   _      _____                 _    
#   | |  | | (_)     | |              /\                 | |    / ____|               | |   
#   | |__| |  _    __| |   ___       /  \     _ __     __| |   | (___     ___    ___  | | __
#   |  __  | | |  / _` |  / _ \     / /\ \   | '_ \   / _` |    \___ \   / _ \  / _ \ | |/ /
#   | |  | | | | | (_| | |  __/    / ____ \  | | | | | (_| |    ____) | |  __/ |  __/ |   < 
#   |_|  |_| |_|  \__,_|  \___|   /_/    \_\ |_| |_|  \__,_|   |_____/   \___|  \___| |_|\_\
#                                                                                           
#                                                                                           
# The original minigame, free, and better than ever !
# @author Ad5001
# @link ad5001.eu

namespace Ad5001\HideAndSeek;
 
use pocketmine\Server;
use pocketmine\Player;
use pocketmine\level\Level;
use pocketmine\event\Listener;
use pocketmine\math\Vector3;
use pocketmine\scheduler\PluginTask;

use Ad5001\HideAndSeek\Main;
use Ad5001\HideAndSeek\GameManager;

class Game extends PluginTask /* Allows easy game running */ implements Listener {

    const STEP_WAIT = 0;
    const STEP_STARTING = 1;
    const STEP_HIDE = 2;
    const STEP_SEEK = 3;
    const STEP_WIN = 4;

    const NO_WIN = 0;
    const WIN_SEEKERS = 1;
    const WIN_HIDERS = 2;

    const ROLE_WAIT = 0;
    const ROLE_SEEK = 1;
    const ROLE_HIDE = 2;
    const ROLE_SPECTATE = 2;

    // Level based informations
    protected $level;
    protected $players = [];
    protected $spectators = [];

    // Game based informations
    protected $step = self::STEP_WAIT;
    protected $win = self::NO_WIN;
    protected $stepTick;
    protected $hidersLeft;
    protected $seekersCount;
    
    /*
    Constructs the class
    @param     $level      Level
    */
    public function __construct(Level $level) {
        // Initialisation
        $this->level = $level;
        $this->initDB();
        $level->game = $this;
        $this->getMain()->getServer()->getPluginManager()->registerEvents($this,$this->getMain());

        // Registering players
        foreach($this->getLevel()->getPlayers() as $p) {
            $this->registerPlayer($p);
        }

        // Loading timer.
        parent::__construct($this->getMain());
        // $this->getMain()->getServer()->getScheduler()->scheduleRepeatingTask($this, 1);
    }

    /*
    Function that runs every x ticks
    @param     $tick    int
    */
    public function onRun($tick) {
        switch($this->step) {
            case self::STEP_WAIT:
            if(count($this->getPlayers()) >= round($this->getMaxPlayers() * 0.75)) {
                $this->stepTick = $tick;
                $this->step = self::STEP_START;
                foreach(array_merge($this->getPlayers(), $this->getSpectators()) as $p) {
                    $p->sendMessage(Main::PREFIX . "§aGame will start in " . $this->getWaitTime() . " seconds.");
                }
            }
            break;
            case self::STEP_START:
            $tickWaited = $tick - $this->stepTick;
            if($tickWaited % 20 == 0) {
                foreach(array_merge($this->getPlayers(), $this->getSpectators()) as $p) {
                    $p->sendMessage(Main::PREFIX . "§aGame will start in " . ($this->getWaitTime() - ($tickWaited / 20)) . " seconds.");
                }
            }
            if($this->getWaitTime() - ($tickWaited / 20) <= 0) {
                $this->stepTick = $tick;
                $this->start();
                foreach(array_merge($this->getPlayers(), $this->getSpectators()) as $p) {
                    $p->sendMessage(Main::PREFIX . "§aGame started ! There is $this->seekersCount seekers and $this->hidersLeft hiders.");
                    if($p->HideAndSeekRole == self::ROLE_SEEK) {
                        $p->teleport($this->getSeekerSpawn());
                    } elseif($p->HideAndSeekRole == self::ROLE_HIDE) {
                        $p->teleport($this->getSpawn());
                        $p->sendPopup("§lHider: You have 1 minute to hide yourself so seekers won't find you ! Don't get caught for " . $this->getSeekTime() . " minutes to win !");
                    }
                }
            }
            break;
            case self::STEP_HIDE:
            $tickWaited = $tick - $this->stepTick;
            if($tickWaited >= 20*60) { // One minute has past !
                $this->step = self::STEP_SEEK;
                $this->stepTick = $tick;
                foreach(array_merge($this->getPlayers(), $this->getSpectators()) as $p) {
                    $p->sendMessage(Main::PREFIX . "§aSeekers released !");
                    if($p->HideAndSeekRole == self::ROLE_SEEK) {
                        $p->teleport($this->getSpawn());
                        $p->sendPopup("§lSeeker: Seek the hiders ! Catch them all to win in " . $this->getSeekTime() . " minutes to win !");
                    }
                }
            }
            break;
            case self:STEP_SEEK:
            $tickWaited = $tick - $this->stepTick;
            if($tickWaited % 20*60 == 0) {
                foreach(array_merge($this->getPlayers(), $this->getSpectators()) as $p) {
                    $p->sendMessage(Main::PREFIX . "§aGame ends in " . ($this->getSeekTime() - ($tickWaited / 20 / 60)) . " minutes.");
                }
            }
            if($tickWaited >= 20*60*$this->getSeekTime()) { // Seek time has past 
                $this->win = self::WIN_HIDERS;
                $this->step = self::STEP_WIN;
            }
            break;
            case self::STEP_WIN:
            foreach(array_merge($this->getPlayers(), $this->getSpectators()) as $p) {
                if($this->win == self::WIN_SEEKERS) {
                    $p->sendMessage(Main::PREFIX . "§aThe last hider got caught ! Seekers won !");
                    $p->sendTip("§a§lSeekers won !");
                } elseif($this->win == self::WIN_SEEKERS) {
                    $p->sendMessage(Main::PREFIX . "§aTimes up ! Hiders won !");
                    $p->sendTip("§a§lHiders won !");
                } else {
                    $p->sendMessage(Main::PREFIX . "§aGame cancelled !");
                }
                $p->teleport($this->getLobbyWorld());
                $p->setGamemode($this->getMain()->getServer()->getDefaultGamemode());
            }
            $this->step = self::STEP_WAIT;
            break;
        }
    }


    /*
    Inits the database for the game.
    */
    protected function initDB() {
        $qry = $this->getMain()->getDatabase()->get("*", ["table" => "Games", "name" => $this->getName()]);
        if($qry instanceof \SQLite3Result) {
            if($qry->num_rows == 0) { // Game not initiated in the db.
                $id = $this->getMain()->getDatabase()->get("*", ["table" => "Games"]);
                $v3 = $this->getLevel()->getSafeSpawn();
                $v3Ser = $v3->x . "," . $v3->y . "," . $v3->z; // V32String
                $this->getMain()->getDatabase()->insert("Games", [$this->getName(), $v3Ser, $v3Ser, $this->getMain()->getMaxPlayers(), $this->getMain()->getWaitTime(), $this->getMain()->getSeekTime(), $this->getMain()->getSeekersPercentage(), $id->num_rows+1]); // Inserting the db with new queries
            }
        } else {
            throw new \Exception("Could not contact database.");
        }
    }




#               _____    _____ 
#       /\     |  __ \  |_   _|
#      /  \    | |__) |   | |  
#     / /\ \   |  ___/    | |  
#    / ____ \  | |       _| |_ 
#   /_/    \_\ |_|      |_____|
#                              
#                              


    /*
    Starts the game
    */
    public function start() {
        $count = count($this->players);
        $this->seekersCount = round($count * ($this->getSeekersPercentage() / 100), 0, PHP_ROUND_HALF_UP); // Minimum $this->getSeekersPercentage() percents of the players (inimum because if there are less than $this->getSeekersPercentage(), then there could be no seeker)
        $this->hidersLeft = count($this->players) - $this->seekersCount;

        shuffle($this->players);
        $i = 0;
        foreach($this->players as $p) {
            if($i < $this->seekersCount) {
                $this->players[$i]->HideAndSeekRole = self::ROLE_SEEK;
                $this->players[$i]->sendTip("§4You're a seeker.");
            } else {
                $this->players[$i]->HideAndSeekRole = self::ROLE_HIDE;
                $this->players[$i]->sendTip("§4You're an hider.");
            }
            $i++;
        }
    }

    /*
    Returns game's spawn (waiting + for players) spawn
    @return \pocketmine\math\Vector3 
    */
    public function getSpawn() : Vector3 {
        $data = $this->getMain()->getDatabase()->get("spawnpoint", ["table" => "Games", "name" => $this->getName()])->fetchArray()[0];
        $data = explode(",", $data);
        return new Vector3($data[0], $data[1], $data[2]);
    }


    /*
    Returns seekers spawn (waiting hiders to hide + startup)
    @return \pocketmine\math\Vector3 
    */
    public function getSeekerSpawn() : Vector3 {
        $data = $this->getMain()->getDatabase()->get("seekerspawn", ["table" => "Games", "name" => $this->getName()])->fetchArray()[0];
        $data = explode(",", $data);
        return new Vector3($data[0], $data[1], $data[2]);
    }


    /*
    Returns the game level.
    @return \pocketmine\level\Level
    */
    public function getLevel() : Level {
        return $this->level;
    }


    /*
    Returns the game's name.
    @return string
    */
    public function getName() : string {
        return $this->level->getName();
    }

    
    /*
    Returns the game's players.
    @return array
    */
    public function getPlayers() : array {
        return $this->players;
    }

    
    /*
    Returns the game's spectators.
    @return array
    */
    public function getSpectators() : array {
        return $this->spectators;
    }

    
    /*
    Returns a player by it's name.
    @param $name string
    @return Player|null
    */
    public function getPlayer(string $name) {
        return isset($this->players[$name]) ? $this->players[$name] : null;
    }

    /*
    Returns the main class
    */
    public function getMain() {
        return Server::getInstance()->getPluginManager()->getPlugin("HideAndSeek");
    }

    /*
    Returns the game id
    */
    public function getId() {
        return $this->getGameManager()->getGameId($this);
    }
    
    
    /*
    Returns the max players in a game.
    @return int
    */
    public function getMaxPlayers() : int {
        return (int) $this->getMain()->getDatabase()->get("max_players", ["table" => "Games", "name" => $this->getName()])->fetchArray()[0];
    }

    /*
    Returns the time to wait between 75% of max players joined and the game start (ending filling if needed)
    @return int
    */
    public function getWaitTime() : int {
        return (int) $this->getMain()->getDatabase()->get("waiting_time", ["table" => "Games", "name" => $this->getName()])->fetchArray()[0];
    }

    /*
    Returns the time that the seekers have to find all hiders (used to balance with the max players)
    @return int
    */
    public function getSeekTime() : int {
        return (int) $this->getMain()->getDatabase()->get("seek_time", ["table" => "Games", "name" => $this->getName()])->fetchArray()[0];
    }

    /*
    Returns the percentage of the players that should be hunters.
    @return int
    */
    public function getSeekersPercentage() : int {
        return (int) $this->getMain()->getDatabase()->get("seekers_percentage", ["table" => "Games", "name" => $this->getName()])->fetchArray()[0];
    }
    
    // SET

    /*
    Sets the spawn of the game
    @param     $v3    Vector3
    */
    public function setSpawn(Vector3 $v3) {
        $str = $v3->x . "," . $v3->y . "," . $v3->z;
        return $this->getMain()->getDatabase()->set("spawnpoint", $str, ["table" => "Games", "name" => $this->getName()])->fetchArray()[0];
    }

    /*
    Sets the seeker spawn. Check get for more details.
    @param     $v3    Vector3
    */
    public function setSeekerSpawn(Vector3 $v3) {
        $str = $v3->x . "," . $v3->y . "," . $v3->z;
        return $this->getMain()->getDatabase()->set("seekerspawn", $str, ["table" => "Games", "name" => $this->getName()])->fetchArray()[0];
    }

    /*
    Changes the level of the game
    @param     $level    Level
    */
    public function setLevel(Level $level) {
        $this->level = $level;
        return $this->getMain()->getDatabase()->set("name", $level->getName(), ["table" => "Games", "name" => $this->getName()])->fetchArray()[0];
    }

    /*
    Sets a maximum number of players
    @param     $int    int
    */
    public function setMaxPlayers(int $int) {
        return $this->getMain()->getDatabase()->set("max_players", $level->getName(), ["table" => "Games", "name" => $this->getName()])->fetchArray()[0];
    }

    /*
    Sets the waiting time in the lobby
    @param     $int    int
    */
    public function setWaitTime(int $int) {
        return $this->getMain()->getDatabase()->set("waiting_time", $level->getName(), ["table" => "Games", "name" => $this->getName()])->fetchArray()[0];
    }

    /*
    Sets the seeking time
    @param     $int    int
    */
    public function setSeekTime(int $int) {
        return $this->getMain()->getDatabase()->set("seek_time", $level->getName(), ["table" => "Games", "name" => $this->getName()])->fetchArray()[0];
    }

    /*
    Sets a the percentage of the seekers
    @param     $int    int
    */
    public function setSeekersPercentage(int $int) {
        return $this->getMain()->getDatabase()->set("seekers_percentage", $level->getName(), ["table" => "Games", "name" => $this->getName()])->fetchArray()[0];
    }

    /*
    Registers a player
    @param     $player    \pocketmine\Player
    */
    public function registerPlayer(Player $player) {
        if($player->isSpectator() || 
            (!is_null($this->getMain()->getServer()->getPluginManager()->getPlugin("SpectatorPlus")) &&
             $this->getMain()->getServer()->getPluginManager()->getPlugin("SpectatorPlus")->isSpectator($player)))  { // Support for spectator Plus
                 $this->spectators[$player->getName()] = $player;
                 $player->HideAndSeekRole = self::ROLE_SPECTATE;
        } elseif($this->step == self::STEP_WAIT || $this->step == self::STEP_START) {
            // API inside player's class (easilier to get data)
            $this->sendMessage("§a" . $player->getName() . " joined (" . count($this->players) . "/" . $this->getMaxPlayers() . "). " . (count($this->players) - round($this->getMaxPlayers() * 0.75)) . "players left before starting");
            $player->hideAndSeekGame = $this;
            $player->HideAndSeekRole = self::ROLE_WAIT;
            $player->playsHideAndSeek = true;
            $this->players[$player->getName()] = $player;
            $player->setGamemode(2); // Set it to adventure so player cannot break blocks.
        } else {
            $this->spectators[$player->getName()] = $player;
            $player->HideAndSeekRole = self::ROLE_SPECTATE;
            $player->setGamemode(3);
        }
    }

    /*
    Unregisters a player
    @param     $player    \pocketmine\Player
    */
    public function unregisterPlayer(Player $player) {
        switch(isset($player->HideAndSeekRole) ? $player->HideAndSeekRole : -1) {
            case self::ROLE_SEEK:
            $this->seekersCount--;
            $this->sendMessage($player->getName() . " left the game. $this->seekersCount seekers left.");
            unset($this->players[$player->getName()]);
            unset($player->hideAndSeekGame);
            unset($player->playsHideAndSeek);
            if($this->seekersCount == 0) {
                $this->step = self::STEP_WIN;
                $this->win = self::WIN_HIDERS;
            }
            break;
            case self::ROLE_HIDE:
            $this->hidersLeft--;
            $this->sendMessage($player->getName() . " left the game. $this->hidersLeft hiders left.");
            unset($this->players[$player->getName()]);
            unset($player->hideAndSeekGame);
            unset($player->playsHideAndSeek);
            if($this->hidersLeft == 0) {
                $this->step = self::STEP_WIN;
                $this->win = self::WIN_SEEKERS;
            }
            break;
            case self::ROLE_WAIT:
            unset($this->players[$player->getName()]);
            unset($player->hideAndSeekGame);
            unset($player->playsHideAndSeek);
            break;
            case self::ROLE_SPECTATE:
            unset($this->spectators[$player->getName()]);
            break;
        }
    }


    /*
    Sends a message to all players and spectators in the game.
    @param     $message    string
    */
    public function sendMessage(string $message) {
        foreach(array_merge($this->getPlayers(), $this->getSpectators()) as $p) {
            $p->sendMessage(Main::PREFIX . $message);
        }
    }


#    ______                          _         
#   |  ____|                        | |        
#   | |__    __   __   ___   _ __   | |_   ___ 
#   |  __|   \ \ / /  / _ \ | '_ \  | __| / __|
#   | |____   \ V /  |  __/ | | | | | |_  \__ \
#   |______|   \_/    \___| |_| |_|  \__| |___/
#                                              
#                                              

    /*
    Checks when an entity changes level to add a player to the list/remove one.
    @param     $event    \pocketmine\event\entity\EntityLevelChangeEvent
    */
    public function onEntityLevelChange(\pocketmine\event\entity\EntityLevelChangeEvent $event) {
        if($event->getTarget()->getName() == $this->getName() && $event->getEntity() instanceof Player) {
            if(count($this->players) >= $this->getMaxPlayers()) {
                $event->setCancelled();
                $event->getEntity()->sendMessage(Main::PREFIX . "§cThe maximum number of players in this game has been reached.");
            }
            $this->registerPlayer($event->getEntity());
        } elseif($event->getOrigin()->getName() == $this->getName() && $event->getEntity() instanceof Player) {
            $this->unregisterPlayer($event->getEntity());
        }
    }

    /*
    Checks when a block breaks to prevent it.
    @param     $event    \pocketmine\event\block\BlockBreakEvent
    */
    public function onBlockBreak(\pocketmine\event\block\BlockBreakEvent $event) {
        if($event->getBlock()->getLevel()->getLevel() == $this->getName()) {
            $event->setCancelled();
        }
    }

    /*
    Checks when a block places to prevent it.
    @param     $event    \pocketmine\event\block\BlockPlaceEvent
    */
    public function onBlockPlace(\pocketmine\event\block\BlockPlaceEvent $event) {
        if($event->getBlock()->getLevel()->getLevel() == $this->getName()) {
            $event->setCancelled();
        }
    }


    /*
    Checks when an entity get hurts (used to tag.)
    @param     $event    \pocketmine\event\entity\EntityDamageEvent
    */
    public function onEntityDamage(\pocketmine\event\entity\EntityDamageEvent $event) {
        if($event->getEntity()->getLevel() == $this->getName()) {
            if($event instanceof \pocketmine\event\entity\EntityDamageByEntityEvent
            && $event->getEntity() instanceof Player 
            && $event->getDamager() instanceof Player
            && (isset($event->getDamager()->HideAndSeekRole) ? $event->getDamager()->HideAndSeekRole : -1) == self::ROLE_SEEK
            && (isset($event->getEntity()->HideAndSeekRole) ? $event->getEntity()->HideAndSeekRole : -1) == self::ROLE_HIDE) { // Tagging
                $event->getEntity()->HideAndSeekRole = self::ROLE_SEEK;
                $event->getEntity()->teleport($this->getSpawn());
            }
            $event->setCancelled();
        }
    }


    /*
    Checks when a player dies to prevent it.
    @param     $event    \pocketmine\event\player\PlayerDeathEvent
    */
    public function onPlayerDeath(\pocketmine\event\player\PlayerDeathEvent $event) {
        if($event->getPlayer()->getLevel() == $this->getName()) {
            $event->setCancelled();
        }
    }



    /*
    Checks when a player joins in the world to make him rejoin automaticly
    @param     $event    \pocketmine\event\player\PlayerJoinEvent
    */
    public function onPlayerJoin(\pocketmine\event\player\PlayerJoinEvent $event) {
        if($event->getPlayer()->getLevel() == $this->getName()) {
            $this->registerPlayer($event->getPlayer());
        }
    }

    /*
    Checks when a player leaves in the world to make him die.
    @param     $event    \pocketmine\event\player\PlayerQuitEvent
    */
    public function onPlayerQuit(\pocketmine\event\player\PlayerQuitEvent $event) {
        if($event->getPlayer()->getLevel() == $this->getName()) {
            $this->unregisterPlayer($event->getPlayer());
        }
    }
    



}