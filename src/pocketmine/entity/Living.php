<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
*/

namespace pocketmine\entity;

use pocketmine\block\Block;
use pocketmine\entity\AI\EntityAITasks;
use pocketmine\entity\AI\EntityLookHelper;
use pocketmine\entity\AI\EntityMoveHelper;
use pocketmine\entity\AI\EntityJumpHelper;
use pocketmine\entity\AI\pathfinding\PathNavigateGround;
use pocketmine\event\entity\EntityDamageByChildEntityEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityDeathEvent;
use pocketmine\event\entity\EntityRegainHealthEvent;
use pocketmine\event\Timings;
use pocketmine\item\Item as ItemItem;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\ShortTag;
use pocketmine\network\protocol\EntityEventPacket;
use pocketmine\utils\BlockIterator;

abstract class Living extends Entity implements Damageable{

	protected $gravity = 0.08;
	protected $drag = 0.02;

	protected $attackTime = 0;

	protected $invisible = false;

	protected $navigator;
	public $tasks;
	public $targetTasks;
	protected $lookHelper;
	protected $moveHelper;
	protected $jumpHelper;

	protected $isJumping = false;
	public $jumpMovementFactor = 0.02;
	private $jumpTicks = 0;

	public $moveForward = 0.0;
	public $moveStrafing = 0.0;
	public $landMovementFactor;
	private $attackTarget;
	private $entityLivingToAttack;
	private $revengeTimer = -1;

	protected function initEntity(){
		parent::initEntity();

		if(isset($this->namedtag->HealF)){
			$this->namedtag->Health = new ShortTag("Health", (int) $this->namedtag["HealF"]);
			unset($this->namedtag->HealF);
		}elseif(!isset($this->namedtag->Health) or !($this->namedtag->Health instanceof ShortTag)){
			$this->namedtag->Health = new ShortTag("Health", $this->getMaxHealth());
		}
		$this->setHealth($this->namedtag["Health"]);
	}

	protected function addAttributes(){
		$this->attributeMap->addAttribute(Attribute::getAttribute(Attribute::HEALTH));
		$this->attributeMap->addAttribute(Attribute::getAttribute(Attribute::FOLLOW_RANGE));
		$this->attributeMap->addAttribute(Attribute::getAttribute(Attribute::KNOCKBACK_RESISTANCE));
		$this->attributeMap->addAttribute(Attribute::getAttribute(Attribute::MOVEMENT_SPEED));
		$this->attributeMap->addAttribute(Attribute::getAttribute(Attribute::ATTACK_DAMAGE));
		$this->attributeMap->addAttribute(Attribute::getAttribute(Attribute::ABSORPTION));
	}

	public function setHealth($amount){
		$wasAlive = $this->isAlive();
		parent::setHealth($amount);
		if($this->isAlive() and !$wasAlive){
			$pk = new EntityEventPacket();
			$pk->eid = $this->getId();
			$pk->event = EntityEventPacket::RESPAWN;
			$this->server->broadcastPacket($this->hasSpawned, $pk);
		}
	}

	public function setMaxHealth($amount){
		parent::setMaxHealth($amount);
	}

	public function saveNBT(){
		parent::saveNBT();
		$this->namedtag->Health = new ShortTag("Health", $this->getHealth());
	}

	public abstract function getName();

	public function hasLineOfSight(Entity $entity){
		//TODO: head height
		return true;
		//return $this->getLevel()->rayTraceBlocks(Vector3::createVector($this->x, $this->y + $this->height, $this->z), Vector3::createVector($entity->x, $entity->y + $entity->height, $entity->z)) === null;
	}

	public function heal($amount, EntityRegainHealthEvent $source){
		parent::heal($amount, $source);
		if($source->isCancelled()){
			return;
		}

		$this->attackTime = 0;
	}

	public function attack($damage, EntityDamageEvent $source){
		if($this->attackTime > 0 or $this->noDamageTicks > 0){
			$lastCause = $this->getLastDamageCause();
			if($lastCause !== null and $lastCause->getDamage() >= $damage){
				$source->setCancelled();
			}
		}

		parent::attack($damage, $source);

		if($source->isCancelled()){
			return;
		}

		if($source instanceof EntityDamageByEntityEvent){
			$e = $source->getDamager();
			if($source instanceof EntityDamageByChildEntityEvent){
				$e = $source->getChild();
			}

			if($e->isOnFire() > 0){
				$this->setOnFire(2 * $this->server->getDifficulty());
			}

			$deltaX = $this->x - $e->x;
			$deltaZ = $this->z - $e->z;
			$this->knockBack($e, $damage, $deltaX, $deltaZ, $source->getKnockBack());
		}

		$pk = new EntityEventPacket();
		$pk->eid = $this->getId();
		$pk->event = $this->getHealth() <= 0 ? EntityEventPacket::DEATH_ANIMATION : EntityEventPacket::HURT_ANIMATION; //Ouch!
		$this->server->broadcastPacket($this->hasSpawned, $pk);

		$this->attackTime = 10; //0.5 seconds cooldown
	}

	public function knockBack(Entity $attacker, $damage, $x, $z, $base = 0.4){
		$f = sqrt($x * $x + $z * $z);
		if($f <= 0){
			return;
		}

		$f = 1 / $f;

		$motion = new Vector3($this->motionX, $this->motionY, $this->motionZ);

		$motion->x /= 2;
		$motion->y /= 2;
		$motion->z /= 2;
		$motion->x += $x * $f * $base;
		$motion->y += $base;
		$motion->z += $z * $f * $base;

		if($motion->y > $base){
			$motion->y = $base;
		}

		$this->setMotion($motion);
	}

	public function kill(){
		if(!$this->isAlive()){
			return;
		}
		parent::kill();
		$this->server->getPluginManager()->callEvent($ev = new EntityDeathEvent($this, $this->getDrops()));
		foreach($ev->getDrops() as $item){
			$this->getLevel()->dropItem($this, $item);
		}
	}

	public function entityBaseTick($tickDiff = 1){
		Timings::$timerLivingEntityBaseTick->startTiming();
		$this->setDataFlag(self::DATA_FLAGS, self::DATA_FLAG_BREATHING, !$this->isInsideOfWater());
		if ($this->jumpTicks > 0){
			--$this->jumpTicks;
		}

		$hasUpdate = parent::entityBaseTick($tickDiff);
		if($this->server->entityAIEnabled && !($this instanceof Human)){
			$this->updateEntityActionState();
			$this->moveStrafing *= 0.98;
			$this->moveForward *= 0.98;
			//$this->moveStrafing  = 0.01;
			//$this->moveForward = 0.05;
			$this->moveEntityWithHeading($this->moveStrafing, $this->moveForward);
		}

		if($this->isAlive()){
			if($this->isInsideOfSolid()){
				$hasUpdate = true;
				$ev = new EntityDamageEvent($this, EntityDamageEvent::CAUSE_SUFFOCATION, 1);
				$this->attack($ev->getFinalDamage(), $ev);
			}

			if(!$this->hasEffect(Effect::WATER_BREATHING) and $this->isInsideOfWater()){
				if($this instanceof WaterAnimal){
					$this->setDataProperty(self::DATA_AIR, self::DATA_TYPE_SHORT, 400);
				}else{
					$hasUpdate = true;
					$airTicks = $this->getDataProperty(self::DATA_AIR) - $tickDiff;
					if($airTicks <= -20){
						$airTicks = 0;

						$ev = new EntityDamageEvent($this, EntityDamageEvent::CAUSE_DROWNING, 2);
						$this->attack($ev->getFinalDamage(), $ev);
					}
					$this->setDataProperty(self::DATA_AIR, self::DATA_TYPE_SHORT, $airTicks);
				}
			}else{
				if($this instanceof WaterAnimal){
					$hasUpdate = true;
					$airTicks = $this->getDataProperty(self::DATA_AIR) - $tickDiff;
					if($airTicks <= -20){
						$airTicks = 0;

						$ev = new EntityDamageEvent($this, EntityDamageEvent::CAUSE_SUFFOCATION, 2);
						$this->attack($ev->getFinalDamage(), $ev);
					}
					$this->setDataProperty(self::DATA_AIR, self::DATA_TYPE_SHORT, $airTicks);
				}else{
					$this->setDataProperty(self::DATA_AIR, self::DATA_TYPE_SHORT, 400);
				}
			}

			if($this->server->entityAIEnabled){
				if ($this->isJumping){
					if ($this->isInsideOfWater()){
						$this->updateAITick();
					}else if ($this->isInsideOfLava()){
						$this->handleJumpLava();
					}else if ($this->onGround && $this->jumpTicks == 0){
						$this->doJump();
						$this->jumpTicks = 10;
					}
				}else{
					$this->jumpTicks = 0;
				}
			}
		}

		if($this->server->entityAIEnabled && !($this instanceof Human)){
			if ($this->entityLivingToAttack != null){
				if (!$this->entityLivingToAttack->isAlive()){
					$this->setRevengeTarget(null);
				}else if ($this->server->getTick() - $this->revengeTimer > 100){
					$this->setRevengeTarget(null);
				}
			}
		}

		if($this->attackTime > 0){
			$this->attackTime -= $tickDiff;
		}

		Timings::$timerLivingEntityBaseTick->stopTiming();

		return $hasUpdate;
	}

	public function moveEntityWithHeading($strafe, $forward){
		if (!$this->isInsideOfWater() || $this instanceof Player && $this->isFlying()){
			if (!$this->isInsideOfLava() || $this instanceof Player && $this->isFlying()){
				$f4 = 0.91;

				if ($this->onGround){
					$f4 = 0.91;
				}

				$f = 0.16277136 / ($f4 * $f4 * $f4);

				if ($this->onGround){
					$f5 = $this->getAIMoveSpeed() * $f;
				}else{
					$f5 = $this->jumpMovementFactor;
				}

				$this->moveFlying($strafe, $forward, $f5);
				$f4 = 0.91;

				if ($this->onGround){
					$f4 = 0.91;
				}

				$this->move($this->motionX, $this->motionY, $this->motionZ);

				if ($this->y > 0.0){
					$this->motionY = -0.1;
				}else{
					$this->motionY = 0.0;
				}

				$this->motionY *= 0.9800000190734863;
				$this->motionX *= $f4;
				$this->motionZ *= $f4;
			}else{
				$d1 = $this->y;
				$this->moveFlying($strafe, $forward, 0.02);
				$this->move($this->motionX, $this->motionY, $this->motionZ);
				$this->motionX *= 0.5;
				$this->motionY *= 0.5;
				$this->motionZ *= 0.5;
				$this->motionY -= 0.02;
			}
		}else{
			$d0 = $this->y;
			$f1 = 0.8;
			$f2 = 0.02;
			$f3 = 0;//水中移動のえんちゃんとレベル

			if ($f3 > 3.0){
				$f3 = 3.0;
			}

			if (!$this->onGround){
				$f3 *= 0.5;
			}

			if ($f3 > 0.0){
				$f1 += (0.54600006 - $f1) * $f3 / 3.0;
				$f2 += ($this->getAIMoveSpeed() * 1.0 - $f2) * $f3 / 3.0;
			}

			$this->moveFlying($strafe, $forward, $f2);
			$this->move($this->motionX, $this->motionY, $this->motionZ);
			$this->motionX *= $f1;
			$this->motionY *= 0.800000011920929;
			$this->motionZ *= $f1;
			$this->motionY -= 0.02;
		}
	}

	/**
	 * @return ItemItem[]
	 */
	public function getDrops(){
		return [];
	}

	/**
	 * @param int   $maxDistance
	 * @param int   $maxLength
	 * @param array $transparent
	 *
	 * @return Block[]
	 */
	public function getLineOfSight($maxDistance, $maxLength = 0, array $transparent = []){
		if($maxDistance > 120){
			$maxDistance = 120;
		}

		if(count($transparent) === 0){
			$transparent = null;
		}

		$blocks = [];
		$nextIndex = 0;

		$itr = new BlockIterator($this->level, $this->getPosition(), $this->getDirectionVector(), $this->getEyeHeight(), $maxDistance);

		while($itr->valid()){
			$itr->next();
			$block = $itr->current();
			$blocks[$nextIndex++] = $block;

			if($maxLength !== 0 and count($blocks) > $maxLength){
				array_shift($blocks);
				--$nextIndex;
			}

			$id = $block->getId();

			if($transparent === null){
				if($id !== 0){
					break;
				}
			}else{
				if(!isset($transparent[$id])){
					break;
				}
			}
		}

		return $blocks;
	}

	/**
	 * @param int   $maxDistance
	 * @param array $transparent
	 *
	 * @return Block
	 */
	public function getTargetBlock($maxDistance, array $transparent = []){
		try{
			$block = $this->getLineOfSight($maxDistance, 1, $transparent)[0];
			if($block instanceof Block){
				return $block;
			}
		}catch(\ArrayOutOfBoundsException $e){
		}

		return null;
	}

	public function updateEntityActionState(){
		$this->targetTasks->onUpdateTasks();
		$this->tasks->onUpdateTasks();
		$this->navigator->onUpdateNavigation();
		$this->updateAITasks();
		$this->moveHelper->onUpdateMoveHelper();
		$this->lookHelper->onUpdateLook();
		$this->jumpHelper->doJump();
		$this->updateMovement();
		return true;
	}

	public function updateAITasks(){
	}

	public function updateAITick(){
		$this->motionY += 0.03999999910593033;
	}

	protected function getJumpUpwardsMotion(){
		return 0.42;
	}

	protected function handleJumpLava(){
		$this->motionY += 0.03999999910593033;
	}

	protected function doJump(){
		$this->motionY = $this->getJumpUpwardsMotion();

		//if (this.isPotionActive(Potion.jump)){
		//	this.motionY += (double)((float)(this.getActivePotionEffect(Potion.jump).getAmplifier() + 1) * 0.1F);
		//}

		if ($this->isSprinting()){
			$f = $this->yaw * 0.017453292;
			$this->motionX -= sin($f) * 0.2;
			$this->motionZ += cos($f) * 0.2;
		}
	}

	public function setJumping($jumping){
		$this->isJumping = $jumping;
	}

	public function setMoveForward($forward){
		$this->moveForward = $forward;
	}

	public function getAIMoveSpeed(){
		//echo($this->landMovementFactor);
		return $this->landMovementFactor;
	}

	public function setAIMoveSpeed($speedIn){
		$this->landMovementFactor = $speedIn;
		$this->setMoveForward($speedIn);
	}

	protected function getNewNavigator($worldIn){
		return new PathNavigateGround($this, $worldIn);
	}

	public function getNavigator(){
		return $this->navigator;
	}

	public function getAITarget(){
		return $this->entityLivingToAttack;
	}

	public function getRevengeTimer(){
		return $this->revengeTimer;
	}

	public function setRevengeTarget($livingBase){
		$this->entityLivingToAttack = $livingBase;
		$this->revengeTimer = $this->server->getTick();
	}

	public function getAttackTarget(){
		return $this->attackTarget;
	}

	public function setAttackTarget($entitylivingbaseIn){
		$this->attackTarget = $entitylivingbaseIn;
	}

	public function getMoveHelper(){
		return $this->moveHelper;
	}

	public function getLookHelper(){
		return $this->lookHelper;
	}

	public function getJumpHelper(){
		return $this->jumpHelper;
	}
}
